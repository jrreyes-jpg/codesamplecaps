<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/audit_log.php';
require_once __DIR__ . '/../includes/engineer_helpers.php';

require_role('engineer');

$userId = (int)($_SESSION['user_id'] ?? 0);
$taskStatusOptions = ['pending', 'ongoing', 'completed', 'delayed'];
engineer_handle_task_update($conn, $userId, $taskStatusOptions, '/codesamplecaps/ENGINEER/dashboards/tasks.php');
$data = engineer_fetch_data($conn, $userId, $taskStatusOptions);
$flash = engineer_consume_flash();
$csrfToken = engineer_get_csrf_token();
$quickFilter = trim((string)($_GET['quick'] ?? ''));
$todayDate = $data['today_date'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Engineer Tasks - Edge Automation</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/engineer-sidebar.css">
    <link rel="stylesheet" href="../css/engineer.css">
</head>
<body>
<?php include '../sidebar/sidebar_engineer.php'; ?>

<div class="main-content">
    <?php if ($flash): ?>
        <div class="flash <?php echo htmlspecialchars((string)($flash['type'] ?? 'success')); ?>">
            <?php echo htmlspecialchars((string)($flash['message'] ?? '')); ?>
        </div>
    <?php endif; ?>

    <div class="section-heading">
        <div>
            <p class="section-kicker">Tasks</p>
            <h2>My Tasks</h2>
            <p class="section-caption">This is the action page for task review, filtering, and status updates.</p>
        </div>
    </div>

    <div class="task-summary">
        <span class="task-pill pending">Pending: <?php echo (int)$data['task_counts']['pending']; ?></span>
        <span class="task-pill ongoing">Ongoing: <?php echo (int)$data['task_counts']['ongoing']; ?></span>
        <span class="task-pill delayed">Delayed: <?php echo (int)$data['task_counts']['delayed']; ?></span>
        <span class="task-pill completed">Completed: <?php echo (int)$data['task_counts']['completed']; ?></span>
    </div>

    <div class="quick-actions">
        <button type="button" class="quick-action-btn" data-task-quick-filter="all-open">All Open</button>
        <button type="button" class="quick-action-btn" data-task-quick-filter="overdue">Overdue</button>
        <button type="button" class="quick-action-btn" data-task-quick-filter="due-today">Due Today</button>
        <button type="button" class="quick-action-btn" data-task-quick-filter="no-update">No Report Yet</button>
        <button type="button" class="quick-action-btn" data-task-quick-filter="blocked">Blocked</button>
        <button type="button" class="quick-action-btn quick-action-btn--muted" data-task-quick-filter="" data-reset-task-filters>Clear Filters</button>
    </div>

    <div class="task-toolbar">
        <input
            type="search"
            id="task-search"
            class="task-toolbar__input"
            placeholder="Search task or project"
            data-task-search
        >
        <select id="task-status-filter" class="task-toolbar__select" data-task-status-filter>
            <option value="">All Status</option>
            <?php foreach ($taskStatusOptions as $statusOption): ?>
                <option value="<?php echo htmlspecialchars($statusOption); ?>"><?php echo htmlspecialchars(ucfirst($statusOption)); ?></option>
            <?php endforeach; ?>
        </select>
        <select id="task-deadline-filter" class="task-toolbar__select" data-task-deadline-filter>
            <option value="">All Deadlines</option>
            <option value="overdue">Overdue</option>
            <option value="due-soon">Due Soon</option>
            <option value="no-deadline">No Deadline</option>
        </select>
    </div>
    <p class="task-toolbar__hint">
        Use filters for urgency first, then update the task with status and progress note.
    </p>
    <input type="hidden" value="<?php echo htmlspecialchars($quickFilter); ?>" data-default-quick-filter>

    <div class="tasks-list">
        <?php if (!empty($data['tasks'])): ?>
            <?php foreach ($data['tasks'] as $task): ?>
                <?php
                $deadlineMeta = engineer_build_deadline_meta($task['deadline'] ?? null, (string)$task['status']);
                $taskId = (int)$task['id'];
                $taskStatus = (string)($task['status'] ?? '');
                $taskProjectStatus = (string)($task['project_status'] ?? '');
                $taskDeadline = (string)($task['deadline'] ?? '');
                $hasLatestProgress = trim((string)($task['latest_progress_note'] ?? '')) !== '';
                $isDueToday = $taskDeadline !== '' && $taskDeadline === $todayDate && $taskStatus !== 'completed' && $taskProjectStatus !== 'completed';
                $isOverdue = $taskDeadline !== '' && $taskDeadline < $todayDate && $taskStatus !== 'completed' && $taskProjectStatus !== 'completed';
                $isBlocked = $taskStatus === 'delayed';
                ?>
                <div
                    id="task-item-<?php echo $taskId; ?>"
                    class="task-item <?php echo htmlspecialchars((string)$task['status']); ?>"
                    data-task-item
                    data-task-item-id="<?php echo $taskId; ?>"
                    data-task-name="<?php echo htmlspecialchars(mb_strtolower((string)$task['task_name'])); ?>"
                    data-project-name="<?php echo htmlspecialchars(mb_strtolower((string)$task['project_name'])); ?>"
                    data-task-status="<?php echo htmlspecialchars((string)$task['status']); ?>"
                    data-task-has-update="<?php echo $hasLatestProgress ? 'yes' : 'no'; ?>"
                    data-task-is-due-today="<?php echo $isDueToday ? 'yes' : 'no'; ?>"
                    data-task-is-overdue="<?php echo $isOverdue ? 'yes' : 'no'; ?>"
                    data-task-is-blocked="<?php echo $isBlocked ? 'yes' : 'no'; ?>"
                    data-task-is-locked="<?php echo $taskProjectStatus === 'completed' ? 'yes' : 'no'; ?>"
                    data-deadline-group="<?php echo htmlspecialchars(
                        ($task['deadline'] ?? null) === null || ($task['deadline'] ?? '') === ''
                            ? 'no-deadline'
                            : ($deadlineMeta['class'] === 'is-danger' ? 'overdue' : ($deadlineMeta['class'] === 'is-warning' ? 'due-soon' : 'scheduled'))
                    ); ?>"
                >
                    <div>
                        <div class="task-name"><?php echo htmlspecialchars((string)$task['task_name']); ?></div>
                        <div class="task-project"><?php echo htmlspecialchars((string)$task['project_name']); ?></div>
                        <div class="task-owners">
                            <span><strong>Assigned by:</strong> <?php echo htmlspecialchars((string)($task['task_owner_name'] ?? 'N/A')); ?></span>
                            <span><strong>Project Owner:</strong> <?php echo htmlspecialchars((string)($task['project_owner_name'] ?? 'N/A')); ?></span>
                            <span><strong>Client:</strong> <?php echo htmlspecialchars((string)($task['client_name'] ?? 'N/A')); ?></span>
                        </div>
                        <?php if (!empty($task['description'])): ?>
                            <div class="task-description"><?php echo htmlspecialchars((string)$task['description']); ?></div>
                        <?php endif; ?>
                        <div class="task-deadline">Deadline: <?php echo htmlspecialchars((string)($task['deadline'] ?? 'No deadline')); ?></div>
                        <div class="deadline-flag <?php echo htmlspecialchars($deadlineMeta['class']); ?>">
                            <?php echo htmlspecialchars($deadlineMeta['label']); ?>
                        </div>
                        <?php if (!empty($task['latest_progress_note'])): ?>
                            <div class="task-latest-update">
                                <strong>Latest report:</strong>
                                <p><?php echo nl2br(htmlspecialchars((string)$task['latest_progress_note'])); ?></p>
                                <?php if (!empty($task['latest_progress_at'])): ?>
                                    <span><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string)$task['latest_progress_at']))); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (($task['project_status'] ?? '') === 'completed'): ?>
                            <div class="task-note">Project is completed, so this task is locked.</div>
                        <?php endif; ?>
                    </div>

                    <div class="task-actions">
                        <span class="status <?php echo htmlspecialchars((string)$task['status']); ?>"><?php echo htmlspecialchars(ucfirst((string)$task['status'])); ?></span>

                        <?php if (($task['project_status'] ?? '') === 'completed'): ?>
                            <button type="button" class="btn btn-secondary" disabled>Locked</button>
                        <?php else: ?>
                            <form method="POST" class="task-form">
                                <input type="hidden" name="action" value="save_task_update">
                                <input type="hidden" name="task_id" value="<?php echo (int)$task['id']; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                                <select name="status" aria-label="Task status" required>
                                    <?php foreach ($taskStatusOptions as $statusOption): ?>
                                        <option value="<?php echo htmlspecialchars($statusOption); ?>" <?php echo ($task['status'] ?? '') === $statusOption ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars(ucfirst($statusOption)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <textarea
                                    name="progress_note"
                                    rows="3"
                                    placeholder="Add your progress report, blockers, or coordination notes"
                                ></textarea>

                                <button type="submit" class="btn">Save Update</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="no-data"><p>No tasks yet.</p></div>
        <?php endif; ?>
    </div>
</div>

<script src="../js/engineer.js"></script>

</body>
</html>
