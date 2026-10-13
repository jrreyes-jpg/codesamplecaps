<?php
require_once __DIR__ . '/../../../../config/auth_middleware.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/material_stock.php';
require_once __DIR__ . '/../../../../config/material_physical_count.php';
require_once __DIR__ . '/../../../../config/material_detail_suggestion_service.php';

require_role('admin');

// Admin lang ang puwedeng magbago ng material master data.
$materialCsrfToken = auth_csrf_token('admin_material_master');
$materialFlashKey = 'admin_material_master_flash';
$materialFormStateKey = 'admin_material_master_form_state';
$materialFilter = in_array($_GET['material_filter'] ?? 'active', ['active', 'inactive', 'all'], true)
    ? (string)($_GET['material_filter'] ?? 'active')
    : 'active';
$materialCategories = material_detail_suggestion_categories();
$materialUnits = material_detail_suggestion_units();
$wholeCountUnits = ['pcs', 'roll', 'box', 'pack', 'set', 'bundle', 'sheet', 'pair', 'tube'];

$materialRedirect = static function (string $filter = 'active'): void {
    header('Location: /codesamplecaps/ADMIN/sidebar/inventory/php/inventory.php?view=materials&material_filter=' . urlencode($filter));
    exit;
};

$materialNameExists = static function (mysqli $conn, string $name): bool {
    $normalized = strtolower(preg_replace('/\s+/', ' ', trim($name)) ?? '');
    $result = $conn->query('SELECT material_name FROM materials');
    if (!$result) {
        throw new RuntimeException('Unable to check existing materials.');
    }
    while ($row = $result->fetch_assoc()) {
        $saved = strtolower(preg_replace('/\s+/', ' ', trim((string)$row['material_name'])) ?? '');
        if ($saved === $normalized) {
            return true;
        }
    }
    return false;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['material_action'] ?? '');
    $redirectFilter = in_array($_POST['material_filter'] ?? '', ['active', 'inactive', 'all'], true)
        ? (string)$_POST['material_filter']
        : 'active';

    try {
        if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'admin_material_master')) {
            throw new RuntimeException('Security check failed. Please try again.');
        }

        if ($action === 'create') {
            if (!material_stock_consume_once_token('admin_material_create', $_POST['request_token'] ?? null)) {
                throw new RuntimeException('This material was already submitted. Please try again.');
            }
            $name = preg_replace('/\s+/', ' ', trim((string)($_POST['material_name'] ?? ''))) ?? '';
            $description = trim((string)($_POST['description'] ?? ''));
            $category = trim((string)($_POST['category'] ?? ''));
            $unit = trim((string)($_POST['unit'] ?? ''));
            $reorderRaw = trim((string)($_POST['reorder_level'] ?? ''));
            $reorder = is_numeric($reorderRaw) ? (float)$reorderRaw : 0.0;

            if ($name === '' || preg_match('/^[\p{L}\p{N}\s\-\/\.\(\)&]+$/u', $name) !== 1 || preg_match('/\p{L}/u', $name) !== 1) {
                throw new RuntimeException('Enter a valid material name.');
            }
            if ((function_exists('mb_strlen') ? mb_strlen($description) : strlen($description)) > 255) {
                throw new RuntimeException('Description must be 255 characters or less.');
            }
            if (!in_array($category, $materialCategories, true) || !in_array($unit, $materialUnits, true)) {
                throw new RuntimeException('Choose a valid category and unit.');
            }
            if ($reorderRaw === '' || !is_finite($reorder) || $reorder <= 0) {
                throw new RuntimeException('Low Stock Alert Level must be greater than 0.');
            }
            if (in_array($unit, $wholeCountUnits, true) && $reorder !== floor($reorder)) {
                throw new RuntimeException('Low Stock Alert Level must be a whole number for ' . $unit . '.');
            }
            if ($materialNameExists($conn, $name)) {
                throw new RuntimeException('Material already exists.');
            }

            $code = material_stock_create_material($conn, $name, $category, $unit, $reorder, $description);
            $_SESSION[$materialFlashKey] = ['type' => 'success', 'message' => 'Material ' . $code . ' created.'];
        } else {
            $materialId = (int)($_POST['material_id'] ?? 0);
            if ($action === 'archive') {
                material_stock_archive_material($conn, $materialId);
                $_SESSION[$materialFlashKey] = ['type' => 'success', 'message' => 'Material archived.'];
            } elseif ($action === 'restore') {
                material_stock_restore_material($conn, $materialId);
                $_SESSION[$materialFlashKey] = ['type' => 'success', 'message' => 'Material restored.'];
            } elseif ($action === 'permanent_delete') {
                material_stock_permanently_delete_material($conn, $materialId);
                $_SESSION[$materialFlashKey] = ['type' => 'success', 'message' => 'Material permanently deleted.'];
            } else {
                throw new RuntimeException('Invalid material action.');
            }
        }
    } catch (Throwable $exception) {
        if ($action === 'create') {
            $errorMessage = $exception->getMessage();
            $errorFields = [];
            if (str_contains($errorMessage, 'material name') || str_contains($errorMessage, 'Material already exists')) {
                $errorFields[] = 'material_name';
            } elseif (str_contains($errorMessage, 'Description')) {
                $errorFields[] = 'description';
            } elseif (str_contains($errorMessage, 'category and unit')) {
                $errorFields = ['category', 'unit'];
            } elseif (str_contains($errorMessage, 'Alert Level')) {
                $errorFields[] = 'reorder_level';
            }
            $_SESSION[$materialFormStateKey] = [
                'error' => $errorMessage,
                'error_fields' => $errorFields,
                'values' => [
                    'material_name' => (string)($_POST['material_name'] ?? ''),
                    'category' => (string)($_POST['category'] ?? ''),
                    'unit' => (string)($_POST['unit'] ?? ''),
                    'reorder_level' => (string)($_POST['reorder_level'] ?? ''),
                    'description' => (string)($_POST['description'] ?? ''),
                ],
            ];
        } else {
            $_SESSION[$materialFlashKey] = ['type' => 'error', 'message' => $exception->getMessage()];
        }
    }
    $materialRedirect($redirectFilter);
}

