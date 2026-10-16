<?php

require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/material_stock.php';

if (!function_exists('material_physical_count_is_ready')) {
    function material_physical_count_is_ready(mysqli $conn): bool
    {
        static $ready = null;

        if ($ready !== null) {
            return $ready;
        }

        $result = $conn->query("SHOW TABLES LIKE 'material_physical_counts'");
        $ready = $result instanceof mysqli_result && $result->num_rows === 1;

        return $ready;
    }
}

if (!function_exists('material_physical_count_fetch_clerk_materials')) {
    function material_physical_count_fetch_clerk_materials(mysqli $conn): array
    {
        // Huwag isama ang system stock sa Clerk query.
        $result = $conn->query(
            "SELECT id, material_name, description, unit
             FROM materials
             WHERE status = 'active'
             ORDER BY material_name ASC, id ASC"
        );

        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('material_physical_count_store_cutoff')) {
    function material_physical_count_store_cutoff(mysqli $conn, string $token): void
    {
        $result = $conn->query(
            "SELECT id, physical_quantity, reserved_quantity
             FROM materials
             WHERE status = 'active'"
        );
        $materials = [];
        if ($result) {
            foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
                $materials[(int)$row['id']] = [
                    'physical_quantity' => (float)$row['physical_quantity'],
                    'reserved_quantity' => (float)$row['reserved_quantity'],
                ];
            }
        }

        $_SESSION['material_physical_count_cutoffs'] ??= [];
        $now = time();
        foreach ($_SESSION['material_physical_count_cutoffs'] as $savedToken => $cutoff) {
            if (($now - (int)($cutoff['created_at'] ?? 0)) > 1800) {
                unset($_SESSION['material_physical_count_cutoffs'][$savedToken]);
            }
        }
        $_SESSION['material_physical_count_cutoffs'][$token] = [
            'created_at' => $now,
            'materials' => $materials,
        ];
    }
}

if (!function_exists('material_physical_count_take_cutoff')) {
    function material_physical_count_take_cutoff(mixed $token): array
    {
        $token = is_string($token) ? $token : '';
        $cutoff = $_SESSION['material_physical_count_cutoffs'][$token] ?? null;
        unset($_SESSION['material_physical_count_cutoffs'][$token]);

        return is_array($cutoff['materials'] ?? null) ? $cutoff['materials'] : [];
    }
}

