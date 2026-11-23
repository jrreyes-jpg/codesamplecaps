<?php
require_once __DIR__ . '/../../../../config/auth_middleware.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/material_stock.php';
require_once __DIR__ . '/../../../../config/material_physical_count.php';

require_role('admin');

$selectedMaterialId = filter_input(INPUT_GET, 'material_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$selectedType = is_string($_GET['type'] ?? null) ? trim((string)$_GET['type']) : '';
$returnToDelivery = ($_GET['return'] ?? '') === 'delivery';
if (!in_array($selectedType, material_stock_history_filter_types(), true)) {
    $selectedType = '';
}
$selectedMaterial = null;
$historyMaterials = material_stock_fetch_materials($conn, 'all');
if ($selectedMaterialId > 0) {
    $materialStmt = $conn->prepare('SELECT id, material_code, material_name, status FROM materials WHERE id = ?');
    if (!$materialStmt) {
        throw new RuntimeException('Unable to load material filter.');
    }
    $materialStmt->bind_param('i', $selectedMaterialId);
    $materialStmt->execute();
    $selectedMaterial = $materialStmt->get_result()->fetch_assoc() ?: null;
    if (!$selectedMaterial) {
        $selectedMaterialId = 0;
    }
}

$movements = material_stock_fetch_history($conn, $selectedMaterialId, $selectedType);
$physicalCounts = material_physical_count_fetch_history($conn, $selectedMaterialId);
$displayQuantity = static function (float $quantity): string {
    return rtrim(rtrim(number_format($quantity, 2, '.', ','), '0'), '.');
};

$adminPageTitle = 'Consumable Stock History - Admin';
$adminBodyClass = 'admin-light-page';
$adminCssFiles = [
    '/codesamplecaps/ADMIN/common/css/admin-common.css?v=20261112-4',
    '/codesamplecaps/ADMIN/sidebar/inventory/css/inventory.css?v=20261123-7',
];
$adminJsFiles = ['/codesamplecaps/ADMIN/common/js/admin-common.js'];
include __DIR__ . '/../../../layout/header.php';
include __DIR__ . '/../../../admin_sidebar.php';
?>
<main class="main-content admin-dashboard-content inventory-main-content inventory-light-main shared-page-surface">
    <div class="shared-page-container page-stack inventory-page-stack material-history-page">
        <?php if ($returnToDelivery): ?>
            <a class="admin-sticky-back-button inventory-sticky-back" href="/codesamplecaps/ADMIN/sidebar/inventory/php/material_stock_in.php">&larr; Back to Delivery Receipt</a>
        <?php endif; ?>
        <section class="form-panel admin-flat-panel inventory-report-panel material-history-panel">
            <div class="inventory-report-heading material-history-heading">
                <div><h1 class="section-title-inline">Consumable Stock History</h1></div>
                <nav class="resource-page-tabs inventory-page-tabs inventory-report-tabs" aria-label="Inventory sections">
                    <a href="/codesamplecaps/ADMIN/sidebar/inventory/php/inventory.php">Consumables</a>
                    <a class="is-active" href="/codesamplecaps/ADMIN/sidebar/inventory/php/inventory.php?view=history<?php echo $returnToDelivery ? '&amp;return=delivery' : ''; ?>">Stock History</a>
                </nav>
                <div class="inventory-report-heading__actions">
                    <?php if ($selectedMaterial || $selectedType !== ''): ?><a class="btn-secondary" href="?view=history<?php echo $returnToDelivery ? '&amp;return=delivery' : ''; ?>">Clear Filter</a><?php endif; ?>
                </div>
            </div>
            <form method="GET" class="form-grid material-history-filter">
                <input type="hidden" name="view" value="history">
                <?php if ($returnToDelivery): ?><input type="hidden" name="return" value="delivery"><?php endif; ?>
                <div class="input-group"><label for="history_material_id">Material</label><select id="history_material_id" name="material_id"><option value="">All materials</option><?php foreach ($historyMaterials as $material): ?><option value="<?php echo (int)$material['id']; ?>"<?php echo (int)$material['id'] === $selectedMaterialId ? ' selected' : ''; ?>><?php echo htmlspecialchars((string)$material['material_code'] . ' | ' . (string)$material['material_name'] . ((string)$material['status'] === 'inactive' ? ' (Archived)' : '')); ?></option><?php endforeach; ?></select></div>
                <div class="input-group"><label for="history_type">Type / Status</label><select id="history_type" name="type"><option value="">All</option><option value="stock_in"<?php echo $selectedType === 'stock_in' ? ' selected' : ''; ?>>Stock-In</option><option value="discrepancy"<?php echo $selectedType === 'discrepancy' ? ' selected' : ''; ?>>Discrepancy</option><option value="reserved"<?php echo $selectedType === 'reserved' ? ' selected' : ''; ?>>Reserved</option></select></div>
                <div class="form-actions"><button type="submit" class="btn-secondary">Apply Filter</button></div>
            </form>
            <?php if (!$selectedMaterial && isset($_GET['material_id']) && $_GET['material_id'] !== ''): ?><div class="alert alert-error">Selected material was not found.</div><?php endif; ?>
            <div class="table-responsive">
                <table class="data-table material-history-table mobile-card-table">
                    <thead><tr><th>Date</th><th>Material</th><th>Type / Status</th><th>Qty</th><th>Before</th><th>After</th><th>Recorded By</th><th>Reference / Remarks</th></tr></thead>
                    <tbody>
                    <?php if ($movements === []): ?><tr><td colspan="8" class="empty-state">No consumable stock movement yet.</td></tr><?php endif; ?>
                    <?php foreach ($movements as $movement): ?><tr><td data-label="Date"><?php echo htmlspecialchars((string)$movement['created_at']); ?></td><td data-label="Material"><?php echo htmlspecialchars((string)$movement['material_name']); ?></td><td data-label="Type / Status"><?php echo htmlspecialchars(material_stock_history_type_label((string)$movement['movement_type'])); ?></td><td data-label="Qty"><?php echo htmlspecialchars((string)$movement['quantity'] . ' ' . (string)$movement['unit']); ?></td><td data-label="Before"><?php echo htmlspecialchars((string)$movement['physical_before']); ?></td><td data-label="After"><?php echo htmlspecialchars((string)$movement['physical_after']); ?></td><td data-label="Recorded By"><?php echo htmlspecialchars((string)($movement['full_name'] ?? 'System')); ?></td><td data-label="Reference / Remarks"><?php echo htmlspecialchars((string)($movement['remarks'] ?? '')); ?></td></tr><?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <h2 class="material-history-subtitle">Clerk Physical Count History</h2>
            <div class="table-responsive">
                <table class="data-table material-history-table mobile-card-table">
                    <thead><tr><th>Date</th><th>Material</th><th>System Stock at Count</th><th>Clerk Count</th><th>Variance</th><th>Review Status</th><th>Counted By</th><th>Remarks</th></tr></thead>
                    <tbody>
                    <?php if ($physicalCounts === []): ?><tr><td colspan="8" class="empty-state">No Clerk physical count yet.</td></tr><?php endif; ?>
                    <?php foreach ($physicalCounts as $count): ?>
                        <?php $variance = (float)$count['counted_quantity'] - (float)$count['total_quantity_snapshot']; $countStatus = material_physical_count_status($count); ?>
                        <tr>
                            <td data-label="Date"><?php echo htmlspecialchars((string)$count['counted_at']); ?></td>
                            <td data-label="Material"><?php echo htmlspecialchars((string)$count['material_name']); ?></td>
                            <td data-label="System Stock at Count"><?php echo htmlspecialchars($displayQuantity((float)$count['total_quantity_snapshot']) . ' ' . (string)$count['unit']); ?></td>
                            <td data-label="Clerk Count"><?php echo htmlspecialchars($displayQuantity((float)$count['counted_quantity']) . ' ' . (string)$count['unit']); ?></td>
                            <td data-label="Variance"><?php echo htmlspecialchars(($variance > 0 ? '+' : '') . $displayQuantity($variance) . ' ' . (string)$count['unit']); ?></td>
                            <td data-label="Review Status"><?php echo htmlspecialchars($countStatus['label']); ?></td>
                            <td data-label="Counted By"><?php echo htmlspecialchars(trim((string)$count['counted_by_name']) ?: 'Inventory Clerk'); ?></td>
                            <td data-label="Remarks"><?php echo htmlspecialchars(trim((string)$count['remarks']) ?: '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</main>
<?php include __DIR__ . '/../../../layout/footer.php'; ?>
