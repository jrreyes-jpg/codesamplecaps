<?php
define('AUTH_REQUIRED_ROLE', 'client');
require_once __DIR__ . '/../../config/auth_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/project_progress.php';
require_once __DIR__ . '/../includes/client_helpers.php';
require_once __DIR__ . '/../includes/client_shell.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$clientName = trim((string)($_SESSION['name'] ?? 'Client User'));
$clientEmail = trim((string)($_SESSION['email'] ?? ''));
$clientEmailDisplay = $clientEmail !== '' ? $clientEmail : 'No email on record';
$shellContext = client_shell_build_topbar_context($conn, $userId, $clientName, $clientEmailDisplay);
$hasDeletedAt = client_column_exists($conn, 'projects', 'deleted_at');
$activeProjectFilter = $hasDeletedAt ? ' AND p.deleted_at IS NULL' : '';
$archiveProjectFilter = $hasDeletedAt ? ' AND p.deleted_at IS NOT NULL' : ' AND 1 = 0';
$archiveDeletedAtSelect = $hasDeletedAt ? 'p.deleted_at' : 'NULL AS deleted_at';
$archiveOrderBy = $hasDeletedAt ? 'p.deleted_at DESC, p.id DESC' : 'p.id DESC';
$view = (string)($_GET['view'] ?? 'current');
$isArchiveView = $view === 'archive';

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
     {$activeProjectFilter}
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

$archivedProjectsStmt = $conn->prepare(
    "SELECT
        p.id,
        p.project_name,
        p.description,
        p.start_date,
        p.end_date,
        p.status,
        {$archiveDeletedAtSelect},
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
     {$archiveProjectFilter}
     ORDER BY {$archiveOrderBy}"
);
$archivedProjectsStmt->bind_param('i', $userId);
$archivedProjectsStmt->execute();
$archivedProjectsResult = $archivedProjectsStmt->get_result();
$archivedProjectRows = $archivedProjectsResult ? $archivedProjectsResult->fetch_all(MYSQLI_ASSOC) : [];
$archivedProjectsStmt->close();

$clientPageTitle = 'My Projects - Edge Automation';
$clientCssFiles = [
    '/codesamplecaps/CLIENT/css/projects.css',
];

require_once __DIR__ . '/../layout/header.php';
?>

<?php include __DIR__ . '/../sidebar/client_sidebar.php'; ?>
<main class="main-content" id="mainContent">
    <?php client_shell_render_topbar($shellContext); ?>

    <section class="projects-page">
        <div class="section-heading">
            <div>
                <span class="section-badge">Projects</span>
                <h2>My Projects</h2>
                <p>View current work, assigned engineer, progress, dates, and archived projects.</p>
            </div>
        </div>

        <nav class="project-view-tabs" aria-label="Project views">
            <a
                class="project-view-tab<?php echo !$isArchiveView ? ' active' : ''; ?>"
                href="/codesamplecaps/CLIENT/dashboards/projects.php"
                <?php echo !$isArchiveView ? 'aria-current="page"' : ''; ?>
            >Current Projects</a>
            <a
                class="project-view-tab<?php echo $isArchiveView ? ' active' : ''; ?>"
                href="/codesamplecaps/CLIENT/dashboards/projects.php?view=archive"
                <?php echo $isArchiveView ? 'aria-current="page"' : ''; ?>
            >Archive</a>
        </nav>

        <?php if (!$isArchiveView): ?>
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
                    <button type="button" class="client-project-search__clear" id="client-project-search-clear" aria-label="Clear project search">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="client-project-search__meta">
                    <span id="client-project-search-hint">Type a keyword to search.</span>
                    <span id="client-project-search-count"><?php echo count($projectRows); ?> project(s)</span>
                </div>
                <div class="client-project-search__dropdown" id="client-project-search-dropdown" role="listbox" hidden></div>
            </div>

            <div class="projects-grid">
                <?php if (!empty($projectRows)): ?>
                    <?php foreach ($projectRows as $project): ?>
                        <?php
                        $projectStatus = (string)($project['status'] ?? 'pending');
                        $projectProgress = build_role_project_progress($project, 'client');
                        $deadlineMeta = client_build_deadline_meta($project['next_deadline'] ?? null, $projectStatus);
                        $projectDescription = trim((string)($project['description'] ?? ''));
                        if ($projectDescription === '') {
                            $projectDescription = 'Project details will appear here once the work scope is ready.';
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
                            tabindex="-1"
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
                                    <span data-progress-width="<?php echo (int)$projectProgress['percent']; ?>"></span>
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
                        <p>Your projects will appear here once work is assigned to your account.</p>
                    </div>
                <?php endif; ?>

                <div class="empty-state empty-state--search" id="client-project-search-empty" hidden>
                    <h3>No matching projects</h3>
                    <p>Try another project name, engineer, status, or timeline.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="projects-grid">
                <?php if (!empty($archivedProjectRows)): ?>
                    <?php foreach ($archivedProjectRows as $project): ?>
                        <?php
                        $projectStatus = (string)($project['status'] ?? 'pending');
                        $projectProgress = build_role_project_progress($project, 'client');
                        $projectDescription = trim((string)($project['description'] ?? ''));
                        if ($projectDescription === '') {
                            $projectDescription = 'Project details were not recorded before this was archived.';
                        }
                        ?>
                        <article class="project-card project-card--archived project-card--<?php echo htmlspecialchars($projectStatus); ?>">
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
                                    <span>Archived</span>
                                    <strong><?php echo htmlspecialchars(client_format_date($project['deleted_at'] ?? null)); ?></strong>
                                </div>
                            </div>

                            <div class="project-progress">
                                <div class="project-progress__meta">
                                    <strong><?php echo (int)$projectProgress['percent']; ?>%</strong>
                                    <span><?php echo htmlspecialchars((string)$projectProgress['summary']); ?></span>
                                </div>
                                <div class="project-progress__bar" aria-hidden="true">
                                    <span data-progress-width="<?php echo (int)$projectProgress['percent']; ?>"></span>
                                </div>
                            </div>

                            <div class="project-card__footer">
                                <span class="project-pill">Read-only archive</span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <h3>No archived projects</h3>
                        <p>Archived projects will appear here for reference.</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</main>

<?php
$clientJsFiles = [
    '/codesamplecaps/CLIENT/js/projects.js',
];

require_once __DIR__ . '/../layout/footer.php';
?>
