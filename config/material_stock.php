<?php

// Materials lang ito. Hiwalay ang reusable Asset at QR flow.

if (!function_exists('material_stock_is_ready')) {
    function material_stock_is_ready(mysqli $conn): bool
    {
        static $ready = null;

        if ($ready !== null) {
            return $ready;
        }

        $result = $conn->query("SHOW TABLES LIKE 'materials'");
        $ready = $result instanceof mysqli_result && $result->num_rows === 1;

        return $ready;
    }
}

if (!function_exists('material_stock_available_quantity')) {
    function material_stock_available_quantity(array $material): float
    {
        return max(0.0, (float)($material['physical_quantity'] ?? 0) - (float)($material['reserved_quantity'] ?? 0));
    }
}

if (!function_exists('material_stock_next_status')) {
    function material_stock_next_status(float $requiredQuantity, float $reservedQuantity, float $issuedQuantity, bool $cancelled = false): string
    {
        if ($cancelled) {
            return 'cancelled';
        }

        return $issuedQuantity >= $requiredQuantity && $reservedQuantity <= 0 ? 'fulfilled' : 'active';
    }
}

if (!function_exists('material_stock_fetch_active_materials')) {
    function material_stock_fetch_active_materials(mysqli $conn): array
    {
        return material_stock_fetch_materials($conn, 'active');
    }
}

