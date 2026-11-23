<?php

require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/material_stock.php';
require_once __DIR__ . '/user_notifications.php';

if (!function_exists('material_physical_count_ensure_ready_notifications')) {
    function material_physical_count_ensure_ready_notifications(mysqli $conn, int $clerkUserId): void
    {
        if ($clerkUserId <= 0) {
            return;
        }

        $stmt = $conn->prepare(
            "SELECT id, material_name
             FROM materials m
             WHERE m.status = 'active'
               AND m.opening_stock_status = 'pending_count'
               AND NOT EXISTS (
                    SELECT 1
                    FROM user_notifications un
                    WHERE un.user_id = ?
                      AND un.type = 'material_physical_count_ready'
                      AND un.reference_id = m.id
               )
             ORDER BY m.id ASC"
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $clerkUserId);
        $stmt->execute();
        $result = $stmt->get_result();

        foreach ($result->fetch_all(MYSQLI_ASSOC) as $material) {
            $materialId = (int)($material['id'] ?? 0);
            user_notifications_create_if_missing(
                $conn,
                $clerkUserId,
                'material_physical_count_ready',
                $materialId,
                'New Physical Count',
                'New physical count request: ' . (string)($material['material_name'] ?? 'Material') . '.',
                '/codesamplecaps/INVENTORY_CLERK/dashboards/materials.php',
                'physical-count-ready-' . $materialId . '-user-' . $clerkUserId
            );
        }
    }
}