$materialFlash = $_SESSION[$materialFlashKey] ?? null;
unset($_SESSION[$materialFlashKey]);
$materialFormState = $_SESSION[$materialFormStateKey] ?? null;
unset($_SESSION[$materialFormStateKey]);
$materialFormValues = is_array($materialFormState['values'] ?? null) ? $materialFormState['values'] : [];
$materialFormErrorFields = is_array($materialFormState['error_fields'] ?? null) ? $materialFormState['error_fields'] : [];
$materialFieldHasError = static fn(string $field): bool => in_array($field, $materialFormErrorFields, true);
$materialCreateRequestToken = material_stock_issue_once_token('admin_material_create');
$materials = material_stock_fetch_materials($conn, 'active');
$latestPhysicalCounts = material_physical_count_fetch_latest($conn);
$projectIssuedTotals = material_stock_fetch_project_issued_totals($conn);
$deletableMaterialIds = array_fill_keys(material_stock_fetch_deletable_material_ids($conn), true);
$displayMaterialQuantity = static function (float $quantity): string {
    return rtrim(rtrim(number_format($quantity, 2, '.', ','), '0'), '.');
};

$adminPageTitle = 'Consumable Material Master - Admin';
$adminCssFiles = [
    '/codesamplecaps/ADMIN/common/css/admin-common.css',
    '/codesamplecaps/ADMIN/sidebar/inventory/css/inventory.css',
];
$adminJsFiles = [
    '/codesamplecaps/ADMIN/common/js/admin-common.js',
    '/codesamplecaps/ADMIN/sidebar/inventory/js/inventory.js',
];
include __DIR__ . '/../../../layout/header.php';
include __DIR__ . '/../../../admin_sidebar.php';
?>
<main class="main-content">
    <div class="page-stack material-master-page">
        <nav class="resource-page-tabs" aria-label="Inventory sections">
            <a class="is-active" href="/codesamplecaps/ADMIN/sidebar/inventory/php/inventory.php">Consumables</a>
            <a href="/codesamplecaps/ADMIN/sidebar/inventory/php/inventory.php?view=history">Stock History</a>
        </nav>

        <?php if ($materialFlash): ?>
            <div class="alert <?php echo $materialFlash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>">
                <?php echo htmlspecialchars((string)$materialFlash['message']); ?>
            </div>
        <?php endif; ?>

        <section class="form-panel inventory-report-panel">
            <div class="inventory-report-heading">
                <div>
                    <h1 class="section-title-inline">Inventory Report</h1>
                </div>
                <button type="button" class="btn-primary" data-material-modal-open>+ Add Material</button>
            </div>
            <div class="inventory-report-search">
                <label class="sr-only" for="inventoryReportSearch">Search inventory report</label>
                <input id="inventoryReportSearch" type="search" placeholder="Search item, description, or unit" data-inventory-report-search>
            </div>
            <div class="table-responsive">
                <table class="data-table material-master-table mobile-card-table">
                    <thead><tr><th>Item</th><th>Description</th><th>Unit</th><th>Total Qty</th><th>Qty Issued</th><th>Physical Count</th><th>Variance</th><th>Remarks</th><th><span class="sr-only">More actions</span></th></tr></thead>
                    <tbody>
                    <?php if ($materials === []): ?><tr><td colspan="9" class="empty-state">No active materials yet.</td></tr><?php endif; ?>
                    <?php foreach ($materials as $material): ?>
                        <?php
                        $latestCount = $latestPhysicalCounts[(int)$material['id']] ?? null;
                        $issuedQuantity = $projectIssuedTotals[(int)$material['id']] ?? 0.0;
                        $variance = $latestCount === null
                            ? null
                            : (float)$latestCount['counted_quantity'] - (float)$latestCount['expected_quantity_snapshot'];
                        $isShortage = $variance !== null && $variance < -0.005;
                        ?>
                        <tr class="<?php echo $isShortage ? 'material-master-row--shortage' : ''; ?>" data-inventory-report-row>
                            <td data-label="Item"><strong><?php echo htmlspecialchars((string)$material['material_name']); ?></strong><small><?php echo htmlspecialchars((string)$material['material_code']); ?></small></td>
                            <td data-label="Description" class="material-report-description"><?php echo htmlspecialchars(trim((string)$material['description']) ?: '—'); ?></td>
                            <td data-label="Unit"><?php echo htmlspecialchars((string)$material['unit']); ?></td>
                            <td data-label="Total Qty" class="material-master-number"><?php echo htmlspecialchars($displayMaterialQuantity((float)$material['physical_quantity'])); ?></td>
                            <td data-label="Qty Issued" class="material-master-number"><?php echo htmlspecialchars($displayMaterialQuantity($issuedQuantity)); ?></td>
                            <td data-label="Physical Count" class="material-master-count">
                                <?php if ($latestCount === null): ?>
                                    <span class="material-audit-pending">Not yet counted</span>
                                <?php else: ?>
                                    <strong><?php echo htmlspecialchars($displayMaterialQuantity((float)$latestCount['counted_quantity'])); ?></strong>
                                    <small>Expected at count: <?php echo htmlspecialchars($displayMaterialQuantity((float)$latestCount['expected_quantity_snapshot'])); ?></small>
                                    <small>
                                        By <?php echo htmlspecialchars(trim((string)$latestCount['counted_by_name']) ?: 'Inventory Clerk'); ?>
                                        &middot; <?php echo htmlspecialchars((string)$latestCount['counted_at']); ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td data-label="Variance" class="material-master-number material-variance<?php echo $isShortage ? ' material-variance--shortage' : ''; ?>">
                                <?php echo $variance === null ? '&mdash;' : htmlspecialchars($displayMaterialQuantity($variance)); ?>
                            </td>
                            <td data-label="Remarks" class="material-report-remarks">
                                <?php echo $latestCount === null || trim((string)($latestCount['remarks'] ?? '')) === '' ? '&mdash;' : nl2br(htmlspecialchars((string)$latestCount['remarks'])); ?>
                            </td>
                            <td data-label="More">
                                <div class="material-row-menu" data-material-row-menu>
                                    <button type="button" class="material-row-menu__toggle" data-material-menu-toggle aria-haspopup="menu" aria-expanded="false" aria-label="More actions for <?php echo htmlspecialchars((string)$material['material_name']); ?>">&#8942;</button>
                                    <div class="material-row-menu__panel" data-material-menu-panel role="menu" hidden>
                                        <?php if (isset($deletableMaterialIds[(int)$material['id']])): ?>
                                            <form method="POST" data-material-remove-form>
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($materialCsrfToken); ?>">
                                                <input type="hidden" name="material_id" value="<?php echo (int)$material['id']; ?>">
                                                <input type="hidden" name="material_action" value="permanent_delete">
                                                <button type="button" class="material-row-menu__remove" data-material-remove-open data-material-name="<?php echo htmlspecialchars((string)$material['material_name']); ?>" role="menuitem">Remove</button>
                                            </form>
                                        <?php else: ?>
                                            <button type="button" class="material-row-menu__remove" role="menuitem" disabled>Remove</button>
                                            <small>Cannot remove: this item has stock or history.</small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr data-inventory-report-empty hidden><td colspan="9" class="empty-state">No matching materials.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="material-create-modal" data-material-modal <?php echo $materialFormState ? 'data-auto-open="true"' : ''; ?> hidden>
            <section class="material-create-modal__panel" role="dialog" aria-modal="true" aria-labelledby="materialCreateModalTitle">
                <div class="material-create-modal__header">
                    <div>
                        <h2 id="materialCreateModalTitle">Add Material</h2>
                        <p>Add a consumable item. Stock receipts are recorded separately.</p>
                    </div>
                    <button type="button" class="material-create-modal__close" data-material-modal-close aria-label="Close Add Material">&times;</button>
                </div>
                <?php if ($materialFormState): ?>
                    <div class="alert alert-error" role="alert"><?php echo htmlspecialchars((string)($materialFormState['error'] ?? 'Please check the form.')); ?></div>
                <?php endif; ?>
                <form method="POST" data-material-create-form>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($materialCsrfToken); ?>">
                    <input type="hidden" name="request_token" value="<?php echo htmlspecialchars($materialCreateRequestToken); ?>">
                    <input type="hidden" name="material_action" value="create">
                    <div class="form-grid material-master-form-grid">
                        <div class="input-group material-master-name">
                            <label for="material_name">Material Name</label>
                            <input id="material_name" name="material_name" maxlength="180" value="<?php echo htmlspecialchars((string)($materialFormValues['material_name'] ?? '')); ?>" <?php echo $materialFieldHasError('material_name') ? 'aria-invalid="true" aria-describedby="materialNameError"' : ''; ?> required>
                            <?php if ($materialFieldHasError('material_name')): ?><small id="materialNameError" class="material-form-error"><?php echo htmlspecialchars((string)$materialFormState['error']); ?></small><?php endif; ?>
                        </div>
                        <div class="input-group">
                            <label for="category">Category</label>
                            <select id="category" name="category" <?php echo $materialFieldHasError('category') ? 'aria-invalid="true" aria-describedby="materialCategoryError"' : ''; ?> required><option value="">Select category</option><?php foreach ($materialCategories as $category): ?><option value="<?php echo htmlspecialchars($category); ?>"<?php echo ($materialFormValues['category'] ?? '') === $category ? ' selected' : ''; ?>><?php echo htmlspecialchars($category); ?></option><?php endforeach; ?></select>
                            <?php if ($materialFieldHasError('category')): ?><small id="materialCategoryError" class="material-form-error"><?php echo htmlspecialchars((string)$materialFormState['error']); ?></small><?php endif; ?>
                        </div>
                        <div class="input-group">
                            <label for="unit">Unit</label>
                            <select id="unit" name="unit" <?php echo $materialFieldHasError('unit') ? 'aria-invalid="true" aria-describedby="materialUnitError"' : ''; ?> required><option value="">Select unit</option><?php foreach ($materialUnits as $unit): ?><option value="<?php echo htmlspecialchars($unit); ?>"<?php echo ($materialFormValues['unit'] ?? '') === $unit ? ' selected' : ''; ?>><?php echo htmlspecialchars($unit); ?></option><?php endforeach; ?></select>
                            <?php if ($materialFieldHasError('unit')): ?><small id="materialUnitError" class="material-form-error"><?php echo htmlspecialchars((string)$materialFormState['error']); ?></small><?php endif; ?>
                        </div>
                        <div class="input-group material-master-reorder">
                            <label for="reorder_level">Low Stock Alert Level</label>
                            <input id="reorder_level" name="reorder_level" type="number" min="0.01" step="0.01" value="<?php echo htmlspecialchars((string)($materialFormValues['reorder_level'] ?? '')); ?>" <?php echo $materialFieldHasError('reorder_level') ? 'aria-invalid="true" aria-describedby="materialReorderError"' : ''; ?> required>
                            <?php if ($materialFieldHasError('reorder_level')): ?><small id="materialReorderError" class="material-form-error"><?php echo htmlspecialchars((string)$materialFormState['error']); ?></small><?php endif; ?>
                        </div>
                        <div class="input-group material-master-description">
                            <label for="description">Description (Optional)</label>
                            <textarea id="description" name="description" maxlength="255" rows="3" <?php echo $materialFieldHasError('description') ? 'aria-invalid="true" aria-describedby="materialDescriptionError"' : ''; ?>><?php echo htmlspecialchars((string)($materialFormValues['description'] ?? '')); ?></textarea>
                            <?php if ($materialFieldHasError('description')): ?><small id="materialDescriptionError" class="material-form-error"><?php echo htmlspecialchars((string)$materialFormState['error']); ?></small><?php endif; ?>
                        </div>
                    </div>
                    <div class="material-create-modal__actions">
                        <button type="button" class="btn-secondary" data-material-modal-close>Cancel</button>
                        <button type="submit" class="btn-primary" data-material-submit>Add Material</button>
                    </div>
                </form>
            </section>
        </div>

        <div class="material-remove-modal" data-material-remove-modal hidden>
            <section class="material-remove-modal__panel" role="dialog" aria-modal="true" aria-labelledby="materialRemoveTitle" aria-describedby="materialRemoveMessage">
                <h2 id="materialRemoveTitle">Remove material?</h2>
                <p id="materialRemoveMessage" data-material-remove-message></p>
                <div class="material-remove-modal__actions">
                    <button type="button" class="btn-secondary" data-material-remove-close>Cancel</button>
                    <button type="button" class="btn-danger" data-material-remove-confirm>Remove</button>
                </div>
            </section>
        </div>
    </div>
</main>
<?php include __DIR__ . '/../../../layout/footer.php'; ?>
