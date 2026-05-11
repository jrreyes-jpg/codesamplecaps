<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/audit_log.php';
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
    <title>Engineer Progress Updates - Edge Automation</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/engineer-sidebar.css">
    <link rel="stylesheet" href="../css/engineer.css">
</head>
<body>
<?php include '../sidebar/sidebar_engineer.php'; ?>

<div class="main-content">
    <div class="section-heading">
        <div>
            <p class="section-kicker">Updates</p>
            <h2>Recent Progress Reports</h2>
            <p class="section-caption">This page is only for update history and follow-up.</p>
        </div>
    </div>

    <div class="updates-panel">
        <?php if (!empty($data['recent_updates'])): ?>
            <div class="updates-list">
                <?php foreach ($data['recent_updates'] as $update): ?>
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
                        <?php if (!empty($update['task_id'])): ?>
                            <div class="update-card__actions">
                                <a href="../dashboards/tasks.php?task=<?php echo (int)$update['task_id']; ?>" class="btn btn-ghost btn-small">
                                    Open Task
                                </a>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="no-data"><p>No progress reports yet.</p></div>
        <?php endif; ?>
    </div>
</div>

<script src="../js/engineer.js"></script>

</body>
</html>
