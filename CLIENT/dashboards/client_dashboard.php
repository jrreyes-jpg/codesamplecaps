<?php
define('AUTH_REQUIRED_ROLE', 'client');
require_once __DIR__ . '/../../config/auth_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/project_progress.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$clientName = trim((string)($_SESSION['name'] ?? 'Client User'));
$clientEmail = trim((string)($_SESSION['email'] ?? ''));
$clientEmailDisplay = $clientEmail !== '' ? $clientEmail : 'No email on record';
$clientInitial = strtoupper(substr($clientName !== '' ? $clientName : 'C', 0, 1));

function client_format_date(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return 'Not set';
    }

    try {
        return (new DateTimeImmutable($value))->format('M j, Y');
    } catch (Throwable $exception) {
        return $value;
    }
}

function client_project_timeline(?string $poDate, ?string $completedDate, string $status): string
{
    $parts = ['P.O Date: ' . client_format_date($poDate)];

    if ($status === 'completed') {
        $parts[] = 'Completed: ' . client_format_date($completedDate);
    }

    return implode(' | ', $parts);
}

function client_status_label(string $status): string
{
    $labels = [
        'pending' => 'Pending',
        'ongoing' => 'In Progress',
        'completed' => 'Completed',
        'on-hold' => 'On Hold',
    ];

    return $labels[$status] ?? ucfirst(str_replace('-', ' ', $status));
}

function client_build_deadline_meta(?string $deadline, string $status): array
{
    $deadline = trim((string)$deadline);
    if ($deadline === '') {
        return [
            'label' => 'No deadline',
            'class' => 'deadline-flag--neutral',
        ];
    }

    $deadlineDate = DateTimeImmutable::createFromFormat('Y-m-d', $deadline);
    if (!$deadlineDate) {
        return [
            'label' => $deadline,
            'class' => 'deadline-flag--neutral',
        ];
    }

    if ($status === 'completed') {
        return [
            'label' => 'Delivered',
            'class' => 'deadline-flag--ok',
        ];
    }

    $today = new DateTimeImmutable('today');
    $days = (int)$today->diff($deadlineDate)->format('%r%a');

    if ($days < 0) {
        return [
            'label' => 'Overdue by ' . abs($days) . ' day' . (abs($days) === 1 ? '' : 's'),
            'class' => 'deadline-flag--danger',
        ];
    }

    if ($days <= 2) {
        return [
            'label' => $days === 0 ? 'Due today' : 'Due in ' . $days . ' day' . ($days === 1 ? '' : 's'),
            'class' => 'deadline-flag--warning',
        ];
    }

    return [
        'label' => 'Due ' . $deadlineDate->format('M j, Y'),
        'class' => 'deadline-flag--ok',
    ];
}

/* PROJECT SUMMARY COUNTS */
$totalProjectsStmt = $conn->prepare("SELECT COUNT(*) FROM projects WHERE client_id = ? AND status <> 'draft'");
$totalProjectsStmt->bind_param('i', $userId);
$totalProjectsStmt->execute();
$totalProjectsStmt->bind_result($totalCount);
$totalProjectsStmt->fetch();
$totalProjectsStmt->close();

$ongoingStmt = $conn->prepare("SELECT COUNT(*) FROM projects WHERE client_id = ? AND status = 'ongoing'");
$ongoingStmt->bind_param('i', $userId);
$ongoingStmt->execute();
$ongoingStmt->bind_result($ongoingCount);
$ongoingStmt->fetch();
$ongoingStmt->close();

$completedStmt = $conn->prepare("SELECT COUNT(*) FROM projects WHERE client_id = ? AND status = 'completed'");
$completedStmt->bind_param('i', $userId);
$completedStmt->execute();
$completedStmt->bind_result($completedCount);
$completedStmt->fetch();
$completedStmt->close();

