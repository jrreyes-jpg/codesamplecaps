<?php
require_once __DIR__ . '/../includes/stock_helpers.php';
require_once __DIR__ . '/../includes/page_shell.php';
require_once __DIR__ . '/../../config/material_stock.php';

$csrfToken = auth_csrf_token('inventory_clerk_material_stock_in');
$flashKey = 'inventory_clerk_material_stock_flash';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'inventory_clerk_material_stock_in')) {
            throw new RuntimeException('Security check failed. Please try again.');
        }
        material_stock_add_stock_in($conn, (int)($_POST['material_id'] ?? 0), (float)($_POST['quantity'] ?? 0), trim((string)($_POST['remarks'] ?? '')) ?: null, (int)$_SESSION['user_id']);
        $_SESSION[$flashKey] = ['type' => 'success', 'message' => 'Material Stock In saved.'];
    } catch (Throwable $exception) {
        $_SESSION[$flashKey] = ['type' => 'error', 'message' => $exception->getMessage()];
    }
    header('Location: /codesamplecaps/INVENTORY_CLERK/sidebar/material_stock_in.php'); exit;
}
$flash = $_SESSION[$flashKey] ?? null; unset($_SESSION[$flashKey]); $materials = material_stock_fetch_active_materials($conn);
?>
<?php inventory_clerk_render_page('Material Stock In', function () use ($flash, $csrfToken, $materials): void { ?>
<div class="page-stack"><section class="form-panel"><h1 class="section-title-inline">Material Stock In</h1><?php if ($flash): ?><div class="alert <?php echo $flash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>"><?php echo htmlspecialchars($flash['message']); ?></div><?php endif; ?><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><div class="form-grid"><div class="input-group"><label>Material</label><select name="material_id" required><option value="">Select material</option><?php foreach ($materials as $material): ?><option value="<?php echo (int)$material['id']; ?>"><?php echo htmlspecialchars($material['material_code'] . ' | ' . $material['material_name'] . ' | Physical: ' . $material['physical_quantity'] . ' ' . $material['unit']); ?></option><?php endforeach; ?></select></div><div class="input-group"><label>Quantity In</label><input name="quantity" type="number" min="0.01" step="0.01" required></div><div class="input-group"><label>Remarks</label><input name="remarks" maxlength="2000"></div></div><div class="form-actions"><button class="btn-primary">Save Material Stock In</button></div></form></section></div>
<?php }); ?>
