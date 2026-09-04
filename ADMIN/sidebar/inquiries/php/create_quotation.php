<?php
require_once __DIR__ . '/../../../includes/admin_auth.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/audit_log.php';
require_once __DIR__ . '/../../../../config/inquiry_quotation_module.php';

$inquiryId = (int)($_GET['inquiry_id'] ?? $_POST['inquiry_id'] ?? 0);

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

if (!$inquiry || (string)$inquiry['status'] !== 'Verified Lead') {
    $_SESSION['inquiry_center_flash'] = 'Only verified leads can have quotations created.';
    header('Location: /codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php');
    exit();
}

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

$csrfToken = auth_csrf_token('admin_create_inquiry_quotation');
$quotationNo = inquiry_quote_generate_number();
$error = '';
$postedTypes = is_array($_POST['item_type'] ?? null) ? $_POST['item_type'] : ['material'];
$postedNames = is_array($_POST['item_name'] ?? null) ? $_POST['item_name'] : [''];
$postedQuantities = is_array($_POST['quantity'] ?? null) ? $_POST['quantity'] : ['1'];
$postedUnits = is_array($_POST['unit'] ?? null) ? $_POST['unit'] : ['unit'];
$postedUnitCosts = is_array($_POST['unit_cost'] ?? null) ? $_POST['unit_cost'] : [''];
$postedNotes = is_array($_POST['item_notes'] ?? null) ? $_POST['item_notes'] : [''];
$marginPercent = (float)($_POST['profit_margin_percent'] ?? 15);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'admin_create_inquiry_quotation')) {
        $error = 'Your form expired. Please refresh and try again.';
    } elseif ($marginPercent < 0 || $marginPercent > 100) {
        $error = 'Profit margin must be from 0 to 100 percent.';
    } elseif (count($postedNames) === 0 || count($postedNames) > 50) {
        $error = 'Add from 1 to 50 quotation items only.';
    } else {
        $allowedTypes = ['material', 'labor', 'equipment', 'service', 'other'];
        $quotationItems = [];
        $subtotal = 0.0;

        foreach ($postedNames as $index => $postedName) {
            $itemName = trim((string)$postedName);
            $itemType = strtolower(trim((string)($postedTypes[$index] ?? 'other')));
            $quantity = (float)($postedQuantities[$index] ?? 0);
            $unit = trim((string)($postedUnits[$index] ?? 'unit'));
            $unitCost = (float)($postedUnitCosts[$index] ?? 0);
            $notes = trim((string)($postedNotes[$index] ?? ''));

            if ($itemName === '' || strlen($itemName) > 180) {
                $error = 'Each quotation item needs a valid name.';
                break;
            }
            if (!in_array($itemType, $allowedTypes, true)) {
                $error = 'Please select a valid item type.';
                break;
            }
            if ($quantity <= 0 || $quantity > 999999 || $unitCost < 0 || $unitCost > 999999999) {
                $error = 'Check the quantity and unit cost of each item.';
                break;
            }
            if ($unit === '' || strlen($unit) > 30 || strlen($notes) > 2000) {
                $error = 'Check the unit and notes of each item.';
                break;
            }

            $lineTotal = round($quantity * $unitCost, 2);
            $subtotal += $lineTotal;
            $quotationItems[] = [
                'type' => $itemType,
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

                $itemStmt = $conn->prepare(
                    'INSERT INTO inquiry_quotation_items
                     (draft_id, item_type, item_name, quantity, unit, unit_cost, line_total, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                if (!$itemStmt) {
                    throw new RuntimeException('Unable to prepare quotation items.');
                }

                foreach ($quotationItems as $item) {
                    $itemStmt->bind_param('issdsdds', $draftId, $item['type'], $item['name'], $item['quantity'], $item['unit'], $item['unit_cost'], $item['line_total'], $item['notes']);
                    $itemStmt->execute();
                }

                inquiry_quote_add_history($conn, $draftId, null, 'draft', 'Admin created quotation from verified inquiry.', $adminId, 'admin');
                $conn->commit();

                audit_log_event($conn, $adminId, 'create_inquiry_quotation_direct', 'quotation', $draftId, null, [
                    'inquiry_id' => $inquiryId,
                    'quotation_no' => $quotationNo,
                    'subtotal' => $subtotal,
                    'profit_margin_percent' => $marginPercent,
                    'grand_total' => $grandTotal,
                ]);

                $_SESSION['inquiry_center_flash'] = 'Quotation draft created.';
                header('Location: /codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?open=inquiryModal' . $inquiryId . '&status=Verified+Lead');
                exit();
            } catch (Throwable $throwable) {
                $conn->rollback();
                error_log('Direct inquiry quotation failed: ' . $throwable->getMessage());
                $error = 'Unable to create quotation. Please check the quotation database setup.';
            }
        }
    }
}

$adminPageTitle = 'Create Quotation - Edge Automation';
$adminCssFiles = [
    '/codesamplecaps/ADMIN/common/css/admin-common.css',
    '/codesamplecaps/ADMIN/sidebar/inquiries/css/inquiries.css',
];
$adminJsFiles = [
    '/codesamplecaps/ADMIN/common/js/admin-common.js',
    '/codesamplecaps/ADMIN/sidebar/inquiries/js/inquiries.js',
];

include __DIR__ . '/../../../layout/header.php';
include __DIR__ . '/../../../admin_sidebar.php';
?>

<main class="main-content admin-dashboard-content">
    <div class="inquiries-shell quotation-create-shell" data-quotation-create>
        <?php if ($error !== ''): ?>
            <div class="inquiry-toast inquiry-toast--error" role="alert" data-inquiry-toast>
                <span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
                <button type="button" data-inquiry-toast-close aria-label="Close notification">&times;</button>
            </div>
        <?php endif; ?>

        <a href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php" class="btn-secondary quotation-create-back">Back to Inquiries</a>

        <section class="quotation-create-card">
            <div class="quotation-create-heading">
                <div>
                    <span class="reports-kicker">Verified Lead</span>
                    <h1>Create Quotation</h1>
                    <p>Add the clear scope and itemized price before sending it to the client.</p>
                </div>
                <div class="quotation-create-number">
                    <span>Quotation No.</span>
                    <strong><?php echo htmlspecialchars($quotationNo, ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
            </div>

            <div class="quotation-create-client-grid">
                <div><span>Contact Person</span><strong><?php echo htmlspecialchars((string)$inquiry['client_name'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div><span>Company</span><strong><?php echo htmlspecialchars((string)($inquiry['company_name'] ?: 'Individual Client'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div><span>Email</span><strong><?php echo htmlspecialchars((string)$inquiry['email'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div><span>Contact</span><strong><?php echo htmlspecialchars((string)$inquiry['contact_no'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div><span>Service</span><strong><?php echo htmlspecialchars((string)$inquiry['service_category'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div><span>Location</span><strong><?php echo htmlspecialchars(trim((string)$inquiry['site_address'] . ', ' . (string)$inquiry['barangay'] . ', ' . (string)$inquiry['city_municipality'] . ', ' . (string)$inquiry['province'], ' ,'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            </div>

            <div class="quotation-create-scope">
                <span>Inquiry Scope</span>
                <p><?php echo nl2br(htmlspecialchars((string)($inquiry['description'] ?: 'No scope description provided.'), ENT_QUOTES, 'UTF-8')); ?></p>
            </div>

            <form method="POST" class="quotation-create-form" data-quotation-create-form>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="inquiry_id" value="<?php echo $inquiryId; ?>">

                <div class="quotation-create-section-head">
                    <div><h2>Cost Breakdown</h2><p>List the materials, labor, equipment, or service costs.</p></div>
                    <button type="button" class="btn-secondary" data-quotation-add-item>Add Item</button>
                </div>

                <div class="quotation-create-items" data-quotation-items>
                    <?php foreach ($postedNames as $index => $postedName): ?>
                        <div class="quotation-create-item" data-quotation-item>
                            <label><span>Type</span><select name="item_type[]" required><?php foreach (['material' => 'Material', 'labor' => 'Labor', 'equipment' => 'Equipment', 'service' => 'Service', 'other' => 'Other'] as $typeValue => $typeLabel): ?><option value="<?php echo $typeValue; ?>" <?php echo (string)($postedTypes[$index] ?? '') === $typeValue ? 'selected' : ''; ?>><?php echo $typeLabel; ?></option><?php endforeach; ?></select></label>
                            <label class="quotation-create-item__name"><span>Item / Work</span><input type="text" name="item_name[]" maxlength="180" value="<?php echo htmlspecialchars((string)$postedName, ENT_QUOTES, 'UTF-8'); ?>" required></label>
                            <label><span>Qty</span><input type="number" name="quantity[]" min="0.01" step="0.01" value="<?php echo htmlspecialchars((string)($postedQuantities[$index] ?? '1'), ENT_QUOTES, 'UTF-8'); ?>" required></label>
                            <label><span>Unit</span><input type="text" name="unit[]" maxlength="30" value="<?php echo htmlspecialchars((string)($postedUnits[$index] ?? 'unit'), ENT_QUOTES, 'UTF-8'); ?>" required></label>
                            <label><span>Unit Cost</span><input type="number" name="unit_cost[]" min="0" step="0.01" value="<?php echo htmlspecialchars((string)($postedUnitCosts[$index] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required></label>
                            <label class="quotation-create-item__notes"><span>Notes / Exclusion</span><input type="text" name="item_notes[]" maxlength="2000" value="<?php echo htmlspecialchars((string)($postedNotes[$index] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></label>
                            <button type="button" class="quotation-create-remove" data-quotation-remove-item aria-label="Remove quotation item">Remove</button>
                        </div>
                    <?php endforeach; ?>
                </div>

                <template data-quotation-item-template>
                    <div class="quotation-create-item" data-quotation-item>
                        <label><span>Type</span><select name="item_type[]" required><option value="material">Material</option><option value="labor">Labor</option><option value="equipment">Equipment</option><option value="service">Service</option><option value="other">Other</option></select></label>
                        <label class="quotation-create-item__name"><span>Item / Work</span><input type="text" name="item_name[]" maxlength="180" required></label>
                        <label><span>Qty</span><input type="number" name="quantity[]" min="0.01" step="0.01" value="1" required></label>
                        <label><span>Unit</span><input type="text" name="unit[]" maxlength="30" value="unit" required></label>
                        <label><span>Unit Cost</span><input type="number" name="unit_cost[]" min="0" step="0.01" required></label>
                        <label class="quotation-create-item__notes"><span>Notes / Exclusion</span><input type="text" name="item_notes[]" maxlength="2000"></label>
                        <button type="button" class="quotation-create-remove" data-quotation-remove-item aria-label="Remove quotation item">Remove</button>
                    </div>
                </template>

                <div class="quotation-create-summary">
                    <label><span>Profit Margin (%)</span><input type="number" name="profit_margin_percent" min="0" max="100" step="0.01" value="<?php echo htmlspecialchars((string)$marginPercent, ENT_QUOTES, 'UTF-8'); ?>" required></label>
                    <div><span>Subtotal</span><strong>PHP <span data-quotation-subtotal>0.00</span></strong></div>
                    <div><span>Profit</span><strong>PHP <span data-quotation-profit>0.00</span></strong></div>
                    <div class="quotation-create-grand-total"><span>Grand Total</span><strong>PHP <span data-quotation-total>0.00</span></strong></div>
                </div>

                <div class="quotation-create-actions">
                    <button type="submit" class="btn-primary">Create Quotation Draft</button>
                    <a href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php" class="btn-secondary">Cancel</a>
                </div>
            </form>
        </section>
    </div>
</main>

<?php include __DIR__ . '/../../../layout/footer.php'; ?>
