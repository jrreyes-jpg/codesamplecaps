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
$materialFilter = in_array($_GET['material_filter'] ?? 'active', ['active', 'inactive'], true)
    ? (string)($_GET['material_filter'] ?? 'active')
    : 'active';
$materialCategories = material_detail_suggestion_categories();
$materialUnits = material_detail_suggestion_units();
$wholeCountUnits = material_stock_whole_count_units();
$materialNameMaxLength = 180;
$materialDescriptionMaxLength = 255;

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

$materialDuplicateStatus = static function (mysqli $conn, string $name, int $excludeMaterialId = 0): ?string {
    $normalized = material_detail_name_identity($name);
    $result = $conn->query('SELECT id, material_name, status FROM materials');
    if (!$result) {
        throw new RuntimeException('Unable to check existing materials.');
    }
    while ($row = $result->fetch_assoc()) {
        if ((int)$row['id'] === $excludeMaterialId) {
            continue;
        }
        $saved = material_detail_name_identity((string)$row['material_name']);
        if ($saved === $normalized) {
            return (string)$row['status'];
        }
    }
    return null;
};

$validateMaterialValues = static function (array $values) use (
    $materialCategories,
    $materialUnits,
    $materialTextLength,
    $materialNameMaxLength,
    $materialDescriptionMaxLength
): array {
    $name = material_detail_normalize_name((string)$values['material_name']);
    $description = trim((string)$values['description']);
    $category = trim((string)$values['category']);
    $unit = trim((string)$values['unit']);
    $reorderRaw = trim((string)$values['reorder_level']);
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

    $thresholdUnit = in_array($unit, $materialUnits, true) ? $unit : 'this unit';
    $thresholdError = material_stock_threshold_error($reorderRaw, $thresholdUnit);
    if ($thresholdError !== null) {
        $fieldErrors['reorder_level'] = $thresholdError;
    }

    if ($materialTextLength($description) > $materialDescriptionMaxLength) {
        $fieldErrors['description'] = 'Description must be 255 characters or less.';
    } else {
        $descriptionControlCheck = preg_replace('/[\r\n\t]/u', '', $description);
        if ($descriptionControlCheck === null || preg_match('/\p{C}/u', $descriptionControlCheck) === 1) {
            $fieldErrors['description'] = 'Description contains invalid characters.';
        }
    }

    return [
        'name' => $name,
        'description' => $description,
        'category' => $category,
        'unit' => $unit,
        'reorder' => $thresholdError === null ? (float)$reorderRaw : null,
        'errors' => $fieldErrors,
    ];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $materialPostText($_POST['material_action'] ?? '');
    $redirectFilter = in_array($_POST['material_filter'] ?? '', ['active', 'inactive'], true)
        ? (string)$_POST['material_filter']
        : 'active';
    $submittedMaterialValues = [];

    try {
        if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'admin_material_master')) {
            throw new RuntimeException('Security check failed. Please try again.');
        }

        if ($action === 'create' || $action === 'update') {
            $tokenScope = $action === 'update' ? 'admin_material_update' : 'admin_material_create';
            if (!material_stock_consume_once_token($tokenScope, $_POST['request_token'] ?? null)) {
                throw new RuntimeException('This material was already submitted. Please try again.');
            }
            $materialId = $action === 'update'
                ? filter_var($materialPostText($_POST['material_id'] ?? ''), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : 0;
            $submittedMaterialValues = [
                'material_name' => $materialPostText($_POST['material_name'] ?? ''),
                'category' => $materialPostText($_POST['category'] ?? ''),
                'unit' => $materialPostText($_POST['unit'] ?? ($_POST['locked_unit'] ?? '')),
                'reorder_level' => $materialPostText($_POST['reorder_level'] ?? ''),
                'description' => $materialPostText($_POST['description'] ?? ''),
            ];
            $validated = $validateMaterialValues($submittedMaterialValues);
            $fieldErrors = $validated['errors'];

            if (!isset($fieldErrors['material_name'])) {
                $duplicateStatus = $materialDuplicateStatus($conn, $validated['name'], (int)$materialId);
                if ($duplicateStatus !== null) {
                    $fieldErrors['material_name'] = $duplicateStatus === 'inactive'
                        ? 'This material already exists and is archived.'
                        : 'This material already exists.';
                }
            }

            if ($fieldErrors !== []) {
                $_SESSION[$materialFormStateKey] = [
                    'mode' => $action === 'update' ? 'edit' : 'add',
                    'material_id' => (int)$materialId,
                    'errors' => $fieldErrors,
                    'values' => $submittedMaterialValues,
                ];
                $materialRedirect($redirectFilter);
            }

            if ($action === 'update') {
                material_stock_update_material(
                    $conn,
                    (int)$materialId,
                    $validated['name'],
                    $validated['category'],
                    $validated['unit'],
                    (float)$validated['reorder'],
                    $validated['description'],
                    (int)$_SESSION['user_id']
                );
                $_SESSION[$materialFlashKey] = ['type' => 'success', 'action' => 'update', 'message' => 'Material updated successfully.'];
            } else {
                $code = material_stock_create_material(
                    $conn,
                    $validated['name'],
                    $validated['category'],
                    $validated['unit'],
                    (float)$validated['reorder'],
                    $validated['description']
                );
                $_SESSION[$materialFlashKey] = ['type' => 'success', 'action' => 'create', 'message' => 'Material ' . $code . ' created.'];
            }
        } elseif ($action === 'bulk_archive') {
            if (!material_stock_consume_once_token('admin_material_bulk_archive', $_POST['request_token'] ?? null)) {
                throw new RuntimeException('This archive request was already sent. Please try again.');
            }

            $selectedIds = is_array($_POST['material_ids'] ?? null) ? $_POST['material_ids'] : [];
            $selectedIds = array_values(array_unique(array_filter(array_map('intval', $selectedIds), static fn(int $id): bool => $id > 0)));
            if ($selectedIds === []) {
                throw new RuntimeException('Select at least one material to archive.');
            }

            $archivedCount = 0;
            $failedMessages = [];
            foreach ($selectedIds as $selectedId) {
                try {
                    material_stock_archive_material($conn, $selectedId);
                    $archivedCount++;
                } catch (Throwable $archiveError) {
                    $failedMessages[] = $archiveError->getMessage();
                }
            }

            if ($failedMessages !== []) {
                $_SESSION[$materialFlashKey] = [
                    'type' => 'error',
                    'message' => $archivedCount . ' archived. ' . count($failedMessages) . ' not archived: ' . $failedMessages[0],
                ];
            } else {
                $_SESSION[$materialFlashKey] = ['type' => 'success', 'message' => $archivedCount . ' material(s) archived.'];
            }
        } else {
            $materialId = filter_var($materialPostText($_POST['material_id'] ?? ''), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
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
        if ($action === 'create' || $action === 'update') {
            $isUnitRestriction = $exception->getMessage() === 'Unit cannot be changed because this material has stock or history.';
            if ($isUnitRestriction && isset($materialId) && (int)$materialId > 0) {
                $savedUnitStatement = $conn->prepare('SELECT unit FROM materials WHERE id = ? LIMIT 1');
                if ($savedUnitStatement) {
                    $savedMaterialId = (int)$materialId;
                    $savedUnitStatement->bind_param('i', $savedMaterialId);
                    $savedUnitStatement->execute();
                    $savedUnitRow = $savedUnitStatement->get_result()->fetch_assoc();
                    if ($savedUnitRow) {
                        $submittedMaterialValues['unit'] = (string)$savedUnitRow['unit'];
                    }
                }
            }
            $safeError = in_array($exception->getMessage(), [
                'Security check failed. Please try again.',
                'This material was already submitted. Please try again.',
                'Material not found. Refresh the report and try again.',
            ], true)
                ? $exception->getMessage()
                : 'We could not save the material. Please try again.';
            $_SESSION[$materialFormStateKey] = [
                'mode' => $action === 'update' ? 'edit' : 'add',
                'material_id' => isset($materialId) ? (int)$materialId : 0,
                'general_error' => $isUnitRestriction ? '' : $safeError,
                'errors' => $isUnitRestriction ? ['unit' => $exception->getMessage()] : [],
                'values' => $submittedMaterialValues !== [] ? $submittedMaterialValues : [
                    'material_name' => $materialPostText($_POST['material_name'] ?? ''),
                    'category' => $materialPostText($_POST['category'] ?? ''),
                    'unit' => $materialPostText($_POST['unit'] ?? ($_POST['locked_unit'] ?? '')),
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
$materialCreateSucceeded = is_array($materialFlash) && ($materialFlash['action'] ?? '') === 'create';
$materialFormState = $_SESSION[$materialFormStateKey] ?? null;
unset($_SESSION[$materialFormStateKey]);
$materialFormValues = is_array($materialFormState['values'] ?? null) ? $materialFormState['values'] : [];
$materialFormErrors = is_array($materialFormState['errors'] ?? null) ? $materialFormState['errors'] : [];
$materialFieldError = static fn(string $field): string => (string)($materialFormErrors[$field] ?? '');
$materialFormMode = ($materialFormState['mode'] ?? 'add') === 'edit' ? 'edit' : 'add';
$materialFormMaterialId = (int)($materialFormState['material_id'] ?? 0);
$materialCreateRequestToken = material_stock_issue_once_token('admin_material_create');
$materialUpdateRequestToken = material_stock_issue_once_token('admin_material_update');
$materialBulkArchiveRequestToken = material_stock_issue_once_token('admin_material_bulk_archive');
$materials = material_stock_fetch_materials($conn, $materialFilter);
$archiveBlockReasons = $materialFilter === 'active'
    ? material_stock_fetch_archive_block_reasons($conn, $materials)
    : [];
$latestPhysicalCounts = material_physical_count_fetch_latest($conn);
$deletableMaterialIds = array_fill_keys(material_stock_fetch_deletable_material_ids($conn), true);
$materialFormUnitEditable = $materialFormMode !== 'edit' || isset($deletableMaterialIds[$materialFormMaterialId]);
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
        <?php if ($materialFlash): ?>
            <div class="alert <?php echo $materialFlash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>">
                <?php echo htmlspecialchars((string)$materialFlash['message']); ?>
            </div>
        <?php endif; ?>

        <section class="form-panel inventory-report-panel">
            <div class="inventory-report-heading">
                <div>
                    <h1 class="section-title-inline"><?php echo $materialFilter === 'inactive' ? 'Archived Materials' : 'Inventory Report'; ?></h1>
                </div>
                <nav class="resource-page-tabs inventory-page-tabs inventory-report-tabs" aria-label="Inventory sections">
                    <a class="<?php echo $materialFilter === 'active' ? 'is-active' : ''; ?>" href="/codesamplecaps/ADMIN/sidebar/inventory/php/inventory.php?view=materials&amp;material_filter=active">Consumables</a>
                    <a href="/codesamplecaps/ADMIN/sidebar/inventory/php/inventory.php?view=history">Stock History</a>
                </nav>
                <div class="inventory-report-heading__actions">
                    <a class="btn-secondary" href="/codesamplecaps/ADMIN/sidebar/inventory/php/material_stock_in.php">Stock In</a>
                    <button type="button" class="btn-primary" data-material-modal-open>+ Add Material</button>
                </div>
            </div>
            <div class="inventory-report-search">
                <label class="sr-only" for="inventoryReportSearch">Search inventory report</label>
                <input id="inventoryReportSearch" type="search" placeholder="Search item, description, or unit" data-inventory-report-search>
            </div>
            <div class="material-list-filters" aria-label="Material status filter">
                <a class="action-chip<?php echo $materialFilter === 'active' ? ' active-chip' : ''; ?>" href="/codesamplecaps/ADMIN/sidebar/inventory/php/inventory.php?view=materials&amp;material_filter=active">Active</a>
                <a class="action-chip<?php echo $materialFilter === 'inactive' ? ' active-chip' : ''; ?>" href="/codesamplecaps/ADMIN/sidebar/inventory/php/inventory.php?view=materials&amp;material_filter=inactive">Archived</a>
            </div>
            <?php if ($materialFilter === 'active' && $materials !== []): ?>
                <form id="materialBulkArchiveForm" method="POST" class="material-bulk-actions" data-material-bulk-archive-form>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($materialCsrfToken); ?>">
                    <input type="hidden" name="request_token" value="<?php echo htmlspecialchars($materialBulkArchiveRequestToken); ?>">
                    <input type="hidden" name="material_action" value="bulk_archive">
                    <input type="hidden" name="material_filter" value="active">
                    <span data-material-selected-count>0 selected</span>
                    <button type="submit" class="btn-secondary" data-material-bulk-archive disabled>Archive Selected</button>
                </form>
            <?php endif; ?>
            <div class="table-responsive">
                <table class="data-table material-master-table mobile-card-table">
                    <thead><tr><?php if ($materialFilter === 'active'): ?><th class="material-selection-cell"><input type="checkbox" data-material-select-all aria-label="Select all active materials"></th><?php endif; ?><th>Item</th><th>Description</th><th>Unit</th><th>System Stock</th><th>Clerk Physical Count</th><th>Variance</th><th>Remarks</th><th><span class="sr-only">More actions</span></th></tr></thead>
                    <tbody>
                    <?php if ($materials === []): ?><tr><td colspan="<?php echo $materialFilter === 'active' ? '9' : '8'; ?>" class="empty-state"><?php echo $materialFilter === 'inactive' ? 'No archived materials.' : 'No active materials yet.'; ?></td></tr><?php endif; ?>
                    <?php foreach ($materials as $material): ?>
                        <?php
                        $latestCount = $latestPhysicalCounts[(int)$material['id']] ?? null;
                        $variance = $latestCount === null
                            ? null
                            : (float)$latestCount['counted_quantity'] - (float)$latestCount['total_quantity_snapshot'];
                        $isShortage = $variance !== null && $variance < -0.005;
                        $archiveBlockReason = $archiveBlockReasons[(int)$material['id']] ?? '';
                        $canArchive = $archiveBlockReason === '';
                        ?>
                        <tr class="<?php echo $isShortage ? 'material-master-row--shortage' : ''; ?>" data-inventory-report-row>
                            <?php if ($materialFilter === 'active'): ?>
                                <td data-label="Archive" class="material-selection-cell">
                                    <div class="material-selection-control">
                                        <input type="checkbox" form="materialBulkArchiveForm" name="material_ids[]" value="<?php echo (int)$material['id']; ?>" data-material-select aria-label="Select <?php echo htmlspecialchars((string)$material['material_name']); ?>"<?php echo $canArchive ? '' : ' disabled title="' . htmlspecialchars($archiveBlockReason, ENT_QUOTES, 'UTF-8') . '"'; ?>>
                                        <?php if (!$canArchive): ?><small><?php echo htmlspecialchars($archiveBlockReason); ?></small><?php endif; ?>
                                    </div>
                                </td>
                            <?php endif; ?>
                            <td data-label="Item"><strong><?php echo htmlspecialchars((string)$material['material_name']); ?></strong><small><?php echo htmlspecialchars((string)$material['material_code']); ?></small></td>
                            <td data-label="Description" class="material-report-description"><?php echo htmlspecialchars(trim((string)$material['description']) ?: '—'); ?></td>
                            <td data-label="Unit"><?php echo htmlspecialchars((string)$material['unit']); ?></td>
                            <td data-label="System Stock" class="material-master-number"><?php echo htmlspecialchars($displayMaterialQuantity((float)$material['physical_quantity'])); ?></td>
                            <td data-label="Clerk Physical Count" class="material-master-count">
                                <?php if ($latestCount === null): ?>
                                    <span class="material-audit-pending">Not yet counted</span>
                                <?php else: ?>
                                    <strong><?php echo htmlspecialchars($displayMaterialQuantity((float)$latestCount['counted_quantity'])); ?></strong>
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
                                        <?php if ($materialFilter === 'active'): ?>
                                            <button
                                                type="button"
                                                class="material-row-menu__edit"
                                                data-material-edit-open
                                                data-material-id="<?php echo (int)$material['id']; ?>"
                                                data-material-name="<?php echo htmlspecialchars((string)$material['material_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-material-description="<?php echo htmlspecialchars((string)($material['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-material-category="<?php echo htmlspecialchars((string)($material['category'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-material-unit="<?php echo htmlspecialchars((string)$material['unit'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-material-reorder="<?php echo htmlspecialchars((string)($material['reorder_level'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-unit-editable="<?php echo isset($deletableMaterialIds[(int)$material['id']]) ? 'true' : 'false'; ?>"
                                                role="menuitem"
                                            >Edit</button>
                                        <?php endif; ?>
                                        <?php if ($materialFilter === 'active'): ?>
                                            <?php if ($canArchive): ?>
                                                <form method="POST" data-material-archive-form>
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($materialCsrfToken); ?>">
                                                    <input type="hidden" name="material_id" value="<?php echo (int)$material['id']; ?>">
                                                    <input type="hidden" name="material_action" value="archive">
                                                    <input type="hidden" name="material_filter" value="active">
                                                    <button type="submit" class="material-row-menu__archive" data-material-name="<?php echo htmlspecialchars((string)$material['material_name']); ?>" role="menuitem">Archive</button>
                                                </form>
                                            <?php else: ?>
                                                <button type="button" class="material-row-menu__archive" role="menuitem" disabled>Archive</button>
                                                <small><?php echo htmlspecialchars($archiveBlockReason); ?></small>
                                            <?php endif; ?>
                                        <?php elseif (isset($deletableMaterialIds[(int)$material['id']])): ?>
                                            <form method="POST">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($materialCsrfToken); ?>">
                                                <input type="hidden" name="material_id" value="<?php echo (int)$material['id']; ?>">
                                                <input type="hidden" name="material_action" value="restore">
                                                <input type="hidden" name="material_filter" value="inactive">
                                                <button type="submit" class="material-row-menu__edit" role="menuitem">Restore</button>
                                            </form>
                                            <form method="POST" data-material-remove-form>
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($materialCsrfToken); ?>">
                                                <input type="hidden" name="material_id" value="<?php echo (int)$material['id']; ?>">
                                                <input type="hidden" name="material_filter" value="inactive">
                                                <input type="hidden" name="material_action" value="permanent_delete">
                                                <button type="button" class="material-row-menu__remove" data-material-remove-open data-material-name="<?php echo htmlspecialchars((string)$material['material_name']); ?>" role="menuitem">Remove</button>
                                            </form>
                                        <?php else: ?>
                                            <?php if ($materialFilter === 'inactive'): ?>
                                                <form method="POST">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($materialCsrfToken); ?>">
                                                    <input type="hidden" name="material_id" value="<?php echo (int)$material['id']; ?>">
                                                    <input type="hidden" name="material_action" value="restore">
                                                    <input type="hidden" name="material_filter" value="inactive">
                                                    <button type="submit" class="material-row-menu__edit" role="menuitem">Restore</button>
                                                </form>
                                                <button type="button" class="material-row-menu__remove" role="menuitem" disabled>Remove</button>
                                                <small>Cannot remove: this item has stock or history.</small>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr data-inventory-report-empty hidden><td colspan="<?php echo $materialFilter === 'active' ? '9' : '8'; ?>" class="empty-state">No matching materials.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="material-create-modal" data-material-modal data-draft-scope="<?php echo (int)$_SESSION['user_id'] . ':' . (int)($_SESSION['logged_in_at'] ?? 0); ?>" data-create-token="<?php echo htmlspecialchars($materialCreateRequestToken); ?>" data-update-token="<?php echo htmlspecialchars($materialUpdateRequestToken); ?>" data-auto-mode="<?php echo htmlspecialchars($materialFormMode); ?>" data-auto-material-id="<?php echo $materialFormMaterialId; ?>" data-create-succeeded="<?php echo $materialCreateSucceeded ? 'true' : 'false'; ?>" <?php echo $materialFormState ? 'data-auto-open="true"' : ''; ?> hidden>
            <section class="material-create-modal__panel" role="dialog" aria-modal="true" aria-labelledby="materialCreateModalTitle" tabindex="-1">
                <div class="material-create-modal__header">
                    <h2 id="materialCreateModalTitle"><?php echo $materialFormMode === 'edit' ? 'Edit Material' : 'Add Material'; ?></h2>
                    <button type="button" class="material-create-modal__close" data-material-modal-close aria-label="Close material form">&times;</button>
                </div>
                <?php if (!empty($materialFormState['general_error'])): ?>
                    <div class="alert alert-error" role="alert" tabindex="-1" data-material-general-error><?php echo htmlspecialchars((string)$materialFormState['general_error']); ?></div>
                <?php endif; ?>
                <form method="POST" data-material-create-form novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($materialCsrfToken); ?>">
                    <input type="hidden" name="request_token" value="<?php echo htmlspecialchars($materialFormMode === 'edit' ? $materialUpdateRequestToken : $materialCreateRequestToken); ?>" data-material-request-token>
                    <input type="hidden" name="material_action" value="<?php echo $materialFormMode === 'edit' ? 'update' : 'create'; ?>" data-material-form-action>
                    <input type="hidden" name="material_id" value="<?php echo $materialFormMode === 'edit' ? $materialFormMaterialId : ''; ?>" data-material-form-id>
                    <input type="hidden" name="locked_unit" value="<?php echo htmlspecialchars((string)($materialFormValues['unit'] ?? '')); ?>" data-material-locked-unit <?php echo $materialFormUnitEditable ? 'disabled' : ''; ?>>
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
                            <?php $materialUnitDescriptions = trim(($materialFormUnitEditable ? '' : 'materialUnitLockReason ') . ($materialFieldError('unit') !== '' ? 'materialUnitError' : '')); ?>
                            <select id="unit" name="unit" <?php echo $materialFormUnitEditable ? '' : 'disabled '; ?><?php echo $materialFieldError('unit') !== '' ? 'aria-invalid="true" ' : ''; ?><?php echo $materialUnitDescriptions !== '' ? 'aria-describedby="' . htmlspecialchars($materialUnitDescriptions) . '" ' : ''; ?>required><option value="" disabled<?php echo empty($materialFormValues['unit']) ? ' selected' : ''; ?>>Select unit</option><?php foreach ($materialUnits as $unit): ?><option value="<?php echo htmlspecialchars($unit); ?>" data-whole-number="<?php echo in_array($unit, $wholeCountUnits, true) ? 'true' : 'false'; ?>"<?php echo ($materialFormValues['unit'] ?? '') === $unit ? ' selected' : ''; ?>><?php echo htmlspecialchars($unit); ?></option><?php endforeach; ?></select>
                            <small id="materialUnitLockReason" class="material-field-hint" data-material-unit-lock<?php echo $materialFormUnitEditable ? ' hidden' : ''; ?>>Unit cannot be changed because this material has stock or history.</small>
                            <small id="materialUnitError" class="material-form-error" data-material-field-error="unit"<?php echo $materialFieldError('unit') === '' ? ' hidden' : ''; ?>><?php echo htmlspecialchars($materialFieldError('unit')); ?></small>
                        </div>
                        <div class="input-group material-master-reorder">
                            <div class="material-field-label-row">
                                <label for="reorder_level">Low Stock Alert Level</label>
                                <span class="material-field-info" data-material-tooltip>
                                    <button type="button" aria-label="About Low Stock Alert Level" aria-describedby="materialReorderTooltip" aria-expanded="false">i</button>
                                    <span id="materialReorderTooltip" role="tooltip">Shows a low-stock alert when on-hand stock reaches this quantity or lower.</span>
                                </span>
                            </div>
                            <input id="reorder_level" name="reorder_level" type="number" min="0" max="<?php echo htmlspecialchars(material_stock_threshold_max_for_unit((string)($materialFormValues['unit'] ?? ''))); ?>" step="<?php echo material_stock_unit_requires_whole_number((string)($materialFormValues['unit'] ?? '')) ? '1' : '0.01'; ?>" placeholder="e.g., 10" value="<?php echo htmlspecialchars((string)($materialFormValues['reorder_level'] ?? '')); ?>" <?php echo $materialFieldError('reorder_level') !== '' ? 'aria-invalid="true" aria-describedby="materialReorderError"' : ''; ?> required>
                            <small id="materialReorderError" class="material-form-error" data-material-field-error="reorder_level"<?php echo $materialFieldError('reorder_level') === '' ? ' hidden' : ''; ?>><?php echo htmlspecialchars($materialFieldError('reorder_level')); ?></small>
                        </div>
                        <div class="input-group material-master-description">
                            <label for="description">Description (Optional)</label>
                            <textarea id="description" name="description" maxlength="255" rows="3" placeholder="e.g., Blue cable for indoor network runs" <?php echo $materialFieldError('description') !== '' ? 'aria-invalid="true" aria-describedby="materialDescriptionError"' : ''; ?>><?php echo htmlspecialchars((string)($materialFormValues['description'] ?? '')); ?></textarea>
                            <small id="materialDescriptionError" class="material-form-error" data-material-field-error="description"<?php echo $materialFieldError('description') === '' ? ' hidden' : ''; ?>><?php echo htmlspecialchars($materialFieldError('description')); ?></small>
                        </div>
                    </div>
                    <div class="material-create-modal__actions">
                        <button type="button" class="btn-secondary material-create-modal__clear" data-material-clear<?php echo $materialFormMode === 'edit' ? ' hidden' : ''; ?>>Clear Form</button>
                        <div class="material-create-modal__actions-end">
                            <button type="button" class="btn-secondary" data-material-modal-close>Cancel</button>
                            <button type="submit" class="btn-primary" data-material-submit><?php echo $materialFormMode === 'edit' ? 'Save Changes' : 'Add Material'; ?></button>
                        </div>
                    </div>
                </form>
            </section>
        </div>

        <div class="material-remove-modal" data-material-archive-modal hidden>
            <section class="material-remove-modal__panel" role="dialog" aria-modal="true" aria-labelledby="materialArchiveTitle" aria-describedby="materialArchiveMessage">
                <h2 id="materialArchiveTitle">Archive material?</h2>
                <p id="materialArchiveMessage" data-material-archive-message></p>
                <div class="material-remove-modal__actions">
                    <button type="button" class="btn-secondary" data-material-archive-close>Cancel</button>
                    <button type="button" class="btn-primary" data-material-archive-confirm>Archive</button>
                </div>
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
