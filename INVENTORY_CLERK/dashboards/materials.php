<?php
require_once __DIR__ . '/../includes/stock_helpers.php';
require_once __DIR__ . '/../includes/page_shell.php';
require_once __DIR__ . '/../../config/material_stock.php';

$csrfToken = auth_csrf_token('inventory_clerk_materials');
$flashKey = 'inventory_clerk_materials_flash';
$defaultFormValues = [
    'material_name' => '',
    'category' => '',
    'unit' => 'pcs',
    'reorder_level' => '',
];
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
$materialUnits = ['pcs', 'meter', 'roll', 'box', 'pack', 'set', 'kg', 'liter', 'bundle', 'sheet', 'pair', 'tube'];
$wholeCountUnits = ['pcs', 'roll', 'box', 'pack', 'set', 'bundle', 'sheet', 'pair', 'tube'];

function inventory_clerk_material_name_key(string $name): string
{
    $normalizedName = preg_replace('/\s+/', ' ', trim($name));
    return strtolower($normalizedName ?? '');
}

function inventory_clerk_material_name_exists(mysqli $conn, string $materialName): bool
{
    $targetName = inventory_clerk_material_name_key($materialName);
    if ($targetName === '') {
        return false;
    }

    $result = $conn->query('SELECT material_name FROM materials');
    if (!$result) {
        throw new RuntimeException('Unable to check existing materials.');
    }

    while ($row = $result->fetch_assoc()) {
        if (inventory_clerk_material_name_key((string)($row['material_name'] ?? '')) === $targetName) {
            return true;
        }
    }

    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedValues = [
        'material_name' => preg_replace('/\s+/', ' ', trim((string)($_POST['material_name'] ?? ''))) ?? '',
        'category' => trim((string)($_POST['category'] ?? '')),
        'unit' => trim((string)($_POST['unit'] ?? '')),
        'reorder_level' => trim((string)($_POST['reorder_level'] ?? '')),
    ];
    try {
        if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'inventory_clerk_materials')) {
            throw new RuntimeException('Security check failed. Please try again.');
        }
        $reorder = $submittedValues['reorder_level'];
        $category = $submittedValues['category'];
        $unit = $submittedValues['unit'];
        $materialName = $submittedValues['material_name'];
        $reorderValue = is_numeric($reorder) ? (float)$reorder : 0.0;
        if ($reorder === '' || !is_numeric($reorder) || !is_finite($reorderValue) || $reorderValue <= 0) {
            throw new RuntimeException('Low Stock Alert Level must be greater than zero.');
        }
        if (!in_array($category, $materialCategories, true) || !in_array($unit, $materialUnits, true)) {
            throw new RuntimeException('Please choose a valid category and unit.');
        }
        if (in_array($unit, $wholeCountUnits, true) && $reorderValue !== (float)floor($reorderValue)) {
            throw new RuntimeException('Low Stock Alert Level must be a whole number for ' . $unit . '.');
        }
        if (inventory_clerk_material_name_exists($conn, $materialName)) {
            throw new RuntimeException('Material already exists.');
        }
        $code = material_stock_create_material(
            $conn,
            $materialName,
            $category,
            $unit,
            $reorderValue
        );
        $_SESSION[$flashKey] = [
            'type' => 'success',
            'title' => 'Material added',
            'message' => 'Material ' . $code . ' created. Add stock through Material Stock In.',
        ];
    } catch (Throwable $exception) {
        $message = $exception->getMessage();
        $_SESSION[$flashKey] = [
            'type' => 'error',
            'title' => 'Cannot add material',
            'message' => $message,
            'values' => $submittedValues,
            'field_errors' => [
                'reorder_level' => str_starts_with($message, 'Low Stock Alert Level') ? $message : '',
                'category' => $message === 'Please choose a valid category and unit.' ? 'Choose a valid category.' : '',
                'unit' => $message === 'Please choose a valid category and unit.' ? 'Choose a valid unit.' : '',
            ],
        ];
    }
    header('Location: /codesamplecaps/INVENTORY_CLERK/dashboards/materials.php');
    exit;
}

