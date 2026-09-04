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

// Fetch inquiry
$inquiryStmt = $conn->prepare(
    'SELECT id, client_name, email, contact_no, site_address, service_category, status
     FROM service_inquiries
     WHERE id = ? LIMIT 1'
);
$inquiryStmt->bind_param('i', $inquiryId);
$inquiryStmt->execute();
$inquiry = $inquiryStmt->get_result()->fetch_assoc();

if (!$inquiry || $inquiry['status'] !== 'Verified Lead') {
    $_SESSION['inquiry_center_flash'] = 'Only verified leads can have quotations created.';
    header('Location: /codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php');
    exit();
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $quotationNo = trim((string)($_POST['quotation_no'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $subtotal = (float)($_POST['subtotal'] ?? 0);
    $marginPercent = (float)($_POST['profit_margin_percent'] ?? 15);

    // Validation
    if ($quotationNo === '') {
        $error = 'Quotation number is required.';
    } elseif ($subtotal <= 0) {
        $error = 'Subtotal must be greater than zero.';
    } elseif ($marginPercent < 0 || $marginPercent > 100) {
        $error = 'Profit margin must be between 0 and 100 percent.';
    } else {
        try {
            // Calculate totals
            $profitAmount = round($subtotal * ($marginPercent / 100), 2);
            $grandTotal = round($subtotal + $profitAmount, 2);

            // Create quotation draft
            $stmt = $conn->prepare(
                'INSERT INTO inquiry_quotation_drafts
                 (inquiry_id, inspection_id, quotation_no, subtotal, profit_margin_percent, profit_amount, grand_total, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            if (!$stmt) {
                throw new RuntimeException('Failed to prepare quotation creation.');
            }

            // Use inspection_id = 0 for direct quotations (no inspection yet)
            $inspectionId = 0;
            $status = 'Draft';
            $adminId = (int)($_SESSION['user_id'] ?? 0);

            $stmt->bind_param(
                'iisddddssi',
                $inquiryId,
                $inspectionId,
                $quotationNo,
                $subtotal,
                $marginPercent,
                $profitAmount,
                $grandTotal,
                $status,
                $adminId
            );

            if (!$stmt->execute()) {
                throw new RuntimeException('Failed to create quotation draft.');
            }

            $draftId = (int)$conn->insert_id;

            // Log the action
            audit_log_event(
                $conn,
                $adminId,
                'create_inquiry_quotation_direct',
                'quotation',
                $draftId,
                null,
                [
                    'inquiry_id' => $inquiryId,
                    'quotation_no' => $quotationNo,
                    'subtotal' => $subtotal,
                    'profit_margin_percent' => $marginPercent,
                    'grand_total' => $grandTotal,
                ]
            );

            $_SESSION['inquiry_center_flash'] = 'Quotation created successfully!';
            header('Location: /codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?open=inquiryModal' . $inquiryId . '&status=Verified+Lead');
            exit();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$adminPageTitle = 'Create Quotation - Edge Automation';
$adminCssFiles = [
    '/codesamplecaps/ADMIN/common/css/admin-common.css',
];
$adminJsFiles = [
    '/codesamplecaps/ADMIN/common/js/admin-common.js',
];

include __DIR__ . '/../../../layout/header.php';
include __DIR__ . '/../../../admin_sidebar.php';
?>

<main class="main-content admin-dashboard-content">
    <div class="inquiries-shell">
        <?php if ($message || $error): ?>
            <div class="inquiry-toast <?php echo $message ? 'inquiry-toast--success' : 'inquiry-toast--error'; ?>" role="status">
                <span><?php echo htmlspecialchars($message ?: $error, ENT_QUOTES, 'UTF-8'); ?></span>
                <button type="button" data-inquiry-toast-close aria-label="Close notification">&times;</button>
            </div>
        <?php endif; ?>

        <div style="max-width: 700px; margin: 0 auto; padding: 20px;">
            <a href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php" class="btn-secondary" style="margin-bottom: 20px; display: inline-block;">← Back to Inquiries</a>

            <div style="background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                <h1 style="margin-top: 0; margin-bottom: 10px;">Create Quotation</h1>
                <p style="color: #666; margin-bottom: 30px;">Fill in the quotation details for this verified lead.</p>

                <!-- Client Info Summary -->
                <div style="background: #f5f5f5; padding: 15px; border-radius: 6px; margin-bottom: 30px;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; font-size: 14px;">
                        <div>
                            <span style="color: #666;">Client Name</span>
                            <div style="font-weight: 600; margin-top: 3px;"><?php echo htmlspecialchars($inquiry['client_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div>
                            <span style="color: #666;">Email</span>
                            <div style="font-weight: 600; margin-top: 3px;"><?php echo htmlspecialchars($inquiry['email'], ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div>
                            <span style="color: #666;">Contact</span>
                            <div style="font-weight: 600; margin-top: 3px;"><?php echo htmlspecialchars($inquiry['contact_no'], ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div>
                            <span style="color: #666;">Service</span>
                            <div style="font-weight: 600; margin-top: 3px;"><?php echo htmlspecialchars($inquiry['service_category'], ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Quotation Form -->
                <form method="POST">
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 500;">
                            Quotation Number *
                        </label>
                        <input 
                            type="text" 
                            name="quotation_no" 
                            placeholder="e.g., QT-20260904-001"
                            value="<?php echo htmlspecialchars($_POST['quotation_no'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; box-sizing: border-box;"
                            required
                        >
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 500;">
                            Description / Scope
                        </label>
                        <textarea 
                            name="description" 
                            rows="4"
                            placeholder="Project description and scope details..."
                            style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; box-sizing: border-box; font-family: inherit;"
                        ><?php echo htmlspecialchars($_POST['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 500;">
                                Subtotal (PHP) *
                            </label>
                            <input 
                                type="number" 
                                name="subtotal" 
                                min="0" 
                                step="0.01"
                                placeholder="0.00"
                                value="<?php echo htmlspecialchars($_POST['subtotal'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; box-sizing: border-box;"
                                required
                            >
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 500;">
                                Profit Margin (%) *
                            </label>
                            <input 
                                type="number" 
                                name="profit_margin_percent" 
                                min="0" 
                                max="100" 
                                step="0.01"
                                value="<?php echo htmlspecialchars($_POST['profit_margin_percent'] ?? '15', ENT_QUOTES, 'UTF-8'); ?>"
                                style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; box-sizing: border-box;"
                                required
                            >
                            <small style="display: block; margin-top: 5px; color: #666;">Default: 15%</small>
                        </div>
                    </div>

                    <div style="background: #e3f2fd; padding: 15px; border-radius: 6px; margin-bottom: 20px; border-left: 4px solid #2196F3;">
                        <strong style="display: block; margin-bottom: 10px;">Calculation Preview</strong>
                        <div style="font-size: 14px; line-height: 1.8;">
                            <div>Subtotal: PHP <span id="preview-subtotal">0.00</span></div>
                            <div>Profit Margin: <span id="preview-margin">0.00</span>%</div>
                            <div style="border-top: 1px solid #90caf9; padding-top: 8px; margin-top: 8px;">
                                <strong>Grand Total: PHP <span id="preview-total">0.00</span></strong>
                            </div>
                        </div>
                    </div>

                    <div style="display: flex; gap: 10px;">
                        <button type="submit" class="btn-primary" style="flex: 1;">✓ Create Quotation Draft</button>
                        <a href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php" class="btn-secondary" style="flex: 1; text-align: center; text-decoration: none;">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const subtotalInput = document.querySelector('input[name="subtotal"]');
    const marginInput = document.querySelector('input[name="profit_margin_percent"]');
    const previewSubtotal = document.getElementById('preview-subtotal');
    const previewMargin = document.getElementById('preview-margin');
    const previewTotal = document.getElementById('preview-total');

    function updatePreview() {
        const subtotal = parseFloat(subtotalInput.value) || 0;
        const margin = parseFloat(marginInput.value) || 0;
        const profit = subtotal * (margin / 100);
        const total = subtotal + profit;

        previewSubtotal.textContent = subtotal.toFixed(2);
        previewMargin.textContent = margin.toFixed(2);
        previewTotal.textContent = total.toFixed(2);
    }

    subtotalInput.addEventListener('input', updatePreview);
    marginInput.addEventListener('input', updatePreview);
    updatePreview();
});
</script>

<?php include __DIR__ . '/../../../layout/footer.php'; ?>
