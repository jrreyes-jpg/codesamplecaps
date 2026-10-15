<?php
require_once __DIR__ . '/../../../../config/auth_middleware.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/material_stock.php';
require_once __DIR__ . '/../../../../config/material_delivery_verification.php';

require_role('admin');

$csrfToken = auth_csrf_token('admin_material_stock_in');
$requestToken = material_stock_issue_once_token('admin_material_stock_in');
$flashKey = 'admin_material_stock_in_flash';
$postText = static fn(mixed $value): string => is_string($value) || is_numeric($value) ? trim((string)$value) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $materialId = filter_var($postText($_POST['material_id'] ?? ''), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    $quantity = $postText($_POST['quantity'] ?? '');
    $supplierName = $postText($_POST['supplier_name'] ?? '');
    $receiptNo = $postText($_POST['receipt_no'] ?? '');
    $remarks = $postText($_POST['remarks'] ?? '');

    try {
        if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'admin_material_stock_in')) {
            throw new RuntimeException('Security check failed. Please try again.');
        }
        if (!material_stock_consume_once_token('admin_material_stock_in', $_POST['request_token'] ?? null)) {
            throw new RuntimeException('This Stock In form was already sent. Please try again.');
        }
        material_delivery_verification_create(
            $conn,
            $materialId,
            $quantity,
            $supplierName,
            $receiptNo,
            $remarks,
            (int)$_SESSION['user_id']
        );
        $_SESSION[$flashKey] = [
            'type' => 'success',
            'message' => 'Delivery saved for Clerk verification. Stock has not changed yet.',
        ];
    } catch (Throwable $exception) {
        $_SESSION[$flashKey] = [
            'type' => 'error',
            'message' => $exception->getMessage(),
            'values' => ['material_id' => $materialId, 'quantity' => $quantity, 'supplier_name' => $supplierName, 'receipt_no' => $receiptNo, 'remarks' => $remarks],
        ];
    }

    header('Location: /codesamplecaps/ADMIN/sidebar/inventory/php/material_stock_in.php');
    exit;
}

$flash = $_SESSION[$flashKey] ?? null;
unset($_SESSION[$flashKey]);
$values = is_array($flash['values'] ?? null) ? $flash['values'] : ['material_id' => 0, 'quantity' => '', 'supplier_name' => '', 'receipt_no' => '', 'remarks' => ''];
$materials = material_stock_fetch_active_materials($conn);
$deliveryReady = material_delivery_verification_is_ready($conn);
$deliveries = material_delivery_verification_fetch($conn);

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
                    <p>Record the supplier delivery receipt. The Clerk will count first before stock is added.</p>
                </div>
                <a class="btn-secondary" href="/codesamplecaps/ADMIN/sidebar/inventory/php/inventory.php">Back to Inventory</a>
            </div>

            <?php if (!$deliveryReady): ?>
                <div class="alert alert-error">Delivery verification setup is not ready. Run the new database migration first.</div>
            <?php endif; ?>

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
                        <label for="quantity">Expected Quantity <span class="required">*</span></label>
                        <input id="quantity" name="quantity" type="number" min="0.01" step="0.01" inputmode="decimal" required value="<?php echo htmlspecialchars((string)$values['quantity']); ?>">
                    </div>
                    <div class="input-group">
                        <label for="supplier_name">Supplier <span class="required">*</span></label>
                        <input id="supplier_name" name="supplier_name" maxlength="180" required placeholder="Supplier name" value="<?php echo htmlspecialchars((string)$values['supplier_name']); ?>">
                    </div>
                    <div class="input-group material-master-description">
                        <label for="receipt_no">Delivery Receipt No. <span class="required">*</span></label>
                        <input id="receipt_no" name="receipt_no" maxlength="120" required placeholder="Example: DR-1001" value="<?php echo htmlspecialchars((string)$values['receipt_no']); ?>">
                    </div>
                    <div class="input-group material-master-description">
                        <label for="remarks">Admin Notes (Optional)</label>
                        <input id="remarks" name="remarks" maxlength="500" placeholder="Optional delivery note" value="<?php echo htmlspecialchars((string)$values['remarks']); ?>">
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn-primary" <?php echo $deliveryReady ? '' : 'disabled'; ?>>Send for Clerk Verification</button>
                </div>
            </form>
        </section>

        <section class="form-panel admin-material-delivery-list">
            <div class="inventory-report-heading">
                <div>
                    <h2 class="section-title-inline">Delivery Verification</h2>
                    <p>Stock updates only after the Clerk verifies the actual quantity.</p>
                </div>
            </div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead><tr><th>Material</th><th>Expected</th><th>Supplier / Receipt</th><th>Status</th><th>Verified</th><th>Difference</th></tr></thead>
                    <tbody>
                    <?php if ($deliveries === []): ?>
                        <tr><td colspan="6" class="table-empty-cell">No delivery records yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($deliveries as $delivery): ?>
                        <?php $difference = $delivery['verified_quantity'] === null ? null : (float)$delivery['verified_quantity'] - (float)$delivery['expected_quantity']; ?>
                        <tr>
                            <td data-label="Material"><strong><?php echo htmlspecialchars((string)$delivery['material_name']); ?></strong><small><?php echo htmlspecialchars((string)$delivery['material_code']); ?></small></td>
                            <td data-label="Expected"><?php echo htmlspecialchars(material_delivery_verification_format_quantity((float)$delivery['expected_quantity']) . ' ' . (string)$delivery['unit']); ?></td>
                            <td data-label="Supplier / Receipt"><?php echo htmlspecialchars(trim((string)$delivery['supplier_name']) ?: '—'); ?><small><?php echo htmlspecialchars((string)$delivery['delivery_receipt_no']); ?></small></td>
                            <td data-label="Status"><span class="material-delivery-status material-delivery-status--<?php echo htmlspecialchars((string)$delivery['status']); ?>"><?php echo $delivery['status'] === 'verified' ? 'Verified' : 'For Verification'; ?></span></td>
                            <td data-label="Verified"><?php echo $delivery['verified_quantity'] !== null ? htmlspecialchars(material_delivery_verification_format_quantity((float)$delivery['verified_quantity']) . ' ' . (string)$delivery['unit']) : 'Waiting for Clerk'; ?></td>
                            <td data-label="Difference"><?php echo $difference === null ? '—' : htmlspecialchars(($difference > 0 ? '+' : '') . material_delivery_verification_format_quantity($difference) . ' ' . (string)$delivery['unit']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</main>
<?php include __DIR__ . '/../../../layout/footer.php'; ?>
