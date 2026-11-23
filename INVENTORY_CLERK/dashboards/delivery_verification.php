<?php
require_once __DIR__ . '/../includes/stock_helpers.php';
require_once __DIR__ . '/../includes/page_shell.php';
require_once __DIR__ . '/../../config/material_delivery_verification.php';

$flashKey = 'inventory_clerk_delivery_verification_flash';
$csrfToken = auth_csrf_token('inventory_clerk_delivery_verification');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'inventory_clerk_delivery_verification')) {
            throw new RuntimeException('Security check failed. Please try again.');
        }
        if (!material_stock_consume_once_token('delivery_verification', $_POST['request_token'] ?? null)) {
            throw new RuntimeException('This delivery was already confirmed. Refresh and try again.');
        }

        $result = material_delivery_verification_confirm(
            $conn,
            (int)($_POST['delivery_id'] ?? 0),
            $_POST['verified_quantity'] ?? null,
            (string)($_POST['clerk_notes'] ?? ''),
            (int)($_SESSION['user_id'] ?? 0)
        );
        $_SESSION[$flashKey] = $result['status'] === 'verified'
            ? ['type' => 'success', 'message' => 'Delivery verified. On-Hand stock was updated.']
            : ['type' => 'error', 'message' => 'Quantity mismatch found. Stock was not changed. Admin review is needed.'];
    } catch (Throwable $exception) {
        $_SESSION[$flashKey] = ['type' => 'error', 'message' => $exception->getMessage()];
    }

    header('Location: /codesamplecaps/INVENTORY_CLERK/dashboards/delivery_verification.php');
    exit;
}

$flash = $_SESSION[$flashKey] ?? null;
unset($_SESSION[$flashKey]);
$storageReady = material_delivery_verification_is_ready($conn);
$deliveries = $storageReady ? material_delivery_verification_fetch($conn, 'for_verification') : [];
$requestToken = material_stock_issue_once_token('delivery_verification');

inventory_clerk_render_page('Delivery Count', function () use ($flash, $storageReady, $deliveries, $csrfToken, $requestToken): void {
?>
<div class="shared-page-container delivery-verification-page">
    <?php if ($flash): ?>
        <div class="shared-toast shared-toast--<?php echo $flash['type'] === 'success' ? 'success' : 'error'; ?>" data-shared-toast role="<?php echo $flash['type'] === 'success' ? 'status' : 'alert'; ?>">
            <span><?php echo htmlspecialchars((string)$flash['message']); ?></span>
            <button type="button" class="shared-toast__close" data-shared-toast-close aria-label="Close notification">&times;</button>
            <span class="shared-toast__progress" aria-hidden="true"></span>
        </div>
    <?php endif; ?>

    <section class="shared-flat-panel delivery-verification-panel">
        <h1 class="section-title-inline">Delivery Count</h1>

        <?php if (!$storageReady): ?>
            <div class="alert alert-error" role="alert">Delivery verification setup is not ready. Please contact the administrator.</div>
        <?php elseif ($deliveries === []): ?>
            <div class="table-empty-state">No delivery is waiting to be counted.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table delivery-verification-table">
                    <thead><tr><th>Material</th><th>Unit</th><th>Actual Count</th><th>Remarks (Optional)</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($deliveries as $delivery): ?>
                        <?php
                        $deliveryId = (int)$delivery['id'];
                        $unit = (string)$delivery['unit'];
                        $step = material_stock_unit_requires_whole_number($unit) ? '1' : '0.01';
                        $minimum = $step === '1' ? '1' : '0.01';
                        $formId = 'delivery_verification_' . $deliveryId;
                        ?>
                        <tr>
                            <td data-label="Material">
                                <strong><?php echo htmlspecialchars((string)$delivery['material_name']); ?></strong>
                                <small>DR: <?php echo htmlspecialchars((string)$delivery['delivery_receipt_no']); ?></small>
                            </td>
                            <td data-label="Unit"><span class="delivery-verification-unit"><?php echo htmlspecialchars($unit); ?></span></td>
                            <td data-label="Actual Count">
                                <label class="sr-only" for="verified_quantity_<?php echo $deliveryId; ?>">Actual count for <?php echo htmlspecialchars((string)$delivery['material_name']); ?></label>
                                <input id="verified_quantity_<?php echo $deliveryId; ?>" class="delivery-verification-input" form="<?php echo $formId; ?>" name="verified_quantity" type="number" min="<?php echo $minimum; ?>" max="<?php echo htmlspecialchars(material_stock_decimal_max()); ?>" step="<?php echo $step; ?>" inputmode="decimal" placeholder="Enter count" aria-describedby="quantity_error_<?php echo $deliveryId; ?>" required>
                                <small id="quantity_error_<?php echo $deliveryId; ?>" class="delivery-verification-error" data-quantity-error aria-live="polite"></small>
                            </td>
                            <td data-label="Remarks (Optional)"><label class="sr-only" for="clerk_notes_<?php echo $deliveryId; ?>">Remarks for <?php echo htmlspecialchars((string)$delivery['material_name']); ?></label><input id="clerk_notes_<?php echo $deliveryId; ?>" class="delivery-verification-notes" form="<?php echo $formId; ?>" name="clerk_notes" maxlength="255" placeholder="Optional note"></td>
                            <td data-label="Action">
                                <form id="<?php echo $formId; ?>" method="POST" class="delivery-verification-form" autocomplete="off">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="request_token" value="<?php echo htmlspecialchars($requestToken); ?>">
                                <input type="hidden" name="delivery_id" value="<?php echo $deliveryId; ?>">
                                <button type="submit" class="btn-primary" disabled>Submit Count</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
<?php
}, [
    '/codesamplecaps/SHARED/admin_ui/css/surfaces.css',
    '/codesamplecaps/SHARED/action-loading/css/action-loading.css',
    '/codesamplecaps/INVENTORY_CLERK/css/delivery_verification.css',
], 'shared-page-surface', [
    '/codesamplecaps/SHARED/action-loading/js/action-loading.js',
    '/codesamplecaps/INVENTORY_CLERK/js/delivery_verification.js',
], true);
