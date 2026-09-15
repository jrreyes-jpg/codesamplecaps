<?php
require_once __DIR__ . '/../includes/stock_helpers.php';
require_once __DIR__ . '/../includes/page_shell.php';
require_once __DIR__ . '/../../config/material_stock.php';

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

$movements = [];
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
if ($historyResult) {
    $movements = $historyResult->fetch_all(MYSQLI_ASSOC);
}
?>
<?php inventory_clerk_render_page('Material Stock History', function () use ($movements, $selectedMaterial, $selectedMaterialId, $historyMaterials): void { ?>
<div class="page-stack"><section class="form-panel"><div class="section-title-row"><h1 class="section-title-inline">Material Stock History</h1><?php if ($selectedMaterial): ?><a class="btn-secondary" href="/codesamplecaps/INVENTORY_CLERK/dashboards/material_stock_history.php">Clear filter</a><?php endif; ?></div><form method="GET" class="form-grid"><div class="input-group"><label for="history_material_id">Material filter</label><select id="history_material_id" name="material_id"><option value="">All materials</option><?php foreach ($historyMaterials as $material): ?><option value="<?php echo (int)$material['id']; ?>"<?php echo (int)$material['id'] === $selectedMaterialId ? ' selected' : ''; ?>><?php echo htmlspecialchars($material['material_code'] . ' | ' . $material['material_name'] . ((string)$material['status'] === 'inactive' ? ' (Archived)' : '')); ?></option><?php endforeach; ?></select></div><div class="form-actions"><button type="submit" class="btn-secondary">Apply filter</button></div></form><?php if ($selectedMaterial): ?><p>Showing: <strong><?php echo htmlspecialchars($selectedMaterial['material_code'] . ' | ' . $selectedMaterial['material_name']); ?></strong><?php echo $selectedMaterial['status'] === 'inactive' ? ' (Archived)' : ''; ?></p><?php elseif (isset($_GET['material_id'])): ?><div class="alert alert-error">Selected material was not found.</div><?php endif; ?><div class="table-responsive"><table class="data-table"><thead><tr><th>Date</th><th>Material</th><th>Type</th><th>Qty</th><th>Before</th><th>After</th><th>User</th><th>Remarks</th></tr></thead><tbody><?php if ($movements === []): ?><tr><td colspan="8">No material movement yet.</td></tr><?php endif; ?><?php foreach ($movements as $movement): ?><tr><td><?php echo htmlspecialchars((string)$movement['created_at']); ?></td><td><?php echo htmlspecialchars($movement['material_name']); ?></td><td><?php echo htmlspecialchars(str_replace('_', ' ', $movement['movement_type'])); ?></td><td><?php echo htmlspecialchars((string)$movement['quantity'] . ' ' . $movement['unit']); ?></td><td><?php echo htmlspecialchars((string)$movement['physical_before']); ?></td><td><?php echo htmlspecialchars((string)$movement['physical_after']); ?></td><td><?php echo htmlspecialchars((string)($movement['full_name'] ?? 'System')); ?></td><td><?php echo htmlspecialchars((string)($movement['remarks'] ?? '')); ?></td></tr><?php endforeach; ?></tbody></table></div></section></div>
<?php }); ?>
