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
    $deliveryDate = $postText($_POST['delivery_date'] ?? '');
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
            (int)$_SESSION['user_id'],
            $deliveryDate
        );
        $_SESSION[$flashKey] = [
            'type' => 'success',
            'message' => 'Delivery receipt sent to the Inventory Clerk for verification. Stock is not updated yet.',
        ];
    } catch (Throwable $exception) {
        $_SESSION[$flashKey] = [
            'type' => 'error',
            'message' => $exception->getMessage(),
            'values' => ['materials' => $materialRows, 'supplier_name' => $supplierName, 'receipt_no' => $receiptNo, 'delivery_date' => $deliveryDate, 'remarks' => $remarks],
        ];
    }

    header('Location: /codesamplecaps/ADMIN/sidebar/inventory/php/material_stock_in.php');
    exit;
}

$flash = $_SESSION[$flashKey] ?? null;
unset($_SESSION[$flashKey]);
$values = is_array($flash['values'] ?? null) ? $flash['values'] : ['materials' => [['material_id' => 0, 'expected_quantity' => '']], 'supplier_name' => '', 'receipt_no' => '', 'delivery_date' => date('Y-m-d'), 'remarks' => ''];
$materialRows = is_array($values['materials'] ?? null) && $values['materials'] !== []
    ? $values['materials']
    : [['material_id' => 0, 'expected_quantity' => '']];
$materials = material_stock_fetch_active_materials($conn);
$deliveryReady = material_delivery_verification_is_ready($conn) && material_delivery_verification_has_delivery_date($conn);
$deliveries = material_delivery_verification_fetch($conn, 'for_verification');
$deliverySummaries = material_delivery_verification_summarize($deliveries);

