<?php
require_once __DIR__ . '/../../../includes/admin_auth.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/audit_log.php';
require_once __DIR__ . '/../../../../config/inquiry_quotation_module.php';
require_once __DIR__ . '/../../../../config/material_stock.php';

$editId = (int)($_GET['edit_id'] ?? $_POST['edit_id'] ?? 0);
$isEditMode = $editId > 0;
$quotationDraft = null;
$savedItems = [];

if ($isEditMode) {
    $draftStmt = $conn->prepare(
        'SELECT id, inquiry_id, quotation_no, status, subtotal, profit_margin_percent, profit_amount, grand_total
         FROM inquiry_quotation_drafts
         WHERE id = ? LIMIT 1'
    );
    if (!$draftStmt) {
        http_response_code(500);
        exit('Unable to load quotation draft.');
    }

    $draftStmt->bind_param('i', $editId);
    $draftStmt->execute();
    $quotationDraft = $draftStmt->get_result()->fetch_assoc();

    if (!$quotationDraft || inquiry_quote_normalize_status((string)$quotationDraft['status']) !== 'draft') {
        $_SESSION['inquiry_center_flash'] = 'Only draft quotations can be edited.';
        header('Location: /codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php');
        exit();
    }

    $inquiryId = (int)$quotationDraft['inquiry_id'];
    $savedItems = inquiry_quote_fetch_items($conn, $editId);
} else {
    $inquiryId = (int)($_GET['inquiry_id'] ?? $_POST['inquiry_id'] ?? 0);
}

if ($inquiryId <= 0) {
    header('Location: /codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php');
    exit();
}

$inquiryStmt = $conn->prepare(
    'SELECT id, client_name, company_name, email, contact_no, province, city_municipality,
            barangay, site_address, service_category, description, status
     FROM service_inquiries
     WHERE id = ? LIMIT 1'
);

if (!$inquiryStmt) {
    http_response_code(500);
    exit('Unable to load inquiry.');
}

$inquiryStmt->bind_param('i', $inquiryId);
$inquiryStmt->execute();
$inquiry = $inquiryStmt->get_result()->fetch_assoc();

if (!$inquiry || (!$isEditMode && (string)$inquiry['status'] !== 'Verified Lead')) {
    $_SESSION['inquiry_center_flash'] = 'Only verified leads can have quotations created.';
    header('Location: /codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php');
    exit();
}

if (!$isEditMode) {
    $existingStmt = $conn->prepare(
        'SELECT id FROM inquiry_quotation_drafts WHERE inquiry_id = ? ORDER BY updated_at DESC, id DESC LIMIT 1'
    );
    if ($existingStmt) {
        $existingStmt->bind_param('i', $inquiryId);
        $existingStmt->execute();
        if ($existingStmt->get_result()->fetch_assoc()) {
            $_SESSION['inquiry_center_flash'] = 'This inquiry already has a quotation.';
            header('Location: /codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?open=inquiryModal' . $inquiryId);
            exit();
        }
    }
}

$csrfToken = auth_csrf_token('admin_create_inquiry_quotation');
$unitOptionsByType = [
    'material' => ['pcs', 'meter', 'roll', 'box', 'pack', 'set', 'kg', 'liter'],
    'labor' => ['person', 'hour', 'day', 'lot'],
    'equipment' => ['unit', 'hour', 'day', 'set', 'lot'],
    'service' => ['service', 'job', 'lot'],
    'other' => ['unit', 'lot'],
];
$wholeCountUnits = ['pcs', 'roll', 'box', 'pack', 'set', 'lot', 'person'];
$materialOptions = material_stock_fetch_active_materials($conn);
$materialUnitsById = [];
$materialNamesById = [];
foreach ($materialOptions as $materialOption) {
    $materialUnitsById[(int)$materialOption['id']] = (string)$materialOption['unit'];
    $materialNamesById[(int)$materialOption['id']] = (string)$materialOption['material_name'];
}

