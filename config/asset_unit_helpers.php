<?php

if (!function_exists('asset_units_table_exists')) {
    function asset_units_table_exists(mysqli $conn, string $tableName): bool
    {
        static $cache = [];

        if (array_key_exists($tableName, $cache)) {
            return $cache[$tableName];
        }

        $statement = $conn->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
             AND table_name = ?'
        );

        if (!$statement) {
            $cache[$tableName] = false;
            return false;
        }

        $statement->bind_param('s', $tableName);
        $statement->execute();
        $statement->bind_result($count);
        $statement->fetch();
        $statement->close();

        $cache[$tableName] = (int)$count > 0;
        return $cache[$tableName];
    }
}

if (!function_exists('asset_units_column_exists')) {
    function asset_units_column_exists(mysqli $conn, string $tableName, string $columnName): bool
    {
        static $cache = [];
        $key = $tableName . '.' . $columnName;

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $statement = $conn->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
             AND table_name = ?
             AND column_name = ?'
        );

        if (!$statement) {
            $cache[$key] = false;
            return false;
        }

        $statement->bind_param('ss', $tableName, $columnName);
        $statement->execute();
        $statement->bind_result($count);
        $statement->fetch();
        $statement->close();

        $cache[$key] = (int)$count > 0;
        return $cache[$key];
    }
}

if (!function_exists('build_asset_unit_label')) {
    function build_asset_unit_label(int $inventoryId, int $unitNumber, string $serialNumber = ''): string
    {
        $base = trim($serialNumber) !== '' ? trim($serialNumber) : ('INV-' . $inventoryId);
        return sprintf('%s-U%03d', $base, $unitNumber);
    }
}

if (!function_exists('build_asset_unit_qr_value')) {
    function build_asset_unit_qr_value(int $assetId, int $unitId, string $unitCode, string $serialNumber = ''): string
    {
        $parts = [
            'asset_id=' . $assetId,
            'unit_id=' . $unitId,
            'unit_code=' . rawurlencode($unitCode),
        ];

        if ($serialNumber !== '') {
            $parts[] = 'sn=' . rawurlencode($serialNumber);
        }

        return implode('|', $parts);
    }
}

if (!function_exists('asset_units_get_column_type')) {
    function asset_units_get_column_type(mysqli $conn, string $tableName, string $columnName): ?string
    {
        $statement = $conn->prepare(
            'SELECT COLUMN_TYPE
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
             AND table_name = ?
             AND column_name = ?
             LIMIT 1'
        );

        if (!$statement) {
            return null;
        }

        $statement->bind_param('ss', $tableName, $columnName);
        $statement->execute();
        $result = $statement->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $statement->close();

        return $row['COLUMN_TYPE'] ?? null;
    }
}

if (!function_exists('ensure_asset_unit_status_values')) {
    function ensure_asset_unit_status_values(mysqli $conn): void
    {
        $columnType = asset_units_get_column_type($conn, 'asset_units', 'status');
        if ($columnType === null || !str_starts_with($columnType, 'enum(')) {
            return;
        }

        preg_match_all("/'([^']+)'/", $columnType, $matches);
        $currentValues = $matches[1] ?? [];
        $requiredValues = ['available', 'deployed', 'maintenance', 'lost', 'archived'];
        $nextValues = array_values(array_unique(array_merge($currentValues, $requiredValues)));

        if ($currentValues === $nextValues) {
            return;
        }

        $enumSql = "'" . implode("','", array_map(static fn(string $value): string => $conn->real_escape_string($value), $nextValues)) . "'";
        $conn->query("ALTER TABLE asset_units MODIFY COLUMN status ENUM($enumSql) NOT NULL DEFAULT 'available'");
    }
}

