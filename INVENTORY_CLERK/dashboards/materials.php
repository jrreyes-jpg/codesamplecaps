<?php
require_once __DIR__ . '/../includes/stock_helpers.php';
require_once __DIR__ . '/../includes/page_shell.php';
require_once __DIR__ . '/../../config/material_stock.php';

$csrfToken = auth_csrf_token('inventory_clerk_materials');
$flashKey = 'inventory_clerk_materials_flash';
$materialCategories = [
    'Cable & Wire',
    'Connectors & Terminals',
    'Conduit & Raceway',
    'Fasteners & Hardware',
    'Electrical Components',
    'Network Components',
    'Automation / Control Components',
    'Other',
];
$materialUnits = ['pcs', 'meter', 'roll', 'box', 'pack', 'set', 'kg', 'liter', 'Other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'inventory_clerk_materials')) {
            throw new RuntimeException('Security check failed. Please try again.');
        }
        $reorder = trim((string)($_POST['reorder_level'] ?? ''));
        $category = trim((string)($_POST['category'] ?? ''));
        $unit = trim((string)($_POST['unit'] ?? ''));
        if ($reorder === '' || !is_numeric($reorder) || !is_finite((float)$reorder) || (float)$reorder <= 0) {
            throw new RuntimeException('Reorder level must be greater than zero.');
        }
        if (!in_array($category, $materialCategories, true) || !in_array($unit, $materialUnits, true)) {
            throw new RuntimeException('Please choose a valid category and unit.');
        }
        $code = material_stock_create_material(
            $conn,
            (string)($_POST['material_name'] ?? ''),
            $category,
            $unit,
            $reorder === '' ? null : (float)$reorder
        );
        $_SESSION[$flashKey] = ['type' => 'success', 'message' => 'Material ' . $code . ' created. Add Stock In when stock arrives.'];
    } catch (Throwable $exception) {
        $_SESSION[$flashKey] = ['type' => 'error', 'message' => $exception->getMessage()];
    }
    header('Location: /codesamplecaps/INVENTORY_CLERK/dashboards/materials.php');
    exit;
}

$flash = $_SESSION[$flashKey] ?? null;
unset($_SESSION[$flashKey]);
$materials = material_stock_fetch_active_materials($conn);
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

inventory_clerk_render_page('Materials', function () use ($csrfToken, $flash, $materials, $shortages, $materialCategories, $materialUnits): void {
?>
    <div class="page-stack materials-page">
        <section class="form-panel">
            <h1 class="section-title-inline">Materials</h1>
            <?php if ($flash): ?><div class="alert <?php echo $flash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>"><?php echo htmlspecialchars($flash['message']); ?></div><?php endif; ?>
            <form method="POST" data-material-form><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <div class="form-grid">
                    <div class="input-group"><label for="material_name">Material Name <span class="materials-suggestion" data-material-suggestion aria-live="polite"></span></label><input id="material_name" name="material_name" maxlength="180" required data-material-name></div>
                    <div class="input-group"><label for="category">Category</label><select id="category" name="category" required data-material-category><option value="">Select category</option><?php foreach ($materialCategories as $category): ?><option value="<?php echo htmlspecialchars($category); ?>"><?php echo htmlspecialchars($category); ?></option><?php endforeach; ?></select></div>
                    <div class="input-group"><label for="unit">Unit</label><select id="unit" name="unit" required data-material-unit><option value="">Select unit</option><?php foreach ($materialUnits as $unit): ?><option value="<?php echo htmlspecialchars($unit); ?>"<?php echo $unit === 'pcs' ? ' selected' : ''; ?>><?php echo htmlspecialchars($unit); ?></option><?php endforeach; ?></select></div>
                    <div class="input-group"><label for="reorder_level">Reorder Level <span class="materials-info-tooltip" tabindex="0" role="img" aria-label="Alert when available stock reaches this level or lower." data-tooltip="Alert when available stock reaches this level or lower.">i</span></label><input id="reorder_level" name="reorder_level" type="number" min="0.01" step="0.01" required inputmode="decimal" data-reorder-level></div>
                </div>
                <div class="form-actions"><button type="submit" class="btn-primary">Add Material</button></div>
            </form>
        </section>
        <section class="form-panel">
            <h2 class="section-title-inline">Material Master List</h2>
            <p class="materials-table-note">Available = Physical minus Reserved.</p>
            <div class="table-responsive"><table class="data-table materials-table"><thead><tr><th>Material Code</th><th>Material Name</th><th>Category</th><th>Unit</th><th>Physical</th><th>Reserved</th><th>Available</th><th>Reorder Level</th><th>Status</th></tr></thead><tbody>
            <?php if ($materials === []): ?><tr><td colspan="9" class="materials-empty">No materials yet.</td></tr><?php endif; ?>
            <?php foreach ($materials as $material): ?>
                <?php
                $physical = (float)$material['physical_quantity'];
                $available = (float)$material['available_quantity'];
                $reorder = $material['reorder_level'] === null ? null : (float)$material['reorder_level'];
                $stockState = $physical <= 0 ? 'out' : ($reorder !== null && $available <= $reorder ? 'low' : 'available');
                $stockLabel = $stockState === 'out' ? 'Out of Stock' : ($stockState === 'low' ? 'Low Stock' : 'Available');
                ?>
                <tr><td><strong><?php echo htmlspecialchars($material['material_code']); ?></strong></td><td><?php echo htmlspecialchars($material['material_name']); ?></td><td><?php echo htmlspecialchars((string)($material['category'] ?: '—')); ?></td><td><?php echo htmlspecialchars($material['unit']); ?></td><td><?php echo htmlspecialchars((string)$material['physical_quantity']); ?></td><td><?php echo htmlspecialchars((string)$material['reserved_quantity']); ?></td><td><?php echo htmlspecialchars((string)$material['available_quantity']); ?></td><td><?php echo $reorder === null ? '—' : htmlspecialchars((string)$material['reorder_level']); ?></td><td><span class="material-status material-status--<?php echo $stockState; ?>"><?php echo $stockLabel; ?></span></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </section>
        <section class="form-panel">
            <h2 class="section-title-inline">Project Material Needs</h2>
            <div class="table-responsive"><table class="data-table"><thead><tr><th>Project</th><th>Material</th><th>Required</th><th>Reserved</th><th>Issued</th><th>Shortage</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($shortages as $shortage): ?>
                <?php $needsProcurement = (float)$shortage['shortage_quantity'] > 0; ?>
                <tr><td><?php echo htmlspecialchars($shortage['project_name']); ?></td><td><?php echo htmlspecialchars($shortage['material_name']); ?></td><td><?php echo htmlspecialchars((string)$shortage['required_quantity']); ?></td><td><?php echo htmlspecialchars((string)$shortage['reserved_quantity']); ?></td><td><?php echo htmlspecialchars((string)$shortage['issued_quantity']); ?></td><td><?php echo htmlspecialchars((string)$shortage['shortage_quantity'] . ' ' . $shortage['unit']); ?></td><td><span class="material-status material-status--<?php echo $needsProcurement ? 'needs' : 'covered'; ?>"><?php echo $needsProcurement ? 'Needs Procurement' : 'Covered'; ?></span></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </section>
    </div>
<?php
}, [
    '/codesamplecaps/INVENTORY_CLERK/css/materials.css',
], '', [
    '/codesamplecaps/INVENTORY_CLERK/js/materials.js',
]);
