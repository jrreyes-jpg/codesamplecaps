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

        material_delivery_verification_confirm(
            $conn,
            (int)($_POST['delivery_id'] ?? 0),
            $_POST['verified_quantity'] ?? null,
            (string)($_POST['clerk_notes'] ?? ''),
            (int)($_SESSION['user_id'] ?? 0)
        );
        $_SESSION[$flashKey] = ['type' => 'success', 'message' => 'Actual quantity was verified and added to stock.'];
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

inventory_clerk_render_page('Delivery Verification', function () use ($flash, $storageReady, $deliveries, $csrfToken, $requestToken): void {
?>
<div class="page-stack delivery-verification-page">
    <?php if ($flash): ?>
        <div class="alert <?php echo $flash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>" role="status">
            <?php echo htmlspecialchars((string)$flash['message']); ?>
        </div>
    <?php endif; ?>

    <section class="form-panel">
        <div class="materials-master-list__heading">
            <div>
                <h1 class="section-title-inline">Delivery Verification</h1>
                <p>Count the delivered materials. Only the verified quantity is added to stock.</p>
            </div>
        </div>

        <?php if (!$storageReady): ?>
            <div class="alert alert-error" role="alert">Delivery verification setup is not ready. Please contact the administrator.</div>
        <?php elseif ($deliveries === []): ?>
            <div class="table-empty-state">No supplier delivery is waiting for verification.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table delivery-verification-table">
                    <thead><tr><th>Material</th><th>Expected Delivery</th><th>Supplier / Receipt</th><th>Actual Count</th><th>Clerk Note</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($deliveries as $delivery): ?>
                        <?php
                        $deliveryId = (int)$delivery['id'];
                        $unit = (string)$delivery['unit'];
                        $step = material_stock_unit_requires_whole_number($unit) ? '1' : '0.01';
                        $formId = 'delivery_verification_' . $deliveryId;
                        ?>
                        <tr>
                            <td data-label="Material"><strong><?php echo htmlspecialchars((string)$delivery['material_name']); ?></strong><small><?php echo htmlspecialchars((string)$delivery['material_code']); ?></small></td>
                            <td data-label="Expected Delivery"><?php echo htmlspecialchars(material_delivery_verification_format_quantity((float)$delivery['expected_quantity']) . ' ' . $unit); ?></td>
                            <td data-label="Supplier / Receipt"><?php echo htmlspecialchars((string)$delivery['supplier_name']); ?><small>DR: <?php echo htmlspecialchars((string)$delivery['delivery_receipt_no']); ?></small></td>
                            <td data-label="Actual Count"><label class="sr-only" for="verified_quantity_<?php echo $deliveryId; ?>">Actual count for <?php echo htmlspecialchars((string)$delivery['material_name']); ?></label><input id="verified_quantity_<?php echo $deliveryId; ?>" class="delivery-verification-input" form="<?php echo $formId; ?>" name="verified_quantity" type="number" min="0.01" max="<?php echo htmlspecialchars(material_stock_decimal_max()); ?>" step="<?php echo $step; ?>" inputmode="decimal" required><span><?php echo htmlspecialchars($unit); ?></span></td>
                            <td data-label="Clerk Note"><label class="sr-only" for="clerk_notes_<?php echo $deliveryId; ?>">Clerk note for <?php echo htmlspecialchars((string)$delivery['material_name']); ?></label><input id="clerk_notes_<?php echo $deliveryId; ?>" class="delivery-verification-notes" form="<?php echo $formId; ?>" name="clerk_notes" maxlength="500" placeholder="Optional note"></td>
                            <td data-label="Action">
                                <form id="<?php echo $formId; ?>" method="POST" class="delivery-verification-form" autocomplete="off">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="request_token" value="<?php echo htmlspecialchars($requestToken); ?>">
                                <input type="hidden" name="delivery_id" value="<?php echo $deliveryId; ?>">
                                <button type="submit" class="btn-primary">Verify & Add Stock</button>
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
}, ['/codesamplecaps/INVENTORY_CLERK/css/delivery_verification.css'], '', [], true);