if (!function_exists('material_physical_count_submit')) {
    function material_physical_count_submit(mysqli $conn, array $counts, array $remarks, int $userId, array $cutoff): int
    {
        if (!material_physical_count_is_ready($conn) || !audit_log_table_exists($conn)) {
            throw new RuntimeException('Physical count storage is not ready. Please contact the administrator.');
        }

        $cleanCounts = [];
        foreach ($counts as $materialId => $rawCount) {
            $materialId = (int)$materialId;
            $rawCount = trim((string)$rawCount);
            if ($rawCount === '') {
                continue;
            }
            if ($materialId <= 0 || !is_numeric($rawCount)) {
                throw new RuntimeException('Enter a valid physical count.');
            }

            $count = (float)$rawCount;
            if (!is_finite($count) || $count < 0 || $count > 9999999999.99) {
                throw new RuntimeException('Physical count must be zero or more.');
            }
            $remark = trim((string)($remarks[$materialId] ?? ''));
            if ((function_exists('mb_strlen') ? mb_strlen($remark) : strlen($remark)) > 255) {
                throw new RuntimeException('Remarks must be 255 characters or less.');
            }
            $cleanCounts[$materialId] = [
                'count' => round($count, 2),
                'remarks' => $remark,
            ];
        }

        if ($cleanCounts === []) {
            throw new RuntimeException('Enter at least one physical count.');
        }

        ksort($cleanCounts);
        $conn->begin_transaction();

        try {
            $materialStmt = $conn->prepare(
                "SELECT id, unit, physical_quantity, reserved_quantity
                 FROM materials
                 WHERE id = ? AND status = 'active'
                 FOR UPDATE"
            );
            $insertStmt = $conn->prepare(
                'INSERT INTO material_physical_counts
                    (material_id, counted_quantity, total_quantity_snapshot, reserved_quantity_snapshot,
                     expected_quantity_snapshot, counted_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            if (!$materialStmt || !$insertStmt) {
                throw new RuntimeException('Unable to save the physical count.');
            }

            foreach ($cleanCounts as $materialId => $countData) {
                $materialStmt->bind_param('i', $materialId);
                $materialStmt->execute();
                $material = $materialStmt->get_result()->fetch_assoc();
                if (!$material) {
                    throw new RuntimeException('One selected material is no longer active.');
                }
                $savedCutoff = $cutoff[$materialId] ?? null;
                if (!is_array($savedCutoff)
                    || abs((float)$material['physical_quantity'] - (float)($savedCutoff['physical_quantity'] ?? -1)) > 0.0001
                    || abs((float)$material['reserved_quantity'] - (float)($savedCutoff['reserved_quantity'] ?? -1)) > 0.0001) {
                    throw new RuntimeException('Stock changed while you were counting. Refresh the page and count again.');
                }

                $countedQuantity = (float)$countData['count'];
                if (material_stock_unit_requires_whole_number((string)$material['unit']) && $countedQuantity !== floor($countedQuantity)) {
                    throw new RuntimeException('Use a whole number for materials counted by ' . $material['unit'] . '.');
                }

                $totalQuantity = (float)$material['physical_quantity'];
                $reservedQuantity = (float)$material['reserved_quantity'];
                // Simple audit: ihambing ang bilang ng Clerk sa system stock.
                $expectedQuantity = $totalQuantity;
                $insertStmt->bind_param(
                    'iddddi',
                    $materialId,
                    $countedQuantity,
                    $totalQuantity,
                    $reservedQuantity,
                    $expectedQuantity,
                    $userId
                );
                if (!$insertStmt->execute()) {
                    throw new RuntimeException('Unable to save the physical count.');
                }

                $auditStmt = $conn->prepare(
                    'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                if (!$auditStmt) {
                    throw new RuntimeException('Unable to save the physical count remarks.');
                }
                $action = 'physical_count_submitted';
                $entityType = 'material_physical_count';
                $entityId = (int)$insertStmt->insert_id;
                $oldValues = null;
                $newValues = json_encode([
                    'material_id' => $materialId,
                    'counted_quantity' => $countedQuantity,
                    'expected_quantity' => $expectedQuantity,
                    'variance' => $countedQuantity - $expectedQuantity,
                    'remarks' => (string)$countData['remarks'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
                $auditStmt->bind_param('ississs', $userId, $action, $entityType, $entityId, $oldValues, $newValues, $ipAddress);
                if (!$auditStmt->execute()) {
                    throw new RuntimeException('Unable to save the physical count remarks.');
                }
            }

            $conn->commit();
            return count($cleanCounts);
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }
}

if (!function_exists('material_physical_count_fetch_latest')) {
    function material_physical_count_fetch_latest(mysqli $conn): array
    {
        if (!material_physical_count_is_ready($conn)) {
            return [];
        }

        $result = $conn->query(
            "SELECT pc.material_id, pc.counted_quantity, pc.total_quantity_snapshot,
                    pc.reserved_quantity_snapshot, pc.expected_quantity_snapshot,
                    pc.counted_at, u.full_name AS counted_by_name, al.new_values AS audit_values
             FROM material_physical_counts pc
             INNER JOIN (
                 SELECT material_id, MAX(id) AS latest_id
                 FROM material_physical_counts
                 GROUP BY material_id
             ) latest ON latest.latest_id = pc.id
             LEFT JOIN users u ON u.id = pc.counted_by
             LEFT JOIN audit_logs al
               ON al.entity_type = 'material_physical_count'
              AND al.entity_id = pc.id
              AND al.action = 'physical_count_submitted'"
        );

        $latestCounts = [];
        if ($result) {
            foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
                $auditValues = json_decode((string)($row['audit_values'] ?? ''), true);
                $row['remarks'] = is_array($auditValues) ? trim((string)($auditValues['remarks'] ?? '')) : '';
                unset($row['audit_values']);
                $latestCounts[(int)$row['material_id']] = $row;
            }
        }

        return $latestCounts;
    }
}

if (!function_exists('material_physical_count_fetch_history')) {
    function material_physical_count_fetch_history(mysqli $conn, int $materialId = 0): array
    {
        if (!material_physical_count_is_ready($conn)) {
            return [];
        }

        $sql = "SELECT pc.id, pc.material_id, pc.counted_quantity, pc.total_quantity_snapshot, pc.counted_at,
                       m.material_name, m.unit, u.full_name AS counted_by_name, al.new_values AS audit_values
                FROM material_physical_counts pc
                INNER JOIN materials m ON m.id = pc.material_id
                LEFT JOIN users u ON u.id = pc.counted_by
                LEFT JOIN audit_logs al
                  ON al.entity_type = 'material_physical_count'
                 AND al.entity_id = pc.id
                 AND al.action = 'physical_count_submitted'";
        if ($materialId > 0) {
            $sql .= ' WHERE pc.material_id = ?';
        }
        $sql .= ' ORDER BY pc.counted_at DESC, pc.id DESC LIMIT 200';

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        if ($materialId > 0) {
            $stmt->bind_param('i', $materialId);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $counts = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

        foreach ($counts as &$count) {
            $auditValues = json_decode((string)($count['audit_values'] ?? ''), true);
            $count['remarks'] = is_array($auditValues) ? trim((string)($auditValues['remarks'] ?? '')) : '';
            unset($count['audit_values']);
        }
        unset($count);

        return $counts;
    }
}
