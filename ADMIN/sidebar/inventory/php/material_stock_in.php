<?php
require_once __DIR__ . '/../../../../config/auth_middleware.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/material_stock.php';

require_role('admin');

$csrfToken = auth_csrf_token('admin_material_stock_in');
$requestToken = material_stock_issue_once_token('admin_material_stock_in');
$flashKey = 'admin_material_stock_in_flash';
$postText = static fn(mixed $value): string => is_string($value) || is_numeric($value) ? trim((string)$value) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $materialId = filter_var($postText($_POST['material_id'] ?? ''), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    $quantity = $postText($_POST['quantity'] ?? '');
    $remarks = $postText($_POST['remarks'] ?? '');

    try {
        if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'admin_material_stock_in')) {
            throw new RuntimeException('Security check failed. Please try again.');
        }
        if (!material_stock_consume_once_token('admin_material_stock_in', $_POST['request_token'] ?? null)) {
            throw new RuntimeException('This Stock In form was already sent. Please try again.');
        }
        if ($remarks === '') {
            throw new RuntimeException('Enter a receipt reference or remarks.');
        }

        $saved = material_stock_add_stock_in($conn, $materialId, $quantity, $remarks, (int)$_SESSION['user_id']);
        $_SESSION[$flashKey] = [
            'type' => 'success',
            'message' => rtrim(rtrim(number_format((float)$saved['quantity'], 2, '.', ','), '0'), '.') . ' ' . $saved['unit'] . ' added to ' . $saved['material_name'] . '.',
        ];
    } catch (Throwable $exception) {
        $_SESSION[$flashKey] = [
            'type' => 'error',
            'message' => $exception->getMessage(),
            'values' => ['material_id' => $materialId, 'quantity' => $quantity, 'remarks' => $remarks],
        ];
    }

    header('Location: /codesamplecaps/ADMIN/sidebar/inventory/php/material_stock_in.php');
    exit;
}

$flash = $_SESSION[$flashKey] ?? null;
unset($_SESSION[$flashKey]);
$values = is_array($flash['values'] ?? null) ? $flash['values'] : ['material_id' => 0, 'quantity' => '', 'remarks' => ''];
$materials = material_stock_fetch_active_materials($conn);

$adminPageTitle = 'Material Stock In - Admin';
$adminCssFiles = [
    '/codesamplecaps/ADMIN/common/css/admin-common.css',
    '/codesamplecaps/ADMIN/sidebar/inventory/css/inventory.css',
];
$adminJsFiles = ['/codesamplecaps/ADMIN/common/js/admin-common.js'];
include __DIR__ . '/../../../layout/header.php';
include __DIR__ . '/../../../admin_sidebar.php';
?>
<main class="main-content inventory-main-content">
    <div class="page-stack inventory-page-stack">
        <nav class="resource-page-tabs inventory-page-tabs" aria-label="Inventory sections">
            <a href="/codesamplecaps/ADMIN/sidebar/inventory/php/inventory.php">Consumables</a>
            <a href="/codesamplecaps/ADMIN/sidebar/inventory/php/inventory.php?view=history">Stock History</a>
        </nav>

        <?php if (is_array($flash)): ?>
            <div class="alert <?php echo ($flash['type'] ?? '') === 'success' ? 'alert-success' : 'alert-error'; ?>">
                <?php echo htmlspecialchars((string)($flash['message'] ?? 'Unable to save Stock In.')); ?>
            </div>
        <?php endif; ?>

        <section class="form-panel admin-material-stock-in-panel">
            <div class="inventory-report-heading">
                <div>
                    <h1 class="section-title-inline">Material Stock In</h1>
                    <p>Add delivered material to the system stock. The Inventory Clerk will count it later.</p>
                </div>
                <a class="btn-secondary" href="/codesamplecaps/ADMIN/sidebar/inventory/php/inventory.php">Back to Inventory</a>
            </div>

            <form method="POST" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="request_token" value="<?php echo htmlspecialchars($requestToken); ?>">
                <div class="form-grid">
                    <div class="input-group">
                        <label for="material_id">Material <span class="required">*</span></label>
                        <select id="material_id" name="material_id" required>
                            <option value="">Select material</option>
                            <?php foreach ($materials as $material): ?>
                                <option value="<?php echo (int)$material['id']; ?>"<?php echo (int)$values['material_id'] === (int)$material['id'] ? ' selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string)$material['material_code'] . ' | ' . (string)$material['material_name'] . ' | ' . (string)$material['unit']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="input-group">
                        <label for="quantity">Quantity In <span class="required">*</span></label>
                        <input id="quantity" name="quantity" type="number" min="0.01" step="0.01" inputmode="decimal" required value="<?php echo htmlspecialchars((string)$values['quantity']); ?>">
                    </div>
                    <div class="input-group material-master-description">
                        <label for="remarks">Receipt Reference / Remarks <span class="required">*</span></label>
                        <input id="remarks" name="remarks" maxlength="2000" required placeholder="Delivery receipt, supplier, or note" value="<?php echo htmlspecialchars((string)$values['remarks']); ?>">
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn-primary">Save Stock In</button>
                </div>
            </form>
        </section>
    </div>
</main>
<?php include __DIR__ . '/../../../layout/footer.php'; ?>