$engineerSummaryRow = [
    'total_engineers' => 0,
    'active_engineers' => 0,
];
$engineerSummaryResult = $conn->query(
    "SELECT
        COUNT(*) AS total_engineers,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_engineers
     FROM users
     WHERE role = 'engineer'"
);
if ($engineerSummaryResult) {
    $engineerSummaryRow = $engineerSummaryResult->fetch_assoc() ?: $engineerSummaryRow;
}
$totalEngineerCount = (int)($engineerSummaryRow['total_engineers'] ?? 0);
$activeEngineerCount = (int)($engineerSummaryRow['active_engineers'] ?? 0);

$engineersPreview = [];
$engineersPreviewResult = $conn->query(
    "SELECT full_name, status
     FROM users
     WHERE role = 'engineer'
     ORDER BY CASE WHEN status = 'active' THEN 0 ELSE 1 END, full_name ASC
     LIMIT 5"
);
if ($engineersPreviewResult) {
    $engineersPreview = $engineersPreviewResult->fetch_all(MYSQLI_ASSOC);
}

$projectsStmt = $conn->prepare(
    "SELECT
        p.id,
        p.project_name,
        p.description,
        p.start_date,
        p.end_date,
        p.status,
        p.created_at,
        engineer.full_name AS engineer_name,
        COALESCE(task_totals.total_tasks, 0) AS total_tasks,
        COALESCE(task_totals.completed_tasks, 0) AS completed_tasks,
        COALESCE(task_totals.ongoing_tasks, 0) AS ongoing_tasks,
        COALESCE(task_totals.delayed_tasks, 0) AS delayed_tasks,
        task_totals.next_deadline
     FROM projects p
     LEFT JOIN (
         SELECT pa.project_id, pa.engineer_id
         FROM project_assignments pa
         INNER JOIN (
             SELECT project_id, MAX(id) AS latest_id
             FROM project_assignments
             GROUP BY project_id
         ) latest_assignment ON latest_assignment.latest_id = pa.id
     ) latest_assignment ON latest_assignment.project_id = p.id
     LEFT JOIN users engineer ON engineer.id = latest_assignment.engineer_id
     LEFT JOIN (
         SELECT
             project_id,
             COUNT(*) AS total_tasks,
             SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_tasks,
             SUM(CASE WHEN status = 'ongoing' THEN 1 ELSE 0 END) AS ongoing_tasks,
             SUM(CASE WHEN status = 'delayed' THEN 1 ELSE 0 END) AS delayed_tasks,
             MIN(CASE WHEN status <> 'completed' AND deadline IS NOT NULL THEN deadline END) AS next_deadline
         FROM tasks
         GROUP BY project_id
     ) task_totals ON task_totals.project_id = p.id
     WHERE p.client_id = ?
     AND p.status <> 'draft'
     ORDER BY
        CASE p.status
            WHEN 'ongoing' THEN 1
            WHEN 'pending' THEN 2
            WHEN 'on-hold' THEN 3
            WHEN 'completed' THEN 4
            ELSE 5
        END,
        p.created_at DESC,
        p.id DESC"
);
$projectsStmt->bind_param('i', $userId);
$projectsStmt->execute();
$projectsResult = $projectsStmt->get_result();
$projectRows = $projectsResult ? $projectsResult->fetch_all(MYSQLI_ASSOC) : [];
$projectsStmt->close();

$pendingCount = 0;
$onHoldCount = 0;
$overallTasks = 0;
$overallCompletedTasks = 0;
$nextDeadlineValue = null;

foreach ($projectRows as $project) {
    $status = (string)($project['status'] ?? 'pending');

    if ($status === 'pending') {
        $pendingCount++;
    } elseif ($status === 'on-hold') {
        $onHoldCount++;
    }

    $overallTasks += (int)($project['total_tasks'] ?? 0);
    $overallCompletedTasks += (int)($project['completed_tasks'] ?? 0);

    $candidateDeadline = trim((string)($project['next_deadline'] ?? ''));
    if ($candidateDeadline !== '' && ($nextDeadlineValue === null || $candidateDeadline < $nextDeadlineValue)) {
        $nextDeadlineValue = $candidateDeadline;
    }
}

