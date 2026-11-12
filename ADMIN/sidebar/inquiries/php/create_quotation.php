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
        'SELECT id, inquiry_id, parent_draft_id, revision_no, quotation_no, status, subtotal, profit_margin_percent, profit_amount, grand_total
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

$isInitialQuotationDraft = !$isEditMode
    || (empty($quotationDraft['parent_draft_id']) && (int)($quotationDraft['revision_no'] ?? 0) === 0);
$quotationVersion = $isInitialQuotationDraft ? 0 : (int)($quotationDraft['revision_no'] ?? 0);
$quotationDraftLabel = $isInitialQuotationDraft ? 'Admin quotation draft' : 'Quotation R' . $quotationVersion . ' draft';

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
    'misc_safety_temp' => ['unit', 'pcs', 'set', 'lot', 'day'],
    'transport' => ['trip', 'day', 'lot'],
    'management_overhead_commission' => ['lot'],
];
$wholeCountUnits = ['pcs', 'roll', 'box', 'pack', 'set', 'lot', 'person', 'unit', 'trip'];
$materialOptions = material_stock_fetch_active_materials($conn);
$materialUnitsById = [];
$materialNamesById = [];
foreach ($materialOptions as $materialOption) {
    $materialUnitsById[(int)$materialOption['id']] = (string)$materialOption['unit'];
    $materialNamesById[(int)$materialOption['id']] = (string)$materialOption['material_name'];
}

function inquiry_quote_render_unit_options(array $unitOptionsByType, array $materialUnitsById, string $itemType, int $materialId, string $currentUnit): void
{
    $unitChoices = $unitOptionsByType[$itemType] ?? ['unit', 'lot'];
    $isLinkedMaterial = $itemType === 'material' && $materialId > 0 && isset($materialUnitsById[$materialId]);
    if ($isLinkedMaterial) {
        $unitChoices = [$materialUnitsById[$materialId]];
    }

    if (!$isLinkedMaterial && $currentUnit === '') {
        echo '<option value="" selected disabled>Select unit</option>';
    }

    foreach ($unitChoices as $unitChoice) {
        $isSelected = strtolower($currentUnit) === strtolower($unitChoice);
        echo '<option value="' . htmlspecialchars($unitChoice, ENT_QUOTES, 'UTF-8') . '"' . ($isSelected ? ' selected' : '') . '>'
            . htmlspecialchars($unitChoice, ENT_QUOTES, 'UTF-8') . '</option>';
    }
}

