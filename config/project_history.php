<?php
// Shared project history helper para iisang source ng project timeline records.

function project_history_ensure_table(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS project_history (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            project_id INT(11) NOT NULL,
            user_id INT(11) DEFAULT NULL,
            action VARCHAR(120) NOT NULL,
            description TEXT DEFAULT NULL,
            previous_value TEXT DEFAULT NULL,
            new_value TEXT DEFAULT NULL,
            source_type VARCHAR(80) DEFAULT NULL,
            source_id INT(11) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_project_history_project (project_id, created_at, id),
            KEY idx_project_history_user (user_id),
            KEY idx_project_history_source (source_type, source_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

function project_history_add(
    mysqli $conn,
    int $projectId,
    ?int $userId,
    string $action,
    string $description = '',
    ?string $previousValue = null,
    ?string $newValue = null,
    ?string $sourceType = null,
    ?int $sourceId = null
): void {
    project_history_ensure_table($conn);

    $stmt = $conn->prepare(
        'INSERT INTO project_history
         (project_id, user_id, action, description, previous_value, new_value, source_type, source_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    if (!$stmt) {
        return;
    }

    $stmt->bind_param(
        'iisssssi',
        $projectId,
        $userId,
        $action,
        $description,
        $previousValue,
        $newValue,
        $sourceType,
        $sourceId
    );
    $stmt->execute();
}

function project_history_fetch(mysqli $conn, int $projectId, int $limit = 50): array
{
    project_history_ensure_table($conn);

    $stmt = $conn->prepare(
        'SELECT ph.*, u.full_name AS user_name
         FROM project_history ph
         LEFT JOIN users u ON u.id = ph.user_id
         WHERE ph.project_id = ?
         ORDER BY ph.created_at DESC, ph.id DESC
         LIMIT ?'
    );

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('ii', $projectId, $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}