if (!function_exists('material_stock_fetch_materials')) {
    function material_stock_fetch_materials(mysqli $conn, string $filter = 'active'): array
    {
        if (!material_stock_is_ready($conn)) {
            return [];
        }

        $where = match ($filter) {
            'inactive' => "WHERE status = 'inactive'",
            'all' => '',
            default => "WHERE status = 'active'",
        };
        $result = $conn->query(
            "SELECT id, material_code, material_name, category, unit, physical_quantity, reserved_quantity, reorder_level, status,
                    GREATEST(physical_quantity - reserved_quantity, 0) AS available_quantity
             FROM materials
             {$where}
             ORDER BY material_name ASC, id ASC"
        );

        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('material_stock_archive_material')) {
    function material_stock_archive_material(mysqli $conn, int $materialId): void
    {
        if ($materialId <= 0) {
            throw new RuntimeException('Material not found.');
        }

        $conn->begin_transaction();
        try {
            $materialStmt = $conn->prepare(
                "SELECT id, status, physical_quantity, reserved_quantity
                 FROM materials
                 WHERE id = ?
                 FOR UPDATE"
            );
            if (!$materialStmt) {
                throw new RuntimeException('Unable to check material.');
            }
            $materialStmt->bind_param('i', $materialId);
            $materialStmt->execute();
            $material = $materialStmt->get_result()->fetch_assoc();
            if (!$material || (string)$material['status'] !== 'active') {
                throw new RuntimeException('Active material not found.');
            }

            $reservationStmt = $conn->prepare(
                "SELECT id
                 FROM project_material_reservations
                 WHERE material_id = ? AND status = 'active'
                 FOR UPDATE"
            );
            if (!$reservationStmt) {
                throw new RuntimeException('Unable to check material reservations.');
            }
            $reservationStmt->bind_param('i', $materialId);
            $reservationStmt->execute();
            $hasActiveReservation = (bool)$reservationStmt->get_result()->fetch_assoc();

            if ((float)$material['physical_quantity'] > 0 || (float)$material['reserved_quantity'] > 0 || $hasActiveReservation) {
                throw new RuntimeException('Cannot archive material while stock or active reservations remain.');
            }

            $archiveStmt = $conn->prepare("UPDATE materials SET status = 'inactive' WHERE id = ? AND status = 'active'");
            if (!$archiveStmt) {
                throw new RuntimeException('Unable to archive material.');
            }
            $archiveStmt->bind_param('i', $materialId);
            if (!$archiveStmt->execute() || $archiveStmt->affected_rows !== 1) {
                throw new RuntimeException('Material status changed. Please try again.');
            }
            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }
}

if (!function_exists('material_stock_restore_material')) {
    function material_stock_restore_material(mysqli $conn, int $materialId): void
    {
        if ($materialId <= 0) {
            throw new RuntimeException('Material not found.');
        }

        $stmt = $conn->prepare("UPDATE materials SET status = 'active' WHERE id = ? AND status = 'inactive'");
        if (!$stmt) {
            throw new RuntimeException('Unable to restore material.');
        }
        $stmt->bind_param('i', $materialId);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            throw new RuntimeException('Archived material not found.');
        }
    }
}

if (!function_exists('material_stock_has_usage_reference')) {
    function material_stock_has_usage_reference(mysqli $conn, int $materialId, bool $forUpdate = false): bool
    {
        // Ito lang ang lahat ng current database tables na may material_id reference.
        $tables = [
            'material_stock_movements',
            'project_material_reservations',
            'inquiry_quotation_items',
            'site_inspection_cost_items',
        ];

        foreach ($tables as $table) {
            $sql = "SELECT id FROM {$table} WHERE material_id = ? LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('Unable to check material usage.');
            }
            $stmt->bind_param('i', $materialId);
            $stmt->execute();
            if ($stmt->get_result()->fetch_assoc()) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('material_stock_fetch_deletable_material_ids')) {
    function material_stock_fetch_deletable_material_ids(mysqli $conn): array
    {
        $result = $conn->query(
            'SELECT m.id
             FROM materials m
             WHERE m.physical_quantity = 0
               AND m.reserved_quantity = 0
               AND NOT EXISTS (SELECT 1 FROM material_stock_movements sm WHERE sm.material_id = m.id)
               AND NOT EXISTS (SELECT 1 FROM project_material_reservations pmr WHERE pmr.material_id = m.id)
               AND NOT EXISTS (SELECT 1 FROM inquiry_quotation_items iqi WHERE iqi.material_id = m.id)
               AND NOT EXISTS (SELECT 1 FROM site_inspection_cost_items sici WHERE sici.material_id = m.id)'
        );
        if (!$result) {
            return [];
        }

        return array_map('intval', array_column($result->fetch_all(MYSQLI_ASSOC), 'id'));
    }
}

if (!function_exists('material_stock_permanently_delete_material')) {
    function material_stock_permanently_delete_material(mysqli $conn, int $materialId): void
    {
        $unsafeMessage = 'This material can no longer be permanently deleted because it has usage or inventory history.';
        if ($materialId <= 0) {
            throw new RuntimeException($unsafeMessage);
        }

        $conn->begin_transaction();
        try {
            $materialStmt = $conn->prepare(
                'SELECT id, physical_quantity, reserved_quantity
                 FROM materials
                 WHERE id = ?
                 FOR UPDATE'
            );
            if (!$materialStmt) {
                throw new RuntimeException('Unable to check material.');
            }
            $materialStmt->bind_param('i', $materialId);
            $materialStmt->execute();
            $material = $materialStmt->get_result()->fetch_assoc();

            if (!$material
                || (float)$material['physical_quantity'] !== 0.0
                || (float)$material['reserved_quantity'] !== 0.0
                || material_stock_has_usage_reference($conn, $materialId, true)) {
                throw new RuntimeException($unsafeMessage);
            }

            $deleteStmt = $conn->prepare(
                'DELETE FROM materials
                 WHERE id = ? AND physical_quantity = 0 AND reserved_quantity = 0'
            );
            if (!$deleteStmt) {
                throw new RuntimeException('Unable to permanently delete material.');
            }
            $deleteStmt->bind_param('i', $materialId);
            if (!$deleteStmt->execute() || $deleteStmt->affected_rows !== 1) {
                throw new RuntimeException($unsafeMessage);
            }

            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }
}

if (!function_exists('material_stock_create_material')) {
    function material_stock_create_material(mysqli $conn, string $name, ?string $category, string $unit, ?float $reorderLevel): string
    {
        $name = trim($name);
        $category = trim((string)$category);
        $unit = trim($unit);

        if ($name === '' || $unit === '') {
            throw new RuntimeException('Material name and unit are required.');
        }

        if ($reorderLevel !== null && $reorderLevel < 0) {
            throw new RuntimeException('Reorder level cannot be negative.');
        }

        $conn->begin_transaction();
        try {
            // Temporary code lang ito habang kumukuha ng auto-increment ID.
            $temporaryCode = 'MAT-TEMP-' . bin2hex(random_bytes(12));
            $reorderValue = $reorderLevel === null ? '' : (string)$reorderLevel;
            $insert = $conn->prepare(
                'INSERT INTO materials (material_code, material_name, category, unit, reorder_level)
                 VALUES (?, ?, NULLIF(?, \'\'), ?, NULLIF(?, \'\'))'
            );
            if (!$insert) {
                throw new RuntimeException('Unable to create material.');
            }
            $insert->bind_param('sssss', $temporaryCode, $name, $category, $unit, $reorderValue);
            if (!$insert->execute()) {
                throw new RuntimeException('Unable to create material.');
            }

            $materialId = (int)$conn->insert_id;
            if ($materialId <= 0) {
                throw new RuntimeException('Unable to generate material code.');
            }

            $materialCode = 'MAT-' . str_pad((string)$materialId, 6, '0', STR_PAD_LEFT);
            $update = $conn->prepare('UPDATE materials SET material_code = ? WHERE id = ? AND material_code = ?');
            if (!$update) {
                throw new RuntimeException('Unable to save material code.');
            }
            $update->bind_param('sis', $materialCode, $materialId, $temporaryCode);
            if (!$update->execute() || $update->affected_rows !== 1) {
                throw new RuntimeException('Unable to save material code.');
            }

            $conn->commit();
            return $materialCode;
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }
}

if (!function_exists('material_stock_material_exists')) {
    function material_stock_material_exists(mysqli $conn, int $materialId): bool
    {
        if ($materialId <= 0 || !material_stock_is_ready($conn)) {
            return false;
        }

        $stmt = $conn->prepare('SELECT id FROM materials WHERE id = ? AND status = \'active\' LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $materialId);
        $stmt->execute();

        return (bool)$stmt->get_result()->fetch_assoc();
    }
}

if (!function_exists('material_stock_reserve_approved_inspection_requirements')) {
    function material_stock_reserve_approved_inspection_requirements(mysqli $conn, int $projectId, int $inspectionId, int $userId): array
    {
        if (!material_stock_is_ready($conn)) {
            throw new RuntimeException('Materials setup is not available yet.');
        }

        $requirementsStmt = $conn->prepare(
            "SELECT material_id, SUM(quantity) AS required_quantity
             FROM site_inspection_cost_items
             WHERE inspection_id = ?
               AND item_type = 'material'
               AND material_id IS NOT NULL
             GROUP BY material_id
             ORDER BY material_id ASC"
        );
        if (!$requirementsStmt) {
            throw new RuntimeException('Unable to load approved material requirements.');
        }

        $requirementsStmt->bind_param('i', $inspectionId);
        $requirementsStmt->execute();
        $requirements = $requirementsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $summary = ['reserved_now' => 0.0, 'shortage_count' => 0, 'material_count' => 0];
        foreach ($requirements as $requirement) {
            $materialId = (int)$requirement['material_id'];
            $requiredQuantity = max(0.0, (float)$requirement['required_quantity']);
            if ($materialId <= 0 || $requiredQuantity <= 0) {
                continue;
            }

            $materialStmt = $conn->prepare(
                "SELECT id, physical_quantity, reserved_quantity
                 FROM materials
                 WHERE id = ? AND status = 'active'
                 FOR UPDATE"
            );
            if (!$materialStmt) {
                throw new RuntimeException('Unable to lock material stock.');
            }
            $materialStmt->bind_param('i', $materialId);
            $materialStmt->execute();
            $material = $materialStmt->get_result()->fetch_assoc();
            if (!$material) {
                throw new RuntimeException('A selected material no longer exists.');
            }

            $reservationStmt = $conn->prepare(
                'SELECT id, required_quantity, reserved_quantity, issued_quantity, status
                 FROM project_material_reservations
                 WHERE project_id = ? AND material_id = ?
                 FOR UPDATE'
            );
            if (!$reservationStmt) {
                throw new RuntimeException('Unable to lock project material reservation.');
            }
            $reservationStmt->bind_param('ii', $projectId, $materialId);
            $reservationStmt->execute();
            $reservation = $reservationStmt->get_result()->fetch_assoc();

            if ($reservation && (string)$reservation['status'] === 'cancelled') {
                continue;
            }

            $currentReserved = (float)($reservation['reserved_quantity'] ?? 0);
            $currentIssued = (float)($reservation['issued_quantity'] ?? 0);
            $remainingNeed = max(0.0, $requiredQuantity - $currentReserved - $currentIssued);
            $availableQuantity = material_stock_available_quantity($material);
            $reserveNow = min($remainingNeed, $availableQuantity);
            $nextReserved = $currentReserved + $reserveNow;
            $nextStatus = material_stock_next_status($requiredQuantity, $nextReserved, $currentIssued);

            if (!$reservation) {
                $insertReservation = $conn->prepare(
                    'INSERT INTO project_material_reservations
                     (project_id, material_id, required_quantity, reserved_quantity, issued_quantity, status, created_by)
                     VALUES (?, ?, ?, ?, 0, ?, ?)'
                );
                if (!$insertReservation) {
                    throw new RuntimeException('Unable to create project material reservation.');
                }
                $insertReservation->bind_param('iiddsi', $projectId, $materialId, $requiredQuantity, $nextReserved, $nextStatus, $userId);
                if (!$insertReservation->execute()) {
                    throw new RuntimeException('Unable to save project material reservation.');
                }
            } else {
                $updateReservation = $conn->prepare(
                    'UPDATE project_material_reservations
                     SET required_quantity = ?, reserved_quantity = ?, status = ?
                     WHERE id = ?'
                );
                if (!$updateReservation) {
                    throw new RuntimeException('Unable to update project material reservation.');
                }
                $reservationId = (int)$reservation['id'];
                $updateReservation->bind_param('ddsi', $requiredQuantity, $nextReserved, $nextStatus, $reservationId);
                if (!$updateReservation->execute()) {
                    throw new RuntimeException('Unable to update project material reservation.');
                }
            }

            if ($reserveNow > 0) {
                $updateMaterial = $conn->prepare(
                    'UPDATE materials
                     SET reserved_quantity = reserved_quantity + ?
                     WHERE id = ?
                       AND physical_quantity - reserved_quantity >= ?'
                );
                if (!$updateMaterial) {
                    throw new RuntimeException('Unable to reserve material stock.');
                }
                $updateMaterial->bind_param('did', $reserveNow, $materialId, $reserveNow);
                if (!$updateMaterial->execute() || $updateMaterial->affected_rows !== 1) {
                    throw new RuntimeException('Material stock changed. Please try again.');
                }
            }

            $shortage = max(0.0, $requiredQuantity - $nextReserved - $currentIssued);
            $summary['reserved_now'] += $reserveNow;
            $summary['material_count']++;
            if ($shortage > 0) {
                $summary['shortage_count']++;
            }
        }

        return $summary;
    }
}

if (!function_exists('material_stock_add_stock_in')) {
    function material_stock_add_stock_in(mysqli $conn, int $materialId, mixed $quantityInput, ?string $remarks, int $userId): array
    {
        $rawQuantity = is_string($quantityInput) ? trim($quantityInput) : (string)$quantityInput;
        if ($rawQuantity === '' || !is_numeric($rawQuantity)) {
            throw new RuntimeException('Stock In quantity must be greater than zero.');
        }

        $quantity = (float)$rawQuantity;
        if (!is_finite($quantity) || $quantity <= 0) {
            throw new RuntimeException('Stock In quantity must be greater than zero.');
        }

        $conn->begin_transaction();
        try {
            $lock = $conn->prepare("SELECT material_name, unit, physical_quantity FROM materials WHERE id = ? AND status = 'active' FOR UPDATE");
            if (!$lock) {
                throw new RuntimeException('Unable to lock material.');
            }
            $lock->bind_param('i', $materialId);
            $lock->execute();
            $material = $lock->get_result()->fetch_assoc();
            if (!$material) {
                throw new RuntimeException('Material not found.');
            }

            $wholeCountUnits = ['pcs', 'roll', 'box', 'pack', 'set', 'bundle', 'sheet', 'pair', 'tube'];
            if (in_array((string)$material['unit'], $wholeCountUnits, true) && $quantity !== floor($quantity)) {
                throw new RuntimeException('Quantity In must be a whole number for ' . $material['unit'] . '.');
            }

            $before = (float)$material['physical_quantity'];
            $after = $before + $quantity;
            $update = $conn->prepare('UPDATE materials SET physical_quantity = ? WHERE id = ?');
            $update->bind_param('di', $after, $materialId);
            $update->execute();

            $movement = $conn->prepare(
                "INSERT INTO material_stock_movements
                 (material_id, movement_type, quantity, physical_before, physical_after, remarks, created_by)
                 VALUES (?, 'stock_in', ?, ?, ?, ?, ?)"
            );
            $movement->bind_param('idddsi', $materialId, $quantity, $before, $after, $remarks, $userId);
            $movement->execute();
            $conn->commit();

            return [
                'material_name' => (string)$material['material_name'],
                'unit' => (string)$material['unit'],
                'quantity' => $quantity,
            ];
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }
}

if (!function_exists('material_stock_issue_reserved')) {
    function material_stock_issue_reserved(mysqli $conn, int $reservationId, float $quantity, ?string $remarks, int $userId): void
    {
        if ($quantity <= 0) {
            throw new RuntimeException('Issue quantity must be greater than zero.');
        }

        $conn->begin_transaction();
        try {
            $reservationStmt = $conn->prepare(
                'SELECT r.id, r.material_id, r.required_quantity, r.reserved_quantity, r.issued_quantity, r.status,
                        m.physical_quantity, m.reserved_quantity AS material_reserved_quantity
                 FROM project_material_reservations r
                 INNER JOIN materials m ON m.id = r.material_id
                 WHERE r.id = ?
                 FOR UPDATE'
            );
            if (!$reservationStmt) {
                throw new RuntimeException('Unable to lock material reservation.');
            }
            $reservationStmt->bind_param('i', $reservationId);
            $reservationStmt->execute();
            $reservation = $reservationStmt->get_result()->fetch_assoc();
            if (!$reservation || (string)$reservation['status'] === 'cancelled') {
                throw new RuntimeException('Active material reservation not found.');
            }

            $reserved = (float)$reservation['reserved_quantity'];
            $physical = (float)$reservation['physical_quantity'];
            if ($quantity > $reserved || $quantity > $physical) {
                throw new RuntimeException('Issue quantity is higher than reserved physical stock.');
            }

            $materialId = (int)$reservation['material_id'];
            $physicalAfter = $physical - $quantity;
            $materialReservedAfter = (float)$reservation['material_reserved_quantity'] - $quantity;
            $reservationReservedAfter = $reserved - $quantity;
            $issuedAfter = (float)$reservation['issued_quantity'] + $quantity;
            $status = material_stock_next_status((float)$reservation['required_quantity'], $reservationReservedAfter, $issuedAfter);

            $updateMaterial = $conn->prepare(
                'UPDATE materials
                 SET physical_quantity = ?, reserved_quantity = ?
                 WHERE id = ? AND physical_quantity >= ? AND reserved_quantity >= ?'
            );
            $updateMaterial->bind_param('ddidd', $physicalAfter, $materialReservedAfter, $materialId, $quantity, $quantity);
            if (!$updateMaterial->execute() || $updateMaterial->affected_rows !== 1) {
                throw new RuntimeException('Material stock changed. Please try again.');
            }

            $updateReservation = $conn->prepare(
                'UPDATE project_material_reservations
                 SET reserved_quantity = ?, issued_quantity = ?, status = ?
                 WHERE id = ? AND reserved_quantity >= ?'
            );
            if (!$updateReservation) {
                throw new RuntimeException('Unable to update project material reservation.');
            }
            $updateReservation->bind_param('ddsid', $reservationReservedAfter, $issuedAfter, $status, $reservationId, $quantity);
            if (!$updateReservation->execute() || $updateReservation->affected_rows !== 1) {
                throw new RuntimeException('Material reservation changed. Please try again.');
            }

            $movement = $conn->prepare(
                "INSERT INTO material_stock_movements
                 (material_id, reservation_id, movement_type, quantity, physical_before, physical_after, remarks, created_by)
                 VALUES (?, ?, 'project_issue', ?, ?, ?, ?, ?)"
            );
            if (!$movement) {
                throw new RuntimeException('Unable to save material issue history.');
            }
            $movement->bind_param('iidddsi', $materialId, $reservationId, $quantity, $physical, $physicalAfter, $remarks, $userId);
            if (!$movement->execute()) {
                throw new RuntimeException('Unable to save material issue history.');
            }

            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }
}

if (!function_exists('material_stock_manual_stock_out')) {
    function material_stock_manual_stock_out(mysqli $conn, int $materialId, float $quantity, ?string $remarks, int $userId): void
    {
        if ($quantity <= 0) {
            throw new RuntimeException('Stock Out quantity must be greater than zero.');
        }

        $conn->begin_transaction();
        try {
            $lock = $conn->prepare("SELECT physical_quantity, reserved_quantity FROM materials WHERE id = ? AND status = 'active' FOR UPDATE");
            if (!$lock) {
                throw new RuntimeException('Unable to lock material.');
            }
            $lock->bind_param('i', $materialId);
            $lock->execute();
            $material = $lock->get_result()->fetch_assoc();
            if (!$material) {
                throw new RuntimeException('Material not found.');
            }

            $before = (float)$material['physical_quantity'];
            if ($quantity > material_stock_available_quantity($material)) {
                throw new RuntimeException('Stock Out cannot use material already reserved for a project.');
            }
            $after = $before - $quantity;
            $update = $conn->prepare(
                'UPDATE materials
                 SET physical_quantity = ?
                 WHERE id = ? AND physical_quantity - reserved_quantity >= ?'
            );
            $update->bind_param('did', $after, $materialId, $quantity);
            if (!$update->execute() || $update->affected_rows !== 1) {
                throw new RuntimeException('Material stock changed. Please try again.');
            }

            $movement = $conn->prepare(
                "INSERT INTO material_stock_movements
                 (material_id, movement_type, quantity, physical_before, physical_after, remarks, created_by)
                 VALUES (?, 'manual_stock_out', ?, ?, ?, ?, ?)"
            );
            $movement->bind_param('idddsi', $materialId, $quantity, $before, $after, $remarks, $userId);
            $movement->execute();
            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }
}