$savedQuotationNo = $isEditMode ? (string)$quotationDraft['quotation_no'] : '';
if ($isInitialQuotationDraft && inquiry_quote_is_system_placeholder_number($savedQuotationNo)) {
    $savedQuotationNo = '';
}
$quotationNo = trim((string)($_POST['quotation_no'] ?? $savedQuotationNo));
if ($isEditMode && !$isInitialQuotationDraft) {
    $quotationNo = (string)$quotationDraft['quotation_no'];
}
$error = '';
$defaultItems = $savedItems ?: [[
    'item_type' => 'material',
    'material_id' => '',
    'item_name' => '',
    'quantity' => '',
    'unit' => '',
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
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    foreach ($postedTypes as $postedIndex => $postedType) {
        $hasFreeTextMaterial = inquiry_quote_normalize_item_type((string)$postedType) === 'material'
            && trim((string)($postedNames[$postedIndex] ?? '')) !== ''
            && (int)($postedMaterialIds[$postedIndex] ?? 0) <= 0;
        if ($hasFreeTextMaterial) {
            $postedMaterialIds[$postedIndex] = 'manual';
        }
    }
}
$savedMaterialNotes = [];
foreach ($defaultItems as $defaultItem) {
    if (inquiry_quote_normalize_item_type((string)($defaultItem['item_type'] ?? '')) === 'material') {
        $savedNote = trim((string)($defaultItem['notes'] ?? ''));
        if ($savedNote !== '' && !in_array($savedNote, $savedMaterialNotes, true)) {
            $savedMaterialNotes[] = $savedNote;
        }
    }
}
$materialsNote = trim((string)($_POST['materials_note'] ?? implode('; ', $savedMaterialNotes)));
$materialRowIndexes = [];
$directCostValues = array_fill_keys(array_keys(inquiry_quote_item_type_options()), 0.0);
foreach ($postedTypes as $postedIndex => $postedType) {
    $normalizedType = inquiry_quote_normalize_item_type((string)$postedType);
    if ($normalizedType === 'material') {
        $materialRowIndexes[] = $postedIndex;
        continue;
    }
    if ($normalizedType !== '' && array_key_exists($normalizedType, $directCostValues)) {
        $directCostValues[$normalizedType] += (float)($postedQuantities[$postedIndex] ?? 0)
            * (float)($postedUnitCosts[$postedIndex] ?? 0);
    }
}
if ($materialRowIndexes === []) {
    $blankMaterialIndex = count($postedNames);
    $postedTypes[$blankMaterialIndex] = 'material';
    $postedMaterialIds[$blankMaterialIndex] = '';
    $postedNames[$blankMaterialIndex] = '';
    $postedQuantities[$blankMaterialIndex] = '';
    $postedUnits[$blankMaterialIndex] = '';
    $postedUnitCosts[$blankMaterialIndex] = '';
    $postedNotes[$blankMaterialIndex] = '';
    $materialRowIndexes[] = $blankMaterialIndex;
}
$postedNotes = array_fill(0, count($postedNames), '');
if ($materialRowIndexes !== []) {
    $postedNotes[$materialRowIndexes[0]] = $materialsNote;
}
$directCostTypes = inquiry_quote_item_type_options();
unset($directCostTypes['material']);
$isQuotationPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$quotationSubmitAction = (string)($_POST['quotation_submit_action'] ?? 'save_draft');
$shouldSendToClient = $quotationSubmitAction === 'send_to_client';
$marginPercentRaw = trim((string)($_POST['profit_margin_percent'] ?? ($quotationDraft['profit_margin_percent'] ?? 15)));
$marginPercent = is_numeric($marginPercentRaw) ? (float)$marginPercentRaw : 0.0;
$markupError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'admin_create_inquiry_quotation')) {
        $error = 'Your form expired. Please refresh and try again.';
    } elseif ($shouldSendToClient && $quotationNo === '') {
        $error = 'Enter the official Edge quotation number before sending.';
    } elseif ($quotationNo !== '' && (strlen($quotationNo) < 3 || strlen($quotationNo) > 80 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', $quotationNo))) {
        $error = 'Use only letters, numbers, dash, slash, underscore, or period for the quotation number.';
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
    } elseif (strlen($materialsNote) > 2000) {
        $error = 'Materials note must be 2000 characters or less.';
    } elseif (count($postedNames) === 0 || count($postedNames) > 50) {
        $error = 'Add from 1 to 50 quotation items only.';
    } else {
        $allowedTypes = array_keys(inquiry_quote_item_type_options());
        $quotationItems = [];
        $subtotal = 0.0;

        $seenMaterialIds = [];
        foreach ($postedNames as $index => $postedName) {
            $itemName = trim((string)$postedName);
            $itemType = inquiry_quote_normalize_item_type((string)($postedTypes[$index] ?? ''));
            $quantityRaw = trim((string)($postedQuantities[$index] ?? ''));
            $quantity = is_numeric($quantityRaw) ? (float)$quantityRaw : 0.0;
            $unit = trim((string)($postedUnits[$index] ?? 'unit'));
            $unitCostRaw = trim((string)($postedUnitCosts[$index] ?? ''));
            if ($itemType !== 'material' && $unitCostRaw === '') {
                $unitCostRaw = '0';
            }
            $unitCost = is_numeric($unitCostRaw) ? (float)$unitCostRaw : 0.0;
            $notes = trim((string)($postedNotes[$index] ?? ''));
            $materialReference = trim((string)($postedMaterialIds[$index] ?? ''));
            $materialId = $itemType === 'material' ? (int)$materialReference : 0;

            if (!in_array($itemType, $allowedTypes, true)) {
                $error = 'Please select a valid item type.';
                break;
            }
            if ($itemType === 'material' && $materialReference !== 'manual' && $materialId <= 0) {
                $error = 'Pick material.';
                break;
            }
            if ($materialId > 0 && !material_stock_material_exists($conn, $materialId)) {
                $error = 'Pick material again.';
                break;
            }
            if ($materialId > 0 && isset($seenMaterialIds[$materialId])) {
                $error = 'Material already added.';
                break;
            }
            if ($materialId > 0) {
                $seenMaterialIds[$materialId] = true;
            }
            if ($itemType === 'material' && $materialId > 0) {
                $itemName = $materialNamesById[$materialId] ?? '';
            }
            if ($itemName === '' || strlen($itemName) > 180) {
                $error = 'Enter item name.';
                break;
            }
            if ($quantityRaw === '') {
                $error = 'Enter quantity.';
                break;
            }
            if (!is_numeric($quantityRaw) || !is_finite($quantity) || $quantity <= 0 || $quantity > 999999) {
                $error = 'Use quantity above 0.';
                break;
            }
            if ($unitCostRaw === '') {
                $error = 'Enter cost.';
                break;
            }
            $minimumUnitCost = $itemType === 'material' ? 0.01 : 0.0;
            if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $unitCostRaw) || !is_finite($unitCost) || $unitCost < $minimumUnitCost || $unitCost > 999999999) {
                $error = $itemType === 'material' ? 'Use material amount above 0.' : 'Use an amount of 0 or higher.';
                break;
            }
            if ($unit === '' || strlen($unit) > 30 || strlen($notes) > 2000) {
                $error = 'Pick a valid unit.';
                break;
            }
            if ($itemType === 'material' && $materialId > 0) {
                $materialUnit = $materialUnitsById[$materialId] ?? '';
                if ($materialUnit === '' || strcasecmp($unit, $materialUnit) !== 0) {
                $error = 'Material unit cannot change.';
                    break;
                }
            } elseif (!in_array(strtolower($unit), $unitOptionsByType[$itemType] ?? [], true)) {
                $error = 'Pick a valid unit.';
                break;
            }
            if (in_array(strtolower($unit), $wholeCountUnits, true) && $quantity !== floor($quantity)) {
                $error = 'Use a whole quantity.';
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

        if ($error === '' && $quotationNo !== '') {
            $numberStmt = $conn->prepare(
                'SELECT id FROM inquiry_quotation_drafts WHERE quotation_no = ? AND id <> ? LIMIT 1'
            );
            if (!$numberStmt) {
                $error = 'Unable to check the quotation number.';
            } else {
                $numberStmt->bind_param('si', $quotationNo, $editId);
                $numberStmt->execute();
                if ($numberStmt->get_result()->fetch_assoc()) {
                    $error = 'Quotation number already exists.';
                }
            }
        }

        if ($error === '') {
            $storedQuotationNo = $quotationNo;
            if ($storedQuotationNo === '') {
                $storedQuotationNo = $isEditMode && inquiry_quote_is_system_placeholder_number((string)($quotationDraft['quotation_no'] ?? ''))
                    ? (string)$quotationDraft['quotation_no']
                    : inquiry_quote_generate_number();
            }
            $profitAmount = round($subtotal * ($marginPercent / 100), 2);
            $grandTotal = round($subtotal + $profitAmount, 2);
            $status = 'Draft';
            $adminId = (int)($_SESSION['user_id'] ?? 0);
            $draftWasSaved = false;

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
                         SET quotation_no = ?, subtotal = ?, profit_margin_percent = ?, profit_amount = ?, grand_total = ?
                         WHERE id = ?'
                    );
                    if (!$stmt) {
                        throw new RuntimeException('Unable to prepare quotation update.');
                    }
                    $stmt->bind_param('sddddi', $storedQuotationNo, $subtotal, $marginPercent, $profitAmount, $grandTotal, $editId);
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

                    $stmt->bind_param('isddddsi', $inquiryId, $storedQuotationNo, $subtotal, $marginPercent, $profitAmount, $grandTotal, $status, $adminId);
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
                $draftWasSaved = true;

                $oldAuditData = $isEditMode ? [
                    'subtotal' => (float)$quotationDraft['subtotal'],
                    'profit_margin_percent' => (float)$quotationDraft['profit_margin_percent'],
                    'profit_amount' => (float)$quotationDraft['profit_amount'],
                    'grand_total' => (float)$quotationDraft['grand_total'],
                ] : null;
                audit_log_event($conn, $adminId, $isEditMode ? 'update_inquiry_quotation_draft' : 'create_inquiry_quotation_direct', 'quotation', $draftId, $oldAuditData, [
                    'inquiry_id' => $inquiryId,
                    'quotation_no' => $storedQuotationNo,
                    'subtotal' => $subtotal,
                    'profit_margin_percent' => $marginPercent,
                    'grand_total' => $grandTotal,
                ]);

                if ($shouldSendToClient) {
                    inquiry_quote_send_to_client($conn, $draftId, $adminId);
                    audit_log_event($conn, $adminId, 'send_inquiry_quotation_to_client', 'quotation', $draftId, ['status' => 'draft'], ['status' => 'sent']);
                }

                $_SESSION['auth_flash'] = [
                    'type' => 'success',
                    'message' => $shouldSendToClient
                        ? 'Quotation saved and sent to the client.'
                        : ($isEditMode
                            ? 'Quotation draft updated successfully.'
                            : 'Quotation draft created successfully.'),
                ];
                $redirectStatus = $shouldSendToClient ? 'sent' : 'draft';
                header('Location: /codesamplecaps/ADMIN/sidebar/quotations/php/quotations.php?status=' . $redirectStatus . '&id=' . $draftId);
                exit();
            } catch (Throwable $throwable) {
                if (!$draftWasSaved) {
                    $conn->rollback();
                }
                error_log('Inquiry quotation save failed: ' . $throwable->getMessage());
                if ($shouldSendToClient && $draftWasSaved) {
                    $_SESSION['auth_flash'] = [
                        'type' => 'warning',
                        'message' => 'Quotation was saved as Draft, but the email was not sent. Please try again.',
                    ];
                    header('Location: /codesamplecaps/ADMIN/sidebar/inquiries/php/create_quotation.php?edit_id=' . $draftId);
                    exit();
                }
                $error = $shouldSendToClient
                    ? 'Unable to save and send the quotation.'
                    : ($isEditMode ? 'Unable to update quotation draft.' : 'Unable to create quotation. Please check the quotation database setup.');
            }
        }
    }
}

