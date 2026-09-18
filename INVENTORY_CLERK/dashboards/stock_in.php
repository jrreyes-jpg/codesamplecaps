<?php
require_once __DIR__ . '/../includes/stock_helpers.php';
require_once __DIR__ . '/../includes/asset_stock_in_service.php';
require_once __DIR__ . '/../includes/page_shell.php';

$csrfToken = auth_csrf_token('inventory_clerk_stock_in');
$stockInRequestToken = inventory_clerk_stock_in_issue_once_token();
inventory_clerk_ensure_stock_movement_table($conn);
ensure_asset_unit_tracking_schema($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'inventory_clerk_stock_in')) {
        inventory_clerk_set_flash('error', 'Security check failed. Please try again.');
        inventory_clerk_redirect('/codesamplecaps/INVENTORY_CLERK/dashboards/stock_in.php');
    }

    if (!inventory_clerk_stock_in_consume_once_token($_POST['stock_in_request_token'] ?? null)) {
        inventory_clerk_set_flash('error', 'This Stock In form was already sent. Please try again.');
        inventory_clerk_redirect('/codesamplecaps/INVENTORY_CLERK/dashboards/stock_in.php');
    }

    $result = inventory_clerk_stock_in_asset(
        $conn,
        (int)($_POST['inventory_id'] ?? 0),
        $_POST['quantity'] ?? null,
        (string)($_POST['remarks'] ?? ''),
        (int)($_SESSION['user_id'] ?? 0)
    );
    inventory_clerk_set_flash(
        $result['success'] ? 'success' : 'error',
        $result['success'] ? 'Stock in saved successfully.' : (string)$result['message']
    );

    inventory_clerk_redirect('/codesamplecaps/INVENTORY_CLERK/dashboards/stock_in.php');
}

$flash = inventory_clerk_consume_flash();
$inventoryItems = inventory_clerk_fetch_items($conn);

inventory_clerk_render_page('Stock In', function () use ($flash, $csrfToken, $stockInRequestToken, $inventoryItems): void {
?>
    <div class="page-stack stock-in-page">
        <section class="form-panel">
            <h1 class="section-title-inline">Stock In</h1>
            <?php if ($flash): ?>
                <div class="alert <?php echo $flash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>"><?php echo htmlspecialchars($flash['message']); ?></div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="stock_in_request_token" value="<?php echo htmlspecialchars($stockInRequestToken); ?>">
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
<?php
}, [
    '/codesamplecaps/INVENTORY_CLERK/css/stock_in.css',
], '', [
    '/codesamplecaps/INVENTORY_CLERK/js/stock_in.js',
]);
