<?php
require_once __DIR__ . '/../../config/auth_middleware.php';

function engineer_normalize_text_or_null(?string $value): ?string
{
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function ensure_engineer_task_updates_table(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS engineer_task_updates (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            task_id INT(11) NOT NULL,
            engineer_id INT(11) NOT NULL,
            status_snapshot VARCHAR(50) DEFAULT NULL,
            progress_note TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_engineer_task_updates_task (task_id),
            KEY idx_engineer_task_updates_engineer (engineer_id),
            KEY idx_engineer_task_updates_created_at (created_at),
            CONSTRAINT fk_engineer_task_updates_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE,
            CONSTRAINT fk_engineer_task_updates_engineer FOREIGN KEY (engineer_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

function engineer_build_deadline_meta(?string $deadline, string $status): array
{
    if ($deadline === null || $deadline === '') {
        return [
            'label' => 'No deadline',
            'class' => 'is-neutral',
        ];
    }

    $today = new DateTimeImmutable('today');
    $deadlineDate = DateTimeImmutable::createFromFormat('Y-m-d', $deadline);

    if (!$deadlineDate) {
        return [
            'label' => $deadline,
            'class' => 'is-neutral',
        ];
    }

    $days = (int)$today->diff($deadlineDate)->format('%r%a');

    if ($status !== 'completed' && $days < 0) {
        return [
            'label' => 'Overdue by ' . abs($days) . ' day' . (abs($days) === 1 ? '' : 's'),
            'class' => 'is-danger',
        ];
    }

    if ($status !== 'completed' && $days <= 2) {
        return [
            'label' => $days === 0 ? 'Due today' : 'Due in ' . $days . ' day' . ($days === 1 ? '' : 's'),
            'class' => 'is-warning',
        ];
    }

    return [
        'label' => 'Due ' . $deadlineDate->format('M j, Y'),
        'class' => 'is-ok',
    ];
}

function engineer_format_project_date(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return 'N/A';
    }

    try {
        return (new DateTimeImmutable($value))->format('M j, Y');
    } catch (Throwable $exception) {
        return $value;
    }
}

function engineer_set_flash(string $type, string $message): void
{
    $_SESSION['engineer_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function engineer_consume_flash(): ?array
{
    $flash = $_SESSION['engineer_flash'] ?? null;
    unset($_SESSION['engineer_flash']);

    return is_array($flash) ? $flash : null;
}

function engineer_get_csrf_token(): string
{
    return auth_csrf_token('engineer_module');
}

function engineer_is_valid_csrf_token(?string $token): bool
{
    return auth_is_valid_csrf($token, 'engineer_module');
}

function engineer_get_task_snapshot(mysqli $conn, int $taskId, int $engineerId): ?array
{
    $stmt = $conn->prepare(
        "SELECT t.id, t.status, t.task_name, t.description, p.status AS project_status, p.project_name
         FROM tasks t
         INNER JOIN projects p ON p.id = t.project_id
         WHERE t.id = ?
         AND t.assigned_to = ?
         AND EXISTS (
             SELECT 1
             FROM project_assignments pa
             WHERE pa.project_id = p.id
             AND pa.engineer_id = ?
         )
         LIMIT 1"
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('iii', $taskId, $engineerId, $engineerId);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result ? $result->fetch_assoc() : null;
}

function engineer_handle_task_update(mysqli $conn, int $userId, array $taskStatusOptions, string $redirectPath): void
{
    ensure_engineer_task_updates_table($conn);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $action = $_POST['action'] ?? '';
    if ($action !== 'save_task_update') {
        return;
    }

    if (!engineer_is_valid_csrf_token($_POST['csrf_token'] ?? null)) {
        engineer_set_flash('error', 'Security check failed. Please try again.');
        header('Location: ' . $redirectPath);
        exit();
    }

    $taskId = (int)($_POST['task_id'] ?? 0);
    $status = trim((string)($_POST['status'] ?? ''));
    $progressNote = engineer_normalize_text_or_null($_POST['progress_note'] ?? null);
    $task = $taskId > 0 ? engineer_get_task_snapshot($conn, $taskId, $userId) : null;

    if ($taskId <= 0 || !in_array($status, $taskStatusOptions, true)) {
        engineer_set_flash('error', 'Invalid task update.');
        header('Location: ' . $redirectPath);
        exit();
    }

    if (!$task) {
        engineer_set_flash('error', 'Task not found.');
        header('Location: ' . $redirectPath);
        exit();
    }

    if (($task['project_status'] ?? '') === 'completed') {
        engineer_set_flash('error', 'This task is locked because the project is already completed.');
        header('Location: ' . $redirectPath);
        exit();
    }

    if ($progressNote !== null && mb_strlen($progressNote) > 1000) {
        engineer_set_flash('error', 'Progress note is too long.');
        header('Location: ' . $redirectPath);
        exit();
    }

    if ($progressNote === null && ($task['status'] ?? '') === $status) {
        engineer_set_flash('error', 'Add a note or change the task status first.');
        header('Location: ' . $redirectPath);
        exit();
    }

    $conn->begin_transaction();

    try {
        if (($task['status'] ?? '') !== $status) {
            $updateTask = $conn->prepare('UPDATE tasks SET status = ? WHERE id = ? AND assigned_to = ?');

            if (
                !$updateTask ||
                !$updateTask->bind_param('sii', $status, $taskId, $userId) ||
                !$updateTask->execute()
            ) {
                throw new RuntimeException('Task update failed.');
            }
        }

        if ($progressNote !== null) {
            $insertUpdate = $conn->prepare(
                'INSERT INTO engineer_task_updates (task_id, engineer_id, status_snapshot, progress_note)
                 VALUES (?, ?, ?, ?)'
            );

            if (
                !$insertUpdate ||
                !$insertUpdate->bind_param('iiss', $taskId, $userId, $status, $progressNote) ||
                !$insertUpdate->execute()
            ) {
                throw new RuntimeException('Progress note save failed.');
            }
        }

        if (function_exists('audit_log_event')) {
            audit_log_event(
                $conn,
                $userId,
                'engineer_task_update',
                'task',
                $taskId,
                [
                    'status' => $task['status'] ?? null,
                ],
                [
                    'status' => $status,
                    'progress_note' => $progressNote,
                ]
            );
        }

        $conn->commit();
        engineer_set_flash('success', 'Task update saved.');
    } catch (Throwable $exception) {
        $conn->rollback();
        engineer_set_flash('error', 'Failed to save task update.');
    }

    header('Location: ' . $redirectPath);
    exit();
}

function engineer_fetch_data(mysqli $conn, int $userId, array $taskStatusOptions): array
{
    ensure_engineer_task_updates_table($conn);

    $assignedCount = 0;
    $inProgressCount = 0;
    $completedCount = 0;
    $assignedProjects = [];
    $tasks = [];
    $recentUpdates = [];
    $taskCounts = array_fill_keys($taskStatusOptions, 0);
    $todayDate = (new DateTimeImmutable('today'))->format('Y-m-d');
    $priorityCounts = [
        'overdue' => 0,
        'due_today' => 0,
        'needs_update' => 0,
        'blocked' => 0,
    ];
    $engineerProfile = [
        'full_name' => (string)($_SESSION['name'] ?? 'Engineer'),
        'email' => '',
        'phone' => '',
        'status' => 'active',
        'role' => 'engineer',
    ];

    $totalAssigned = $conn->prepare(
        "SELECT COUNT(DISTINCT pa.project_id)
         FROM project_assignments pa
         INNER JOIN projects p ON p.id = pa.project_id
         WHERE pa.engineer_id = ?
         AND p.status <> 'draft'"
    );
    if ($totalAssigned) {
        $totalAssigned->bind_param('i', $userId);
        $totalAssigned->execute();
        $totalAssigned->bind_result($assignedCount);
        $totalAssigned->fetch();
        $totalAssigned->close();
    }

    $inProgress = $conn->prepare(
        "SELECT COUNT(*)
         FROM projects p
         WHERE p.status = 'ongoing'
         AND EXISTS (
             SELECT 1
             FROM project_assignments pe
             WHERE pe.project_id = p.id
             AND pe.engineer_id = ?
         )"
    );
    if ($inProgress) {
        $inProgress->bind_param('i', $userId);
        $inProgress->execute();
        $inProgress->bind_result($inProgressCount);
        $inProgress->fetch();
        $inProgress->close();
    }

    $completedProjects = $conn->prepare(
        "SELECT COUNT(*)
         FROM projects p
         WHERE p.status = 'completed'
         AND EXISTS (
             SELECT 1
             FROM project_assignments pe
             WHERE pe.project_id = p.id
             AND pe.engineer_id = ?
         )"
    );
    if ($completedProjects) {
        $completedProjects->bind_param('i', $userId);
        $completedProjects->execute();
        $completedProjects->bind_result($completedCount);
        $completedProjects->fetch();
        $completedProjects->close();
    }

    $projectsStmt = $conn->prepare(
        "SELECT p.id, p.project_name, p.status, p.description, p.start_date, p.end_date, u.full_name AS client_name,
                creator.full_name AS project_owner_name,
                COUNT(t.id) AS total_tasks,
                SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) AS completed_tasks,
                SUM(CASE WHEN t.status = 'ongoing' THEN 1 ELSE 0 END) AS ongoing_tasks,
                SUM(CASE WHEN t.status = 'delayed' THEN 1 ELSE 0 END) AS delayed_tasks,
                MIN(CASE WHEN t.status <> 'completed' AND t.deadline IS NOT NULL THEN t.deadline END) AS next_deadline
         FROM projects p
         LEFT JOIN users u ON p.client_id = u.id
         LEFT JOIN users creator ON creator.id = p.created_by
         LEFT JOIN tasks t ON t.project_id = p.id
         WHERE EXISTS (
             SELECT 1
             FROM project_assignments pe
             WHERE pe.project_id = p.id
             AND pe.engineer_id = ?
         )
         AND p.status <> 'draft'
         GROUP BY p.id, p.project_name, p.status, p.description, p.start_date, p.end_date, u.full_name, creator.full_name
         ORDER BY
            CASE p.status
                WHEN 'ongoing' THEN 1
                WHEN 'pending' THEN 2
                WHEN 'on-hold' THEN 3
                WHEN 'completed' THEN 4
                ELSE 5
            END,
            p.created_at DESC"
    );
    if ($projectsStmt) {
        $projectsStmt->bind_param('i', $userId);
        $projectsStmt->execute();
        $projectResult = $projectsStmt->get_result();
        if ($projectResult) {
            $assignedProjects = $projectResult->fetch_all(MYSQLI_ASSOC);
        }
        $projectsStmt->close();
    }

    $tasksStmt = $conn->prepare(
        "SELECT t.id, t.task_name, t.description, t.status, t.deadline, p.project_name, p.id AS project_id, p.status AS project_status,
                task_creator.full_name AS task_owner_name,
                project_creator.full_name AS project_owner_name,
                client.full_name AS client_name,
                (
                    SELECT etu.progress_note
                    FROM engineer_task_updates etu
                    WHERE etu.task_id = t.id
                    ORDER BY etu.created_at DESC, etu.id DESC
                    LIMIT 1
                ) AS latest_progress_note,
                (
                    SELECT etu.created_at
                    FROM engineer_task_updates etu
                    WHERE etu.task_id = t.id
                    ORDER BY etu.created_at DESC, etu.id DESC
                    LIMIT 1
                ) AS latest_progress_at
         FROM tasks t
         INNER JOIN projects p ON t.project_id = p.id
         LEFT JOIN users task_creator ON task_creator.id = t.created_by
         LEFT JOIN users project_creator ON project_creator.id = p.created_by
         LEFT JOIN users client ON client.id = p.client_id
         WHERE t.assigned_to = ?
         AND p.status <> 'draft'
         AND EXISTS (
             SELECT 1
             FROM project_assignments pe
             WHERE pe.project_id = p.id
             AND pe.engineer_id = ?
         )
         ORDER BY
             CASE t.status
                 WHEN 'pending' THEN 1
                 WHEN 'ongoing' THEN 2
                 WHEN 'delayed' THEN 3
                 WHEN 'completed' THEN 4
                 ELSE 5
             END,
             t.deadline IS NULL,
             t.deadline ASC,
             t.id DESC"
    );
    if ($tasksStmt) {
        $tasksStmt->bind_param('ii', $userId, $userId);
        $tasksStmt->execute();
        $taskResult = $tasksStmt->get_result();
        if ($taskResult) {
            $tasks = $taskResult->fetch_all(MYSQLI_ASSOC);
        }
        $tasksStmt->close();
    }

    $recentUpdatesStmt = $conn->prepare(
        "SELECT etu.progress_note, etu.status_snapshot, etu.created_at, t.id AS task_id, t.task_name, p.project_name
         FROM engineer_task_updates etu
         INNER JOIN tasks t ON t.id = etu.task_id
         INNER JOIN projects p ON p.id = t.project_id
         WHERE etu.engineer_id = ?
         ORDER BY etu.created_at DESC, etu.id DESC
         LIMIT 10"
    );
    if ($recentUpdatesStmt) {
        $recentUpdatesStmt->bind_param('i', $userId);
        $recentUpdatesStmt->execute();
        $recentUpdatesResult = $recentUpdatesStmt->get_result();
        if ($recentUpdatesResult) {
            $recentUpdates = $recentUpdatesResult->fetch_all(MYSQLI_ASSOC);
        }
        $recentUpdatesStmt->close();
    }

    $profileStmt = $conn->prepare(
        'SELECT full_name, email, phone, status, role
         FROM users
         WHERE id = ?
         LIMIT 1'
    );
    if ($profileStmt) {
        $profileStmt->bind_param('i', $userId);
        $profileStmt->execute();
        $profileResult = $profileStmt->get_result();

        if ($profileResult && $profileResult->num_rows === 1) {
            $engineerProfile = array_merge($engineerProfile, $profileResult->fetch_assoc());
        }

        $profileStmt->close();
    }

    foreach ($tasks as $task) {
        $statusKey = (string)($task['status'] ?? '');

        if (isset($taskCounts[$statusKey])) {
            $taskCounts[$statusKey]++;
        }

        $taskDeadline = (string)($task['deadline'] ?? '');
        $projectStatus = (string)($task['project_status'] ?? '');
        $hasLatestProgress = trim((string)($task['latest_progress_note'] ?? '')) !== '';
        $isOpenTask = $statusKey !== 'completed' && $projectStatus !== 'completed';

        if (!$isOpenTask) {
            continue;
        }

        if ($taskDeadline !== '' && $taskDeadline < $todayDate) {
            $priorityCounts['overdue']++;
        }

        if ($taskDeadline === $todayDate) {
            $priorityCounts['due_today']++;
        }

        if (!$hasLatestProgress) {
            $priorityCounts['needs_update']++;
        }

        if ($statusKey === 'delayed') {
            $priorityCounts['blocked']++;
        }
    }

    $openTaskCount = $taskCounts['pending'] + $taskCounts['ongoing'] + $taskCounts['delayed'];
    $priorityCards = [
        [
            'title' => 'Overdue',
            'count' => $priorityCounts['overdue'],
            'filter' => 'overdue',
            'action' => 'Review overdue tasks',
            'tone' => 'danger',
        ],
        [
            'title' => 'Due Today',
            'count' => $priorityCounts['due_today'],
            'filter' => 'due-today',
            'action' => 'See today\'s tasks',
            'tone' => 'warning',
        ],
        [
            'title' => 'Needs Update',
            'count' => $priorityCounts['needs_update'],
            'filter' => 'no-update',
            'action' => 'Report progress',
            'tone' => 'neutral',
        ],
        [
            'title' => 'Blocked',
            'count' => $priorityCounts['blocked'],
            'filter' => 'blocked',
            'action' => 'Check blockers',
            'tone' => 'info',
        ],
    ];

    return [
        'assigned_count' => (int)$assignedCount,
        'in_progress_count' => (int)$inProgressCount,
        'completed_count' => (int)$completedCount,
        'assigned_projects' => $assignedProjects,
        'tasks' => $tasks,
        'recent_updates' => $recentUpdates,
        'task_counts' => $taskCounts,
        'open_task_count' => (int)$openTaskCount,
        'today_date' => $todayDate,
        'priority_counts' => $priorityCounts,
        'priority_cards' => $priorityCards,
        'engineer_profile' => $engineerProfile,
    ];
}
