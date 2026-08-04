<?php
// Shared DB helpers para hindi paulit-ulit ang table/column checks at count queries sa Admin pages.

if (!function_exists('admin_db_table_exists')) {
    function admin_db_table_exists(mysqli $conn, string $tableName): bool
    {
        static $tableCache = [];
        $cacheKey = $conn->thread_id . ':' . $tableName;

        if (array_key_exists($cacheKey, $tableCache)) {
            return $tableCache[$cacheKey];
        }

        $stmt = $conn->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = ?
             LIMIT 1'
        );
        if (!$stmt) {
            $tableCache[$cacheKey] = false;
            return false;
        }

        $stmt->bind_param('s', $tableName);
        $stmt->execute();
        $result = $stmt->get_result();
        $tableCache[$cacheKey] = (bool)($result && $result->fetch_assoc());

        return $tableCache[$cacheKey];
    }
}

if (!function_exists('admin_db_column_exists')) {
    function admin_db_column_exists(mysqli $conn, string $tableName, string $columnName): bool
    {
        static $columnCache = [];
        $cacheKey = $conn->thread_id . ':' . $tableName . ':' . $columnName;

        if (array_key_exists($cacheKey, $columnCache)) {
            return $columnCache[$cacheKey];
        }

        $stmt = $conn->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = ?
             AND COLUMN_NAME = ?
             LIMIT 1'
        );
        if (!$stmt) {
            $columnCache[$cacheKey] = false;
            return false;
        }

        $stmt->bind_param('ss', $tableName, $columnName);
        $stmt->execute();
        $result = $stmt->get_result();
        $columnCache[$cacheKey] = (bool)($result && $result->fetch_assoc());

        return $columnCache[$cacheKey];
    }
}

if (!function_exists('admin_db_scalar_int')) {
    function admin_db_scalar_int(mysqli $conn, string $sql): int
    {
        $result = $conn->query($sql);
        if (!$result) {
            return 0;
        }

        $row = $result->fetch_row();
        return (int)($row[0] ?? 0);
    }
}
