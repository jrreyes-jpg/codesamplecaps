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
            'message' => $savedCount . ($savedCount === 1 ? ' physical count was submitted.' : ' physical counts were submitted.'),
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
if ($countStorageReady) {
    material_physical_count_store_cutoff($conn, $countRequestToken);
}

inventory_clerk_render_page('Physical Count', function () use (
    $materials,
    $countCsrfToken,
    $countRequestToken,
    $countFlash,
    $countStorageReady
): void {
?>
<div class="page-stack materials-page blind-audit-page">
    <?php if ($countFlash): ?>
        <div class="alert <?php echo $countFlash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>" role="status">
            <?php echo htmlspecialchars((string)$countFlash['message']); ?>
        </div>
    <?php endif; ?>

    <section class="form-panel">
        <div class="materials-master-list__heading">
            <div>
                <h1 class="section-title-inline">Physical Count</h1>
                <p>Count each material you can see. System stock numbers are hidden for a fair audit.</p>
            </div>
        </div>

        <?php if (!$countStorageReady): ?>
            <div class="alert alert-error" role="alert">Physical count storage is not ready. Please contact the administrator.</div>
        <?php endif; ?>

        <form method="POST" class="blind-audit-form" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($countCsrfToken); ?>">
            <input type="hidden" name="request_token" value="<?php echo htmlspecialchars($countRequestToken); ?>">
            <div class="table-responsive">
                <table class="data-table materials-table blind-audit-table">
                    <thead><tr><th>Item</th><th>Description</th><th>Unit</th><th>Physical Count</th><th>Remarks</th></tr></thead>
                    <tbody>
                    <?php if ($materials === []): ?>
                        <tr><td colspan="5" class="materials-empty">No active materials yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($materials as $material): ?>
                        <?php $inputId = 'physical_count_' . (int)$material['id']; ?>
                        <tr>
                            <td data-label="Item"><strong class="materials-table__name"><?php echo htmlspecialchars((string)$material['material_name']); ?></strong></td>
                            <td data-label="Description"><?php echo htmlspecialchars(trim((string)$material['description']) ?: '—'); ?></td>
                            <td data-label="Unit"><?php echo htmlspecialchars((string)$material['unit']); ?></td>
                            <td data-label="Physical Count">
                                <label class="sr-only" for="<?php echo $inputId; ?>">Physical count for <?php echo htmlspecialchars((string)$material['material_name']); ?></label>
                                <input id="<?php echo $inputId; ?>" class="blind-audit-count-input" name="physical_counts[<?php echo (int)$material['id']; ?>]" type="number" min="0" max="9999999999.99" step="<?php echo in_array(strtolower((string)$material['unit']), ['pcs', 'roll', 'box', 'pack', 'set', 'bundle', 'sheet', 'pair', 'tube'], true) ? '1' : '0.01'; ?>" inputmode="decimal" placeholder="Enter count" <?php echo $countStorageReady ? '' : 'disabled'; ?>>
                            </td>
                            <td data-label="Remarks">
                                <label class="sr-only" for="count_remarks_<?php echo (int)$material['id']; ?>">Remarks for <?php echo htmlspecialchars((string)$material['material_name']); ?></label>
                                <input id="count_remarks_<?php echo (int)$material['id']; ?>" class="blind-audit-remarks-input" name="count_remarks[<?php echo (int)$material['id']; ?>]" maxlength="255" placeholder="Optional note" <?php echo $countStorageReady ? '' : 'disabled'; ?>>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($materials !== []): ?>
                <div class="form-actions blind-audit-actions">
                    <button type="submit" class="btn-primary" <?php echo $countStorageReady ? '' : 'disabled'; ?>>Submit Physical Count</button>
                </div>
            <?php endif; ?>
        </form>
    </section>
</div>
<?php
}, [
    '/codesamplecaps/INVENTORY_CLERK/css/materials.css',
], '', [], true);
