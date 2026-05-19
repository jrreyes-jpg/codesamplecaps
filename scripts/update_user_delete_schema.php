<?php

require_once __DIR__ . '/../config/database.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function foreignKeyExists(mysqli $conn, string $tableName, string $constraintName): bool
{
    $stmt = $conn->prepare(
        'SELECT 1
         FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
         AND TABLE_NAME = ?
         AND CONSTRAINT_NAME = ?
         AND CONSTRAINT_TYPE = "FOREIGN KEY"
         LIMIT 1'
    );
    $stmt->bind_param('ss', $tableName, $constraintName);
    $stmt->execute();
    $result = $stmt->get_result();

    return (bool)($result && $result->fetch_assoc());
}

function columnExists(mysqli $conn, string $tableName, string $columnName): bool
{
    $stmt = $conn->prepare(
        'SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = ?
         AND COLUMN_NAME = ?
         LIMIT 1'
    );
    $stmt->bind_param('ss', $tableName, $columnName);
    $stmt->execute();
    $result = $stmt->get_result();

    return (bool)($result && $result->fetch_assoc());
}

function runQuery(mysqli $conn, string $sql): void
{
    $conn->query($sql);
    echo "[ok] {$sql}\n";
}

try {
    $conn->begin_transaction();

    runQuery(
        $conn,
        "CREATE TABLE IF NOT EXISTS deleted_users_archive (
            id INT(11) NOT NULL AUTO_INCREMENT,
            original_user_id INT(11) NOT NULL,
            full_name VARCHAR(150) NOT NULL,
            email VARCHAR(100) NOT NULL,
            phone VARCHAR(20) DEFAULT NULL,
            role VARCHAR(30) NOT NULL,
            status VARCHAR(20) NOT NULL,
            deleted_by INT(11) DEFAULT NULL,
            deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            payload_json LONGTEXT DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_deleted_users_archive_original (original_user_id),
            KEY idx_deleted_users_archive_deleted_by (deleted_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    if (!columnExists($conn, 'users', 'deleted_at')) {
        runQuery($conn, 'ALTER TABLE users ADD COLUMN deleted_at DATETIME DEFAULT NULL AFTER status');
    }
    if (!columnExists($conn, 'users', 'deleted_by')) {
        runQuery($conn, 'ALTER TABLE users ADD COLUMN deleted_by INT(11) DEFAULT NULL AFTER deleted_at');
    }
    if (!columnExists($conn, 'users', 'restored_at')) {
        runQuery($conn, 'ALTER TABLE users ADD COLUMN restored_at DATETIME DEFAULT NULL AFTER deleted_by');
    }
    if (!columnExists($conn, 'users', 'restored_by')) {
        runQuery($conn, 'ALTER TABLE users ADD COLUMN restored_by INT(11) DEFAULT NULL AFTER restored_at');
    }

    runQuery($conn, 'ALTER TABLE projects MODIFY client_id INT(11) NULL');
    if (foreignKeyExists($conn, 'projects', 'fk_project_client')) {
        runQuery($conn, 'ALTER TABLE projects DROP FOREIGN KEY fk_project_client');
    }
    runQuery(
        $conn,
        'ALTER TABLE projects
         ADD CONSTRAINT fk_project_client
         FOREIGN KEY (client_id) REFERENCES users (id) ON DELETE SET NULL'
    );

    runQuery($conn, 'ALTER TABLE tasks MODIFY assigned_to INT(11) NULL');
    if (foreignKeyExists($conn, 'tasks', 'fk_task_engineer')) {
        runQuery($conn, 'ALTER TABLE tasks DROP FOREIGN KEY fk_task_engineer');
    }
    runQuery(
        $conn,
        'ALTER TABLE tasks
         ADD CONSTRAINT fk_task_engineer
         FOREIGN KEY (assigned_to) REFERENCES users (id) ON DELETE SET NULL'
    );

    runQuery($conn, 'ALTER TABLE project_assignments MODIFY assigned_by INT(11) NULL');
    if (foreignKeyExists($conn, 'project_assignments', 'fk_assignment_assigner')) {
        runQuery($conn, 'ALTER TABLE project_assignments DROP FOREIGN KEY fk_assignment_assigner');
    }
    runQuery(
        $conn,
        'ALTER TABLE project_assignments
         ADD CONSTRAINT fk_assignment_assigner
         FOREIGN KEY (assigned_by) REFERENCES users (id) ON DELETE SET NULL'
    );

    $conn->commit();
    echo "User delete schema migration completed.\n";
} catch (Throwable $exception) {
    $conn->rollback();
    fwrite(STDERR, "Migration failed: " . $exception->getMessage() . "\n");
    exit(1);
}
