<?php

require_once __DIR__ . '/asset_unit_helpers.php';
require_once __DIR__ . '/audit_log.php';

if (!function_exists('asset_stock_issue_once_token')) {
    function asset_stock_issue_once_token(): string
    {
        $_SESSION['asset_stock_once_tokens'] ??= [];
        $now = time();
        foreach ($_SESSION['asset_stock_once_tokens'] as $token => $createdAt) {
            if (($now - (int)$createdAt) > 1800) {
                unset($_SESSION['asset_stock_once_tokens'][$token]);
            }
        }
        $token = bin2hex(random_bytes(24));
        $_SESSION['asset_stock_once_tokens'][$token] = $now;
        return $token;
    }
}

if (!function_exists('asset_stock_consume_once_token')) {
    function asset_stock_consume_once_token(mixed $token): bool
    {
        $token = is_string($token) ? trim($token) : '';
        if ($token === '' || empty($_SESSION['asset_stock_once_tokens'][$token])) {
            return false;
        }
        unset($_SESSION['asset_stock_once_tokens'][$token]);
        return true;
    }
}

if (!function_exists('asset_stock_status')) {
    function asset_stock_status(int $quantity, ?int $minStock): string
    {
        if ($quantity <= 0) {
            return 'out-of-stock';
        }
        return $minStock !== null && $quantity <= $minStock ? 'low-stock' : 'available';
    }
}

if (!function_exists('asset_stock_change')) {
    function asset_stock_change(mysqli $conn, int $inventoryId, string $direction, mixed $quantityInput, string $remarks, int $userId): array
    {
        $rawQuantity = trim((string)$quantityInput);
        if (!preg_match('/^[0-9]+$/', $rawQuantity) || (int)$rawQuantity < 1) {
            throw new RuntimeException('Enter a whole quantity greater than zero.');
        }
        if (!in_array($direction, ['stock_in', 'stock_out'], true)) {
            throw new RuntimeException('Invalid asset stock action.');
        }
        if ($inventoryId <= 0) {
            throw new RuntimeException('Asset inventory item was not found.');
        }
        $remarks = trim($remarks);
        if ($remarks === '') {
            throw new RuntimeException('Reference or remarks is required.');
        }

        $quantity = (int)$rawQuantity;
        ensure_asset_unit_tracking_schema($conn);
        $conn->begin_transaction();
        try {
            $itemStmt = $conn->prepare(
                "SELECT i.id, i.quantity, i.min_stock, i.max_stock, a.asset_name
                 FROM inventory i
                 INNER JOIN assets a ON a.id = i.asset_id
                 WHERE i.id = ? AND a.deleted_at IS NULL
                 LIMIT 1 FOR UPDATE"
            );
            if (!$itemStmt) {
                throw new RuntimeException('Unable to lock the asset inventory item.');
            }
            $itemStmt->bind_param('i', $inventoryId);
            $itemStmt->execute();
            $item = $itemStmt->get_result()->fetch_assoc();
            if (!$item) {
                throw new RuntimeException('Asset inventory item was not found.');
            }

            $before = (int)$item['quantity'];
            $activeUnitCount = asset_units_count_active_rows($conn, $inventoryId);
            if ($before > 0 && $activeUnitCount === 0) {
                throw new RuntimeException('This legacy asset needs unit rebuilding before a stock transaction.');
            }

            if ($direction === 'stock_out') {
                $availableCounts = asset_units_fetch_status_counts($conn, $inventoryId);
                $availableUnits = (int)($availableCounts['available'] ?? 0);
                if ($quantity > $before || $quantity > $availableUnits) {
                    throw new RuntimeException('Stock Out can use available units only. Deployed, maintenance, and lost units are protected.');
                }
                $after = $before - $quantity;
                asset_units_archive_available_rows($conn, $inventoryId, $quantity);
            } else {
                $after = $before + $quantity;
                if ($item['max_stock'] !== null && $after > (int)$item['max_stock']) {
                    throw new RuntimeException('Stock In exceeds the configured maximum stock for this asset.');
                }
                asset_units_sync_for_inventory($conn, $inventoryId, $after, false);
            }

            $minStock = $item['min_stock'] !== null ? (int)$item['min_stock'] : null;
            $status = asset_stock_status($after, $minStock);
            $updateStmt = $conn->prepare('UPDATE inventory SET quantity = ?, status = ?, updated_at = NOW() WHERE id = ?');
            if (!$updateStmt || !$updateStmt->bind_param('isi', $after, $status, $inventoryId) || !$updateStmt->execute()) {
                throw new RuntimeException('Unable to update asset stock.');
            }

            $movementStmt = $conn->prepare(
                'INSERT INTO inventory_stock_movements
                 (inventory_id, movement_type, quantity, previous_quantity, new_quantity, remarks, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$movementStmt || !$movementStmt->bind_param('isiiisi', $inventoryId, $direction, $quantity, $before, $after, $remarks, $userId) || !$movementStmt->execute()) {
                throw new RuntimeException('Unable to save asset stock history.');
            }

            audit_log_event($conn, $userId, $direction, 'inventory', $inventoryId, ['quantity' => $before], ['quantity' => $after, 'changed' => $quantity, 'remarks' => $remarks]);
            $conn->commit();
            return ['asset_name' => (string)$item['asset_name'], 'quantity' => $quantity, 'new_quantity' => $after];
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }
}