$flash = $_SESSION[$flashKey] ?? null;
unset($_SESSION[$flashKey]);
$formValues = array_merge($defaultFormValues, is_array($flash['values'] ?? null) ? $flash['values'] : []);
$formErrors = is_array($flash['field_errors'] ?? null) ? $flash['field_errors'] : [];
$materials = material_stock_fetch_active_materials($conn);
$stockedMaterialIds = [];
$stockHistoryResult = $conn->query(
    "SELECT DISTINCT material_id
     FROM material_stock_movements
     WHERE movement_type IN ('stock_in', 'adjustment_in')"
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

inventory_clerk_render_page('Materials', function () use ($csrfToken, $flash, $formValues, $formErrors, $materials, $stockedMaterialIds, $shortages, $materialCategories, $materialUnits): void {
?>
    <div class="page-stack materials-page">
        <section class="form-panel">
            <h1 class="section-title-inline">Materials</h1>
            <form method="POST" data-material-form><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <div class="form-grid">
                    <div class="input-group"><label for="material_name">Material Name <span class="materials-suggestion" data-material-suggestion aria-live="polite"></span></label><input id="material_name" name="material_name" maxlength="180" required value="<?php echo htmlspecialchars($formValues['material_name']); ?>" data-material-name><span class="materials-field-error" data-material-error="material_name" aria-live="polite"></span></div>
                    <div class="input-group"><label for="category">Category</label><select id="category" name="category" required data-material-category><option value="">Select category</option><?php foreach ($materialCategories as $category): ?><option value="<?php echo htmlspecialchars($category); ?>"<?php echo $formValues['category'] === $category ? ' selected' : ''; ?>><?php echo htmlspecialchars($category); ?></option><?php endforeach; ?></select><span class="materials-field-error" data-material-error="category" aria-live="polite"><?php echo htmlspecialchars((string)($formErrors['category'] ?? '')); ?></span></div>
                    <div class="input-group"><label for="unit">Unit</label><select id="unit" name="unit" required data-material-unit><option value="">Select unit</option><?php foreach ($materialUnits as $unit): ?><option value="<?php echo htmlspecialchars($unit); ?>"<?php echo $formValues['unit'] === $unit ? ' selected' : ''; ?>><?php echo htmlspecialchars($unit); ?></option><?php endforeach; ?></select><span class="materials-field-error" data-material-error="unit" aria-live="polite"><?php echo htmlspecialchars((string)($formErrors['unit'] ?? '')); ?></span></div>
                    <div class="input-group"><label for="reorder_level">Low Stock Alert Level <span class="materials-info-tooltip" tabindex="0" role="img" aria-label="Shows a Low Stock warning when available quantity reaches this level or lower." data-tooltip="Shows a Low Stock warning when available quantity reaches this level or lower.">i</span> <span class="materials-unit-change-message" data-unit-change-message aria-live="polite"></span></label><input id="reorder_level" name="reorder_level" type="number" min="1" step="1" required inputmode="numeric" value="<?php echo htmlspecialchars($formValues['reorder_level']); ?>" data-reorder-level><span class="materials-field-error" data-material-error="reorder_level" aria-live="polite"><?php echo htmlspecialchars((string)($formErrors['reorder_level'] ?? '')); ?></span></div>
                </div>
                <div class="form-actions"><button type="submit" class="btn-primary">Add Material</button></div>
            </form>
        </section>
        <section class="form-panel">
            <h2 class="section-title-inline">Material Master List</h2>
            <div class="table-responsive"><table class="data-table materials-table"><thead><tr><th>Material Code</th><th>Material Name</th><th>Category</th><th>Unit</th><th>Physical</th><th>Reserved</th><th>Available <span class="materials-table-tooltip" tabindex="0" role="button" aria-expanded="false" aria-label="Physical stock minus quantity reserved for projects." title="Physical stock minus quantity reserved for projects." data-tooltip="Physical stock minus quantity reserved for projects.">i</span></th><th>Low Stock Alert Level</th><th>Status</th></tr></thead><tbody>
            <?php if ($materials === []): ?><tr><td colspan="9" class="materials-empty">No materials yet.</td></tr><?php endif; ?>
            <?php foreach ($materials as $material): ?>
                <?php
                $physical = (float)$material['physical_quantity'];
                $available = (float)$material['available_quantity'];
                $reorder = $material['reorder_level'] === null ? null : (float)$material['reorder_level'];
                $hasReceivedStock = isset($stockedMaterialIds[(int)$material['id']]);
                if ($physical <= 0) {
                    $stockState = $hasReceivedStock ? 'out' : 'no-stock-yet';
                    $stockLabel = $hasReceivedStock ? 'Out of Stock' : 'No Stock Yet';
                } elseif ($available > 0 && $reorder !== null && $available <= $reorder) {
                    $stockState = 'low';
                    $stockLabel = 'Low Stock';
                } else {
                    $stockState = 'available';
                    $stockLabel = 'In Stock';
                }
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
    <?php if ($flash): ?>
        <?php $toastType = in_array($flash['type'] ?? '', ['success', 'warning', 'error'], true) ? $flash['type'] : 'error'; ?>
        <div class="materials-toast-stack" aria-live="polite" aria-atomic="true" data-material-toast-stack>
            <div class="materials-toast materials-toast--<?php echo htmlspecialchars($toastType); ?>" role="<?php echo $toastType === 'error' ? 'alert' : 'status'; ?>" data-material-toast>
                <span class="materials-toast__icon" aria-hidden="true"><?php echo $toastType === 'success' ? '✓' : ($toastType === 'warning' ? '!' : '×'); ?></span>
                <div class="materials-toast__content"><strong><?php echo htmlspecialchars((string)($flash['title'] ?? 'Notice')); ?></strong><span><?php echo htmlspecialchars((string)($flash['message'] ?? '')); ?></span></div>
                <button type="button" aria-label="Close notification" data-material-toast-close>&times;</button>
            </div>
        </div>
    <?php endif; ?>
<?php
}, [
    '/codesamplecaps/INVENTORY_CLERK/css/materials.css',
], '', [
    '/codesamplecaps/INVENTORY_CLERK/js/materials.js',
]);
