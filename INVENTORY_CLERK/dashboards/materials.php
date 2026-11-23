<?php
require_once __DIR__ . '/../includes/stock_helpers.php';
require_once __DIR__ . '/../includes/page_shell.php';
require_once __DIR__ . '/../../config/material_stock.php';
require_once __DIR__ . '/../../config/material_physical_count.php';

$countFlashKey = 'inventory_clerk_physical_count_flash';
$countCsrfToken = auth_csrf_token('inventory_clerk_physical_count');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'inventory_clerk_physical_count')) {
            throw new RuntimeException('Security check failed. Please try again.');
        }
        if (!material_stock_consume_once_token('material_physical_count', $_POST['request_token'] ?? null)) {
            throw new RuntimeException('This count was already submitted. Refresh and try again.');
        }
        $countCutoff = material_physical_count_take_cutoff($_POST['request_token'] ?? null);

        $savedCount = material_physical_count_submit(
            $conn,
            is_array($_POST['physical_counts'] ?? null) ? $_POST['physical_counts'] : [],
            is_array($_POST['count_remarks'] ?? null) ? $_POST['count_remarks'] : [],
            (int)($_SESSION['user_id'] ?? 0),
            $countCutoff
        );
        $_SESSION[$countFlashKey] = [
            'type' => 'success',
            'message' => 'Physical count submitted.',
        ];
    } catch (Throwable $exception) {
        $_SESSION[$countFlashKey] = ['type' => 'error', 'message' => $exception->getMessage()];
    }

    header('Location: /codesamplecaps/INVENTORY_CLERK/dashboards/materials.php');
    exit;
}

$countFlash = $_SESSION[$countFlashKey] ?? null;
unset($_SESSION[$countFlashKey]);
$countRequestToken = material_stock_issue_once_token('material_physical_count');
$countStorageReady = material_physical_count_is_ready($conn) && audit_log_table_exists($conn);
$materials = material_physical_count_fetch_clerk_materials($conn);
$latestPhysicalCounts = material_physical_count_fetch_latest($conn);
$recountNotice = null;
$notificationState = user_notifications_fetch_unread_state($conn, (int)($_SESSION['user_id'] ?? 0), 20);
foreach ($notificationState['items'] as $notification) {
    if (($notification['type'] ?? '') !== 'material_physical_count_recount') {
        continue;
    }
    $recountNotice = (string)($notification['message'] ?? 'Admin requested a physical recount.');
    user_notifications_mark_read($conn, (int)$notification['id'], (int)($_SESSION['user_id'] ?? 0));
    break;
}
if ($countStorageReady) {
    material_physical_count_store_cutoff($conn, $countRequestToken);
}

inventory_clerk_render_page('Physical Count', function () use (
    $materials,
    $countCsrfToken,
    $countRequestToken,
    $countFlash,
    $recountNotice,
    $countStorageReady,
    $latestPhysicalCounts
): void {
?>
<div class="shared-page-container page-stack materials-page blind-audit-page">
    <?php if ($countFlash): ?>
        <?php $countToastType = $countFlash['type'] === 'success' ? 'success' : 'error'; ?>
        <div class="shared-toast shared-toast--<?php echo $countToastType; ?>" data-shared-toast role="<?php echo $countToastType === 'error' ? 'alert' : 'status'; ?>">
            <span><?php echo htmlspecialchars((string)$countFlash['message']); ?></span>
            <button type="button" class="shared-toast__close" data-shared-toast-close aria-label="Close notification">&times;</button>
            <span class="shared-toast__progress" aria-hidden="true"></span>
        </div>
    <?php endif; ?>
    <?php if ($recountNotice && !$countFlash): ?>
        <div class="shared-toast shared-toast--warning" data-shared-toast role="status">
            <span><?php echo htmlspecialchars($recountNotice); ?></span>
            <button type="button" class="shared-toast__close" data-shared-toast-close aria-label="Close notification">&times;</button>
            <span class="shared-toast__progress" aria-hidden="true"></span>
        </div>
    <?php endif; ?>

    <section class="form-panel shared-flat-panel">
        <div class="materials-master-list__heading">
            <div>
                <h1 class="section-title-inline">Physical Count</h1>
            </div>
        </div>

        <?php if (!$countStorageReady): ?>
            <div class="alert alert-error" role="alert">Physical count storage is not ready. Please contact the administrator.</div>
        <?php endif; ?>

        <form method="POST" class="blind-audit-form" data-physical-count-form autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($countCsrfToken); ?>">
            <input type="hidden" name="request_token" value="<?php echo htmlspecialchars($countRequestToken); ?>">
            <div class="table-responsive">
                <table class="data-table materials-table blind-audit-table">
                    <thead><tr><th>Item</th><th>Unit</th><th>Physical Count</th><th>Remarks (Optional)</th></tr></thead>
                    <tbody>
                    <?php if ($materials === []): ?>
                        <tr><td colspan="4" class="materials-empty">No active materials yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($materials as $material): ?>
                        <?php
                        $inputId = 'physical_count_' . (int)$material['id'];
                        $latestCount = $latestPhysicalCounts[(int)$material['id']] ?? null;
                        $countStatus = material_physical_count_status($latestCount);
                        $canCount = $countStorageReady && (!$latestCount || in_array($countStatus['key'], ['verified', 'recount'], true));
                        ?>
                        <tr>
                            <td data-label="Item"><strong class="materials-table__name"><?php echo htmlspecialchars((string)$material['material_name']); ?></strong></td>
                            <td data-label="Unit"><?php echo htmlspecialchars((string)$material['unit']); ?></td>
                            <td data-label="Physical Count">
                                <label class="sr-only" for="<?php echo $inputId; ?>">Physical count for <?php echo htmlspecialchars((string)$material['material_name']); ?></label>
                                <input id="<?php echo $inputId; ?>" class="blind-audit-count-input" data-physical-count-input name="physical_counts[<?php echo (int)$material['id']; ?>]" type="number" min="0" max="<?php echo htmlspecialchars(material_stock_decimal_max()); ?>" step="<?php echo material_stock_unit_requires_whole_number((string)$material['unit']) ? '1' : '0.01'; ?>" inputmode="decimal" placeholder="<?php echo $canCount ? 'Enter count' : 'Waiting for review'; ?>" <?php echo $canCount ? '' : 'disabled'; ?>>
                            </td>
                            <td data-label="Remarks">
                                <label class="sr-only" for="count_remarks_<?php echo (int)$material['id']; ?>">Remarks for <?php echo htmlspecialchars((string)$material['material_name']); ?></label>
                                <input id="count_remarks_<?php echo (int)$material['id']; ?>" class="blind-audit-remarks-input" name="count_remarks[<?php echo (int)$material['id']; ?>]" maxlength="255" placeholder="Optional note" <?php echo $canCount ? '' : 'disabled'; ?>>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($materials !== []): ?>
                <div class="form-actions blind-audit-actions">
                    <button type="submit" class="btn-primary" data-physical-count-submit disabled>Submit Physical Count</button>
                </div>
            <?php endif; ?>
        </form>
    </section>
</div>
<?php
}, [
    '/codesamplecaps/SHARED/admin_ui/css/surfaces.css?v=20261123-4',
    '/codesamplecaps/SHARED/action-loading/css/action-loading.css',
    '/codesamplecaps/INVENTORY_CLERK/css/materials.css?v=20261123-2',
], 'shared-page-surface physical-count-main', [
    '/codesamplecaps/SHARED/action-loading/js/action-loading.js',
    '/codesamplecaps/INVENTORY_CLERK/js/materials.js?v=20261123-2',
], true);
