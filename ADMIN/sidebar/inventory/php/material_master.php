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
$materialNameMaxLength = 180;
$materialDescriptionMaxLength = 255;
$materialReorderMax = 9999999999.99;

$materialPostText = static function (mixed $value): string {
    return is_string($value) || is_numeric($value) ? (string)$value : '';
};

$materialTextLength = static function (string $value): int {
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
};

$materialRedirect = static function (string $filter = 'active'): void {
    header('Location: /codesamplecaps/ADMIN/sidebar/inventory/php/inventory.php?view=materials&material_filter=' . urlencode($filter));
    exit;
};

$materialDuplicateStatus = static function (mysqli $conn, string $name): ?string {
    $normalized = material_detail_name_identity($name);
    $result = $conn->query('SELECT material_name, status FROM materials');
    if (!$result) {
        throw new RuntimeException('Unable to check existing materials.');
    }
    while ($row = $result->fetch_assoc()) {
        $saved = material_detail_name_identity((string)$row['material_name']);
        if ($saved === $normalized) {
            return (string)$row['status'];
        }
    }
    return null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $materialPostText($_POST['material_action'] ?? '');
    $redirectFilter = in_array($_POST['material_filter'] ?? '', ['active', 'inactive', 'all'], true)
        ? (string)$_POST['material_filter']
        : 'active';
    $submittedMaterialValues = [];

    try {
        if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'admin_material_master')) {
            throw new RuntimeException('Security check failed. Please try again.');
        }

        if ($action === 'create') {
            if (!material_stock_consume_once_token('admin_material_create', $_POST['request_token'] ?? null)) {
                throw new RuntimeException('This material was already submitted. Please try again.');
            }
            $submittedMaterialValues = [
                'material_name' => $materialPostText($_POST['material_name'] ?? ''),
                'category' => $materialPostText($_POST['category'] ?? ''),
                'unit' => $materialPostText($_POST['unit'] ?? ''),
                'reorder_level' => $materialPostText($_POST['reorder_level'] ?? ''),
                'description' => $materialPostText($_POST['description'] ?? ''),
            ];
            $name = material_detail_normalize_name($submittedMaterialValues['material_name']);
            $description = trim($submittedMaterialValues['description']);
            $category = trim($submittedMaterialValues['category']);
            $unit = trim($submittedMaterialValues['unit']);
            $reorderRaw = trim($submittedMaterialValues['reorder_level']);
            $fieldErrors = [];

            if ($name === '') {
                $fieldErrors['material_name'] = 'Enter a material name.';
            } elseif ($materialTextLength($name) > $materialNameMaxLength) {
                $fieldErrors['material_name'] = 'Material name must be 180 characters or less.';
            } elseif (!material_detail_suggestion_valid_name($name)) {
                $fieldErrors['material_name'] = 'Enter a valid material name.';
            }
            if (!in_array($category, $materialCategories, true)) {
                $fieldErrors['category'] = 'Select a category.';
            }
            if (!in_array($unit, $materialUnits, true)) {
                $fieldErrors['unit'] = 'Select a unit.';
            }
            if ($reorderRaw === '') {
                $fieldErrors['reorder_level'] = 'Enter zero or a positive number.';
            } elseif (is_numeric($reorderRaw) && (float)$reorderRaw < 0) {
                $fieldErrors['reorder_level'] = 'Enter zero or a positive number.';
            } elseif (preg_match('/^\d+(?:\.\d{1,2})?$/', $reorderRaw) !== 1) {
                $fieldErrors['reorder_level'] = 'Enter zero or a positive number with up to 2 decimal places.';
            } else {
                $reorder = (float)$reorderRaw;
                if (!is_finite($reorder) || $reorder < 0) {
                    $fieldErrors['reorder_level'] = 'Enter zero or a positive number.';
                } elseif ($reorder > $materialReorderMax) {
                    $fieldErrors['reorder_level'] = 'Enter a value not greater than 9,999,999,999.99.';
                } elseif (in_array($unit, $wholeCountUnits, true) && $reorder !== floor($reorder)) {
                    $fieldErrors['reorder_level'] = 'This unit requires a whole number.';
                }
            }
            if ($materialTextLength($description) > $materialDescriptionMaxLength) {
                $fieldErrors['description'] = 'Description must be 255 characters or less.';
            } else {
                $descriptionControlCheck = preg_replace('/[\r\n\t]/u', '', $description);
                if ($descriptionControlCheck === null || preg_match('/\p{C}/u', $descriptionControlCheck) === 1) {
                    $fieldErrors['description'] = 'Description contains invalid characters.';
                }
            }

            if (!isset($fieldErrors['material_name'])) {
                $duplicateStatus = $materialDuplicateStatus($conn, $name);
                if ($duplicateStatus !== null) {
                    $fieldErrors['material_name'] = $duplicateStatus === 'inactive'
                        ? 'This material already exists and is archived.'
                        : 'This material already exists.';
                }
            }

            if ($fieldErrors !== []) {
                $_SESSION[$materialFormStateKey] = [
                    'errors' => $fieldErrors,
                    'values' => $submittedMaterialValues,
                ];
                $materialRedirect($redirectFilter);
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
            $safeError = in_array($exception->getMessage(), [
                'Security check failed. Please try again.',
                'This material was already submitted. Please try again.',
            ], true)
                ? $exception->getMessage()
                : 'We could not save the material. Please try again.';
            $_SESSION[$materialFormStateKey] = [
                'general_error' => $safeError,
                'errors' => [],
                'values' => $submittedMaterialValues !== [] ? $submittedMaterialValues : [
                    'material_name' => $materialPostText($_POST['material_name'] ?? ''),
                    'category' => $materialPostText($_POST['category'] ?? ''),
                    'unit' => $materialPostText($_POST['unit'] ?? ''),
                    'reorder_level' => $materialPostText($_POST['reorder_level'] ?? ''),
                    'description' => $materialPostText($_POST['description'] ?? ''),
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
$materialFormErrors = is_array($materialFormState['errors'] ?? null) ? $materialFormState['errors'] : [];
$materialFieldError = static fn(string $field): string => (string)($materialFormErrors[$field] ?? '');
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
<main class="main-content inventory-main-content">
    <div class="page-stack inventory-page-stack material-master-page">
        <nav class="resource-page-tabs inventory-page-tabs" aria-label="Inventory sections">
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
            <section class="material-create-modal__panel" role="dialog" aria-modal="true" aria-labelledby="materialCreateModalTitle" tabindex="-1">
                <div class="material-create-modal__header">
                    <h2 id="materialCreateModalTitle">Add Material</h2>
                    <button type="button" class="material-create-modal__close" data-material-modal-close aria-label="Close Add Material">&times;</button>
                </div>
                <?php if (!empty($materialFormState['general_error'])): ?>
                    <div class="alert alert-error" role="alert" tabindex="-1" data-material-general-error><?php echo htmlspecialchars((string)$materialFormState['general_error']); ?></div>
                <?php endif; ?>
                <form method="POST" data-material-create-form novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($materialCsrfToken); ?>">
                    <input type="hidden" name="request_token" value="<?php echo htmlspecialchars($materialCreateRequestToken); ?>">
                    <input type="hidden" name="material_action" value="create">
                    <div class="form-grid material-master-form-grid">
                        <div class="input-group material-master-name">
                            <label for="material_name">Material Name</label>
                            <input id="material_name" name="material_name" maxlength="180" placeholder="e.g., CAT6 Cable or PVC Pipe 1/2&quot;" value="<?php echo htmlspecialchars((string)($materialFormValues['material_name'] ?? '')); ?>" <?php echo $materialFieldError('material_name') !== '' ? 'aria-invalid="true" aria-describedby="materialNameError"' : ''; ?> required>
                            <small id="materialNameError" class="material-form-error" data-material-field-error="material_name"<?php echo $materialFieldError('material_name') === '' ? ' hidden' : ''; ?>><?php echo htmlspecialchars($materialFieldError('material_name')); ?></small>
                        </div>
                        <div class="input-group">
                            <label for="category">Category</label>
                            <select id="category" name="category" <?php echo $materialFieldError('category') !== '' ? 'aria-invalid="true" aria-describedby="materialCategoryError"' : ''; ?> required><option value="" disabled<?php echo empty($materialFormValues['category']) ? ' selected' : ''; ?>>Select category</option><?php foreach ($materialCategories as $category): ?><option value="<?php echo htmlspecialchars($category); ?>"<?php echo ($materialFormValues['category'] ?? '') === $category ? ' selected' : ''; ?>><?php echo htmlspecialchars($category); ?></option><?php endforeach; ?></select>
                            <small id="materialCategoryError" class="material-form-error" data-material-field-error="category"<?php echo $materialFieldError('category') === '' ? ' hidden' : ''; ?>><?php echo htmlspecialchars($materialFieldError('category')); ?></small>
                        </div>
                        <div class="input-group">
                            <label for="unit">Unit</label>
                            <select id="unit" name="unit" <?php echo $materialFieldError('unit') !== '' ? 'aria-invalid="true" aria-describedby="materialUnitError"' : ''; ?> required><option value="" disabled<?php echo empty($materialFormValues['unit']) ? ' selected' : ''; ?>>Select unit</option><?php foreach ($materialUnits as $unit): ?><option value="<?php echo htmlspecialchars($unit); ?>" data-whole-number="<?php echo in_array($unit, $wholeCountUnits, true) ? 'true' : 'false'; ?>"<?php echo ($materialFormValues['unit'] ?? '') === $unit ? ' selected' : ''; ?>><?php echo htmlspecialchars($unit); ?></option><?php endforeach; ?></select>
                            <small id="materialUnitError" class="material-form-error" data-material-field-error="unit"<?php echo $materialFieldError('unit') === '' ? ' hidden' : ''; ?>><?php echo htmlspecialchars($materialFieldError('unit')); ?></small>
                        </div>
                        <div class="input-group material-master-reorder">
                            <label for="reorder_level">Low Stock Alert Level</label>
                            <input id="reorder_level" name="reorder_level" type="number" min="0" max="9999999999.99" step="0.01" placeholder="e.g., 10" value="<?php echo htmlspecialchars((string)($materialFormValues['reorder_level'] ?? '')); ?>" aria-describedby="materialReorderHint<?php echo $materialFieldError('reorder_level') !== '' ? ' materialReorderError' : ''; ?>" <?php echo $materialFieldError('reorder_level') !== '' ? 'aria-invalid="true"' : ''; ?> required>
                            <small id="materialReorderHint" class="material-field-hint">Alert threshold, not starting stock.</small>
                            <small id="materialReorderError" class="material-form-error" data-material-field-error="reorder_level"<?php echo $materialFieldError('reorder_level') === '' ? ' hidden' : ''; ?>><?php echo htmlspecialchars($materialFieldError('reorder_level')); ?></small>
                        </div>
                        <div class="input-group material-master-description">
                            <label for="description">Description (Optional)</label>
                            <textarea id="description" name="description" maxlength="255" rows="3" placeholder="e.g., Blue cable for indoor network runs" <?php echo $materialFieldError('description') !== '' ? 'aria-invalid="true" aria-describedby="materialDescriptionError"' : ''; ?>><?php echo htmlspecialchars((string)($materialFormValues['description'] ?? '')); ?></textarea>
                            <small id="materialDescriptionError" class="material-form-error" data-material-field-error="description"<?php echo $materialFieldError('description') === '' ? ' hidden' : ''; ?>><?php echo htmlspecialchars($materialFieldError('description')); ?></small>
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