$activeProjectCount = $ongoingCount + $pendingCount + $onHoldCount;
$clientProgressTotals = 0;
foreach ($projectRows as $project) {
    $clientProgressTotals += (int)build_role_project_progress($project, 'client')['percent'];
}
$overallProgressPercent = $totalCount > 0 ? (int)round($clientProgressTotals / $totalCount) : 0;
$nextDeadlineDisplay = $nextDeadlineValue !== null ? client_format_date($nextDeadlineValue) : 'No active deadline';
$nextDeadlineHint = $nextDeadlineValue !== null
    ? 'Closest open target across your current projects'
    : 'No upcoming task deadline is tracked right now';

$portfolioMix = [
    ['label' => 'Completed', 'count' => $completedCount, 'class' => 'is-completed'],
    ['label' => 'In Progress', 'count' => $ongoingCount, 'class' => 'is-ongoing'],
    ['label' => 'Pending', 'count' => $pendingCount, 'class' => 'is-pending'],
    ['label' => 'On Hold', 'count' => $onHoldCount, 'class' => 'is-on-hold'],
];

$notificationItems = [
    [
        'title' => $activeProjectCount . ' active project(s)',
        'detail' => 'Pending, ongoing, and on-hold work still needs visibility.',
    ],
    [
        'title' => $overallProgressPercent . '% overall progress',
        'detail' => $overallTasks > 0
            ? $overallCompletedTasks . ' of ' . $overallTasks . ' tracked tasks are already complete.'
            : 'No task progress data is available yet.',
    ],
    [
        'title' => $nextDeadlineDisplay,
        'detail' => $nextDeadlineValue !== null ? 'Closest open deadline in your current project queue.' : 'No urgent due date is currently recorded.',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Dashboard - Edge Automation</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/client_sidebar.css">
    <link rel="stylesheet" href="../css/client_dashboard.css">
</head>
<body>
    <?php include '../sidebar/client_sidebar.php'; ?>

    <main class="main-content" id="mainContent">
        <header class="global-topbar" aria-live="polite">
            <div class="global-topbar__copy">
                <img src="/codesamplecaps/IMAGES/edge.jpg" alt="Edge Automation logo" class="global-topbar__brand-logo">
                <div class="global-topbar__copy-text">
                    <strong>EDGE Automation</strong>
                    <span>Welcome, <?php echo htmlspecialchars($clientName); ?></span>
                </div>
            </div>

            <div class="global-topbar__actions">
                <div class="topbar-profile" data-profile-root>
                    <button
                        id="topbarProfileToggle"
                        class="topbar-profile__toggle"
                        type="button"
                        aria-label="Open profile menu"
                        aria-controls="topbarProfileDropdown"
                        aria-expanded="false"
                    >
                        <span class="topbar-profile__avatar" aria-hidden="true"><?php echo htmlspecialchars($clientInitial); ?></span>
                        <span class="topbar-profile__identity">
                            <strong>Client</strong>
                            <span><?php echo htmlspecialchars($clientName); ?></span>
                        </span>
                        <span class="topbar-profile__chevron" aria-hidden="true">
                            <svg viewBox="0 0 20 20" focusable="false">
                                <path d="M5 7.5 10 12.5 15 7.5"></path>
                            </svg>
                        </span>
                    </button>

                    <div id="topbarProfileDropdown" class="topbar-profile__dropdown" hidden>
                        <div class="topbar-profile__panel-head">
                            <span class="topbar-profile__avatar topbar-profile__avatar--panel" aria-hidden="true"><?php echo htmlspecialchars($clientInitial); ?></span>
                            <div>
                                <strong><?php echo htmlspecialchars($clientName); ?></strong>
                                <span>Client</span>
                            </div>
                        </div>
                        <div class="topbar-profile__links">
                            <a href="#overview-section">Dashboard</a>
                            <a href="#projects-tab">Projects</a>
                            <a href="#profile-tab">Profile</a>
                            <a href="../../LOGIN/php/logout.php">Logout</a>
                        </div>
                    </div>
                </div>

                <div class="topbar-notifications" data-notification-root>
                    <button
                        id="topbarNotificationToggle"
                        class="topbar-notifications__toggle"
                        type="button"
                        aria-label="Open notifications"
                        aria-controls="topbarNotificationDropdown"
                        aria-expanded="false"
                    >
                          <span class="topbar-notifications__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" focusable="false">
                        <path d="M12 3a4 4 0 0 0-4 4v1.1a7 7 0 0 1-1.52 4.33L5 14.5V16h14v-1.5l-1.48-2.07A7 7 0 0 1 16 8.1V7a4 4 0 0 0-4-4Zm0 18a3 3 0 0 0 2.83-2H9.17A3 3 0 0 0 12 21Z" fill="currentColor"/>
                    </svg>
                </span>
                        <?php if ($activeProjectCount > 0): ?>
                            <span class="topbar-notifications__badge"><?php echo $activeProjectCount; ?></span>
                        <?php endif; ?>
                    </button>

                    <div id="topbarNotificationDropdown" class="topbar-notifications__dropdown" hidden>
                        <div class="topbar-notifications__panel-head">
                            <div>
                                <strong>Project Updates</strong>
                                <span><?php echo (int)$activeProjectCount; ?> active items</span>
                            </div>
                        </div>
                        <div class="topbar-notifications__section">
                            <div class="topbar-notifications__section-title">Recent signals</div>
                            <?php foreach ($notificationItems as $notification): ?>
                                <article class="notification-item notification-item--neutral">
                                    <span class="notification-item__dot"></span>
                                    <div class="notification-item__copy">
                                        <strong><?php echo htmlspecialchars($notification['title']); ?></strong>
                                        <span><?php echo htmlspecialchars($notification['detail']); ?></span>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="global-topbar__clock">
                    <span class="global-topbar__clock-label">Philippines Time</span>
                    <strong class="global-topbar__time" data-ph-time>--:--:--</strong>
                    <span class="global-topbar__date" data-ph-date>Loading date...</span>
                </div>
            </div>
        </header>

        <section id="overview-section" class="tab-content active">
            <div class="section-heading">
                <div>
                    <span class="section-badge">Dashboard</span>
                    <h2>Overview</h2>
                    <p>Quick summary of your projects, live delivery progress, and team visibility.</p>
                </div>
            </div>

            <section class="stats-grid" aria-label="Project summary cards">
                <article class="stat-card">
                    <div class="stat-card__icon stat-card__icon--projects" aria-hidden="true">
                        <svg viewBox="0 0 24 24">
                            <path d="M4 7a2 2 0 0 1 2-2h4l2 2h6a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7z"></path>
                        </svg>
                    </div>
                    <div class="stat-card__content">
                        <span>Your Projects</span>
                        <strong><?php echo $totalCount; ?></strong>
                        <small>All active and completed client projects</small>
                    </div>
                </article>

                <article class="stat-card">
                    <div class="stat-card__icon stat-card__icon--ongoing" aria-hidden="true">
                        <svg viewBox="0 0 24 24">
                            <path d="M12 6v6l4 2"></path>
                            <path d="M21 12a9 9 0 1 1-3-6.7"></path>
                        </svg>
                    </div>
                    <div class="stat-card__content">
                        <span>In Progress</span>
                        <strong><?php echo $ongoingCount; ?></strong>
                        <small>Projects currently being delivered</small>
                    </div>
                </article>

                <article class="stat-card">
                    <div class="stat-card__icon stat-card__icon--completed" aria-hidden="true">
                        <svg viewBox="0 0 24 24">
                            <path d="M20 6L9 17l-5-5"></path>
                        </svg>
                    </div>
                    <div class="stat-card__content">
                        <span>Completed</span>
                        <strong><?php echo $completedCount; ?></strong>
                        <small>Projects already delivered to completion</small>
                    </div>
                </article>

                <article class="stat-card">
                    <div class="stat-card__icon stat-card__icon--engineers" aria-hidden="true">
                        <svg viewBox="0 0 24 24">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"></path>
                            <path d="M9.5 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"></path>
                            <path d="M21 21v-2a4 4 0 0 0-3-3.87"></path>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                        </svg>
                    </div>
                    <div class="stat-card__content">
                        <span>Active Engineers</span>
                        <strong><?php echo $activeEngineerCount; ?></strong>
                        <small><?php echo $totalEngineerCount; ?> engineering team members in total</small>
                    </div>
                </article>
            </section>

            <section class="status-strip" aria-label="Portfolio mix">
                <?php foreach ($portfolioMix as $mix): ?>
                    <article class="status-strip__item status-strip__item--<?php echo htmlspecialchars($mix['class']); ?>">
                        <span><?php echo htmlspecialchars($mix['label']); ?></span>
                        <strong><?php echo (int)$mix['count']; ?></strong>
                    </article>
                <?php endforeach; ?>
            </section>
        </section>

        <section id="projects-tab" class="tab-content">
            <div class="section-heading">
                <div>
                    <span class="section-badge">Projects</span>
                    <h2>My Projects</h2>
                    <p>Each card shows delivery status, assigned engineer, and real task-based progress.</p>
                </div>
            </div>

            <div class="client-project-search" data-client-project-search>
                <div class="client-project-search__input-row">
                    <span class="client-project-search__icon" aria-hidden="true">&#128269;</span>
                    <input
                        type="text"
                        id="client-project-search"
                        class="client-project-search__input"
                        placeholder="Search project, engineer, timeline, or status"
                        autocomplete="off"
                        aria-label="Search my projects"
                        aria-controls="client-project-search-dropdown"
                        aria-expanded="false"
                    >
                    <button type="button" class="client-project-search__clear" id="client-project-search-clear" aria-label="Clear client project search">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="client-project-search__meta">
                    <span id="client-project-search-hint">Type your keyword, then pause for 3 seconds to search.</span>
                    <span id="client-project-search-count"><?php echo count($projectRows); ?> project(s)</span>
                </div>
                <div class="client-project-search__dropdown" id="client-project-search-dropdown" role="listbox" hidden></div>
            </div>

            <div class="dashboard-content-grid">
                <div class="projects-grid">
                    <?php if (!empty($projectRows)): ?>
                        <?php foreach ($projectRows as $project): ?>
                            <?php
                            $projectStatus = (string)($project['status'] ?? 'pending');
                            $projectProgress = build_role_project_progress($project, 'client');
                            $deadlineMeta = client_build_deadline_meta($project['next_deadline'] ?? null, $projectStatus);
                            $projectDescription = trim((string)($project['description'] ?? ''));
                            if ($projectDescription === '') {
                                $projectDescription = 'Project details will appear here as the work scope is finalized.';
                            }
                            $projectSearchText = strtolower(trim(implode(' ', [
                                $project['project_name'] ?? '',
                                $project['engineer_name'] ?? '',
                                $project['status'] ?? '',
                                $project['start_date'] ?? '',
                                $project['end_date'] ?? '',
                                $projectDescription,
                            ])));
                            ?>
                            <article
                                class="project-card project-card--<?php echo htmlspecialchars($projectStatus); ?>"
                                data-client-project-card
                                data-search="<?php echo htmlspecialchars($projectSearchText); ?>"
                                data-title="<?php echo htmlspecialchars((string)($project['project_name'] ?? 'Untitled Project')); ?>"
                                data-engineer="<?php echo htmlspecialchars((string)($project['engineer_name'] ?? 'Not assigned')); ?>"
                                data-status="<?php echo htmlspecialchars($projectStatus); ?>"
                                data-timeline="<?php echo htmlspecialchars(client_project_timeline($project['start_date'] ?? null, $project['end_date'] ?? null, $projectStatus)); ?>"
                            >
                                <div class="project-card__header">
                                    <div>
                                        <span class="project-card__eyebrow">Project #<?php echo (int)($project['id'] ?? 0); ?></span>
                                        <h3><?php echo htmlspecialchars((string)($project['project_name'] ?? 'Untitled Project')); ?></h3>
                                    </div>
                                    <span class="status-badge status-badge--<?php echo htmlspecialchars($projectStatus); ?>">
                                        <?php echo htmlspecialchars(client_status_label($projectStatus)); ?>
                                    </span>
                                </div>

                                <p class="project-card__description"><?php echo htmlspecialchars(substr($projectDescription, 0, 180)); ?></p>

                                <div class="project-card__meta-grid">
                                    <div class="project-meta">
                                        <span>Assigned Engineer</span>
                                        <strong><?php echo htmlspecialchars((string)($project['engineer_name'] ?? 'Not assigned')); ?></strong>
                                    </div>
                                    <div class="project-meta">
                                        <span>Project Dates</span>
                                        <strong><?php echo htmlspecialchars(client_project_timeline($project['start_date'] ?? null, $project['end_date'] ?? null, $projectStatus)); ?></strong>
                                    </div>
                                </div>

                                <div class="project-progress">
                                    <div class="project-progress__meta">
                                        <strong><?php echo (int)$projectProgress['percent']; ?>%</strong>
                                        <span><?php echo htmlspecialchars((string)$projectProgress['summary']); ?></span>
                                    </div>
                                    <div class="project-progress__bar" aria-hidden="true">
                                        <span style="width: <?php echo (int)$projectProgress['percent']; ?>%;"></span>
                                    </div>
                                </div>
                                <p class="project-card__description"><?php echo htmlspecialchars((string)$projectProgress['hint']); ?></p>

                                <div class="project-card__footer">
                                    <span class="project-pill"><?php echo (int)($project['ongoing_tasks'] ?? 0); ?> active tasks</span>
                                    <span class="project-pill project-pill--alert"><?php echo (int)($project['delayed_tasks'] ?? 0); ?> delayed</span>
                                    <span class="deadline-flag <?php echo htmlspecialchars($deadlineMeta['class']); ?>">
                                        <?php echo htmlspecialchars($deadlineMeta['label']); ?>
                                    </span>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <h3>No projects yet</h3>
                            <p>Your active project cards will appear here once work is assigned to your account.</p>
                        </div>
                    <?php endif; ?>
                    <div class="empty-state empty-state--search" id="client-project-search-empty" hidden>
                        <h3>No matching projects</h3>
                        <p>Try a different project name, engineer, status, or timeline keyword.</p>
                    </div>
                </div>

                <aside class="dashboard-aside">
                    <article class="aside-card">
                        <div class="aside-card__head">
                            <div>
                                <span class="section-badge">Support</span>
                                <h3>Engineering Desk</h3>
                            </div>
                        </div>
                        <p>Quick visibility into the engineering team currently supporting delivery work.</p>
                        <div class="engineer-list">
                            <?php if (!empty($engineersPreview)): ?>
                                <?php foreach ($engineersPreview as $engineer): ?>
                                    <div class="engineer-list__item">
                                        <div>
                                            <strong><?php echo htmlspecialchars((string)($engineer['full_name'] ?? 'Engineer')); ?></strong>
                                            <span>Engineering support</span>
                                        </div>
                                        <span class="mini-status mini-status--<?php echo htmlspecialchars((string)($engineer['status'] ?? 'inactive')); ?>">
                                            <?php echo htmlspecialchars(ucfirst((string)($engineer['status'] ?? 'inactive'))); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="aside-card__empty">No engineer availability data yet.</p>
                            <?php endif; ?>
                        </div>
                    </article>

                    <article class="aside-card">
                        <div class="aside-card__head">
                            <div>
                                <span class="section-badge">Portfolio</span>
                                <h3>Project Mix</h3>
                            </div>
                        </div>

                        <div class="mix-bars">
                            <?php foreach ($portfolioMix as $mix): ?>
                                <?php
                                $mixCount = (int)$mix['count'];
                                $mixWidth = $totalCount > 0 ? (int)round(($mixCount / $totalCount) * 100) : 0;
                                ?>
                                <div class="mix-bars__row">
                                    <div class="mix-bars__meta">
                                        <span><?php echo htmlspecialchars($mix['label']); ?></span>
                                        <strong><?php echo $mixCount; ?></strong>
                                    </div>
                                    <div class="mix-bars__track">
                                        <span class="mix-bars__fill <?php echo htmlspecialchars($mix['class']); ?>" style="width: <?php echo $mixWidth; ?>%;"></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </article>
                </aside>
            </div>
        </section>

        <section id="profile-tab" class="tab-content">
            <div class="section-heading">
                <div>
                    <span class="section-badge">Profile</span>
                    <h2>Account Details</h2>
                    <p>Your account is read-only here so client records stay consistent across the system.</p>
                </div>
            </div>

            <div class="profile-grid">
                <article class="profile-card profile-card--identity">
                    <div class="profile-card__avatar" aria-hidden="true"><?php echo htmlspecialchars($clientInitial); ?></div>
                    <div class="profile-card__identity">
                        <h3><?php echo htmlspecialchars($clientName); ?></h3>
                        <p><?php echo htmlspecialchars($clientEmailDisplay); ?></p>
                    </div>
                    <div class="profile-highlights">
                        <span>Role: Client</span>
                        <span>Projects: <?php echo $totalCount; ?></span>
                        <span>Active: <?php echo $activeProjectCount; ?></span>
                    </div>
                </article>

                <article class="profile-card">
                    <h3>Profile Snapshot</h3>
                    <div class="profile-form-grid">
                        <div class="form-field">
                            <label>Full Name</label>
                            <input type="text" value="<?php echo htmlspecialchars($clientName); ?>" disabled>
                        </div>
                        <div class="form-field">
                            <label>Email</label>
                            <input type="email" value="<?php echo htmlspecialchars($clientEmailDisplay); ?>" disabled>
                        </div>
                        <div class="form-field">
                            <label>Account Type</label>
                            <input type="text" value="Client" disabled>
                        </div>
                        <div class="form-field">
                            <label>Progress Visibility</label>
                            <input type="text" value="Enabled" disabled>
                        </div>
                    </div>

                    <div class="profile-actions">
                        <button type="button" class="btn-secondary" data-jump-tab="projects-tab">Back to Projects</button>
                        <a href="/codesamplecaps/LOGIN/php/forgot.php" class="btn-ghost">Reset Password</a>
                    </div>
                </article>

                <article class="profile-card profile-card--support">
                    <h3>Need help?</h3>
                    <p>
                        For timeline changes, new scope requests, or account concerns, coordinate with the Edge Automation
                        admin team so project records and approvals stay aligned.
                    </p>
                    <div class="support-notes">
                        <span>Review live task progress from the project cards.</span>
                        <span>Use the password reset flow if you need credential help.</span>
                        <span>Track upcoming deadlines from the top dashboard summary.</span>
                    </div>
                </article>
            </div>
        </section>
    </main>

    <script src="../js/client_dashboard.js"></script>
</body>
</html>
