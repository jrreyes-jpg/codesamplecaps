<?php
require_once __DIR__ . '/../includes/stock_helpers.php';
require_once __DIR__ . '/../includes/page_shell.php';
require_once __DIR__ . '/../../config/material_stock.php';

// Checker page lang ito. Admin ang may hawak ng material master data.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Material master changes are limited to Admin.');
}

$materials = material_stock_fetch_materials($conn, 'active');
$stockedMaterialIds = [];
$stockHistoryResult = $conn->query(
    "SELECT DISTINCT material_id FROM material_stock_movements WHERE movement_type IN ('stock_in', 'adjustment_in')"
);
if ($stockHistoryResult) {
    foreach ($stockHistoryResult->fetch_all(MYSQLI_ASSOC) as $stockedMaterial) {
        $stockedMaterialIds[(int)$stockedMaterial['material_id']] = true;
    }
}

$shortages = [];
$shortageResult = $conn->query(
    "SELECT p.project_name, m.material_name, m.unit, r.required_quantity, r.reserved_quantity, r.issued_quantity,
            GREATEST(r.required_quantity - r.reserved_quantity - r.issued_quantity, 0) AS shortage_quantity
     FROM project_material_reservations r
     INNER JOIN projects p ON p.id = r.project_id
     INNER JOIN materials m ON m.id = r.material_id
     WHERE r.status = 'active'
     ORDER BY p.created_at ASC, r.id ASC"
);
if ($shortageResult) {
    $shortages = $shortageResult->fetch_all(MYSQLI_ASSOC);
}

inventory_clerk_render_page('Material Quantities', function () use ($materials, $stockedMaterialIds, $shortages): void {
?>
<div class="page-stack materials-page">
    <section class="form-panel">
        <div class="materials-master-list__heading">
            <div><h1 class="section-title-inline">Material Quantities</h1><p>Check stock here. Admin manages material names, units, and alert levels.</p></div>
            <div class="materials-master-list__controls">
                <a class="btn-primary" href="/codesamplecaps/INVENTORY_CLERK/dashboards/material_stock_in.php">Record Stock In</a>
                <a class="btn-secondary" href="/codesamplecaps/INVENTORY_CLERK/dashboards/material_issue.php">Record Issue</a>
            </div>
        </div>
        <div class="table-responsive">
            <table class="data-table materials-table">
                <thead><tr><th>Material</th><th>Category</th><th>Unit</th><th>Physical</th><th>Reserved</th><th>Available</th><th>Alert Level</th><th>Status</th><th>History</th></tr></thead>
                <tbody>
                <?php if ($materials === []): ?><tr><td colspan="9" class="materials-empty">No active materials yet.</td></tr><?php endif; ?>
                <?php foreach ($materials as $material): ?>
                    <?php
                    $physical = (float)$material['physical_quantity'];
                    $available = (float)$material['available_quantity'];
                    $reorder = $material['reorder_level'] === null ? null : (float)$material['reorder_level'];
                    $hasReceivedStock = isset($stockedMaterialIds[(int)$material['id']]);
                    if ($physical <= 0) {
                        $stockState = $hasReceivedStock ? 'out' : 'no-stock-yet';
                        $stockLabel = $hasReceivedStock ? 'Out of Stock' : 'No Stock Yet';
                    } elseif ($reorder !== null && $available <= $reorder) {
                        $stockState = 'low';
                        $stockLabel = 'Low Stock';
                    } else {
                        $stockState = 'available';
                        $stockLabel = 'In Stock';
                    }
                    ?>
                    <tr>
                        <td data-label="Material"><strong class="materials-table__name"><?php echo htmlspecialchars((string)$material['material_name']); ?></strong><small class="materials-table__code"><?php echo htmlspecialchars((string)$material['material_code']); ?></small></td>
                        <td data-label="Category"><?php echo htmlspecialchars((string)($material['category'] ?: '—')); ?></td>
                        <td data-label="Unit"><?php echo htmlspecialchars((string)$material['unit']); ?></td>
                        <td data-label="Physical"><?php echo htmlspecialchars((string)$material['physical_quantity']); ?></td>
                        <td data-label="Reserved"><?php echo htmlspecialchars((string)$material['reserved_quantity']); ?></td>
                        <td data-label="Available"><?php echo htmlspecialchars((string)$material['available_quantity']); ?></td>
                        <td data-label="Alert Level"><?php echo $reorder === null ? '—' : htmlspecialchars((string)$material['reorder_level']); ?></td>
                        <td data-label="Status"><span class="material-status material-status--<?php echo $stockState; ?>"><?php echo $stockLabel; ?></span></td>
                        <td data-label="History"><a class="materials-row-action__button" href="/codesamplecaps/INVENTORY_CLERK/dashboards/material_stock_history.php?material_id=<?php echo (int)$material['id']; ?>">View History</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section id="project-material-needs" class="form-panel">
        <h2 class="section-title-inline">Project Material Needs</h2>
        <div class="table-responsive"><table class="data-table"><thead><tr><th>Project</th><th>Material</th><th>Required</th><th>Reserved</th><th>Issued</th><th>Shortage</th><th>Status</th></tr></thead><tbody>
        <?php if ($shortages === []): ?><tr><td colspan="7" class="materials-empty">No active project material needs.</td></tr><?php endif; ?>
        <?php foreach ($shortages as $shortage): ?>
            <?php $needsProcurement = (float)$shortage['shortage_quantity'] > 0; ?>
            <tr><td><?php echo htmlspecialchars((string)$shortage['project_name']); ?></td><td><?php echo htmlspecialchars((string)$shortage['material_name']); ?></td><td><?php echo htmlspecialchars((string)$shortage['required_quantity']); ?></td><td><?php echo htmlspecialchars((string)$shortage['reserved_quantity']); ?></td><td><?php echo htmlspecialchars((string)$shortage['issued_quantity']); ?></td><td><?php echo htmlspecialchars((string)$shortage['shortage_quantity'] . ' ' . $shortage['unit']); ?></td><td><span class="material-status material-status--<?php echo $needsProcurement ? 'needs' : 'covered'; ?>"><?php echo $needsProcurement ? 'Needs Procurement' : 'Covered'; ?></span></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </section>
</div>
<?php
}, [
    '/codesamplecaps/INVENTORY_CLERK/css/materials.css',
]);
