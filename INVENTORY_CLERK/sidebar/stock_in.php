<?php
require_once __DIR__ . '/../includes/stock_helpers.php';

$csrfToken = auth_csrf_token('inventory_clerk_stock_in');
inventory_clerk_ensure_stock_movement_table($conn);
ensure_asset_unit_tracking_schema($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'inventory_clerk_stock_in')) {
        inventory_clerk_set_flash('error', 'Security check failed. Please try again.');
        inventory_clerk_redirect('/codesamplecaps/INVENTORY_CLERK/sidebar/stock_in.php');
    }

    $inventoryId = (int)($_POST['inventory_id'] ?? 0);
    $quantity = max(0, (int)($_POST['quantity'] ?? 0));
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    if ($inventoryId <= 0 || $quantity <= 0) {
        inventory_clerk_set_flash('error', 'Select an item and enter a valid stock in quantity.');
        inventory_clerk_redirect('/codesamplecaps/INVENTORY_CLERK/sidebar/stock_in.php');
    }

    $stmt = $conn->prepare('SELECT quantity, min_stock FROM inventory WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $inventoryId);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();

    if (!$item) {
        inventory_clerk_set_flash('error', 'Inventory item not found.');
        inventory_clerk_redirect('/codesamplecaps/INVENTORY_CLERK/sidebar/stock_in.php');
    }

    $previousQuantity = (int)$item['quantity'];
    $newQuantity = $previousQuantity + $quantity;
    $minStock = $item['min_stock'] !== null ? (int)$item['min_stock'] : null;
    $status = inventory_clerk_status($newQuantity, $minStock);

    try {
        $conn->begin_transaction();

        $update = $conn->prepare('UPDATE inventory SET quantity = ?, status = ?, updated_at = NOW() WHERE id = ?');
        $update->bind_param('isi', $newQuantity, $status, $inventoryId);
        $update->execute();

        $movementType = 'stock_in';
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $insert = $conn->prepare(
            'INSERT INTO inventory_stock_movements
             (inventory_id, movement_type, quantity, previous_quantity, new_quantity, remarks, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->bind_param('isiiisi', $inventoryId, $movementType, $quantity, $previousQuantity, $newQuantity, $remarks, $userId);
        $insert->execute();

        asset_units_sync_for_inventory($conn, $inventoryId, $newQuantity);
        audit_log_event($conn, $userId, 'stock_in', 'inventory', $inventoryId, ['quantity' => $previousQuantity], ['quantity' => $newQuantity, 'added' => $quantity]);

        $conn->commit();
        inventory_clerk_set_flash('success', 'Stock in saved successfully.');
    } catch (Throwable $exception) {
        $conn->rollback();
        inventory_clerk_set_flash('error', $exception->getMessage());
    }

    inventory_clerk_redirect('/codesamplecaps/INVENTORY_CLERK/sidebar/stock_in.php');
}

$flash = inventory_clerk_consume_flash();
$inventoryItems = inventory_clerk_fetch_items($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock In</title>
    <link rel="stylesheet" href="/codesamplecaps/SHARED/admin_ui/css/base.css">
    <link rel="stylesheet" href="../css/inventory_clerk_dashboard.css">
    <link rel="stylesheet" href="/codesamplecaps/SHARED/admin_ui/css/sidebar.css">
    <link rel="stylesheet" href="/codesamplecaps/SHARED/admin_ui/css/header.css">
    <link rel="stylesheet" href="/codesamplecaps/SHARED/admin_ui/css/notifications.css">
    <link rel="stylesheet" href="/codesamplecaps/SHARED/admin_ui/css/layout.css">
</head>
<body>
<div class="container">
    <?php include __DIR__ . '/inventory_clerk_sidebar.php'; ?>
    <main class="main-content">
        <div class="page-stack">
            <section class="form-panel">
                <h1 class="section-title-inline">Stock In</h1>
                <?php if ($flash): ?>
                    <div class="alert <?php echo $flash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>"><?php echo htmlspecialchars($flash['message']); ?></div>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <div class="form-grid">
                        <div class="input-group">
                            <label for="inventory_id">Inventory Item</label>
                            <select id="inventory_id" name="inventory_id" required>
                                <option value="">Select item</option>
                                <?php foreach ($inventoryItems as $item): ?>
                                    <option value="<?php echo (int)$item['id']; ?>"><?php echo htmlspecialchars($item['asset_name'] . ' | Qty: ' . (int)$item['quantity']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <label for="quantity">Quantity In</label>
                            <input id="quantity" name="quantity" type="number" min="1" step="1" required>
                        </div>
                        <div class="input-group">
                            <label for="remarks">Remarks</label>
                            <input id="remarks" name="remarks" type="text" placeholder="Supplier, delivery, or note">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn-primary">Save Stock In</button>
                    </div>
                </form>
            </section>
        </div>
    </main>
</div>
<script src="../js/inventory_clerk_dashboard.js"></script>
</body>
</html>
