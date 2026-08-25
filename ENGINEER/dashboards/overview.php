<?php
define('AUTH_REQUIRED_ROLE', 'engineer');
require_once __DIR__ . '/../../config/auth_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/audit_log.php';
require_once __DIR__ . '/../../config/project_progress.php';
require_once __DIR__ . '/../includes/engineer_helpers.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$taskStatusOptions = ['pending', 'ongoing', 'completed', 'delayed'];
$data = engineer_fetch_data($conn, $userId, $taskStatusOptions);
$flash = engineer_consume_flash();
$priorityCards = $data['priority_cards'];
$recentUpdates = array_slice($data['recent_updates'], 0, 4);
$assignedProjects = array_slice($data['assigned_projects'], 0, 3);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Engineer Overview - Edge Automation</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <script src="/codesamplecaps/SHARED/sidebar/js/sidebar-state.js"></script>
    <link rel="stylesheet" href="/codesamplecaps/SHARED/sidebar/css/sidebar.css">
    <link rel="stylesheet" href="../css/engineer.css">
</head>
<body>
<?php include __DIR__ . '/../../SHARED/sidebar/php/sidebar.php'; ?>

<div class="main-content">
    <?php
    include __DIR__ . '/../includes/header.php';
    ?>

    <main class="engineer-page-body">
    <?php if ($flash): ?>
        <div class="flash <?php echo htmlspecialchars((string)($flash['type'] ?? 'success')); ?>">
            <?php echo htmlspecialchars((string)($flash['message'] ?? '')); ?>
        </div>
    <?php endif; ?>


    <div class="stats-grid">
        <div class="stat-card">
            <h4>Assigned Projects</h4>
            <p><?php echo (int)$data['assigned_count']; ?></p>
        </div>
        <div class="stat-card">
            <h4>Active Projects</h4>
            <p><?php echo (int)$data['in_progress_count']; ?></p>
        </div>
        <div class="stat-card">
            <h4>Completed Projects</h4>
            <p><?php echo (int)$data['completed_count']; ?></p>
        </div>
        <div class="stat-card">
            <h4>Open Tasks</h4>
            <p><?php echo (int)$data['open_task_count']; ?></p>
        </div>
    </div>

    <section class="priorities-panel">
        <div class="section-heading">
            <div>
                <p class="section-kicker">Today's Priorities</p>
                <h2>Start with what needs action first.</h2>
            </div>
        </div>
        <div class="priority-grid">
            <?php foreach ($priorityCards as $priorityCard): ?>
                <?php
                $priorityCount = (int)($priorityCard['count'] ?? 0);
                $priorityUrl = '../dashboards/tasks.php?quick=' . urlencode((string)$priorityCard['filter']);
                $priorityToneClass = $priorityCount > 0
                    ? 'priority-card--' . htmlspecialchars((string)$priorityCard['tone'])
                    : 'is-empty';
                ?>
                <article
                    class="priority-card <?php echo $priorityToneClass; ?><?php echo $priorityCount > 0 ? ' is-clickable' : ''; ?>"
                    <?php if ($priorityCount > 0): ?>
                        tabindex="0"
                        role="button"
                        data-card-url="<?php echo htmlspecialchars($priorityUrl); ?>"
                        aria-label="<?php echo htmlspecialchars((string)$priorityCard['title'] . ': ' . $priorityCount . ' items'); ?>"
                    <?php endif; ?>
                >
                    <span class="priority-card__label"><?php echo htmlspecialchars((string)$priorityCard['title']); ?></span>
                    <strong class="priority-card__count"><?php echo $priorityCount; ?></strong>
                    <?php if ($priorityCount > 0): ?>
                        <span class="priority-card__hint"><?php echo htmlspecialchars((string)$priorityCard['action']); ?></span>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="updates-panel">
        <div class="section-heading">
            <div>
                <p class="section-kicker">Recent Updates</p>
                <h2>Latest progress reports</h2>
            </div>
            <a class="btn btn-ghost btn-small" href="../dashboards/progress_updates.php">View All Updates</a>
        </div>
        <?php if (!empty($recentUpdates)): ?>
            <div class="updates-list">
                <?php foreach ($recentUpdates as $update): ?>
                    <article class="update-card">
                        <div class="update-card__topline">
                            <strong><?php echo htmlspecialchars((string)$update['task_name']); ?></strong>
                            <span><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string)$update['created_at']))); ?></span>
                        </div>
                        <p><?php echo nl2br(htmlspecialchars((string)$update['progress_note'])); ?></p>
                        <div class="update-card__meta">
                            <span><?php echo htmlspecialchars((string)$update['project_name']); ?></span>
                            <?php if (!empty($update['status_snapshot'])): ?>
                                <span>Status: <?php echo htmlspecialchars(ucfirst((string)$update['status_snapshot'])); ?></span>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="updates-empty-message">No progress reports available yet.</p>
        <?php endif; ?>
    </div>

    <section>
        <div class="section-heading">
            <div>
                <p class="section-kicker">Projects Preview</p>
                <h2>Assigned projects at a glance</h2>
            </div>
            <a class="btn btn-ghost btn-small" href="../dashboards/projects.php">Open My Projects</a>
        </div>
        <div class="projects-grid">
            <?php if (!empty($assignedProjects)): ?>
                <?php foreach ($assignedProjects as $project): ?>
                    <?php
                    $projectProgress = build_role_project_progress($project, 'engineer');
                    ?>
                    <div class="project-card">
                        <div class="project-card__topline">
                            <div class="project-name"><?php echo htmlspecialchars((string)$project['project_name']); ?></div>
                            <span class="status <?php echo htmlspecialchars((string)$project['status']); ?>"><?php echo htmlspecialchars(ucfirst((string)$project['status'])); ?></span>
                        </div>
                        <p><strong>Client:</strong> <?php echo htmlspecialchars((string)($project['client_name'] ?? 'N/A')); ?></p>
                        <p><strong>Project Owner:</strong> <?php echo htmlspecialchars((string)($project['project_owner_name'] ?? 'N/A')); ?></p>
                        <div class="project-progress">
                            <div class="project-progress__meta">
                                <strong><?php echo (int)$projectProgress['percent']; ?>%</strong>
                                <span><?php echo htmlspecialchars((string)$projectProgress['summary']); ?></span>
                            </div>
                            <div class="project-progress__bar">
                                <span data-progress-width="<?php echo (int)$projectProgress['percent']; ?>"></span>
                            </div>
                        </div>
                        <p class="updates-empty-message"><?php echo htmlspecialchars((string)$projectProgress['hint']); ?></p>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-data no-data-full"><p>No assigned projects yet.</p></div>
            <?php endif; ?>
        </div>
    </section>
    </main>
</div>

<script src="/codesamplecaps/SHARED/sidebar/js/sidebar.js"></script>
<script src="../js/engineer.js"></script>
<script src="../js/overview.js"></script>

</body>
</html>
