<?php
require_once __DIR__ . '/../../../../config/auth_middleware.php';
require_once __DIR__ . '/../../../../config/database.php';

require_role('admin');

$movements = [];
$result = $conn->query(
    "SELECT sm.created_at, sm.movement_type, sm.quantity, sm.previous_quantity, sm.new_quantity,
            sm.remarks, a.asset_name, u.full_name
     FROM inventory_stock_movements sm
     INNER JOIN inventory i ON i.id = sm.inventory_id
     INNER JOIN assets a ON a.id = i.asset_id
     LEFT JOIN users u ON u.id = sm.created_by
     ORDER BY sm.created_at DESC, sm.id DESC
     LIMIT 200"
);
if ($result) {
    $movements = $result->fetch_all(MYSQLI_ASSOC);
}

$adminPageTitle = 'Asset Stock History - Admin';
$adminCssFiles = [
    '/codesamplecaps/ADMIN/common/css/admin-common.css',
    '/codesamplecaps/ADMIN/sidebar/assets/css/assets.css',
];
$adminJsFiles = ['/codesamplecaps/ADMIN/common/js/admin-common.js'];
include __DIR__ . '/../../../layout/header.php';
include __DIR__ . '/../../../admin_sidebar.php';
?>
<main class="main-content assets-content">
    <nav class="resource-page-tabs" aria-label="Asset sections">
        <a href="/codesamplecaps/ADMIN/sidebar/assets/php/assets.php">Asset Management</a>
        <a href="/codesamplecaps/ADMIN/sidebar/inventory/php/inventory.php?view=assets">Asset Inventory</a>
        <a class="is-active" href="/codesamplecaps/ADMIN/sidebar/assets/php/asset_stock_history.php">Stock History</a>
    </nav>
    <section class="form-section">
        <h1 class="dashboard-section-title">Asset Stock History</h1>
        <div class="table-wrapper">
            <table class="responsive-table mobile-card-table assets-table">
                <thead><tr><th>Date</th><th>Asset</th><th>Type</th><th>Qty</th><th>Before</th><th>After</th><th>Recorded By</th><th>Reference / Remarks</th></tr></thead>
                <tbody>
                <?php if ($movements === []): ?><tr><td colspan="8" class="table-empty-cell">No asset stock movement yet.</td></tr><?php endif; ?>
                <?php foreach ($movements as $movement): ?><tr><td data-label="Date"><?php echo htmlspecialchars((string)$movement['created_at']); ?></td><td data-label="Asset"><?php echo htmlspecialchars((string)$movement['asset_name']); ?></td><td data-label="Type"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$movement['movement_type']))); ?></td><td data-label="Qty"><?php echo (int)$movement['quantity']; ?></td><td data-label="Before"><?php echo (int)$movement['previous_quantity']; ?></td><td data-label="After"><?php echo (int)$movement['new_quantity']; ?></td><td data-label="Recorded By"><?php echo htmlspecialchars((string)($movement['full_name'] ?? 'System')); ?></td><td data-label="Reference / Remarks"><?php echo htmlspecialchars((string)($movement['remarks'] ?? '')); ?></td></tr><?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php include __DIR__ . '/../../../layout/footer.php'; ?>