if (!function_exists('ensure_asset_unit_tracking_schema')) {
    function ensure_asset_unit_tracking_schema(mysqli $conn): void
    {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS asset_units (
                id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                asset_id INT(11) NOT NULL,
                inventory_id INT(11) NOT NULL,
                unit_number INT(11) NOT NULL,
                unit_code VARCHAR(190) NOT NULL,
                qr_code_value VARCHAR(512) NOT NULL,
                status ENUM('available', 'deployed', 'archived') NOT NULL DEFAULT 'available',
                archived_at DATETIME DEFAULT NULL,
                last_scanned_at DATETIME DEFAULT NULL,
                last_scanned_by INT(11) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_asset_units_code (unit_code),
                UNIQUE KEY uniq_asset_units_qr (qr_code_value),
                UNIQUE KEY uniq_asset_units_inventory_number (inventory_id, unit_number),
                KEY idx_asset_units_asset (asset_id),
                KEY idx_asset_units_inventory (inventory_id),
                KEY idx_asset_units_status (status),
                CONSTRAINT fk_asset_units_asset FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE CASCADE,
                CONSTRAINT fk_asset_units_inventory FOREIGN KEY (inventory_id) REFERENCES inventory (id) ON DELETE CASCADE,
                CONSTRAINT fk_asset_units_last_scanned_by FOREIGN KEY (last_scanned_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        ensure_asset_unit_status_values($conn);

        if (asset_units_table_exists($conn, 'project_inventory_deployments')) {
            $conn->query(
                "CREATE TABLE IF NOT EXISTS project_inventory_deployment_units (
                    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    deployment_id INT(11) NOT NULL,
                    asset_unit_id INT(11) NOT NULL,
                    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    returned_at DATETIME DEFAULT NULL,
                    KEY idx_project_inventory_deployment_units_deployment (deployment_id),
                    KEY idx_project_inventory_deployment_units_unit (asset_unit_id),
                    KEY idx_project_inventory_deployment_units_returned (returned_at),
                    UNIQUE KEY uniq_project_inventory_deployment_units_active (deployment_id, asset_unit_id, returned_at),
                    CONSTRAINT fk_project_inventory_deployment_units_deployment FOREIGN KEY (deployment_id) REFERENCES project_inventory_deployments (id) ON DELETE CASCADE,
                    CONSTRAINT fk_project_inventory_deployment_units_unit FOREIGN KEY (asset_unit_id) REFERENCES asset_units (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
            );
        }

        if (asset_units_table_exists($conn, 'asset_usage_logs') && !asset_units_column_exists($conn, 'asset_usage_logs', 'asset_unit_id')) {
            $conn->query("ALTER TABLE asset_usage_logs ADD COLUMN asset_unit_id INT(11) DEFAULT NULL AFTER asset_id");
            $conn->query("ALTER TABLE asset_usage_logs ADD KEY idx_asset_usage_logs_unit (asset_unit_id)");
            $conn->query("ALTER TABLE asset_usage_logs ADD CONSTRAINT fk_asset_usage_logs_unit FOREIGN KEY (asset_unit_id) REFERENCES asset_units (id) ON DELETE SET NULL");
        }

        if (asset_units_table_exists($conn, 'asset_scan_history') && !asset_units_column_exists($conn, 'asset_scan_history', 'asset_unit_id')) {
            $conn->query("ALTER TABLE asset_scan_history ADD COLUMN asset_unit_id INT(11) DEFAULT NULL AFTER asset_id");
            $conn->query("ALTER TABLE asset_scan_history ADD KEY idx_asset_scan_history_unit (asset_unit_id)");
            $conn->query("ALTER TABLE asset_scan_history ADD CONSTRAINT fk_asset_scan_history_unit FOREIGN KEY (asset_unit_id) REFERENCES asset_units (id) ON DELETE SET NULL");
        }
    }
}

if (!function_exists('asset_units_fetch_status_counts')) {
    function asset_units_fetch_status_counts(mysqli $conn, int $inventoryId): array
    {
        $counts = [
            'available' => 0,
            'deployed' => 0,
            'maintenance' => 0,
            'lost' => 0,
        ];

        $statement = $conn->prepare(
            "SELECT status, COUNT(*) AS total
             FROM asset_units
             WHERE inventory_id = ?
             AND status <> 'archived'
             GROUP BY status"
        );

        if (!$statement) {
            return $counts;
        }

        $statement->bind_param('i', $inventoryId);
        $statement->execute();
        $result = $statement->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $statement->close();

        foreach ($rows as $row) {
            $statusKey = (string)($row['status'] ?? '');
            if (array_key_exists($statusKey, $counts)) {
                $counts[$statusKey] = (int)($row['total'] ?? 0);
            }
        }

        return $counts;
    }
}

if (!function_exists('asset_units_mark_available_units')) {
    function asset_units_mark_available_units(mysqli $conn, int $inventoryId, int $quantity, string $targetStatus): array
    {
        $allowedStatuses = ['maintenance', 'lost'];
        if ($quantity <= 0 || !in_array($targetStatus, $allowedStatuses, true)) {
            return [];
        }

        $statement = $conn->prepare(
            "SELECT id, unit_code
             FROM asset_units
             WHERE inventory_id = ?
             AND status = 'available'
             ORDER BY id DESC
             LIMIT ?"
        );

        if (!$statement) {
            throw new RuntimeException('Failed to prepare asset unit status update lookup.');
        }

        $statement->bind_param('ii', $inventoryId, $quantity);
        $statement->execute();
        $result = $statement->get_result();
        $units = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $statement->close();

        if (count($units) < $quantity) {
            throw new RuntimeException('Not enough available units were found for this asset.');
        }

        $unitIds = array_map('intval', array_column($units, 'id'));
        $unitCodes = array_map(static fn(array $row): string => (string)($row['unit_code'] ?? ''), $units);
        $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
        $types = 's' . str_repeat('i', count($unitIds));
        $params = array_merge([$targetStatus], $unitIds);

        $updateStatement = $conn->prepare(
            "UPDATE asset_units
             SET status = ?
             WHERE id IN ($placeholders)"
        );

        if (!$updateStatement) {
            throw new RuntimeException('Failed to update asset unit statuses.');
        }

        $updateStatement->bind_param($types, ...$params);
        if (!$updateStatement->execute()) {
            throw new RuntimeException('Failed to update asset unit statuses.');
        }
        $updateStatement->close();

        return $unitCodes;
    }
}

if (!function_exists('asset_units_restore_units_to_available')) {
    function asset_units_restore_units_to_available(mysqli $conn, int $inventoryId, int $quantity, string $sourceStatus): array
    {
        $allowedStatuses = ['deployed', 'maintenance', 'lost'];
        if ($quantity <= 0 || !in_array($sourceStatus, $allowedStatuses, true)) {
            return [];
        }

        $statement = $conn->prepare(
            "SELECT id, unit_code
             FROM asset_units
             WHERE inventory_id = ?
             AND status = ?
             ORDER BY id DESC
             LIMIT ?"
        );

        if (!$statement) {
            throw new RuntimeException('Failed to prepare asset unit recovery lookup.');
        }

        $statement->bind_param('isi', $inventoryId, $sourceStatus, $quantity);
        $statement->execute();
        $result = $statement->get_result();
        $units = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $statement->close();

        if (count($units) < $quantity) {
            throw new RuntimeException('Not enough asset units were found for the selected recovery action.');
        }

        $unitIds = array_map('intval', array_column($units, 'id'));
        $unitCodes = array_map(static fn(array $row): string => (string)($row['unit_code'] ?? ''), $units);
        $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
        $types = str_repeat('i', count($unitIds));

        if (
            $sourceStatus === 'deployed' &&
            asset_units_table_exists($conn, 'project_inventory_deployment_units')
        ) {
            $mappingTypes = $types;
            $mappingStatement = $conn->prepare(
                "UPDATE project_inventory_deployment_units
                 SET returned_at = NOW()
                 WHERE asset_unit_id IN ($placeholders)
                 AND returned_at IS NULL"
            );

            if (!$mappingStatement) {
                throw new RuntimeException('Failed to release deployment mapping for returned units.');
            }

            $mappingStatement->bind_param($mappingTypes, ...$unitIds);
            if (!$mappingStatement->execute()) {
                throw new RuntimeException('Failed to release deployment mapping for returned units.');
            }
            $mappingStatement->close();
        }

        $updateStatement = $conn->prepare(
            "UPDATE asset_units
             SET status = 'available'
             WHERE id IN ($placeholders)"
        );

        if (!$updateStatement) {
            throw new RuntimeException('Failed to restore asset unit statuses.');
        }

        $updateStatement->bind_param($types, ...$unitIds);
        if (!$updateStatement->execute()) {
            throw new RuntimeException('Failed to restore asset unit statuses.');
        }
        $updateStatement->close();

        return $unitCodes;
    }
}

if (!function_exists('asset_units_fetch_inventory_context')) {
    function asset_units_fetch_inventory_context(mysqli $conn, int $inventoryId): ?array
    {
        $hasProjectDeploymentTables = asset_units_table_exists($conn, 'project_inventory_deployments')
            && asset_units_table_exists($conn, 'project_inventory_return_logs');

        $sql = "SELECT
                i.id AS inventory_id,
                i.asset_id,
                i.quantity AS available_quantity,
                a.serial_number,";

        if ($hasProjectDeploymentTables) {
            $sql .= "
                COALESCE(active_deployments.active_quantity, 0) AS active_deployed_quantity
             FROM inventory i
             INNER JOIN assets a ON a.id = i.asset_id
             LEFT JOIN (
                SELECT
                    pid.inventory_id,
                    SUM(pid.quantity - COALESCE(returns.returned_quantity, 0)) AS active_quantity
                FROM project_inventory_deployments pid
                LEFT JOIN (
                    SELECT deployment_id, SUM(quantity) AS returned_quantity
                    FROM project_inventory_return_logs
                    GROUP BY deployment_id
                ) returns ON returns.deployment_id = pid.id
                WHERE (pid.quantity - COALESCE(returns.returned_quantity, 0)) > 0
                GROUP BY pid.inventory_id
             ) active_deployments ON active_deployments.inventory_id = i.id
             WHERE i.id = ?
             LIMIT 1";
        } else {
            $sql .= "
                0 AS active_deployed_quantity
             FROM inventory i
             INNER JOIN assets a ON a.id = i.asset_id
             WHERE i.id = ?
             LIMIT 1";
        }

        $statement = $conn->prepare($sql);

        if (!$statement) {
            return null;
        }

        $statement->bind_param('i', $inventoryId);
        $statement->execute();
        $result = $statement->get_result();
        return $result ? $result->fetch_assoc() : null;
    }
}

if (!function_exists('asset_units_count_active_rows')) {
    function asset_units_count_active_rows(mysqli $conn, int $inventoryId): int
    {
        $statement = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM asset_units
             WHERE inventory_id = ?
             AND status <> 'archived'"
        );

        if (!$statement) {
            return 0;
        }

        $statement->bind_param('i', $inventoryId);
        $statement->execute();
        $result = $statement->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        return (int)($row['total'] ?? 0);
    }
}

if (!function_exists('asset_units_insert_new_rows')) {
    function asset_units_insert_new_rows(mysqli $conn, int $inventoryId, int $assetId, string $serialNumber, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $numberStatement = $conn->prepare(
            "SELECT COALESCE(MAX(unit_number), 0) AS max_unit_number
             FROM asset_units
             WHERE inventory_id = ?"
        );
        $numberStatement->bind_param('i', $inventoryId);
        $numberStatement->execute();
        $numberResult = $numberStatement->get_result();
        $maxUnitNumber = (int)(($numberResult ? $numberResult->fetch_assoc() : [])['max_unit_number'] ?? 0);

        for ($offset = 1; $offset <= $count; $offset++) {
            $unitNumber = $maxUnitNumber + $offset;
            $unitCode = build_asset_unit_label($inventoryId, $unitNumber, $serialNumber);
            $placeholderQr = 'pending:' . $assetId . ':' . $inventoryId . ':' . $unitNumber . ':' . microtime(true);

            $insertStatement = $conn->prepare(
                "INSERT INTO asset_units (asset_id, inventory_id, unit_number, unit_code, qr_code_value, status)
                 VALUES (?, ?, ?, ?, ?, 'available')"
            );

            if (
                !$insertStatement ||
                !$insertStatement->bind_param('iiiss', $assetId, $inventoryId, $unitNumber, $unitCode, $placeholderQr) ||
                !$insertStatement->execute()
            ) {
                throw new RuntimeException('Failed to create asset unit records.');
            }

            $assetUnitId = (int)$insertStatement->insert_id;
            $qrValue = build_asset_unit_qr_value($assetId, $assetUnitId, $unitCode, $serialNumber);

            $updateStatement = $conn->prepare('UPDATE asset_units SET qr_code_value = ? WHERE id = ?');
            if (
                !$updateStatement ||
                !$updateStatement->bind_param('si', $qrValue, $assetUnitId) ||
                !$updateStatement->execute()
            ) {
                throw new RuntimeException('Failed to finalize asset unit QR values.');
            }
        }
    }
}

if (!function_exists('asset_units_archive_available_rows')) {
    function asset_units_archive_available_rows(mysqli $conn, int $inventoryId, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $statement = $conn->prepare(
            "SELECT id
             FROM asset_units
             WHERE inventory_id = ?
             AND status = 'available'
             ORDER BY id DESC
             LIMIT ?"
        );

        if (!$statement) {
            throw new RuntimeException('Failed to prepare removable asset units lookup.');
        }

        $statement->bind_param('ii', $inventoryId, $count);
        $statement->execute();
        $result = $statement->get_result();
        $unitIds = $result ? array_map('intval', array_column($result->fetch_all(MYSQLI_ASSOC), 'id')) : [];

        if (count($unitIds) < $count) {
            throw new RuntimeException('Cannot reduce inventory below the number of already deployed units.');
        }

        $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
        $types = str_repeat('i', count($unitIds));
        $params = $unitIds;

        $archiveStatement = $conn->prepare(
            "UPDATE asset_units
             SET status = 'archived',
                 archived_at = NOW()
             WHERE id IN ($placeholders)"
        );

        if (!$archiveStatement) {
            throw new RuntimeException('Failed to archive surplus asset units.');
        }

        $archiveStatement->bind_param($types, ...$params);
        if (!$archiveStatement->execute()) {
            throw new RuntimeException('Failed to archive surplus asset units.');
        }
    }
}

if (!function_exists('asset_units_assign_available_to_deployment')) {
    function asset_units_assign_available_to_deployment(mysqli $conn, int $deploymentId, int $inventoryId, int $quantity): array
    {
        if ($quantity <= 0) {
            return [];
        }

        $statement = $conn->prepare(
            "SELECT id, unit_code
             FROM asset_units
             WHERE inventory_id = ?
             AND status = 'available'
             ORDER BY id ASC
             LIMIT ?"
        );

        if (!$statement) {
            throw new RuntimeException('Failed to prepare available asset unit lookup.');
        }

        $statement->bind_param('ii', $inventoryId, $quantity);
        $statement->execute();
        $result = $statement->get_result();
        $units = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

        if (count($units) < $quantity) {
            throw new RuntimeException('Not enough unit instances are available for deployment.');
        }

        $unitIds = array_map('intval', array_column($units, 'id'));
        $unitCodes = array_map(static fn(array $row): string => (string)($row['unit_code'] ?? ''), $units);

        $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
        $types = str_repeat('i', count($unitIds));

        $updateStatement = $conn->prepare(
            "UPDATE asset_units
             SET status = 'deployed'
             WHERE id IN ($placeholders)"
        );

        if (!$updateStatement) {
            throw new RuntimeException('Failed to mark selected units as deployed.');
        }

        $updateStatement->bind_param($types, ...$unitIds);
        if (!$updateStatement->execute()) {
            throw new RuntimeException('Failed to mark selected units as deployed.');
        }

        $insertStatement = $conn->prepare(
            "INSERT INTO project_inventory_deployment_units (deployment_id, asset_unit_id)
             VALUES (?, ?)"
        );

        if (!$insertStatement) {
            throw new RuntimeException('Failed to save deployment-unit assignments.');
        }

        foreach ($unitIds as $unitId) {
            if (!$insertStatement->bind_param('ii', $deploymentId, $unitId) || !$insertStatement->execute()) {
                throw new RuntimeException('Failed to save deployment-unit assignments.');
            }
        }

        return $unitCodes;
    }
}

if (!function_exists('asset_units_release_from_deployment')) {
    function asset_units_release_from_deployment(mysqli $conn, int $deploymentId, int $quantity): array
    {
        if ($quantity <= 0) {
            return [];
        }

        $statement = $conn->prepare(
            "SELECT pdu.id, pdu.asset_unit_id, au.unit_code
             FROM project_inventory_deployment_units pdu
             INNER JOIN asset_units au ON au.id = pdu.asset_unit_id
             WHERE pdu.deployment_id = ?
             AND pdu.returned_at IS NULL
             ORDER BY pdu.id DESC
             LIMIT ?"
        );

        if (!$statement) {
            throw new RuntimeException('Failed to prepare deployed unit lookup.');
        }

        $statement->bind_param('ii', $deploymentId, $quantity);
        $statement->execute();
        $result = $statement->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

        if (count($rows) < $quantity) {
            throw new RuntimeException('Not enough deployed unit assignments were found for this return.');
        }

        $mappingIds = array_map('intval', array_column($rows, 'id'));
        $unitIds = array_map('intval', array_column($rows, 'asset_unit_id'));
        $unitCodes = array_map(static fn(array $row): string => (string)($row['unit_code'] ?? ''), $rows);

        $mappingPlaceholders = implode(',', array_fill(0, count($mappingIds), '?'));
        $mappingTypes = str_repeat('i', count($mappingIds));
        $mappingUpdate = $conn->prepare(
            "UPDATE project_inventory_deployment_units
             SET returned_at = NOW()
             WHERE id IN ($mappingPlaceholders)"
        );

        if (!$mappingUpdate) {
            throw new RuntimeException('Failed to release deployment-unit mappings.');
        }

        $mappingUpdate->bind_param($mappingTypes, ...$mappingIds);
        if (!$mappingUpdate->execute()) {
            throw new RuntimeException('Failed to release deployment-unit mappings.');
        }

        $unitPlaceholders = implode(',', array_fill(0, count($unitIds), '?'));
        $unitTypes = str_repeat('i', count($unitIds));
        $unitUpdate = $conn->prepare(
            "UPDATE asset_units
             SET status = 'available'
             WHERE id IN ($unitPlaceholders)"
        );

        if (!$unitUpdate) {
            throw new RuntimeException('Failed to mark returned units as available.');
        }

        $unitUpdate->bind_param($unitTypes, ...$unitIds);
        if (!$unitUpdate->execute()) {
            throw new RuntimeException('Failed to mark returned units as available.');
        }

        return $unitCodes;
    }
}

if (!function_exists('asset_units_reconcile_active_deployments')) {
    function asset_units_reconcile_active_deployments(mysqli $conn, int $inventoryId): void
    {
        if (
            !asset_units_table_exists($conn, 'project_inventory_deployment_units') ||
            !asset_units_table_exists($conn, 'project_inventory_deployments') ||
            !asset_units_table_exists($conn, 'project_inventory_return_logs')
        ) {
            return;
        }

        $statement = $conn->prepare(
            "SELECT
                pid.id,
                (pid.quantity - COALESCE(returns.returned_quantity, 0)) AS remaining_quantity
             FROM project_inventory_deployments pid
             LEFT JOIN (
                SELECT deployment_id, SUM(quantity) AS returned_quantity
                FROM project_inventory_return_logs
                GROUP BY deployment_id
             ) returns ON returns.deployment_id = pid.id
             WHERE pid.inventory_id = ?
             AND (pid.quantity - COALESCE(returns.returned_quantity, 0)) > 0
             ORDER BY pid.deployed_at ASC, pid.id ASC"
        );

        if (!$statement) {
            return;
        }

        $statement->bind_param('i', $inventoryId);
        $statement->execute();
        $result = $statement->get_result();
        $deployments = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

        foreach ($deployments as $deployment) {
            $deploymentId = (int)($deployment['id'] ?? 0);
            $remainingQuantity = (int)($deployment['remaining_quantity'] ?? 0);

            $countStatement = $conn->prepare(
                "SELECT COUNT(*) AS assigned_units
                 FROM project_inventory_deployment_units
                 WHERE deployment_id = ?
                 AND returned_at IS NULL"
            );

            if (!$countStatement) {
                continue;
            }

            $countStatement->bind_param('i', $deploymentId);
            $countStatement->execute();
            $countResult = $countStatement->get_result();
            $assignedUnits = (int)(($countResult ? $countResult->fetch_assoc() : [])['assigned_units'] ?? 0);

            if ($remainingQuantity > $assignedUnits) {
                asset_units_assign_available_to_deployment($conn, $deploymentId, $inventoryId, $remainingQuantity - $assignedUnits);
            }
        }
    }
}

if (!function_exists('asset_units_sync_for_inventory')) {
    function asset_units_sync_for_inventory(mysqli $conn, int $inventoryId, ?int $desiredAvailableQuantity = null): void
    {
        ensure_asset_unit_tracking_schema($conn);

        $context = asset_units_fetch_inventory_context($conn, $inventoryId);
        if (!$context) {
            throw new RuntimeException('Inventory context was not found for unit sync.');
        }

        $availableQuantity = $desiredAvailableQuantity !== null
            ? max(0, $desiredAvailableQuantity)
            : (int)($context['available_quantity'] ?? 0);
        $activeDeployedQuantity = (int)($context['active_deployed_quantity'] ?? 0);
        $desiredTotalUnits = $availableQuantity + $activeDeployedQuantity;
        $currentTotalUnits = asset_units_count_active_rows($conn, $inventoryId);

        if ($currentTotalUnits < $desiredTotalUnits) {
            asset_units_insert_new_rows(
                $conn,
                $inventoryId,
                (int)($context['asset_id'] ?? 0),
                (string)($context['serial_number'] ?? ''),
                $desiredTotalUnits - $currentTotalUnits
            );
        } elseif ($currentTotalUnits > $desiredTotalUnits) {
            asset_units_archive_available_rows($conn, $inventoryId, $currentTotalUnits - $desiredTotalUnits);
        }

        asset_units_reconcile_active_deployments($conn, $inventoryId);
    }
}

if (!function_exists('asset_units_find_by_scan_context')) {
    function asset_units_find_by_scan_context(mysqli $conn, int $assetId, ?int $assetUnitId = null, ?string $unitCode = null): ?array
    {
        ensure_asset_unit_tracking_schema($conn);

        if ($assetUnitId !== null && $assetUnitId > 0) {
            $statement = $conn->prepare(
                "SELECT
                    au.id AS asset_unit_id,
                    au.asset_id,
                    au.inventory_id,
                    au.unit_code,
                    au.qr_code_value,
                    au.status AS unit_status,
                    a.asset_name,
                    a.asset_type,
                    a.serial_number,
                    a.asset_status
                 FROM asset_units au
                 INNER JOIN assets a ON a.id = au.asset_id
                 WHERE au.id = ?
                 AND au.asset_id = ?
                 AND au.status <> 'archived'
                 LIMIT 1"
            );

            if (!$statement) {
                return null;
            }

            $statement->bind_param('ii', $assetUnitId, $assetId);
            $statement->execute();
            $result = $statement->get_result();
            return $result ? $result->fetch_assoc() : null;
        }

        if ($unitCode !== null && $unitCode !== '') {
            $statement = $conn->prepare(
                "SELECT
                    au.id AS asset_unit_id,
                    au.asset_id,
                    au.inventory_id,
                    au.unit_code,
                    au.qr_code_value,
                    au.status AS unit_status,
                    a.asset_name,
                    a.asset_type,
                    a.serial_number,
                    a.asset_status
                 FROM asset_units au
                 INNER JOIN assets a ON a.id = au.asset_id
                 WHERE au.unit_code = ?
                 AND au.asset_id = ?
                 AND au.status <> 'archived'
                 LIMIT 1"
            );

            if (!$statement) {
                return null;
            }

            $statement->bind_param('si', $unitCode, $assetId);
            $statement->execute();
            $result = $statement->get_result();
            return $result ? $result->fetch_assoc() : null;
        }

        return null;
    }
}
