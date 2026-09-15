<?php
require_once __DIR__ . '/../includes/stock_helpers.php';
require_once __DIR__ . '/../includes/page_shell.php';
require_once __DIR__ . '/../../config/material_stock.php';

$csrfToken = auth_csrf_token('inventory_clerk_material_stock_in');
$flashKey = 'inventory_clerk_material_stock_flash';

function inventory_clerk_material_stock_in_display_quantity(float $quantity, string $unit): string
{
    $amount = rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
    $isOne = abs($quantity - 1.0) < 0.00001;
    $displayUnit = match ($unit) {
        'pcs' => $isOne ? 'pc' : 'pcs',
        'box' => $isOne ? 'box' : 'boxes',
        'kg' => 'kg',
        default => $isOne ? $unit : $unit . 's',
    };

    return $amount . ' ' . $displayUnit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedMaterialId = (int)($_POST['material_id'] ?? 0);
    $submittedQuantity = trim((string)($_POST['quantity'] ?? ''));
    $submittedRemarks = trim((string)($_POST['remarks'] ?? ''));
    try {
        if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'inventory_clerk_material_stock_in')) {
            throw new RuntimeException('Security check failed. Please try again.');
        }
        $savedStockIn = material_stock_add_stock_in($conn, $submittedMaterialId, $submittedQuantity, $submittedRemarks ?: null, (int)$_SESSION['user_id']);
        $_SESSION[$flashKey] = [
            'type' => 'success',
            'title' => 'Stock added',
            'message' => inventory_clerk_material_stock_in_display_quantity((float)$savedStockIn['quantity'], (string)$savedStockIn['unit']) . ' added to ' . $savedStockIn['material_name'] . '.',
        ];
    } catch (Throwable $exception) {
        $message = $exception->getMessage();
        $_SESSION[$flashKey] = [
            'type' => 'error',
            'title' => 'Cannot add stock',
            'message' => $message,
            'values' => [
                'material_id' => $submittedMaterialId,
                'quantity' => $submittedQuantity,
                'remarks' => $submittedRemarks,
            ],
            'quantity_error' => str_starts_with($message, 'Stock In quantity') || str_starts_with($message, 'Quantity In'),
        ];
    }
    $redirect = '/codesamplecaps/INVENTORY_CLERK/dashboards/material_stock_in.php';
    if ($submittedMaterialId > 0) {
        $redirect .= '?material_id=' . $submittedMaterialId;
    }
    header('Location: ' . $redirect);
    exit;
}
$flash = $_SESSION[$flashKey] ?? null;
unset($_SESSION[$flashKey]);
$materials = material_stock_fetch_active_materials($conn);
$selectedMaterialId = filter_input(INPUT_GET, 'material_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$formValues = is_array($flash['values'] ?? null) ? $flash['values'] : ['quantity' => '', 'remarks' => ''];
if (!isset($formValues['quantity'])) {
    $formValues['quantity'] = '';
}
if (!isset($formValues['remarks'])) {
    $formValues['remarks'] = '';
}
$selectedMaterialIsActive = $selectedMaterialId === 0;
foreach ($materials as $material) {
    if ((int)$material['id'] === $selectedMaterialId) {
        $selectedMaterialIsActive = true;
        break;
    }
}
if (!$selectedMaterialIsActive) {
    $selectedMaterialId = 0;
    $flash = ['type' => 'error', 'title' => 'Cannot add stock', 'message' => 'Selected material is unavailable for Stock In.'];
}
?>
<?php inventory_clerk_render_page('Material Stock In', function () use ($flash, $csrfToken, $materials, $selectedMaterialId, $formValues): void { ?>
<div class="page-stack material-stock-in-page">
    <section class="form-panel">
        <h1 class="section-title-inline">Material Stock In</h1>
        <form method="POST" class="material-stock-in-form" data-material-stock-in-form>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <div class="form-grid">
                <div class="input-group">
                    <label for="material_id">Material</label>
                    <select id="material_id" name="material_id" required data-material-stock-in-material>
                        <option value="">Select material</option>
                        <?php foreach ($materials as $material): ?>
                            <option value="<?php echo (int)$material['id']; ?>" data-unit="<?php echo htmlspecialchars((string)$material['unit']); ?>"<?php echo (int)$material['id'] === $selectedMaterialId ? ' selected' : ''; ?>><?php echo htmlspecialchars($material['material_code'] . ' | ' . $material['material_name'] . ' | Physical: ' . inventory_clerk_material_stock_in_display_quantity((float)$material['physical_quantity'], (string)$material['unit'])); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-group">
                    <label for="quantity">Quantity In</label>
                    <input id="quantity" name="quantity" type="number" min="0.01" step="0.01" required inputmode="decimal" value="<?php echo htmlspecialchars((string)$formValues['quantity']); ?>" data-material-stock-in-quantity>
                    <span class="material-stock-in-field-error" data-material-stock-in-error aria-live="polite"><?php echo !empty($flash['quantity_error']) ? htmlspecialchars((string)$flash['message']) : ''; ?></span>
                </div>
                <div class="input-group">
                    <label for="remarks">Remarks (Optional)</label>
                    <input id="remarks" name="remarks" maxlength="2000" value="<?php echo htmlspecialchars((string)$formValues['remarks']); ?>">
                </div>
            </div>
            <div class="form-actions"><button type="submit" class="btn-primary">Save Material Stock In</button></div>
        </form>
    </section>
</div>
<?php if ($flash): ?>
    <?php $toastType = $flash['type'] === 'success' ? 'success' : 'error'; ?>
    <div class="material-stock-in-toast-stack" aria-live="polite" aria-atomic="true">
        <div class="material-stock-in-toast material-stock-in-toast--<?php echo htmlspecialchars($toastType); ?>" role="<?php echo $toastType === 'error' ? 'alert' : 'status'; ?>" data-material-stock-in-toast>
            <span class="material-stock-in-toast__icon" aria-hidden="true"><?php echo $toastType === 'success' ? '✓' : '!'; ?></span>
            <div><strong><?php echo htmlspecialchars((string)($flash['title'] ?? 'Notice')); ?></strong><span><?php echo htmlspecialchars((string)$flash['message']); ?></span></div>
            <button type="button" aria-label="Close notification" data-material-stock-in-toast-close>&times;</button>
        </div>
    </div>
<?php endif; ?>
<?php }, [
    '/codesamplecaps/INVENTORY_CLERK/css/material_stock_in.css',
], '', [
    '/codesamplecaps/INVENTORY_CLERK/js/material_stock_in.js',
]); ?>