function inquiry_quote_render_unit_options(array $unitOptionsByType, array $materialUnitsById, string $itemType, int $materialId, string $currentUnit): void
{
    $unitChoices = $unitOptionsByType[$itemType] ?? $unitOptionsByType['other'];
    if ($itemType === 'material' && $materialId > 0 && isset($materialUnitsById[$materialId])) {
        $unitChoices = [$materialUnitsById[$materialId]];
    }

    foreach ($unitChoices as $unitChoice) {
        $isSelected = strtolower($currentUnit) === strtolower($unitChoice);
        echo '<option value="' . htmlspecialchars($unitChoice, ENT_QUOTES, 'UTF-8') . '"' . ($isSelected ? ' selected' : '') . '>'
            . htmlspecialchars($unitChoice, ENT_QUOTES, 'UTF-8') . '</option>';
    }
}

$quotationNo = $isEditMode
    ? (string)$quotationDraft['quotation_no']
    : inquiry_quote_generate_number();
$error = '';
$defaultItems = $savedItems ?: [[
    'item_type' => 'material',
    'material_id' => '',
    'item_name' => '',
    'quantity' => '1',
    'unit' => 'pcs',
    'unit_cost' => '',
    'notes' => '',
]];
$postedTypes = is_array($_POST['item_type'] ?? null) ? $_POST['item_type'] : array_column($defaultItems, 'item_type');
$postedMaterialIds = is_array($_POST['material_id'] ?? null) ? $_POST['material_id'] : array_column($defaultItems, 'material_id');
$postedNames = is_array($_POST['item_name'] ?? null) ? $_POST['item_name'] : array_column($defaultItems, 'item_name');
$postedQuantities = is_array($_POST['quantity'] ?? null) ? $_POST['quantity'] : array_column($defaultItems, 'quantity');
$postedUnits = is_array($_POST['unit'] ?? null) ? $_POST['unit'] : array_column($defaultItems, 'unit');
$postedUnitCosts = is_array($_POST['unit_cost'] ?? null) ? $_POST['unit_cost'] : array_column($defaultItems, 'unit_cost');
$postedNotes = is_array($_POST['item_notes'] ?? null) ? $_POST['item_notes'] : array_column($defaultItems, 'notes');
$isQuotationPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$marginPercentRaw = trim((string)($_POST['profit_margin_percent'] ?? ($quotationDraft['profit_margin_percent'] ?? 15)));
$marginPercent = is_numeric($marginPercentRaw) ? (float)$marginPercentRaw : 0.0;
$markupError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'admin_create_inquiry_quotation')) {
        $error = 'Your form expired. Please refresh and try again.';
    } elseif ($marginPercentRaw === '') {
        $markupError = 'Markup is required.';
        $error = $markupError;
    } elseif (!is_numeric($marginPercentRaw) || !is_finite($marginPercent)) {
        $markupError = 'Markup must be a number from 0 to 100%.';
        $error = $markupError;
    } elseif ($marginPercent < 0) {
        $markupError = 'Markup cannot be negative.';
        $error = $markupError;
    } elseif ($marginPercent > 100) {
        $markupError = 'Markup cannot be greater than 100%.';
        $error = $markupError;
    } elseif (count($postedNames) === 0 || count($postedNames) > 50) {
        $error = 'Add from 1 to 50 quotation items only.';
    } else {
        $allowedTypes = ['material', 'labor', 'equipment', 'service', 'other'];
        $quotationItems = [];
        $subtotal = 0.0;

        foreach ($postedNames as $index => $postedName) {
            $itemName = trim((string)$postedName);
            $itemType = strtolower(trim((string)($postedTypes[$index] ?? 'other')));
            $quantityRaw = trim((string)($postedQuantities[$index] ?? ''));
            $quantity = is_numeric($quantityRaw) ? (float)$quantityRaw : 0.0;
            $unit = trim((string)($postedUnits[$index] ?? 'unit'));
            $unitCostRaw = trim((string)($postedUnitCosts[$index] ?? ''));
            $unitCost = is_numeric($unitCostRaw) ? (float)$unitCostRaw : 0.0;
            $notes = trim((string)($postedNotes[$index] ?? ''));
            $materialId = $itemType === 'material' ? (int)($postedMaterialIds[$index] ?? 0) : 0;

            if (!in_array($itemType, $allowedTypes, true)) {
                $error = 'Please select a valid item type.';
                break;
            }
            if ($materialId > 0 && !material_stock_material_exists($conn, $materialId)) {
                $error = 'Selected material is no longer available. Please choose again.';
                break;
            }
            if ($itemType === 'material' && $materialId > 0) {
                $itemName = $materialNamesById[$materialId] ?? '';
            }
            if ($itemName === '' || strlen($itemName) > 180) {
                $error = 'Each quotation item needs a valid name.';
                break;
            }
            if ($quantityRaw === '') {
                $error = 'Quantity is required.';
                break;
            }
            if (!is_numeric($quantityRaw) || !is_finite($quantity) || $quantity <= 0 || $quantity > 999999) {
                $error = 'Quantity must be greater than 0.';
                break;
            }
            if ($unitCostRaw === '') {
                $error = 'Estimated Unit Cost is required.';
                break;
            }
            if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $unitCostRaw) || !is_finite($unitCost) || $unitCost <= 0 || $unitCost > 999999999) {
                $error = 'Estimated Unit Cost must be greater than 0.';
                break;
            }
            if ($unit === '' || strlen($unit) > 30 || strlen($notes) > 2000) {
                $error = 'Check the unit and notes of each item.';
                break;
            }
            if ($itemType === 'material' && $materialId > 0) {
                $materialUnit = $materialUnitsById[$materialId] ?? '';
                if ($materialUnit === '' || strcasecmp($unit, $materialUnit) !== 0) {
                    $error = 'Linked material units cannot be changed.';
                    break;
                }
            } elseif (!in_array(strtolower($unit), $unitOptionsByType[$itemType] ?? [], true)) {
                $error = 'Choose a valid unit for each item type.';
                break;
            }
            if (in_array(strtolower($unit), $wholeCountUnits, true) && $quantity !== floor($quantity)) {
                $error = 'Whole-count units require a whole quantity of at least 1.';
                break;
            }

            $lineTotal = round($quantity * $unitCost, 2);
            $subtotal += $lineTotal;
            $quotationItems[] = [
                'type' => $itemType,
                'material_id' => $materialId > 0 ? $materialId : null,
                'name' => $itemName,
                'quantity' => $quantity,
                'unit' => $unit,
                'unit_cost' => $unitCost,
                'line_total' => $lineTotal,
                'notes' => $notes,
            ];
        }

        if ($error === '' && $subtotal <= 0) {
            $error = 'Quotation total must be greater than zero.';
        }

        if ($error === '') {
            $profitAmount = round($subtotal * ($marginPercent / 100), 2);
            $grandTotal = round($subtotal + $profitAmount, 2);
            $status = 'Draft';
            $adminId = (int)($_SESSION['user_id'] ?? 0);

            $conn->begin_transaction();
            try {
                if ($isEditMode) {
                    $lockStmt = $conn->prepare('SELECT status FROM inquiry_quotation_drafts WHERE id = ? FOR UPDATE');
                    if (!$lockStmt) {
                        throw new RuntimeException('Unable to lock quotation draft.');
                    }
                    $lockStmt->bind_param('i', $editId);
                    $lockStmt->execute();
                    $lockedDraft = $lockStmt->get_result()->fetch_assoc();
                    if (!$lockedDraft || inquiry_quote_normalize_status((string)$lockedDraft['status']) !== 'draft') {
                        throw new RuntimeException('Only draft quotations can be edited.');
                    }

                    $stmt = $conn->prepare(
                        'UPDATE inquiry_quotation_drafts
                         SET subtotal = ?, profit_margin_percent = ?, profit_amount = ?, grand_total = ?
                         WHERE id = ?'
                    );
                    if (!$stmt) {
                        throw new RuntimeException('Unable to prepare quotation update.');
                    }
                    $stmt->bind_param('ddddi', $subtotal, $marginPercent, $profitAmount, $grandTotal, $editId);
                    $stmt->execute();
                    $draftId = $editId;

                    $deleteItems = $conn->prepare('DELETE FROM inquiry_quotation_items WHERE draft_id = ?');
                    if (!$deleteItems) {
                        throw new RuntimeException('Unable to prepare quotation item update.');
                    }
                    $deleteItems->bind_param('i', $draftId);
                    $deleteItems->execute();

                } else {
                    $stmt = $conn->prepare(
                        'INSERT INTO inquiry_quotation_drafts
                         (inquiry_id, inspection_id, quotation_no, subtotal, profit_margin_percent, profit_amount, grand_total, status, created_by)
                         VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    if (!$stmt) {
                        throw new RuntimeException('Unable to prepare quotation.');
                    }

                    $stmt->bind_param('isddddsi', $inquiryId, $quotationNo, $subtotal, $marginPercent, $profitAmount, $grandTotal, $status, $adminId);
                    $stmt->execute();
                    $draftId = (int)$conn->insert_id;
                }

                $itemStmt = $conn->prepare(
                    'INSERT INTO inquiry_quotation_items
                     (draft_id, item_type, material_id, item_name, quantity, unit, unit_cost, line_total, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                if (!$itemStmt) {
                    throw new RuntimeException('Unable to prepare quotation items.');
                }

                foreach ($quotationItems as $item) {
                    $itemStmt->bind_param('isisdsdds', $draftId, $item['type'], $item['material_id'], $item['name'], $item['quantity'], $item['unit'], $item['unit_cost'], $item['line_total'], $item['notes']);
                    $itemStmt->execute();
                }

                if (!$isEditMode) {
                    inquiry_quote_add_history($conn, $draftId, null, 'draft', 'Admin created quotation from verified inquiry.', $adminId, 'admin');
                }
                $conn->commit();

                $oldAuditData = $isEditMode ? [
                    'subtotal' => (float)$quotationDraft['subtotal'],
                    'profit_margin_percent' => (float)$quotationDraft['profit_margin_percent'],
                    'profit_amount' => (float)$quotationDraft['profit_amount'],
                    'grand_total' => (float)$quotationDraft['grand_total'],
                ] : null;
                audit_log_event($conn, $adminId, $isEditMode ? 'update_inquiry_quotation_draft' : 'create_inquiry_quotation_direct', 'quotation', $draftId, $oldAuditData, [
                    'inquiry_id' => $inquiryId,
                    'quotation_no' => $quotationNo,
                    'subtotal' => $subtotal,
                    'profit_margin_percent' => $marginPercent,
                    'grand_total' => $grandTotal,
                ]);

                $_SESSION['inquiry_center_flash'] = $isEditMode ? 'Quotation draft updated.' : 'Quotation draft created.';
                header(
                    'Location: /codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?open=inquiryModal'
                        . $inquiryId
                        . '&status=' . rawurlencode((string)$inquiry['status'])
                        . '&tab=quotation'
                );
                exit();
            } catch (Throwable $throwable) {
                $conn->rollback();
                error_log('Inquiry quotation save failed: ' . $throwable->getMessage());
                $error = $isEditMode ? 'Unable to update quotation draft.' : 'Unable to create quotation. Please check the quotation database setup.';
            }
        }
    }
}

$adminPageTitle = ($isEditMode ? 'Edit Quotation' : 'Create Quotation') . ' - Edge Automation';
$adminCssFiles = [
    '/codesamplecaps/ADMIN/common/css/admin-common.css',
    '/codesamplecaps/SHARED/toast/css/toast.css',
    '/codesamplecaps/ADMIN/sidebar/inquiries/css/inquiries.css',
];
$adminJsFiles = [
    '/codesamplecaps/ADMIN/common/js/admin-common.js',
    '/codesamplecaps/SHARED/toast/js/toast.js',
    '/codesamplecaps/ADMIN/sidebar/inquiries/js/inquiries.js',
];

include __DIR__ . '/../../../layout/header.php';
include __DIR__ . '/../../../admin_sidebar.php';
?>

<main class="main-content admin-dashboard-content">
    <div class="inquiries-shell quotation-create-shell" data-quotation-create>
        <?php if ($error !== ''): ?>
            <div class="shared-toast shared-toast--error" role="alert" data-shared-toast>
                <span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
                <button type="button" class="shared-toast__close" data-shared-toast-close aria-label="Close notification">&times;</button>
                <span class="shared-toast__progress" aria-hidden="true"></span>
            </div>
        <?php endif; ?>

        <section class="quotation-create-card">
            <a href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?inquiry_id=<?php echo $inquiryId; ?>&amp;tab=quotation" class="btn-secondary quotation-create-back">&larr; Back to Inquiries</a>

            <div class="quotation-create-heading">
                <div>
                    <span class="reports-kicker<?php echo $isEditMode ? '' : ' quotation-create-status'; ?>"><?php echo $isEditMode ? 'Quotation Draft' : 'Verified Lead'; ?></span>
                    <h1><?php echo $isEditMode ? 'Edit Quotation' : 'Create Quotation'; ?></h1>
                    <?php if ($isEditMode): ?>
                        <p>Update the draft scope costs before approval and sending.</p>
                    <?php endif; ?>
                </div>
                <div class="quotation-create-number">
                    <span>Quotation No.</span>
                    <strong><?php echo htmlspecialchars($quotationNo, ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
            </div>

            <div class="quotation-create-client-grid">
                <div><span>Contact Person</span><strong><?php echo htmlspecialchars((string)$inquiry['client_name'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <?php if (trim((string)$inquiry['company_name']) !== ''): ?>
                    <div><span>Company / Organization</span><strong><?php echo htmlspecialchars((string)$inquiry['company_name'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <?php endif; ?>
                <div><span>Service</span><strong><?php echo htmlspecialchars((string)$inquiry['service_category'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div><span>Location</span><strong><?php echo htmlspecialchars(trim((string)$inquiry['site_address'] . ', ' . (string)$inquiry['barangay'] . ', ' . (string)$inquiry['city_municipality'] . ', ' . (string)$inquiry['province'], ' ,'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            </div>

            <div class="quotation-create-scope">
                <span>Inquiry Scope</span>
                <p><?php echo nl2br(htmlspecialchars((string)($inquiry['description'] ?: 'No scope description provided.'), ENT_QUOTES, 'UTF-8')); ?></p>
            </div>

            <form method="POST" class="quotation-create-form" data-quotation-create-form data-quotation-edit-mode="<?php echo $isEditMode ? 'true' : 'false'; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="inquiry_id" value="<?php echo $inquiryId; ?>">
                <?php if ($isEditMode): ?>
                    <input type="hidden" name="edit_id" value="<?php echo $editId; ?>">
                <?php endif; ?>

                <div class="quotation-create-section-head">
                    <div>
                        <h2>Cost Breakdown</h2>
                        <p>Billable materials, labor, services, and other project costs included in the quotation.</p>
                    </div>
                </div>

                <div class="quotation-create-items" data-quotation-items>
                    <?php foreach ($postedNames as $index => $postedName): ?>
                        <?php
                        $rowType = (string)($postedTypes[$index] ?? 'material');
                        $rowMaterialId = (int)($postedMaterialIds[$index] ?? 0);
                        $rowUnit = (string)($postedUnits[$index] ?? 'pcs');
                        $rowUnitLocked = $rowType === 'material' && $rowMaterialId > 0;
                        $rowNameLocked = $rowUnitLocked;
                        ?>
                        <div class="quotation-create-item<?php echo $rowType === 'material' ? ' is-material-item' : ''; ?>" data-quotation-item>
                            <label class="quotation-create-item__type"><span>Type</span><select name="item_type[]" required><?php foreach (['material' => 'Material', 'labor' => 'Labor', 'equipment' => 'Equipment (Billable / Rental)', 'service' => 'Service', 'other' => 'Other'] as $typeValue => $typeLabel): ?><option value="<?php echo $typeValue; ?>" <?php echo $rowType === $typeValue ? 'selected' : ''; ?>><?php echo $typeLabel; ?></option><?php endforeach; ?></select></label>
                            <label class="quotation-create-item__material-reference" data-quotation-material-reference<?php echo $rowType !== 'material' ? ' hidden' : ''; ?>><span>Material Reference</span><select name="material_id[]">
                                    <option value="">Select material</option><?php foreach ($materialOptions as $material): ?><option value="<?php echo (int)$material['id']; ?>" data-material-name="<?php echo htmlspecialchars((string)$material['material_name'], ENT_QUOTES, 'UTF-8'); ?>" data-material-unit="<?php echo htmlspecialchars((string)$material['unit'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo $rowMaterialId === (int)$material['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$material['material_name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?><option value="manual" <?php echo (string)($postedMaterialIds[$index] ?? '') === 'manual' ? ' selected' : ''; ?>>Manual / non-stock material</option>
                                </select></label>
                            <label class="quotation-create-item__name"><span>Item / Work</span><input type="text" name="item_name[]" maxlength="180" value="<?php echo htmlspecialchars((string)$postedName, ENT_QUOTES, 'UTF-8'); ?>" required<?php echo $rowNameLocked ? ' class="is-linked-material-name" readonly aria-readonly="true"' : ''; ?>></label>
                            <label class="quotation-create-item__quantity"><span>Qty</span><input type="number" name="quantity[]" min="0.01" step="0.01" value="<?php echo htmlspecialchars((string)($postedQuantities[$index] ?? '1'), ENT_QUOTES, 'UTF-8'); ?>" required><span class="quotation-create-item__quantity-error" data-quotation-quantity-error aria-live="polite"></span></label>
                            <label class="quotation-create-item__unit"><span>Unit</span><select name="unit[]" required data-quotation-unit class="<?php echo $rowUnitLocked ? 'is-locked' : ''; ?>" <?php echo $rowUnitLocked ? ' aria-readonly="true" tabindex="-1"' : ''; ?>><?php inquiry_quote_render_unit_options($unitOptionsByType, $materialUnitsById, $rowType, $rowMaterialId, $rowUnit); ?></select></label>
                            <label class="quotation-create-item__cost"><span>Estimated Unit Cost</span><input type="number" name="unit_cost[]" min="0.01" step="0.01" value="<?php echo htmlspecialchars((string)($postedUnitCosts[$index] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required><span class="quotation-create-item__cost-error" data-quotation-cost-error aria-live="polite"></span></label>
                            <div class="quotation-create-item__line-total"><span>Line Total</span><strong>PHP <span data-quotation-line-total>0.00</span></strong></div>
                            <label class="quotation-create-item__notes"><span>Notes / Exclusions (Optional)</span><input type="text" name="item_notes[]" maxlength="2000" value="<?php echo htmlspecialchars((string)($postedNotes[$index] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></label>
                            <button type="button" class="quotation-create-remove" data-quotation-remove-item aria-label="Remove quotation item"><span class="quotation-create-remove__icon" aria-hidden="true">🗑</span><span>Remove</span></button>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="quotation-create-add-row">
                    <button type="button" class="btn-secondary" data-quotation-add-item>Add Item</button>
                </div>

                <template data-quotation-item-template>
                    <div class="quotation-create-item is-material-item" data-quotation-item>
                        <label class="quotation-create-item__type"><span>Type</span><select name="item_type[]" required>
                                <option value="material">Material</option>
                                <option value="labor">Labor</option>
                                <option value="equipment">Equipment (Billable / Rental)</option>
                                <option value="service">Service</option>
                                <option value="other">Other</option>
                            </select></label>
                        <label class="quotation-create-item__material-reference" data-quotation-material-reference><span>Material Reference</span><select name="material_id[]">
                                <option value="">Select material</option><?php foreach ($materialOptions as $material): ?><option value="<?php echo (int)$material['id']; ?>" data-material-name="<?php echo htmlspecialchars((string)$material['material_name'], ENT_QUOTES, 'UTF-8'); ?>" data-material-unit="<?php echo htmlspecialchars((string)$material['unit'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$material['material_name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?><option value="manual">Manual / non-stock material</option>
                            </select></label>
                        <label class="quotation-create-item__name"><span>Item / Work</span><input type="text" name="item_name[]" maxlength="180" required></label>
                        <label class="quotation-create-item__quantity"><span>Qty</span><input type="number" name="quantity[]" min="0.01" step="0.01" value="1" required><span class="quotation-create-item__quantity-error" data-quotation-quantity-error aria-live="polite"></span></label>
                        <label class="quotation-create-item__unit"><span>Unit</span><select name="unit[]" required data-quotation-unit>
                                <option value="pcs" selected>pcs</option>
                                <option value="meter">meter</option>
                                <option value="roll">roll</option>
                                <option value="box">box</option>
                                <option value="pack">pack</option>
                                <option value="set">set</option>
                                <option value="kg">kg</option>
                                <option value="liter">liter</option>
                            </select></label>
                        <label class="quotation-create-item__cost"><span>Estimated Unit Cost</span><input type="number" name="unit_cost[]" min="0.01" step="0.01" required><span class="quotation-create-item__cost-error" data-quotation-cost-error aria-live="polite"></span></label>
                        <div class="quotation-create-item__line-total"><span>Line Total</span><strong>PHP <span data-quotation-line-total>0.00</span></strong></div>
                        <label class="quotation-create-item__notes"><span>Notes / Exclusions (Optional)</span><input type="text" name="item_notes[]" maxlength="2000"></label>
                        <button type="button" class="quotation-create-remove" data-quotation-remove-item aria-label="Remove quotation item"><span class="quotation-create-remove__icon" aria-hidden="true">🗑</span><span>Remove</span></button>
                    </div>
                </template>

                <div class="quotation-create-summary">
                    <label><span>Markup (%)</span><input type="number" name="profit_margin_percent" min="0" max="100" step="0.01" value="<?php echo htmlspecialchars($marginPercentRaw, ENT_QUOTES, 'UTF-8'); ?>" required><span class="quotation-create-summary__error" data-quotation-markup-error aria-live="polite"><?php echo htmlspecialchars($markupError, ENT_QUOTES, 'UTF-8'); ?></span></label>
                    <div><span>Subtotal</span><strong>PHP <span data-quotation-subtotal>0.00</span></strong></div>
                    <div><span>Profit</span><strong>PHP <span data-quotation-profit>0.00</span></strong></div>
                    <div class="quotation-create-grand-total"><span>Grand Total</span><strong>PHP <span data-quotation-total>0.00</span></strong></div>
                </div>

                <div class="quotation-create-actions">
                    <button type="submit" class="btn-primary" <?php echo $isEditMode ? 'data-confirm-quotation-update data-quotation-update-submit disabled' : 'data-confirm-quotation-save'; ?>><?php echo $isEditMode ? 'Save Quotation Changes' : 'Create Quotation Draft'; ?></button>
                    <a href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?inquiry_id=<?php echo $inquiryId; ?>&amp;tab=quotation" class="btn-secondary" data-quotation-cancel>Cancel</a>
                </div>
            </form>
        </section>
    </div>
</main>

<?php include __DIR__ . '/../../../layout/footer.php'; ?>
