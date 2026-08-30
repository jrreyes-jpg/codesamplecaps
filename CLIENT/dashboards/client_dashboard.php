<?php
define('AUTH_REQUIRED_ROLE', 'client');
require_once __DIR__ . '/../../config/auth_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/project_progress.php';
require_once __DIR__ . '/../includes/client_helpers.php';
require_once __DIR__ . '/../includes/client_shell.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$clientName = trim((string)($_SESSION['name'] ?? 'Client User'));
$clientInitial = client_shell_initial($clientName);
$activeProjectFilter = client_column_exists($conn, 'projects', 'deleted_at')
    ? ' AND p.deleted_at IS NULL'
    : '';

$summaryStmt = $conn->prepare(
    "SELECT
        COUNT(*) AS total_projects,
        SUM(CASE WHEN p.status = 'ongoing' THEN 1 ELSE 0 END) AS ongoing_projects,
        SUM(CASE WHEN p.status = 'completed' THEN 1 ELSE 0 END) AS completed_projects,
        SUM(CASE WHEN p.status = 'pending' THEN 1 ELSE 0 END) AS pending_projects,
        SUM(CASE WHEN p.status = 'on-hold' THEN 1 ELSE 0 END) AS on_hold_projects
     FROM projects p
     WHERE p.client_id = ?
     AND p.status <> 'draft'
     {$activeProjectFilter}"
);
$summaryStmt->bind_param('i', $userId);
$summaryStmt->execute();
$summaryResult = $summaryStmt->get_result();
$summary = $summaryResult ? ($summaryResult->fetch_assoc() ?: []) : [];
$summaryStmt->close();

$totalCount = (int)($summary['total_projects'] ?? 0);
$ongoingCount = (int)($summary['ongoing_projects'] ?? 0);
$completedCount = (int)($summary['completed_projects'] ?? 0);
$pendingCount = (int)($summary['pending_projects'] ?? 0);
$onHoldCount = (int)($summary['on_hold_projects'] ?? 0);
$activeProjectCount = $ongoingCount + $pendingCount + $onHoldCount;

$progressStmt = $conn->prepare(
    "SELECT
        p.status,
        COALESCE(task_totals.total_tasks, 0) AS total_tasks,
        COALESCE(task_totals.completed_tasks, 0) AS completed_tasks,
        task_totals.next_deadline
     FROM projects p
     LEFT JOIN (
         SELECT
             project_id,
             COUNT(*) AS total_tasks,
             SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_tasks,
             MIN(CASE WHEN status <> 'completed' AND deadline IS NOT NULL THEN deadline END) AS next_deadline
         FROM tasks
         GROUP BY project_id
     ) task_totals ON task_totals.project_id = p.id
     WHERE p.client_id = ?
     AND p.status <> 'draft'
     {$activeProjectFilter}"
);
$progressStmt->bind_param('i', $userId);
$progressStmt->execute();
$progressResult = $progressStmt->get_result();
$progressRows = $progressResult ? $progressResult->fetch_all(MYSQLI_ASSOC) : [];
$progressStmt->close();

$overallTasks = 0;
$overallCompletedTasks = 0;
$progressTotal = 0;
$nextDeadlineValue = null;

foreach ($progressRows as $project) {
    $overallTasks += (int)($project['total_tasks'] ?? 0);
    $overallCompletedTasks += (int)($project['completed_tasks'] ?? 0);
    $progressTotal += (int)build_role_project_progress($project, 'client')['percent'];

    $candidateDeadline = trim((string)($project['next_deadline'] ?? ''));
    if ($candidateDeadline !== '' && ($nextDeadlineValue === null || $candidateDeadline < $nextDeadlineValue)) {
        $nextDeadlineValue = $candidateDeadline;
    }
}

$overallProgressPercent = $totalCount > 0 ? (int)round($progressTotal / $totalCount) : 0;
$nextDeadlineDisplay = $nextDeadlineValue !== null
    ? client_format_date($nextDeadlineValue)
    : 'No active deadline';

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
            ? $overallCompletedTasks . ' of ' . $overallTasks . ' tracked tasks are complete.'
            : 'No task progress data is available yet.',
    ],
    [
        'title' => $nextDeadlineDisplay,
        'detail' => $nextDeadlineValue !== null
            ? 'Closest open deadline in your project queue.'
            : 'No urgent due date is recorded.',
    ],
];

$clientPageTitle = 'Client Dashboard - Edge Automation';
$clientCssFiles = [
    '/codesamplecaps/CLIENT/css/client_dashboard.css',
];

require_once __DIR__ . '/../layout/header.php';
?>

<?php include __DIR__ . '/../sidebar/client_sidebar.php'; ?>
<main class="main-content" id="mainContent">
    <?php
    client_shell_render_topbar([
        'client_name' => $clientName,
        'client_initial' => $clientInitial,
        'active_project_count' => $activeProjectCount,
        'notification_items' => $notificationItems,
    ]);
    ?>

    <section class="dashboard-overview">
        <div class="section-heading">
            <div>
                <span class="section-badge">Dashboard</span>
                <h2>Overview</h2>
                <p>Quick summary of your projects, progress, and next deadline.</p>
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
                    <small>All current and completed projects</small>
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
                    <small>Projects already delivered</small>
                </div>
            </article>

            <article class="stat-card">
                <div class="stat-card__icon stat-card__icon--progress" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <path d="M4 19V9"></path>
                        <path d="M10 19V5"></path>
                        <path d="M16 19v-7"></path>
                        <path d="M22 19H2"></path>
                    </svg>
                </div>
                <div class="stat-card__content">
                    <span>Overall Progress</span>
                    <strong><?php echo $overallProgressPercent; ?>%</strong>
                    <small>Based on your tracked project tasks</small>
                </div>
            </article>
        </section>

        <section class="status-strip" aria-label="Project status summary">
            <?php foreach ($portfolioMix as $mix): ?>
                <article class="status-strip__item status-strip__item--<?php echo htmlspecialchars($mix['class']); ?>">
                    <span><?php echo htmlspecialchars($mix['label']); ?></span>
                    <strong><?php echo (int)$mix['count']; ?></strong>
                </article>
            <?php endforeach; ?>
        </section>

        <article class="deadline-summary">
            <span>Next deadline</span>
            <strong><?php echo htmlspecialchars($nextDeadlineDisplay); ?></strong>
        </article>
    </section>
</main>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
