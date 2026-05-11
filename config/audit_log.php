<?php

function audit_log_table_exists(mysqli $conn): bool {
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    $stmt = $conn->prepare(
        'SELECT 1
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = ?
         LIMIT 1'
    );

    if (!$stmt) {
        $exists = false;
        return false;
    }

    $tableName = 'audit_logs';
    $stmt->bind_param('s', $tableName);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = (bool)($result && $result->fetch_assoc());

    return $exists;
}

function audit_log_event(
    mysqli $conn,
    ?int $userId,
    string $action,
    string $entityType,
    ?int $entityId = null,
    ?array $oldValues = null,
    ?array $newValues = null
): void {
    if (!audit_log_table_exists($conn)) {
        return;
    }

    $stmt = $conn->prepare(
        'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    if (!$stmt) {
        return;
    }

    $oldJson = $oldValues !== null ? json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $newJson = $newValues !== null ? json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt->bind_param('ississs', $userId, $action, $entityType, $entityId, $oldJson, $newJson, $ipAddress);
    $stmt->execute();
}
