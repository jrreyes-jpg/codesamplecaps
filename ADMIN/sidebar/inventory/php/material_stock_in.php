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
    $postedMaterialIds = is_array($_POST['material_ids'] ?? null) ? $_POST['material_ids'] : [];
    $postedQuantities = is_array($_POST['expected_quantities'] ?? null) ? $_POST['expected_quantities'] : [];
    $materialRows = [];
    foreach ($postedMaterialIds as $index => $postedMaterialId) {
        $materialRows[] = [
            'material_id' => filter_var($postText($postedMaterialId), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0,
            'expected_quantity' => $postText($postedQuantities[$index] ?? ''),
        ];
    }
    $supplierName = $postText($_POST['supplier_name'] ?? '');
    $receiptNo = $postText($_POST['receipt_no'] ?? '');
    $remarks = $postText($_POST['remarks'] ?? '');

    try {
        if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'admin_material_stock_in')) {
            throw new RuntimeException('Security check failed. Please try again.');
        }
        if (!material_stock_consume_once_token('admin_material_stock_in', $_POST['request_token'] ?? null)) {
            throw new RuntimeException('This delivery receipt was already sent. Please try again.');
        }
        $savedCount = material_delivery_verification_create_batch(
            $conn,
            $materialRows,
            $supplierName,
            $receiptNo,
            $remarks,
            (int)$_SESSION['user_id']
        );
        $_SESSION[$flashKey] = [
            'type' => 'success',
            'message' => $savedCount . ' material' . ($savedCount === 1 ? '' : 's') . ' sent to Clerk. Stock has not changed yet.',
        ];
    } catch (Throwable $exception) {
        $_SESSION[$flashKey] = [
            'type' => 'error',
            'message' => $exception->getMessage(),
            'values' => ['materials' => $materialRows, 'supplier_name' => $supplierName, 'receipt_no' => $receiptNo, 'remarks' => $remarks],
        ];
    }

    header('Location: /codesamplecaps/ADMIN/sidebar/inventory/php/material_stock_in.php');
    exit;
}

$flash = $_SESSION[$flashKey] ?? null;
unset($_SESSION[$flashKey]);
$values = is_array($flash['values'] ?? null) ? $flash['values'] : ['materials' => [['material_id' => 0, 'expected_quantity' => '']], 'supplier_name' => '', 'receipt_no' => '', 'remarks' => ''];
$materialRows = is_array($values['materials'] ?? null) && $values['materials'] !== []
    ? $values['materials']
    : [['material_id' => 0, 'expected_quantity' => '']];
$materials = material_stock_fetch_active_materials($conn);
$deliveryReady = material_delivery_verification_is_ready($conn);
$deliveries = material_delivery_verification_fetch($conn);

