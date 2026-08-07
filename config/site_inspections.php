<?php
// Shared Site Inspection helpers para hindi duplicate sa Admin at Engineer.

if (!function_exists('site_inspection_table_exists')) {
    function site_inspection_table_exists(mysqli $conn, string $tableName): bool
    {
        $stmt = $conn->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
        );
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('s', $tableName);
        $stmt->execute();
        $result = $stmt->get_result();
        return (bool)($result && $result->fetch_assoc());
    }
}

if (!function_exists('site_inspection_column_exists')) {
    function site_inspection_column_exists(mysqli $conn, string $columnName): bool
    {
        $stmt = $conn->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = "site_inspections"
             AND COLUMN_NAME = ?
             LIMIT 1'
        );
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('s', $columnName);
        $stmt->execute();
        return (bool)$stmt->get_result()->fetch_assoc();
    }
}

if (!function_exists('site_inspection_cost_column_exists')) {
    function site_inspection_cost_column_exists(mysqli $conn, string $columnName): bool
    {
        $stmt = $conn->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = "site_inspection_cost_items"
             AND COLUMN_NAME = ?
             LIMIT 1'
        );
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('s', $columnName);
        $stmt->execute();
        return (bool)$stmt->get_result()->fetch_assoc();
    }
}

if (!function_exists('site_inspection_ensure_table')) {
    function site_inspection_ensure_table(mysqli $conn): void
    {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS site_inspections (
                id INT AUTO_INCREMENT PRIMARY KEY,
                inquiry_id INT NOT NULL,
                engineer_id INT NOT NULL,
                scheduled_at DATETIME NOT NULL,
                site_notes TEXT NULL,
                engineer_findings TEXT NULL,
                risk_notes TEXT NULL,
                client_requests TEXT NULL,
                status VARCHAR(40) NOT NULL DEFAULT 'Scheduled',
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_site_inspections_inquiry (inquiry_id),
                KEY idx_site_inspections_engineer (engineer_id),
                KEY idx_site_inspections_status (status),
                KEY idx_site_inspections_scheduled_at (scheduled_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Dagdag notes ni Engineer para ready ang costing sa Admin review.
        $columns = [
            'engineer_findings' => 'ALTER TABLE site_inspections ADD COLUMN engineer_findings TEXT NULL AFTER site_notes',
            'risk_notes' => 'ALTER TABLE site_inspections ADD COLUMN risk_notes TEXT NULL AFTER engineer_findings',
            'client_requests' => 'ALTER TABLE site_inspections ADD COLUMN client_requests TEXT NULL AFTER risk_notes',
        ];

        foreach ($columns as $column => $sql) {
            if (!site_inspection_column_exists($conn, $column)) {
                $conn->query($sql);
            }
        }
    }
}

if (!function_exists('site_inspection_ensure_costing_table')) {
    function site_inspection_ensure_costing_table(mysqli $conn): void
    {
        // Costing draft ito ng Engineer bago gawing quotation ni Admin/System.
        $conn->query(
            "CREATE TABLE IF NOT EXISTS site_inspection_cost_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                inspection_id INT NOT NULL,
                item_type VARCHAR(30) NOT NULL DEFAULT 'material',
                inventory_id INT NULL,
                item_name VARCHAR(180) NOT NULL,
                quantity DECIMAL(12,2) NOT NULL DEFAULT 1.00,
                unit VARCHAR(30) NOT NULL DEFAULT 'unit',
                unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                line_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_site_inspection_cost_items_inspection (inspection_id),
                KEY idx_site_inspection_cost_items_inventory (inventory_id),
                KEY idx_site_inspection_cost_items_type (item_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        if (!site_inspection_cost_column_exists($conn, 'unit')) {
            $conn->query("ALTER TABLE site_inspection_cost_items ADD COLUMN unit VARCHAR(30) NOT NULL DEFAULT 'unit' AFTER quantity");
        }
    }
}

if (!function_exists('site_inspection_format_datetime')) {
    function site_inspection_format_datetime(?string $dateTime): string
    {
        $timestamp = $dateTime ? strtotime($dateTime) : false;
        if ($timestamp === false) {
            return 'Not set';
        }

        return date('M j, Y, g:ia', $timestamp);
    }
}
