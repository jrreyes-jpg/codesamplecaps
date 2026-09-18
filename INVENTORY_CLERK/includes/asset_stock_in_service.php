<?php

require_once __DIR__ . '/stock_helpers.php';

if (!function_exists('inventory_clerk_stock_in_issue_once_token')) {
    function inventory_clerk_stock_in_issue_once_token(): string
    {
        $_SESSION['inventory_clerk_stock_in_tokens'] ??= [];
        $now = time();

        foreach ($_SESSION['inventory_clerk_stock_in_tokens'] as $token => $createdAt) {
            if (!is_int($createdAt) || $createdAt < ($now - 1800)) {
                unset($_SESSION['inventory_clerk_stock_in_tokens'][$token]);
            }
        }

        $token = bin2hex(random_bytes(24));
        $_SESSION['inventory_clerk_stock_in_tokens'][$token] = $now;
        return $token;
    }
}

if (!function_exists('inventory_clerk_stock_in_consume_once_token')) {
    function inventory_clerk_stock_in_consume_once_token(?string $token): bool
    {
        $token = trim((string)$token);
        if ($token === '' || empty($_SESSION['inventory_clerk_stock_in_tokens'][$token])) {
            return false;
        }

        unset($_SESSION['inventory_clerk_stock_in_tokens'][$token]);
        return true;
    }
}

if (!function_exists('inventory_clerk_stock_in_validate_quantity')) {
    function inventory_clerk_stock_in_validate_quantity(mixed $value): array
    {
        $rawValue = trim((string)$value);
        if ($rawValue === '') {
            return ['valid' => false, 'message' => 'Quantity In is required.'];
        }

        if (!preg_match('/^[0-9]+$/', $rawValue) || (int)$rawValue < 1) {
            return ['valid' => false, 'message' => 'Enter a whole number greater than zero.'];
        }

        return ['valid' => true, 'quantity' => (int)$rawValue];
    }
}

if (!function_exists('inventory_clerk_stock_in_asset')) {
    function inventory_clerk_stock_in_asset(mysqli $conn, int $inventoryId, mixed $quantityValue, string $remarks, int $userId): array
    {
        $quantityCheck = inventory_clerk_stock_in_validate_quantity($quantityValue);
        if (!$quantityCheck['valid']) {
            return ['success' => false, 'field' => 'quantity', 'message' => $quantityCheck['message']];
        }

        if ($inventoryId <= 0) {
            return ['success' => false, 'field' => 'general', 'message' => 'Asset inventory item was not found.'];
        }

        $quantity = (int)$quantityCheck['quantity'];
        $remarks = trim($remarks);

        try {
            // Schema check muna para walang DDL habang nasa Stock In transaction.
            ensure_asset_unit_tracking_schema($conn);
            $conn->begin_transaction();

            $itemStatement = $conn->prepare(
                "SELECT i.id, i.quantity, i.min_stock, a.asset_name
                 FROM inventory i
                 INNER JOIN assets a ON a.id = i.asset_id
                 WHERE i.id = ? AND a.deleted_at IS NULL
                 LIMIT 1 FOR UPDATE"
            );
            if (!$itemStatement) {
                throw new RuntimeException('Could not lock the asset inventory item.');
            }
            $itemStatement->bind_param('i', $inventoryId);
            $itemStatement->execute();
            $item = $itemStatement->get_result()->fetch_assoc();
            $itemStatement->close();

            if (!$item) {
                throw new RuntimeException('Asset inventory item was not found.');
            }

            $previousQuantity = (int)$item['quantity'];
            $currentUnitCount = asset_units_count_active_rows($conn, $inventoryId);
            if ($previousQuantity > 0 && $currentUnitCount === 0) {
                throw new RuntimeException('This legacy asset needs unit rebuilding before Stock In.');
            }

            $newQuantity = $previousQuantity + $quantity;
            $minStock = $item['min_stock'] !== null ? (int)$item['min_stock'] : null;
            $status = inventory_clerk_status($newQuantity, $minStock);

            $updateStatement = $conn->prepare('UPDATE inventory SET quantity = ?, status = ?, updated_at = NOW() WHERE id = ?');
            if (!$updateStatement || !$updateStatement->bind_param('isi', $newQuantity, $status, $inventoryId) || !$updateStatement->execute()) {
                throw new RuntimeException('Could not update asset inventory quantity.');
            }
            $updateStatement->close();

            $movementType = 'stock_in';
            $movementStatement = $conn->prepare(
                'INSERT INTO inventory_stock_movements
                 (inventory_id, movement_type, quantity, previous_quantity, new_quantity, remarks, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$movementStatement || !$movementStatement->bind_param('isiiisi', $inventoryId, $movementType, $quantity, $previousQuantity, $newQuantity, $remarks, $userId) || !$movementStatement->execute()) {
                throw new RuntimeException('Could not save asset Stock In history.');
            }
            $movementStatement->close();

            asset_units_sync_for_inventory($conn, $inventoryId, $newQuantity, false);
            audit_log_event($conn, $userId, 'stock_in', 'inventory', $inventoryId, ['quantity' => $previousQuantity], ['quantity' => $newQuantity, 'added' => $quantity]);

            $conn->commit();
            return [
                'success' => true,
                'asset_name' => (string)$item['asset_name'],
                'quantity' => $quantity,
                'previous_quantity' => $previousQuantity,
                'new_quantity' => $newQuantity,
            ];
        } catch (Throwable $exception) {
            try {
                $conn->rollback();
            } catch (Throwable) {
                // Walang ibang gagawin kapag rollback mismo ang pumalya.
            }

            return ['success' => false, 'field' => 'general', 'message' => $exception->getMessage()];
        }
    }
}
