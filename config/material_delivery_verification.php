<?php
// Admin expected delivery at Clerk actual count lang ang flow nito.
require_once __DIR__ . '/material_stock.php';

if (!function_exists('material_delivery_verification_is_ready')) {
    function material_delivery_verification_is_ready(mysqli $conn): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }

        $result = $conn->query("SHOW TABLES LIKE 'material_delivery_verifications'");
        $ready = $result instanceof mysqli_result && $result->num_rows === 1;
        return $ready;
    }
}

if (!function_exists('material_delivery_verification_format_quantity')) {
    function material_delivery_verification_format_quantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 2, '.', ','), '0'), '.');
    }
}

if (!function_exists('material_delivery_verification_validate_quantity')) {
    function material_delivery_verification_validate_quantity(mixed $input, string $unit): float
    {
        $raw = is_string($input) || is_numeric($input) ? trim((string)$input) : '';
        if ($raw === '' || !is_numeric($raw) || !is_finite((float)$raw) || (float)$raw <= 0) {
            throw new RuntimeException('Enter quantity above 0.');
        }

        $quantity = (float)$raw;
        if (material_stock_unit_requires_whole_number($unit) && $quantity !== floor($quantity)) {
            throw new RuntimeException('Use a whole ' . $unit . ' quantity.');
        }

        return $quantity;
    }
}