if (!function_exists('material_physical_count_status')) {
    function material_physical_count_status(?array $count): array
    {
        if (!$count) {
            return ['key' => 'not_counted', 'label' => 'Not Yet Counted', 'tone' => 'neutral', 'needs_review' => false];
        }

        $reviewAction = (string)($count['review_action'] ?? '');
        if ($reviewAction === 'physical_count_accepted') {
            return ['key' => 'verified', 'label' => 'Verified', 'tone' => 'success', 'needs_review' => false];
        }
        if ($reviewAction === 'physical_count_recount_requested') {
            return ['key' => 'recount', 'label' => 'Recount Requested', 'tone' => 'warning', 'needs_review' => false];
        }

        $variance = (float)$count['counted_quantity'] - (float)$count['expected_quantity_snapshot'];
        if (abs($variance) < 0.005) {
            if (($count['count_type'] ?? 'regular') === 'opening') {
                return ['key' => 'opening_review', 'label' => 'Initial Count Review', 'tone' => 'warning', 'needs_review' => true];
            }
            return ['key' => 'matched', 'label' => 'Matched', 'tone' => 'success', 'needs_review' => false];
        }
        if ($variance > 0) {
            return ['key' => 'excess', 'label' => 'Excess', 'tone' => 'warning', 'needs_review' => true];
        }

        return ['key' => 'shortage', 'label' => 'Shortage', 'tone' => 'danger', 'needs_review' => true];
    }
}

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
             FROM materials m
             WHERE m.status = 'active'
               AND (
                    m.opening_stock_status IN ('pending_count', 'recount', 'approved')
                    OR m.physical_quantity > 0
                    OR EXISTS (SELECT 1 FROM material_stock_movements sm WHERE sm.material_id = m.id)
               )
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
                "SELECT id, unit, physical_quantity, reserved_quantity, opening_stock_expected, opening_stock_status
                 FROM materials
                 WHERE id = ? AND status = 'active'
                 FOR UPDATE"
            );
            $insertStmt = $conn->prepare(
                'INSERT INTO material_physical_counts
                    (material_id, counted_quantity, total_quantity_snapshot, reserved_quantity_snapshot,
                     expected_quantity_snapshot, count_type, counted_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
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

                $latestStmt = $conn->prepare(
                    "SELECT pc.id, pc.counted_quantity, pc.total_quantity_snapshot, pc.expected_quantity_snapshot, pc.count_type,
                            (SELECT review_log.action
                             FROM audit_logs review_log
                             WHERE review_log.entity_type = 'material_physical_count'
                               AND review_log.entity_id = pc.id
                               AND review_log.action IN ('physical_count_accepted', 'physical_count_recount_requested')
                             ORDER BY review_log.id DESC LIMIT 1) AS review_action
                     FROM material_physical_counts pc
                     WHERE pc.material_id = ?
                     ORDER BY pc.id DESC
                     LIMIT 1
                     FOR UPDATE"
                );
                if (!$latestStmt) {
                    throw new RuntimeException('Unable to check the latest physical count.');
                }
                $latestStmt->bind_param('i', $materialId);
                $latestStmt->execute();
                $latestCount = $latestStmt->get_result()->fetch_assoc();
                if ($latestCount) {
                    $latestStatus = material_physical_count_status($latestCount);
                    if (!in_array($latestStatus['key'], ['verified', 'recount'], true)) {
                        throw new RuntimeException(
                            $latestStatus['key'] === 'matched'
                                ? 'This material already has a matched physical count.'
                                : 'This material count is waiting for Admin review.'
                        );
                    }
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
                $isOpeningCount = in_array((string)$material['opening_stock_status'], ['pending_count', 'recount'], true);
                $expectedQuantity = $isOpeningCount
                    ? (float)$material['opening_stock_expected']
                    : $totalQuantity;
                $countType = $isOpeningCount ? 'opening' : 'regular';
                $insertStmt->bind_param(
                    'iddddsi',
                    $materialId,
                    $countedQuantity,
                    $totalQuantity,
                    $reservedQuantity,
                    $expectedQuantity,
                    $countType,
                    $userId
                );
                if (!$insertStmt->execute()) {
                    throw new RuntimeException('Unable to save the physical count.');
                }
                if ($isOpeningCount) {
                    $openingUpdate = $conn->prepare("UPDATE materials SET opening_stock_status = 'pending_review' WHERE id = ?");
                    $openingUpdate->bind_param('i', $materialId);
                    if (!$openingUpdate->execute()) {
                        throw new RuntimeException('Unable to update the initial count status.');
                    }
                }
                $physicalCountId = (int)$insertStmt->insert_id;

                $auditStmt = $conn->prepare(
                    'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                if (!$auditStmt) {
                    throw new RuntimeException('Unable to save the physical count remarks.');
                }
                $action = 'physical_count_submitted';
                $entityType = 'material_physical_count';
                $entityId = $physicalCountId;
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

                $variance = $countedQuantity - $expectedQuantity;
                if (abs($variance) >= 0.005) {
                    $adminResult = $conn->query(
                        "SELECT id FROM users WHERE role = 'admin' AND status = 'active' AND deleted_at IS NULL ORDER BY id ASC LIMIT 1"
                    );
                    $adminId = $adminResult instanceof mysqli_result ? (int)($adminResult->fetch_assoc()['id'] ?? 0) : 0;
                    user_notifications_create_if_missing(
                        $conn,
                        $adminId,
                        'material_physical_count_variance',
                        $physicalCountId,
                        'Inventory variance needs review',
                        'A Clerk physical count does not match the system stock.',
                        '/codesamplecaps/ADMIN/sidebar/inventory/php/inventory.php?view=materials',
                        'material-count-variance-' . $physicalCountId
                    );
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
            "SELECT pc.id, pc.material_id, pc.counted_quantity, pc.total_quantity_snapshot,
                    pc.reserved_quantity_snapshot, pc.expected_quantity_snapshot, pc.count_type,
                    pc.counted_at, u.full_name AS counted_by_name, al.new_values AS audit_values,
                    (SELECT review_log.action
                     FROM audit_logs review_log
                     WHERE review_log.entity_type = 'material_physical_count'
                       AND review_log.entity_id = pc.id
                       AND review_log.action IN ('physical_count_accepted', 'physical_count_recount_requested')
                     ORDER BY review_log.id DESC LIMIT 1) AS review_action
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

        $sql = "SELECT pc.id, pc.material_id, pc.counted_quantity, pc.total_quantity_snapshot, pc.expected_quantity_snapshot, pc.count_type, pc.counted_at,
                       m.material_name, m.unit, u.full_name AS counted_by_name, al.new_values AS audit_values
                       ,(SELECT review_log.action
                         FROM audit_logs review_log
                         WHERE review_log.entity_type = 'material_physical_count'
                           AND review_log.entity_id = pc.id
                           AND review_log.action IN ('physical_count_accepted', 'physical_count_recount_requested')
                         ORDER BY review_log.id DESC LIMIT 1) AS review_action
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

if (!function_exists('material_physical_count_review')) {
    function material_physical_count_review(mysqli $conn, int $countId, string $decision, int $adminId): void
    {
        if ($countId <= 0 || !in_array($decision, ['accept', 'recount'], true)) {
            throw new RuntimeException('Invalid physical count review.');
        }

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare(
                "SELECT pc.id, pc.material_id, pc.counted_quantity, pc.total_quantity_snapshot, pc.expected_quantity_snapshot, pc.count_type, pc.counted_by,
                        m.material_name, m.unit, m.physical_quantity, m.reserved_quantity, m.opening_stock_status,
                        (SELECT review_log.action
                         FROM audit_logs review_log
                         WHERE review_log.entity_type = 'material_physical_count'
                           AND review_log.entity_id = pc.id
                           AND review_log.action IN ('physical_count_accepted', 'physical_count_recount_requested')
                         ORDER BY review_log.id DESC LIMIT 1) AS review_action,
                        (SELECT MAX(latest_pc.id) FROM material_physical_counts latest_pc WHERE latest_pc.material_id = pc.material_id) AS latest_id
                 FROM material_physical_counts pc
                 INNER JOIN materials m ON m.id = pc.material_id
                 WHERE pc.id = ?
                 FOR UPDATE"
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to review the physical count.');
            }
            $stmt->bind_param('i', $countId);
            $stmt->execute();
            $count = $stmt->get_result()->fetch_assoc();
            if (!$count || (int)$count['latest_id'] !== $countId) {
                throw new RuntimeException('Only the latest physical count can be reviewed.');
            }
            if (trim((string)($count['review_action'] ?? '')) !== '') {
                throw new RuntimeException('This physical count was already reviewed.');
            }

            $variance = (float)$count['counted_quantity'] - (float)$count['expected_quantity_snapshot'];
            $isOpeningCount = (string)($count['count_type'] ?? 'regular') === 'opening';
            if (!$isOpeningCount && abs($variance) < 0.005) {
                throw new RuntimeException('This physical count already matches the system stock.');
            }

            $action = $decision === 'accept' ? 'physical_count_accepted' : 'physical_count_recount_requested';
            if ($decision === 'accept') {
                $materialId = (int)$count['material_id'];
                $deliveryTable = $conn->query("SHOW TABLES LIKE 'material_delivery_verifications'");
                if ($deliveryTable instanceof mysqli_result && $deliveryTable->num_rows === 1) {
                    $deliveryStmt = $conn->prepare(
                        "SELECT id
                         FROM material_delivery_verifications
                         WHERE material_id = ? AND status = 'for_verification'
                         LIMIT 1 FOR UPDATE"
                    );
                    if (!$deliveryStmt) {
                        throw new RuntimeException('Unable to check pending deliveries.');
                    }
                    $deliveryStmt->bind_param('i', $materialId);
                    $deliveryStmt->execute();
                    if ($deliveryStmt->get_result()->fetch_assoc()) {
                        throw new RuntimeException('Verify the pending delivery first to avoid adding the same stock twice.');
                    }
                }

                $currentQuantity = (float)$count['physical_quantity'];
                if (abs($currentQuantity - (float)$count['total_quantity_snapshot']) >= 0.005) {
                    throw new RuntimeException('Stock changed after this count. Request a new count instead.');
                }
                $newQuantity = (float)$count['counted_quantity'];
                if ($newQuantity + 0.0001 < (float)$count['reserved_quantity']) {
                    throw new RuntimeException('The count is below reserved stock. Resolve the reservation first.');
                }

                $update = $conn->prepare('UPDATE materials SET physical_quantity = ? WHERE id = ?');
                if (!$update) {
                    throw new RuntimeException('Unable to update the system stock.');
                }
                $update->bind_param('di', $newQuantity, $materialId);
                if (!$update->execute()) {
                    throw new RuntimeException('Unable to update the system stock.');
                }

                material_stock_log_movement(
                    $conn,
                    $materialId,
                    $variance > 0 ? 'adjustment_in' : 'adjustment_out',
                    abs($variance),
                    $currentQuantity,
                    $newQuantity,
                    $isOpeningCount ? 'Accepted opening count #' . $countId : 'Accepted Clerk physical count #' . $countId,
                    $adminId
                );

                if ($isOpeningCount) {
                    $openingStatusUpdate = $conn->prepare("UPDATE materials SET opening_stock_status = 'approved' WHERE id = ?");
                    $openingStatusUpdate->bind_param('i', $materialId);
                    if (!$openingStatusUpdate->execute()) {
                        throw new RuntimeException('Unable to approve the opening stock.');
                    }
                }
            }

            $oldValues = ['system_stock' => (float)$count['total_quantity_snapshot']];
            $newValues = ['clerk_count' => (float)$count['counted_quantity'], 'variance' => $variance, 'decision' => $decision];
            $auditStmt = $conn->prepare(
                'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$auditStmt) {
                throw new RuntimeException('Unable to save the physical count review.');
            }
            $entityType = 'material_physical_count';
            $oldJson = json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $newJson = json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
            $auditStmt->bind_param('ississs', $adminId, $action, $entityType, $countId, $oldJson, $newJson, $ipAddress);
            if (!$auditStmt->execute()) {
                throw new RuntimeException('Unable to save the physical count review.');
            }

            if ($decision === 'recount') {
                if ($isOpeningCount) {
                    $openingStatusUpdate = $conn->prepare("UPDATE materials SET opening_stock_status = 'recount' WHERE id = ?");
                    $openingMaterialId = (int)$count['material_id'];
                    $openingStatusUpdate->bind_param('i', $openingMaterialId);
                    if (!$openingStatusUpdate->execute()) {
                        throw new RuntimeException('Unable to request the opening stock recount.');
                    }
                }
                user_notifications_create_if_missing(
                    $conn,
                    (int)$count['counted_by'],
                    'material_physical_count_recount',
                    $countId,
                    'Physical recount requested',
                    'Please count ' . (string)$count['material_name'] . ' again.',
                    '/codesamplecaps/INVENTORY_CLERK/dashboards/materials.php',
                    'material-count-recount-' . $countId
                );
            }

            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }
}
