<?php
require_once __DIR__ . '/../includes/stock_helpers.php';
require_once __DIR__ . '/../includes/page_shell.php';
require_once __DIR__ . '/../../config/material_stock.php';
require_once __DIR__ . '/../../config/material_physical_count.php';

$selectedMaterialId = filter_input(INPUT_GET, 'material_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$selectedType = is_string($_GET['type'] ?? null) ? trim((string)$_GET['type']) : '';
if (!in_array($selectedType, material_stock_history_filter_types(), true)) {
    $selectedType = '';
}

$selectedMaterial = null;
$historyMaterials = material_stock_fetch_materials($conn, 'all');
if ($selectedMaterialId > 0) {
    $materialStmt = $conn->prepare('SELECT id, material_code, material_name, status FROM materials WHERE id = ?');
    $materialStmt->bind_param('i', $selectedMaterialId);
    $materialStmt->execute();
    $selectedMaterial = $materialStmt->get_result()->fetch_assoc() ?: null;
    if (!$selectedMaterial) {
        $selectedMaterialId = 0;
    }
}

$movements = material_stock_fetch_history($conn, $selectedMaterialId, $selectedType);
$physicalCounts = material_physical_count_fetch_history($conn, $selectedMaterialId);
$displayQuantity = static fn(float $quantity): string => rtrim(rtrim(number_format($quantity, 2, '.', ','), '0'), '.');

inventory_clerk_render_page('Material Stock History', function () use ($movements, $physicalCounts, $selectedMaterial, $selectedMaterialId, $selectedType, $historyMaterials, $displayQuantity): void {
?>
<div class="page-stack">
    <section class="form-panel">
        <div class="section-title-row">
            <h1 class="section-title-inline">Material Stock History</h1>
            <?php if ($selectedMaterial || $selectedType !== ''): ?><a class="btn-secondary" href="/codesamplecaps/INVENTORY_CLERK/dashboards/material_stock_history.php">Clear Filter</a><?php endif; ?>
        </div>
        <form method="GET" class="form-grid">
            <div class="input-group"><label for="history_material_id">Material</label><select id="history_material_id" name="material_id"><option value="">All materials</option><?php foreach ($historyMaterials as $material): ?><option value="<?php echo (int)$material['id']; ?>"<?php echo (int)$material['id'] === $selectedMaterialId ? ' selected' : ''; ?>><?php echo htmlspecialchars($material['material_code'] . ' | ' . $material['material_name']); ?></option><?php endforeach; ?></select></div>
            <div class="input-group"><label for="history_type">Type / Status</label><select id="history_type" name="type"><option value="">All</option><option value="stock_in"<?php echo $selectedType === 'stock_in' ? ' selected' : ''; ?>>Stock-In</option><option value="discrepancy"<?php echo $selectedType === 'discrepancy' ? ' selected' : ''; ?>>Discrepancy</option><option value="reserved"<?php echo $selectedType === 'reserved' ? ' selected' : ''; ?>>Reserved</option></select></div>
            <div class="form-actions"><button type="submit" class="btn-secondary">Apply Filter</button></div>
        </form>
        <div class="table-responsive"><table class="data-table"><thead><tr><th>Date</th><th>Material</th><th>Type / Status</th><th>Qty</th><th>Before</th><th>After</th><th>Recorded By</th><th>Reference / Remarks</th></tr></thead><tbody>
        <?php if ($movements === []): ?><tr><td colspan="8">No material movement yet.</td></tr><?php endif; ?>
        <?php foreach ($movements as $movement): ?><tr><td><?php echo htmlspecialchars((string)$movement['created_at']); ?></td><td><?php echo htmlspecialchars((string)$movement['material_name']); ?></td><td><?php echo htmlspecialchars(material_stock_history_type_label((string)$movement['movement_type'])); ?></td><td><?php echo htmlspecialchars($displayQuantity((float)$movement['quantity']) . ' ' . (string)$movement['unit']); ?></td><td><?php echo htmlspecialchars($displayQuantity((float)$movement['physical_before'])); ?></td><td><?php echo htmlspecialchars($displayQuantity((float)$movement['physical_after'])); ?></td><td><?php echo htmlspecialchars((string)($movement['full_name'] ?? 'System')); ?></td><td><?php echo htmlspecialchars((string)($movement['remarks'] ?? '')); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>

        <h2>Clerk Physical Count History</h2>
        <div class="table-responsive"><table class="data-table"><thead><tr><th>Date</th><th>Material</th><th>System Stock</th><th>Clerk Count</th><th>Variance</th><th>Counted By</th><th>Remarks</th></tr></thead><tbody>
        <?php if ($physicalCounts === []): ?><tr><td colspan="7">No Clerk physical count yet.</td></tr><?php endif; ?>
        <?php foreach ($physicalCounts as $count): ?><?php $variance = (float)$count['counted_quantity'] - (float)$count['total_quantity_snapshot']; ?><tr><td><?php echo htmlspecialchars((string)$count['counted_at']); ?></td><td><?php echo htmlspecialchars((string)$count['material_name']); ?></td><td><?php echo htmlspecialchars($displayQuantity((float)$count['total_quantity_snapshot']) . ' ' . (string)$count['unit']); ?></td><td><?php echo htmlspecialchars($displayQuantity((float)$count['counted_quantity']) . ' ' . (string)$count['unit']); ?></td><td><?php echo htmlspecialchars(($variance > 0 ? '+' : '') . $displayQuantity($variance)); ?></td><td><?php echo htmlspecialchars((string)($count['counted_by_name'] ?? 'Inventory Clerk')); ?></td><td><?php echo htmlspecialchars(trim((string)($count['remarks'] ?? '')) ?: '—'); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
</div>
<?php
});