$adminPageTitle = ($isEditMode ? 'Edit Quotation' : 'Create Quotation') . ' - Edge Automation';
$adminCssFiles = [
    '/codesamplecaps/ADMIN/common/css/admin-common.css',
    '/codesamplecaps/SHARED/toast/css/toast.css',
    '/codesamplecaps/SHARED/action-loading/css/action-loading.css?v=20261103-3',
    '/codesamplecaps/ADMIN/sidebar/inquiries/css/inquiries.css?v=20261112-1',
];
$adminJsFiles = [
    '/codesamplecaps/ADMIN/common/js/admin-common.js',
    '/codesamplecaps/SHARED/toast/js/toast.js',
    '/codesamplecaps/SHARED/action-loading/js/action-loading.js?v=20261103-2',
    '/codesamplecaps/ADMIN/sidebar/inquiries/js/inquiries.js?v=20261112-1',
];

include __DIR__ . '/../../../layout/header.php';
include __DIR__ . '/../../../admin_sidebar.php';
?>

<main class="main-content admin-dashboard-content initial-quotation-editor">
    <div class="inquiries-shell quotation-create-shell" data-quotation-create>
        <?php if ($error !== ''): ?>
            <div class="shared-toast shared-toast--error" role="alert" data-shared-toast>
                <span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
                <button type="button" class="shared-toast__close" data-shared-toast-close aria-label="Close notification">&times;</button>
                <span class="shared-toast__progress" aria-hidden="true"></span>
            </div>
        <?php endif; ?>

        <section class="quotation-create-card">
            <a href="<?php echo $isEditMode ? '/codesamplecaps/ADMIN/sidebar/quotations/php/quotations.php?status=draft' : '/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?inquiry_id=' . $inquiryId . '&amp;tab=quotation'; ?>" class="btn-secondary quotation-create-back">&larr; Back to <?php echo $isEditMode ? 'Quotations' : 'Inquiries'; ?></a>

            <div class="quotation-create-heading">
                <div class="quotation-create-title-row">
                    <h1><?php echo $isEditMode ? 'Edit Quotation' : 'Create Quotation'; ?></h1>
                    <span class="quotation-create-status">Draft</span>
                </div>
                <div class="quotation-create-number">
                    <label for="quotationNumber">Quotation Number <span class="quotation-required-mark" aria-hidden="true">*</span></label>
                    <input id="quotationNumber" form="quotationCreateForm" type="text" name="quotation_no" maxlength="80" value="<?php echo htmlspecialchars($quotationNo, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Enter official Edge number" autocomplete="off" aria-describedby="quotationNumberError"<?php echo !$isInitialQuotationDraft ? ' readonly aria-readonly="true"' : ''; ?>>
                    <span id="quotationNumberError" class="quotation-number-error" data-quotation-number-error aria-live="polite"></span>
                    <?php if (!$isInitialQuotationDraft): ?>
                        <small>Revision number is based on the original quotation.</small>
                    <?php endif; ?>
                </div>
            </div>

            <div class="quotation-create-client-grid">
                <div><span>Client</span><strong><?php echo htmlspecialchars((string)$inquiry['client_name'], ENT_QUOTES, 'UTF-8'); ?></strong><?php if (trim((string)$inquiry['company_name']) !== ''): ?><small><?php echo htmlspecialchars((string)$inquiry['company_name'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?></div>
                <div><span>Service</span><strong><?php echo htmlspecialchars((string)$inquiry['service_category'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div><span>Location</span><strong><?php echo htmlspecialchars(trim((string)$inquiry['site_address'] . ', ' . (string)$inquiry['barangay'] . ', ' . (string)$inquiry['city_municipality'] . ', ' . (string)$inquiry['province'], ' ,'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            </div>

            <details class="quotation-create-scope">
                <summary>Inquiry Scope</summary>
                <p><?php echo nl2br(htmlspecialchars((string)($inquiry['description'] ?: 'No scope description provided.'), ENT_QUOTES, 'UTF-8')); ?></p>
            </details>

            <form id="quotationCreateForm" method="POST" class="quotation-create-form" data-quotation-create-form data-quotation-edit-mode="<?php echo $isEditMode ? 'true' : 'false'; ?>" data-initial-quotation="<?php echo $isInitialQuotationDraft ? 'true' : 'false'; ?>" data-quotation-inquiry-id="<?php echo $inquiryId; ?>" data-quotation-client-email="<?php echo htmlspecialchars((string)$inquiry['email'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="inquiry_id" value="<?php echo $inquiryId; ?>">
                <input type="hidden" name="quotation_submit_action" value="save_draft" data-quotation-submit-action>
                <?php if ($isEditMode): ?>
                    <input type="hidden" name="edit_id" value="<?php echo $editId; ?>">
                <?php endif; ?>

                <div class="quotation-create-section-head">
                    <div>
                        <h2>Cost Breakdown</h2>
                    </div>
                </div>

                <div class="quotation-create-items" data-quotation-items>
                    <?php foreach ($materialRowIndexes as $index): ?>
                        <?php
                        $postedName = (string)($postedNames[$index] ?? '');
                        $rowType = 'material';
                        $rowMaterialId = (int)($postedMaterialIds[$index] ?? 0);
                        $rowUnit = (string)($postedUnits[$index] ?? '');
                        $rowUnitLocked = $rowType === 'material' && $rowMaterialId > 0;
                        $rowNameLocked = $rowUnitLocked;
                        ?>
                        <div class="quotation-create-item<?php echo $rowType === 'material' ? ' is-material-item' : ''; ?>" data-quotation-item>
                            <label class="quotation-create-item__type" hidden><span>Type</span><select name="item_type[]" required><option value="material" selected>Materials</option></select></label>
                            <label class="quotation-create-item__material-reference" data-quotation-material-reference<?php echo $rowType !== 'material' ? ' hidden' : ''; ?>><span>Material</span><select name="material_id[]" required>
                                    <option value="" disabled hidden<?php echo $rowMaterialId <= 0 && (string)($postedMaterialIds[$index] ?? '') !== 'manual' ? ' selected' : ''; ?>>Select material</option><?php foreach ($materialOptions as $material): ?><option value="<?php echo (int)$material['id']; ?>" data-material-name="<?php echo htmlspecialchars((string)$material['material_name'], ENT_QUOTES, 'UTF-8'); ?>" data-material-unit="<?php echo htmlspecialchars((string)$material['unit'], ENT_QUOTES, 'UTF-8'); ?>" data-material-available="<?php echo htmlspecialchars((string)material_stock_available_quantity($material), ENT_QUOTES, 'UTF-8'); ?>" data-material-reorder="<?php echo htmlspecialchars((string)($material['reorder_level'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>" <?php echo $rowMaterialId === (int)$material['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$material['material_name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?><option value="manual" <?php echo (string)($postedMaterialIds[$index] ?? '') === 'manual' ? ' selected' : ''; ?>><?php echo htmlspecialchars(material_stock_non_stock_label(), ENT_QUOTES, 'UTF-8'); ?></option>
                                </select><span class="quotation-create-item__material-feedback" data-quotation-material-feedback aria-live="polite" hidden></span><span class="quotation-create-item__material-error" data-quotation-material-error aria-live="polite"></span></label>
                            <label class="quotation-create-item__name"><span>Item</span><input type="text" name="item_name[]" maxlength="180" value="<?php echo htmlspecialchars((string)$postedName, ENT_QUOTES, 'UTF-8'); ?>" required<?php echo $rowNameLocked ? ' class="is-linked-material-name" readonly aria-readonly="true"' : ''; ?>></label>
                            <label class="quotation-create-item__quantity"><span>Qty</span><input type="number" name="quantity[]" min="0.01" step="0.01" value="<?php echo htmlspecialchars((string)($postedQuantities[$index] ?? '1'), ENT_QUOTES, 'UTF-8'); ?>" required><span class="quotation-create-item__quantity-error" data-quotation-quantity-error aria-live="polite"></span></label>
                            <label class="quotation-create-item__unit"><span>Unit</span><select name="unit[]" required data-quotation-unit class="<?php echo $rowUnitLocked ? 'is-locked' : ''; ?>" <?php echo $rowUnitLocked ? ' aria-readonly="true" tabindex="-1"' : ''; ?>><?php inquiry_quote_render_unit_options($unitOptionsByType, $materialUnitsById, $rowType, $rowMaterialId, $rowUnit); ?></select><span class="quotation-create-item__unit-error" data-quotation-unit-error aria-live="polite"></span></label>
                            <label class="quotation-create-item__cost"><span>Unit Cost</span><input type="number" name="unit_cost[]" min="<?php echo $rowType === 'material' ? '0.01' : '0'; ?>" step="0.01" value="<?php echo htmlspecialchars((string)($postedUnitCosts[$index] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required><span class="quotation-create-item__cost-error" data-quotation-cost-error aria-live="polite"></span></label>
                            <input type="hidden" name="item_notes[]" value="">
                            <div class="quotation-create-item__line-total"><span>Total</span><strong>PHP <span data-quotation-line-total>0.00</span></strong></div>
                            <button type="button" class="quotation-create-remove" data-quotation-remove-item aria-label="Remove quotation item"><span class="quotation-create-remove__icon" aria-hidden="true">🗑</span><span>Remove</span></button>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="quotation-create-add-item-row">
                    <button type="button" class="btn-secondary quotation-create-add-item" data-quotation-add-item>+ Add Material</button>
                </div>

                <label class="quotation-create-materials-note">
                    <span>Material Note (Optional)</span>
                    <textarea name="materials_note" rows="2" maxlength="2000" placeholder="Add note"><?php echo htmlspecialchars($materialsNote, ENT_QUOTES, 'UTF-8'); ?></textarea>
                </label>

                <template data-quotation-item-template>
                    <div class="quotation-create-item is-material-item" data-quotation-item>
                        <label class="quotation-create-item__type" hidden><span>Type</span><select name="item_type[]" required><option value="material" selected>Materials</option></select></label>
                        <label class="quotation-create-item__material-reference" data-quotation-material-reference><span>Material</span><select name="material_id[]" required>
                                <option value="" selected disabled hidden>Select material</option><?php foreach ($materialOptions as $material): ?><option value="<?php echo (int)$material['id']; ?>" data-material-name="<?php echo htmlspecialchars((string)$material['material_name'], ENT_QUOTES, 'UTF-8'); ?>" data-material-unit="<?php echo htmlspecialchars((string)$material['unit'], ENT_QUOTES, 'UTF-8'); ?>" data-material-available="<?php echo htmlspecialchars((string)material_stock_available_quantity($material), ENT_QUOTES, 'UTF-8'); ?>" data-material-reorder="<?php echo htmlspecialchars((string)($material['reorder_level'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$material['material_name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?><option value="manual"><?php echo htmlspecialchars(material_stock_non_stock_label(), ENT_QUOTES, 'UTF-8'); ?></option>
                            </select><span class="quotation-create-item__material-feedback" data-quotation-material-feedback aria-live="polite" hidden></span><span class="quotation-create-item__material-error" data-quotation-material-error aria-live="polite"></span></label>
                        <label class="quotation-create-item__name"><span>Item</span><input type="text" name="item_name[]" maxlength="180" required></label>
                        <label class="quotation-create-item__quantity"><span>Qty</span><input type="number" name="quantity[]" min="0.01" step="0.01" required><span class="quotation-create-item__quantity-error" data-quotation-quantity-error aria-live="polite"></span></label>
                        <label class="quotation-create-item__unit"><span>Unit</span><select name="unit[]" required data-quotation-unit>
                                <option value="" selected disabled>Select unit</option>
                                <option value="pcs">pcs</option>
                                <option value="meter">meter</option>
                                <option value="roll">roll</option>
                                <option value="box">box</option>
                                <option value="pack">pack</option>
                                <option value="set">set</option>
                                <option value="kg">kg</option>
                                <option value="liter">liter</option>
                            </select><span class="quotation-create-item__unit-error" data-quotation-unit-error aria-live="polite"></span></label>
                        <label class="quotation-create-item__cost"><span>Unit Cost</span><input type="number" name="unit_cost[]" min="0.01" step="0.01" required><span class="quotation-create-item__cost-error" data-quotation-cost-error aria-live="polite"></span></label>
                        <input type="hidden" name="item_notes[]" value="">
                        <div class="quotation-create-item__line-total"><span>Total</span><strong>PHP <span data-quotation-line-total>0.00</span></strong></div>
                        <button type="button" class="quotation-create-remove" data-quotation-remove-item aria-label="Remove quotation item"><span class="quotation-create-remove__icon" aria-hidden="true">🗑</span><span>Remove</span></button>
                    </div>
                </template>

                <section class="quotation-create-direct-costs" aria-labelledby="quotationDirectCostsTitle">
                    <div class="quotation-create-direct-costs__heading">
                        <strong id="quotationDirectCostsTitle">Other Cost Categories</strong>
                    </div>
                    <div class="quotation-create-direct-costs__grid">
                        <?php foreach ($directCostTypes as $directType => $directLabel): ?>
                            <label class="quotation-create-direct-cost">
                                <span><?php echo htmlspecialchars($directLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="quotation-create-direct-cost__input">
                                    <b aria-hidden="true">PHP</b>
                                    <input type="number" name="unit_cost[]" min="0" step="0.01" placeholder="0.00" value="<?php echo (float)($directCostValues[$directType] ?? 0) > 0 ? htmlspecialchars(number_format((float)$directCostValues[$directType], 2, '.', ''), ENT_QUOTES, 'UTF-8') : ''; ?>" data-quotation-direct-cost>
                                </span>
                                <input type="hidden" name="item_type[]" value="<?php echo htmlspecialchars($directType, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="material_id[]" value="">
                                <input type="hidden" name="item_name[]" value="<?php echo htmlspecialchars($directLabel, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="quantity[]" value="1">
                                <input type="hidden" name="unit[]" value="lot">
                                <input type="hidden" name="item_notes[]" value="">
                            </label>
                        <?php endforeach; ?>
                    </div>
                </section>

                <div class="quotation-create-summary">
                    <label><span>Markup (%)</span><input type="number" name="profit_margin_percent" min="0" max="100" step="0.01" value="<?php echo htmlspecialchars($marginPercentRaw, ENT_QUOTES, 'UTF-8'); ?>" required><span class="quotation-create-summary__error" data-quotation-markup-error aria-live="polite"><?php echo htmlspecialchars($markupError, ENT_QUOTES, 'UTF-8'); ?></span></label>
                    <div><span>Subtotal</span><strong>PHP <span data-quotation-subtotal>0.00</span></strong></div>
                    <div class="quotation-create-grand-total"><span>Grand Total</span><strong>PHP <span data-quotation-total>0.00</span></strong></div>
                </div>

                <div class="quotation-create-actions">
                    <div class="quotation-create-actions__primary">
                        <button type="submit" class="btn-secondary" data-quotation-save-draft <?php echo $isEditMode ? 'data-confirm-quotation-update data-quotation-update-submit disabled' : 'data-confirm-quotation-save'; ?>>Save Draft</button>
                        <button type="submit" class="btn-primary" data-confirm-quotation-send>Send to Client</button>
                    </div>
                    <a href="<?php echo $isEditMode ? '/codesamplecaps/ADMIN/sidebar/quotations/php/quotations.php?status=draft' : '/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?inquiry_id=' . $inquiryId . '&amp;tab=quotation'; ?>" class="btn-secondary" data-quotation-cancel>Cancel</a>
                </div>
            </form>
        </section>
    </div>
</main>

<?php include __DIR__ . '/../../../layout/footer.php'; ?>
