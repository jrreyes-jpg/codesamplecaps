<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/audit_log.php';
require_once __DIR__ . '/../../config/project_progress.php';
require_once __DIR__ . '/../includes/engineer_helpers.php';

require_role('engineer');

$userId = (int)($_SESSION['user_id'] ?? 0);
$taskStatusOptions = ['pending', 'ongoing', 'completed', 'delayed'];
$data = engineer_fetch_data($conn, $userId, $taskStatusOptions);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Engineer Projects - Edge Automation</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/engineer-sidebar.css">
    <link rel="stylesheet" href="../css/engineer.css">
</head>
<body>
<?php include '../sidebar/sidebar_engineer.php'; ?>

<div class="main-content">
    <div class="section-heading">
        <div>
            <p class="section-kicker">Projects</p>
            <h2>Assigned Projects</h2>
            <p class="section-caption">This page is for project visibility only, not task updates.</p>
        </div>
    </div>

    <div class="projects-grid">
        <?php if (!empty($data['assigned_projects'])): ?>
            <?php foreach ($data['assigned_projects'] as $project): ?>
                <?php
                $projectProgress = build_role_project_progress($project, 'engineer');
                $projectDeadlineMeta = engineer_build_deadline_meta($project['next_deadline'] ?? null, (string)($project['status'] ?? 'pending'));
                ?>
                <div class="project-card">
                    <div class="project-card__topline">
                        <div class="project-name"><?php echo htmlspecialchars((string)$project['project_name']); ?></div>
                        <span class="status <?php echo htmlspecialchars((string)$project['status']); ?>"><?php echo htmlspecialchars(ucfirst((string)$project['status'])); ?></span>
                    </div>
                    <p><strong>Client:</strong> <?php echo htmlspecialchars((string)($project['client_name'] ?? 'N/A')); ?></p>
                    <p><strong>Project Owner:</strong> <?php echo htmlspecialchars((string)($project['project_owner_name'] ?? 'N/A')); ?></p>
                    <p><strong>P.O Date:</strong> <?php echo htmlspecialchars(engineer_format_project_date($project['start_date'] ?? null)); ?></p>
                    <p><strong>Completed:</strong> <?php echo htmlspecialchars(engineer_format_project_date($project['end_date'] ?? null)); ?></p>
                    <p class="project-description">
                        <?php echo htmlspecialchars(substr((string)($project['description'] ?? ''), 0, 120)); ?>
                    </p>
                    <div class="project-progress">
                        <div class="project-progress__meta">
                            <strong><?php echo (int)$projectProgress['percent']; ?>%</strong>
                            <span><?php echo htmlspecialchars((string)$projectProgress['summary']); ?></span>
                        </div>
                        <div class="project-progress__bar">
                            <span style="width: <?php echo (int)$projectProgress['percent']; ?>%;"></span>
                        </div>
                    </div>
                    <p class="section-caption"><?php echo htmlspecialchars((string)$projectProgress['hint']); ?></p>
                    <div class="project-mini-stats">
                        <span>Ongoing: <?php echo (int)($project['ongoing_tasks'] ?? 0); ?></span>
                        <span>Delayed: <?php echo (int)($project['delayed_tasks'] ?? 0); ?></span>
                        <span class="deadline-flag <?php echo htmlspecialchars($projectDeadlineMeta['class']); ?>">
                            <?php echo htmlspecialchars($projectDeadlineMeta['label']); ?>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="no-data no-data-full"><p>No assigned projects yet.</p></div>
        <?php endif; ?>
    </div>
</div>

<script src="../js/engineer.js"></script>

</body>
</html>