if (!function_exists('material_delivery_verification_create')) {
    function material_delivery_verification_create(
        mysqli $conn,
        int $materialId,
        mixed $expectedInput,
        string $supplierName,
        string $receiptNo,
        string $adminNotes,
        int $adminUserId
    ): void {
        if (!material_delivery_verification_is_ready($conn)) {
            throw new RuntimeException('Delivery verification setup is not ready.');
        }

        $supplierName = trim($supplierName);
        $receiptNo = trim($receiptNo);
        if ($supplierName === '') {
            throw new RuntimeException('Enter supplier name.');
        }
        if ($receiptNo === '') {
            throw new RuntimeException('Enter delivery receipt number.');
        }

        $materialStmt = $conn->prepare('SELECT unit FROM materials WHERE id = ? AND status = \'active\' LIMIT 1');
        if (!$materialStmt) {
            throw new RuntimeException('Unable to check material.');
        }
        $materialStmt->bind_param('i', $materialId);
        $materialStmt->execute();
        $material = $materialStmt->get_result()->fetch_assoc();
        if (!$material) {
            throw new RuntimeException('Pick active material.');
        }

        $expectedQuantity = material_delivery_verification_validate_quantity($expectedInput, (string)$material['unit']);
        $supplierName = substr($supplierName, 0, 180);
        $receiptNo = substr($receiptNo, 0, 120);
        $adminNotes = substr(trim($adminNotes), 0, 500);

        $insert = $conn->prepare(
            'INSERT INTO material_delivery_verifications
                (material_id, expected_quantity, supplier_name, delivery_receipt_no, admin_notes, created_by)
             VALUES (?, ?, NULLIF(?, \'\'), ?, NULLIF(?, \'\'), ?)'
        );
        if (!$insert) {
            throw new RuntimeException('Unable to save delivery record.');
        }
        $insert->bind_param('idsssi', $materialId, $expectedQuantity, $supplierName, $receiptNo, $adminNotes, $adminUserId);
        if (!$insert->execute()) {
            throw new RuntimeException('Unable to save delivery record.');
        }
    }
}

if (!function_exists('material_delivery_verification_fetch')) {
    function material_delivery_verification_fetch(mysqli $conn, string $status = ''): array
    {
        if (!material_delivery_verification_is_ready($conn)) {
            return [];
        }

        $where = $status === 'for_verification' ? "WHERE d.status = 'for_verification'" : '';
        $result = $conn->query(
            "SELECT d.*, m.material_name, m.material_code, m.unit,
                    creator.full_name AS created_by_name, verifier.full_name AS verified_by_name
             FROM material_delivery_verifications d
             INNER JOIN materials m ON m.id = d.material_id
             INNER JOIN users creator ON creator.id = d.created_by
             LEFT JOIN users verifier ON verifier.id = d.verified_by
             {$where}
             ORDER BY CASE WHEN d.status = 'for_verification' THEN 0 ELSE 1 END, d.created_at DESC
             LIMIT 50"
        );

        return $result instanceof mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('material_delivery_verification_confirm')) {
    function material_delivery_verification_confirm(mysqli $conn, int $deliveryId, mixed $verifiedInput, string $clerkNotes, int $clerkUserId): array
    {
        if (!material_delivery_verification_is_ready($conn)) {
            throw new RuntimeException('Delivery verification setup is not ready.');
        }

        $conn->begin_transaction();
        try {
            $deliveryStmt = $conn->prepare(
                "SELECT d.id, d.material_id, d.expected_quantity, d.delivery_receipt_no, m.material_name, m.unit, m.physical_quantity
                 FROM material_delivery_verifications d
                 INNER JOIN materials m ON m.id = d.material_id
                 WHERE d.id = ? AND d.status = 'for_verification' AND m.status = 'active'
                 FOR UPDATE"
            );
            if (!$deliveryStmt) {
                throw new RuntimeException('Unable to open delivery record.');
            }
            $deliveryStmt->bind_param('i', $deliveryId);
            $deliveryStmt->execute();
            $delivery = $deliveryStmt->get_result()->fetch_assoc();
            if (!$delivery) {
                throw new RuntimeException('Delivery record is no longer waiting.');
            }

            $verifiedQuantity = material_delivery_verification_validate_quantity($verifiedInput, (string)$delivery['unit']);
            $before = (float)$delivery['physical_quantity'];
            $after = $before + $verifiedQuantity;
            $clerkNotes = substr(trim($clerkNotes), 0, 500);
            $remarks = 'DR ' . (string)$delivery['delivery_receipt_no']
                . ' | Expected: ' . material_delivery_verification_format_quantity((float)$delivery['expected_quantity'])
                . ' | Verified: ' . material_delivery_verification_format_quantity($verifiedQuantity);
            if ($clerkNotes !== '') {
                $remarks .= ' | ' . $clerkNotes;
            }

            $materialUpdate = $conn->prepare('UPDATE materials SET physical_quantity = ? WHERE id = ?');
            $materialUpdate->bind_param('di', $after, $delivery['material_id']);
            if (!$materialUpdate->execute()) {
                throw new RuntimeException('Unable to update stock.');
            }

            $movement = $conn->prepare(
                "INSERT INTO material_stock_movements
                    (material_id, movement_type, quantity, physical_before, physical_after, remarks, created_by)
                 VALUES (?, 'stock_in', ?, ?, ?, ?, ?)"
            );
            $materialId = (int)$delivery['material_id'];
            $movement->bind_param('idddsi', $materialId, $verifiedQuantity, $before, $after, $remarks, $clerkUserId);
            if (!$movement->execute()) {
                throw new RuntimeException('Unable to save stock history.');
            }

            $deliveryUpdate = $conn->prepare(
                "UPDATE material_delivery_verifications
                 SET status = 'verified', verified_quantity = ?, clerk_notes = NULLIF(?, ''), verified_by = ?, verified_at = NOW()
                 WHERE id = ? AND status = 'for_verification'"
            );
            $deliveryUpdate->bind_param('dsii', $verifiedQuantity, $clerkNotes, $clerkUserId, $deliveryId);
            if (!$deliveryUpdate->execute() || $deliveryUpdate->affected_rows !== 1) {
                throw new RuntimeException('Delivery record changed. Please try again.');
            }

            $conn->commit();
            return [
                'material_name' => (string)$delivery['material_name'],
                'unit' => (string)$delivery['unit'],
                'expected_quantity' => (float)$delivery['expected_quantity'],
                'verified_quantity' => $verifiedQuantity,
            ];
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }
}
