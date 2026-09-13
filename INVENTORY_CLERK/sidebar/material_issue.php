<?php
require_once __DIR__ . '/../includes/stock_helpers.php';
require_once __DIR__ . '/../includes/page_shell.php';
require_once __DIR__ . '/../../config/material_stock.php';

$csrfToken = auth_csrf_token('inventory_clerk_material_issue');
$flashKey = 'inventory_clerk_material_issue_flash';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'inventory_clerk_material_issue')) { throw new RuntimeException('Security check failed. Please try again.'); }
        $quantity = (float)($_POST['quantity'] ?? 0);
        $remarks = trim((string)($_POST['remarks'] ?? '')) ?: null;
        if (($_POST['issue_type'] ?? '') === 'manual_stock_out') {
            material_stock_manual_stock_out($conn, (int)($_POST['material_id'] ?? 0), $quantity, $remarks, (int)$_SESSION['user_id']);
            $_SESSION[$flashKey] = ['type' => 'success', 'message' => 'Manual Material Stock Out saved.'];
        } else {
            material_stock_issue_reserved($conn, (int)($_POST['reservation_id'] ?? 0), $quantity, $remarks, (int)$_SESSION['user_id']);
            $_SESSION[$flashKey] = ['type' => 'success', 'message' => 'Reserved material issued to project.'];
        }
    } catch (Throwable $exception) { $_SESSION[$flashKey] = ['type' => 'error', 'message' => $exception->getMessage()]; }
    header('Location: /codesamplecaps/INVENTORY_CLERK/sidebar/material_issue.php'); exit;
}
$flash = $_SESSION[$flashKey] ?? null; unset($_SESSION[$flashKey]);
$reservations = [];
$result = $conn->query("SELECT r.id, r.required_quantity, r.reserved_quantity, r.issued_quantity, p.project_name, m.material_name, m.unit FROM project_material_reservations r INNER JOIN projects p ON p.id = r.project_id INNER JOIN materials m ON m.id = r.material_id WHERE r.status = 'active' AND r.reserved_quantity > 0 ORDER BY p.created_at ASC, r.id ASC");
if ($result) { $reservations = $result->fetch_all(MYSQLI_ASSOC); }
$materials = material_stock_fetch_active_materials($conn);
?>
<?php inventory_clerk_render_page('Material Issue', function () use ($flash, $csrfToken, $reservations, $materials): void { ?>
<div class="page-stack"><section class="form-panel"><h1 class="section-title-inline">Issue Reserved Material</h1><?php if ($flash): ?><div class="alert <?php echo $flash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>"><?php echo htmlspecialchars($flash['message']); ?></div><?php endif; ?><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><input type="hidden" name="issue_type" value="project_issue"><div class="form-grid"><div class="input-group"><label>Project Material Reservation</label><select name="reservation_id" required><option value="">Select reservation</option><?php foreach ($reservations as $reservation): ?><option value="<?php echo (int)$reservation['id']; ?>"><?php echo htmlspecialchars($reservation['project_name'] . ' | ' . $reservation['material_name'] . ' | Reserved: ' . $reservation['reserved_quantity'] . ' ' . $reservation['unit']); ?></option><?php endforeach; ?></select></div><div class="input-group"><label>Quantity to Issue</label><input name="quantity" type="number" min="0.01" step="0.01" required></div><div class="input-group"><label>Remarks</label><input name="remarks" maxlength="2000"></div></div><div class="form-actions"><button class="btn-primary">Issue to Project</button></div></form></section><section class="form-panel"><h2 class="section-title-inline">Manual Material Stock Out</h2><p>Only unreserved material can be removed here.</p><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><input type="hidden" name="issue_type" value="manual_stock_out"><div class="form-grid"><div class="input-group"><label>Material</label><select name="material_id" required><option value="">Select material</option><?php foreach ($materials as $material): ?><option value="<?php echo (int)$material['id']; ?>"><?php echo htmlspecialchars($material['material_code'] . ' | ' . $material['material_name'] . ' | Available: ' . $material['available_quantity'] . ' ' . $material['unit']); ?></option><?php endforeach; ?></select></div><div class="input-group"><label>Quantity</label><input name="quantity" type="number" min="0.01" step="0.01" required></div><div class="input-group"><label>Reason</label><input name="remarks" maxlength="2000" required></div></div><div class="form-actions"><button class="btn-secondary">Save Manual Stock Out</button></div></form></section></div>
<?php }); ?>