$adminPageTitle = 'Log Delivery Receipt - Admin';
$adminBodyClass = 'admin-light-page';
$adminCssFiles = [
    '/codesamplecaps/ADMIN/common/css/admin-common.css?v=20261112-4',
    '/codesamplecaps/ADMIN/sidebar/inventory/css/inventory.css?v=20261117-4',
    '/codesamplecaps/SHARED/modal/css/modal-scroll-lock.css?v=20261120-1',
];
$adminJsFiles = [
    '/codesamplecaps/ADMIN/common/js/admin-common.js',
    '/codesamplecaps/SHARED/modal/js/modal-scroll-lock.js?v=20261120-1',
    '/codesamplecaps/SHARED/modal/js/modal-controller.js?v=20261120-1',
    '/codesamplecaps/ADMIN/sidebar/inventory/js/material_stock_in.js?v=20261120-1',
];
include __DIR__ . '/../../../layout/header.php';
include __DIR__ . '/../../../admin_sidebar.php';
?>
<main class="main-content admin-dashboard-content inventory-main-content inventory-light-main shared-page-surface">
    <div class="shared-page-container page-stack inventory-page-stack material-stock-in-page">
        <?php if (is_array($flash)): ?>
            <div class="alert <?php echo ($flash['type'] ?? '') === 'success' ? 'alert-success' : 'alert-error'; ?>">
                <?php echo htmlspecialchars((string)($flash['message'] ?? 'Unable to save delivery receipt.')); ?>
            </div>
        <?php endif; ?>

        <section class="form-panel admin-material-stock-in-panel">
            <div class="inventory-report-heading material-stock-in-heading">
                <div>
                    <h1 class="section-title-inline">Log Delivery Receipt</h1>
                </div>
                <div class="inventory-report-heading__actions">
                    <a class="btn-secondary" href="/codesamplecaps/ADMIN/sidebar/inventory/php/inventory.php?view=history">Stock History</a>
                    <a class="btn-secondary" href="/codesamplecaps/ADMIN/sidebar/inventory/php/inventory.php">Back to Inventory</a>
                </div>
            </div>

            <?php if (!$deliveryReady): ?>
                <div class="alert alert-error">Delivery verification setup is not ready. Run the new database migration first.</div>
            <?php endif; ?>

            <form method="POST" data-stock-in-form data-delivery-ready="<?php echo $deliveryReady ? 'true' : 'false'; ?>" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="request_token" value="<?php echo htmlspecialchars($requestToken); ?>">
                <div class="material-delivery-meta-grid">
                    <div class="input-group">
                        <label for="receipt_no">DR No. <span class="required-indicator" aria-hidden="true">*</span></label>
                        <input id="receipt_no" name="receipt_no" maxlength="120" required placeholder="Example: DR-1001" value="<?php echo htmlspecialchars((string)$values['receipt_no']); ?>">
                    </div>
                    <div class="input-group">
                        <label for="supplier_name">Supplier <span class="required-indicator" aria-hidden="true">*</span></label>
                        <input id="supplier_name" name="supplier_name" maxlength="180" required placeholder="Supplier name" value="<?php echo htmlspecialchars((string)$values['supplier_name']); ?>">
                    </div>
                    <div class="input-group">
                        <label for="remarks">Notes (Optional)</label>
                        <input id="remarks" name="remarks" maxlength="500" placeholder="Optional delivery note" value="<?php echo htmlspecialchars((string)$values['remarks']); ?>">
                    </div>
                </div>

                <div class="material-delivery-rows" data-stock-in-rows>
                    <?php foreach ($materialRows as $rowIndex => $row): ?>
                    <div class="material-delivery-row" data-stock-in-row>
                        <div class="input-group">
                            <label for="material_id_<?php echo $rowIndex; ?>">Material <span class="required-indicator" aria-hidden="true">*</span></label>
                            <select id="material_id_<?php echo $rowIndex; ?>" name="material_ids[]" data-stock-in-material required>
                                <option value="" disabled<?php echo (int)($row['material_id'] ?? 0) === 0 ? ' selected' : ''; ?>>Select material</option>
                            <?php foreach ($materials as $material): ?>
                                <option value="<?php echo (int)$material['id']; ?>" data-material-unit="<?php echo htmlspecialchars((string)$material['unit'], ENT_QUOTES, 'UTF-8'); ?>" data-whole-number="<?php echo material_stock_unit_requires_whole_number((string)$material['unit']) ? 'true' : 'false'; ?>"<?php echo (int)($row['material_id'] ?? 0) === (int)$material['id'] ? ' selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string)$material['material_code'] . ' | ' . (string)$material['material_name'] . ' | ' . (string)$material['unit']); ?>
                                </option>
                            <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <label for="quantity_<?php echo $rowIndex; ?>">Expected Qty <span class="required-indicator" aria-hidden="true">*</span></label>
                            <div class="material-quantity-control">
                                <input id="quantity_<?php echo $rowIndex; ?>" name="expected_quantities[]" type="number" min="0.01" step="0.01" inputmode="decimal" data-stock-in-quantity required value="<?php echo htmlspecialchars((string)($row['expected_quantity'] ?? '')); ?>">
                                <span data-stock-in-unit>Unit</span>
                            </div>
                        </div>
                        <button type="button" class="material-row-remove" data-stock-in-row-remove aria-label="Remove material"<?php echo count($materialRows) === 1 ? ' disabled' : ''; ?>>&times;</button>
                    </div>
                    <?php endforeach; ?>
                </div>

                <template data-stock-in-row-template>
                    <div class="material-delivery-row" data-stock-in-row>
                        <div class="input-group">
                            <label>Material <span class="required-indicator" aria-hidden="true">*</span></label>
                            <select name="material_ids[]" data-stock-in-material required>
                                <option value="" disabled selected>Select material</option>
                                <?php foreach ($materials as $material): ?>
                                <option value="<?php echo (int)$material['id']; ?>" data-material-unit="<?php echo htmlspecialchars((string)$material['unit'], ENT_QUOTES, 'UTF-8'); ?>" data-whole-number="<?php echo material_stock_unit_requires_whole_number((string)$material['unit']) ? 'true' : 'false'; ?>"><?php echo htmlspecialchars((string)$material['material_code'] . ' | ' . (string)$material['material_name'] . ' | ' . (string)$material['unit']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <label>Expected Qty <span class="required-indicator" aria-hidden="true">*</span></label>
                            <div class="material-quantity-control">
                                <input name="expected_quantities[]" type="number" min="0.01" step="0.01" inputmode="decimal" data-stock-in-quantity required>
                                <span data-stock-in-unit>Unit</span>
                            </div>
                        </div>
                        <button type="button" class="material-row-remove" data-stock-in-row-remove aria-label="Remove material">&times;</button>
                    </div>
                </template>

                <div class="material-delivery-action-bar">
                    <button type="button" class="btn-secondary" data-stock-in-add>+ Add Material</button>
                    <button type="submit" class="btn-primary" data-stock-in-submit disabled>Submit Delivery Receipt</button>
                </div>
            </form>
        </section>

        <div class="material-remove-modal" data-stock-in-confirm-modal hidden>
            <section class="material-remove-modal__panel" role="dialog" aria-modal="true" aria-labelledby="stockInConfirmTitle" aria-describedby="stockInConfirmMessage">
                <h2 id="stockInConfirmTitle">Send delivery for verification?</h2>
                <p id="stockInConfirmMessage">The Clerk will count the actual quantity. Stock will not change until the Clerk verifies it.</p>
                <div class="material-remove-modal__actions">
                    <button type="button" class="btn-secondary" data-stock-in-confirm-close>Cancel</button>
                    <button type="button" class="btn-primary" data-stock-in-confirm-submit>Submit Delivery Receipt</button>
                </div>
            </section>
        </div>

        <section class="form-panel admin-material-delivery-list">
            <div class="inventory-report-heading">
                <div>
                    <h2 class="section-title-inline">Delivery Verification</h2>
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
                            <td data-label="Status"><span class="material-delivery-status material-delivery-status--<?php echo htmlspecialchars((string)$delivery['status']); ?>"><?php echo match ((string)$delivery['status']) { 'verified' => 'Verified', 'discrepancy' => 'Discrepancy', default => 'Pending Verification' }; ?></span></td>
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
