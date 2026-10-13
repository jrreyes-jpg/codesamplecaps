<?php
require_once __DIR__ . '/../../../../config/auth_middleware.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/material_stock.php';

require_role('admin');

$selectedMaterialId = filter_input(INPUT_GET, 'material_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
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

$historySql = "SELECT sm.*, m.material_name, m.unit, u.full_name
    FROM material_stock_movements sm
    INNER JOIN materials m ON m.id = sm.material_id
    LEFT JOIN users u ON u.id = sm.created_by";
if ($selectedMaterialId > 0) {
    $historySql .= ' WHERE sm.material_id = ?';
}
$historySql .= ' ORDER BY sm.created_at DESC, sm.id DESC LIMIT 200';
$historyStmt = $conn->prepare($historySql);
if (!$historyStmt) {
    throw new RuntimeException('Unable to load material history.');
}
if ($selectedMaterialId > 0) {
    $historyStmt->bind_param('i', $selectedMaterialId);
}
$historyStmt->execute();
$historyResult = $historyStmt->get_result();
$movements = $historyResult ? $historyResult->fetch_all(MYSQLI_ASSOC) : [];

$adminPageTitle = 'Consumable Stock History - Admin';
$adminCssFiles = [
    '/codesamplecaps/ADMIN/common/css/admin-common.css',
    '/codesamplecaps/ADMIN/sidebar/inventory/css/inventory.css',
];
$adminJsFiles = ['/codesamplecaps/ADMIN/common/js/admin-common.js'];
include __DIR__ . '/../../../layout/header.php';
include __DIR__ . '/../../../admin_sidebar.php';
?>
<main class="main-content">
    <div class="page-stack material-history-page">
        <nav class="resource-page-tabs" aria-label="Inventory sections">
            <a href="/codesamplecaps/ADMIN/sidebar/inventory/php/inventory.php">Consumables</a>
            <a class="is-active" href="/codesamplecaps/ADMIN/sidebar/inventory/php/inventory.php?view=history">Stock History</a>
        </nav>
        <section class="form-panel">
            <div class="material-master-heading">
                <h1 class="section-title-inline">Consumable Stock History</h1>
                <?php if ($selectedMaterial): ?><a class="btn-secondary" href="?view=history">Clear Filter</a><?php endif; ?>
            </div>
            <form method="GET" class="form-grid material-history-filter">
                <input type="hidden" name="view" value="history">
                <div class="input-group"><label for="history_material_id">Material</label><select id="history_material_id" name="material_id"><option value="">All materials</option><?php foreach ($historyMaterials as $material): ?><option value="<?php echo (int)$material['id']; ?>"<?php echo (int)$material['id'] === $selectedMaterialId ? ' selected' : ''; ?>><?php echo htmlspecialchars((string)$material['material_code'] . ' | ' . (string)$material['material_name'] . ((string)$material['status'] === 'inactive' ? ' (Archived)' : '')); ?></option><?php endforeach; ?></select></div>
                <div class="form-actions"><button type="submit" class="btn-secondary">Apply Filter</button></div>
            </form>
            <?php if (!$selectedMaterial && isset($_GET['material_id']) && $_GET['material_id'] !== ''): ?><div class="alert alert-error">Selected material was not found.</div><?php endif; ?>
            <div class="table-responsive">
                <table class="data-table material-history-table">
                    <thead><tr><th>Date</th><th>Material</th><th>Type</th><th>Qty</th><th>Before</th><th>After</th><th>Recorded By</th><th>Reference / Remarks</th></tr></thead>
                    <tbody>
                    <?php if ($movements === []): ?><tr><td colspan="8" class="empty-state">No consumable stock movement yet.</td></tr><?php endif; ?>
                    <?php foreach ($movements as $movement): ?><tr><td data-label="Date"><?php echo htmlspecialchars((string)$movement['created_at']); ?></td><td data-label="Material"><?php echo htmlspecialchars((string)$movement['material_name']); ?></td><td data-label="Type"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$movement['movement_type']))); ?></td><td data-label="Qty"><?php echo htmlspecialchars((string)$movement['quantity'] . ' ' . (string)$movement['unit']); ?></td><td data-label="Before"><?php echo htmlspecialchars((string)$movement['physical_before']); ?></td><td data-label="After"><?php echo htmlspecialchars((string)$movement['physical_after']); ?></td><td data-label="Recorded By"><?php echo htmlspecialchars((string)($movement['full_name'] ?? 'System')); ?></td><td data-label="Reference / Remarks"><?php echo htmlspecialchars((string)($movement['remarks'] ?? '')); ?></td></tr><?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</main>
<?php include __DIR__ . '/../../../layout/footer.php'; ?>