$adminPageTitle = 'Log Delivery Receipt - Admin';
$adminBodyClass = 'admin-light-page';
$adminCssFiles = [
    '/codesamplecaps/ADMIN/common/css/admin-common.css?v=20261112-4',
    '/codesamplecaps/SHARED/material-suggestions/css/material-suggestions.css?v=20261120-12',
    '/codesamplecaps/ADMIN/sidebar/inventory/css/inventory.css?v=20261123-11',
    '/codesamplecaps/SHARED/modal/css/modal-scroll-lock.css?v=20261120-1',
];
$adminJsFiles = [
    '/codesamplecaps/ADMIN/common/js/admin-common.js',
    '/codesamplecaps/SHARED/modal/js/modal-scroll-lock.js?v=20261120-1',
    '/codesamplecaps/SHARED/modal/js/modal-controller.js?v=20261120-1',
    '/codesamplecaps/SHARED/material-suggestions/js/material-suggestions.js?v=20261120-13',
    '/codesamplecaps/ADMIN/sidebar/inventory/js/material_stock_in.js?v=20261123-10',
];
include __DIR__ . '/../../../layout/header.php';
include __DIR__ . '/../../../admin_sidebar.php';
?>
<main class="main-content admin-dashboard-content inventory-main-content inventory-light-main shared-page-surface">
    <div class="shared-page-container page-stack inventory-page-stack material-stock-in-page">
        <?php if (is_array($flash)): ?>
            <?php $flashType = ($flash['type'] ?? '') === 'success' ? 'success' : 'error'; ?>
            <div class="shared-toast shared-toast--<?php echo $flashType; ?>" data-shared-toast role="<?php echo $flashType === 'error' ? 'alert' : 'status'; ?>">
                <span><?php echo htmlspecialchars((string)($flash['message'] ?? 'Unable to save delivery receipt.')); ?></span>
                <button type="button" class="shared-toast__close" data-shared-toast-close aria-label="Close notification">&times;</button>
                <span class="shared-toast__progress" aria-hidden="true"></span>
            </div>
        <?php endif; ?>

        <section class="form-panel admin-material-stock-in-panel">
            <a class="admin-sticky-back-button material-stock-in-back" href="/codesamplecaps/ADMIN/sidebar/inventory/php/inventory.php">&larr; Back to Inventory</a>
            <div class="inventory-report-heading material-stock-in-heading">
                <div class="material-stock-in-title">
                    <h1 class="section-title-inline">Log Delivery Receipt</h1>
                </div>
                <a class="btn-secondary material-stock-in-history" href="/codesamplecaps/ADMIN/sidebar/inventory/php/inventory.php?view=history&amp;return=delivery">Stock History</a>
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
                        <input id="receipt_no" name="receipt_no" maxlength="120" required placeholder="Example: DR-1001" title="Use letters, numbers, dash, and slash only." value="<?php echo htmlspecialchars((string)$values['receipt_no']); ?>">
                    </div>
                    <div class="input-group">
                        <label for="supplier_name">Supplier <span class="required-indicator" aria-hidden="true">*</span></label>
                        <input id="supplier_name" name="supplier_name" maxlength="180" required placeholder="Supplier name" title="Use letters, numbers, spaces, &amp;, period, dash, and comma only." value="<?php echo htmlspecialchars((string)$values['supplier_name']); ?>">
                    </div>
                    <div class="input-group">
                        <label for="delivery_date">Delivery Date <span class="required-indicator" aria-hidden="true">*</span></label>
                        <input id="delivery_date" name="delivery_date" type="date" min="<?php echo (new DateTimeImmutable('today'))->modify('-30 days')->format('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required value="<?php echo htmlspecialchars((string)($values['delivery_date'] ?? date('Y-m-d'))); ?>">
                    </div>
                    <div class="input-group">
                        <label for="remarks">Notes (Optional)</label>
                        <input id="remarks" name="remarks" maxlength="500" placeholder="Optional delivery note" value="<?php echo htmlspecialchars((string)$values['remarks']); ?>">
                    </div>
                </div>

                <div class="material-delivery-rows" data-stock-in-rows>
                    <?php foreach ($materialRows as $rowIndex => $row): ?>
                    <div class="material-delivery-row" data-stock-in-row data-material-suggestion-scope>
                        <div class="input-group">
                            <label for="material_search_<?php echo $rowIndex; ?>">Material <span class="required-indicator" aria-hidden="true">*</span></label>
                            <span class="material-combobox" data-material-combobox>
                                <input id="material_search_<?php echo $rowIndex; ?>" type="text" data-material-combobox-input placeholder="Search or select material" autocomplete="off" aria-label="Search or select material">
                                <button type="button" data-material-combobox-toggle aria-label="Search materials"></button>
                                <span class="material-combobox__list" data-material-combobox-list></span>
                            </span>
                            <select class="material-picker-source" name="material_ids[]" data-stock-in-material data-material-suggestion-source required tabindex="-1" aria-hidden="true">
                                <option value="" disabled<?php echo (int)($row['material_id'] ?? 0) === 0 ? ' selected' : ''; ?>>Select material</option>
                            <?php foreach ($materials as $material): ?>
                                <option value="<?php echo (int)$material['id']; ?>" data-material-unit="<?php echo htmlspecialchars((string)$material['unit'], ENT_QUOTES, 'UTF-8'); ?>" data-whole-number="<?php echo material_stock_unit_requires_whole_number((string)$material['unit']) ? 'true' : 'false'; ?>" data-current-stock="<?php echo htmlspecialchars((string)($material['physical_quantity'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>" data-maximum-stock="<?php echo htmlspecialchars((string)($material['maximum_stock_level'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"<?php echo (int)($row['material_id'] ?? 0) === (int)$material['id'] ? ' selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string)$material['material_code'] . ' | ' . (string)$material['material_name'] . ' | ' . (string)$material['unit']); ?>
                                </option>
                            <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <label for="quantity_<?php echo $rowIndex; ?>">Expected Qty <span class="required-indicator" aria-hidden="true">*</span></label>
                            <div class="material-quantity-control">
                                <input id="quantity_<?php echo $rowIndex; ?>" name="expected_quantities[]" type="number" min="0.01" max="<?php echo htmlspecialchars(material_stock_decimal_max()); ?>" step="0.01" inputmode="decimal" data-stock-in-quantity required value="<?php echo htmlspecialchars((string)($row['expected_quantity'] ?? '')); ?>">
                                <span data-stock-in-unit>Unit</span>
                            </div>
                            <small class="material-stock-limit-warning" data-stock-limit-warning hidden></small>
                        </div>
                        <button type="button" class="material-row-remove" data-stock-in-row-remove aria-label="Remove material"<?php echo count($materialRows) === 1 ? ' disabled hidden' : ''; ?>>&times;</button>
                    </div>
                    <?php endforeach; ?>
                </div>

                <template data-stock-in-row-template>
                    <div class="material-delivery-row" data-stock-in-row data-material-suggestion-scope>
                        <div class="input-group">
                            <label>Material <span class="required-indicator" aria-hidden="true">*</span></label>
                            <span class="material-combobox" data-material-combobox>
                                <input type="text" data-material-combobox-input placeholder="Search or select material" autocomplete="off" aria-label="Search or select material">
                                <button type="button" data-material-combobox-toggle aria-label="Search materials"></button>
                                <span class="material-combobox__list" data-material-combobox-list></span>
                            </span>
                            <select class="material-picker-source" name="material_ids[]" data-stock-in-material data-material-suggestion-source required tabindex="-1" aria-hidden="true">
                                <option value="" disabled selected>Select material</option>
                                <?php foreach ($materials as $material): ?>
                                <option value="<?php echo (int)$material['id']; ?>" data-material-unit="<?php echo htmlspecialchars((string)$material['unit'], ENT_QUOTES, 'UTF-8'); ?>" data-whole-number="<?php echo material_stock_unit_requires_whole_number((string)$material['unit']) ? 'true' : 'false'; ?>" data-current-stock="<?php echo htmlspecialchars((string)($material['physical_quantity'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>" data-maximum-stock="<?php echo htmlspecialchars((string)($material['maximum_stock_level'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$material['material_code'] . ' | ' . (string)$material['material_name'] . ' | ' . (string)$material['unit']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <label>Expected Qty <span class="required-indicator" aria-hidden="true">*</span></label>
                            <div class="material-quantity-control">
                                <input name="expected_quantities[]" type="number" min="0.01" max="<?php echo htmlspecialchars(material_stock_decimal_max()); ?>" step="0.01" inputmode="decimal" data-stock-in-quantity required>
                                <span data-stock-in-unit>Unit</span>
                            </div>
                            <small class="material-stock-limit-warning" data-stock-limit-warning hidden></small>
                        </div>
                        <button type="button" class="material-row-remove" data-stock-in-row-remove aria-label="Remove material">&times;</button>
                    </div>
                </template>

                <div class="material-delivery-action-bar">
                    <button type="button" class="btn-secondary" data-stock-in-add>+ Add Material</button>
                    <div class="material-delivery-action-bar__submit">
                        <button type="button" class="btn-secondary" data-stock-in-clear disabled>Clear Form</button>
                        <button type="submit" class="btn-primary" data-stock-in-submit disabled>Send for Verification</button>
                    </div>
                </div>
            </form>
        </section>

        <div class="material-remove-modal" data-stock-in-confirm-modal hidden>
            <section class="material-remove-modal__panel" role="dialog" aria-modal="true" aria-labelledby="stockInConfirmTitle" aria-describedby="stockInConfirmMessage">
                <h2 id="stockInConfirmTitle">Send delivery for verification?</h2>
                <p id="stockInConfirmMessage">The Clerk will count the actual quantity. Stock will not change until the Clerk verifies it.</p>
                <div class="material-remove-modal__actions">
                    <button type="button" class="btn-secondary" data-stock-in-confirm-close>Cancel</button>
                    <button type="button" class="btn-primary" data-stock-in-confirm-submit>Send for Verification</button>
                </div>
            </section>
        </div>

        <div class="material-remove-modal" data-stock-in-clear-modal hidden>
            <section class="material-remove-modal__panel" role="dialog" aria-modal="true" aria-labelledby="stockInClearTitle" aria-describedby="stockInClearMessage">
                <h2 id="stockInClearTitle">Clear this form?</h2>
                <p id="stockInClearMessage">All entered delivery details will be removed.</p>
                <div class="material-remove-modal__actions">
                    <button type="button" class="btn-secondary" data-stock-in-clear-close>Keep Editing</button>
                    <button type="button" class="btn-danger" data-stock-in-clear-confirm>Clear Form</button>
                </div>
            </section>
        </div>

        <section class="form-panel admin-material-delivery-list">
            <div class="inventory-report-heading">
                <div>
                    <h2 class="section-title-inline">Pending Verification</h2>
                </div>
            </div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead><tr><th>DR Number</th><th>Supplier</th><th>Delivery Date</th><th>Items</th><th>Status</th><th>Details</th></tr></thead>
                    <tbody>
                    <?php if ($deliverySummaries === []): ?>
                        <tr><td colspan="6" class="table-empty-cell">No delivery records yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($deliverySummaries as $delivery): ?>
                        <?php $status = (string)$delivery['status']; ?>
                        <tr>
                            <td data-label="DR Number"><strong><?php echo htmlspecialchars((string)$delivery['delivery_receipt_no']); ?></strong></td>
                            <td data-label="Supplier"><?php echo htmlspecialchars((string)$delivery['supplier_name']); ?></td>
                            <td data-label="Delivery Date"><?php echo htmlspecialchars(date('M j, Y', strtotime((string)$delivery['delivery_date']))); ?></td>
                            <td data-label="Items"><?php echo count($delivery['items']); ?></td>
                            <td data-label="Status"><span class="material-delivery-status material-delivery-status--<?php echo htmlspecialchars($status); ?>"><?php echo match ($status) { 'verified' => 'Verified', 'discrepancy' => 'Discrepancy', default => 'Pending Verification' }; ?></span></td>
                            <td data-label="Details">
                                <details class="material-delivery-details">
                                    <summary>View Details</summary>
                                    <ul>
                                        <?php foreach ($delivery['items'] as $item): ?>
                                            <li><?php echo htmlspecialchars((string)$item['material_name'] . ' - ' . material_delivery_verification_format_quantity((float)$item['expected_quantity']) . ' ' . (string)$item['unit']); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</main>
<?php include __DIR__ . '/../../../layout/footer.php'; ?>
