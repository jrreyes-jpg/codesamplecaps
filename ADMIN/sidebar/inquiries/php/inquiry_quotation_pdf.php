<?php
require_once __DIR__ . '/../../../includes/admin_auth.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/inquiry_quotation_module.php';
require_once __DIR__ . '/../../../../config/site_inspections.php';

$draftId = (int)($_GET['id'] ?? 0);
$quotation = $draftId > 0 ? inquiry_quote_fetch_full($conn, $draftId) : null;
$items = $quotation ? inquiry_quote_fetch_items($conn, $draftId) : [];

if (!$quotation) {
    http_response_code(404);
    echo 'Quotation not found.';
    exit();
}

$quoteStatus = inquiry_quote_normalize_status((string)($quotation['status'] ?? 'draft'));
$isFinalized = $quoteStatus === 'accepted' && !empty($quotation['scheduled_at']);
$showInspectionSchedule = $isFinalized && !empty($quotation['engineer_name']);
$statusClass = $isFinalized ? 'is-approved' : ($quoteStatus === 'sent' ? 'is-pending' : 'is-draft');
$statusLabel = $isFinalized
    ? 'Approved / Finalized'
    : ($quoteStatus === 'sent' ? 'Pending Review' : inquiry_quote_status_label($quoteStatus));
$preparedByName = trim((string)($quotation['approved_by_name'] ?? '')) ?: 'Erika Reyes';
$quotationTimestamp = strtotime((string)($quotation['created_at'] ?? '')) ?: time();
$quotationDate = date('M j, Y', $quotationTimestamp);
$validUntil = date('M j, Y', strtotime('+14 days', $quotationTimestamp));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars((string)$quotation['quotation_no'], ENT_QUOTES, 'UTF-8'); ?> - Quotation</title>
    <link rel="stylesheet" href="/codesamplecaps/ADMIN/sidebar/inquiries/css/inquiry-quotation-pdf.css">
    <link rel="icon" type="image/x-icon" href="/codesamplecaps/IMAGES/edge.jpg">
</head>
<body>
    <div class="quote-actions">
        <a href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php">Back to Inquiries</a>
        <?php if ($quoteStatus === 'draft'): ?>
            <a class="quote-edit-link" href="/codesamplecaps/ADMIN/sidebar/inquiries/php/create_quotation.php?edit_id=<?php echo $draftId; ?>">Edit Details</a>
        <?php endif; ?>
        <button type="button" data-print-quotation>Print / Save as PDF</button>
    </div>

    <main class="quote-page">
        <header class="quote-header">
            <div class="quote-brand">
                <img src="/codesamplecaps/IMAGES/edge.jpg" alt="Edge Automation logo">
                <div>
                    <strong>EDGE AUTOMATION TECHNOLOGY SERVICES, CO.</strong>
                    <span>Project Management, Asset Tracking, Inventory, and Quotation System</span>
                </div>
            </div>
            <div class="quote-meta-box">
                <span>Quotation No.</span>
                <strong><?php echo htmlspecialchars((string)$quotation['quotation_no'], ENT_QUOTES, 'UTF-8'); ?></strong>
                <small class="<?php echo $statusClass; ?>">
                    <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>
                </small>
            </div>
        </header>

        <section class="quote-title-row">
            <div>
                <span>Prepared For</span>
                <h1><?php echo htmlspecialchars((string)$quotation['client_name'], ENT_QUOTES, 'UTF-8'); ?></h1>
                <p><?php echo htmlspecialchars((string)($quotation['company_name'] ?: 'Individual Client'), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div>
                <span>Date</span>
                <strong><?php echo htmlspecialchars($quotationDate, ENT_QUOTES, 'UTF-8'); ?></strong>
                <span>Prepared By</span>
                <strong><?php echo htmlspecialchars($preparedByName, ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
        </section>

        <section class="quote-grid">
            <div class="quote-info-card">
                <span>Email</span>
                <strong><?php echo htmlspecialchars((string)$quotation['email'], ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div class="quote-info-card">
                <span>Contact</span>
                <strong><?php echo htmlspecialchars((string)$quotation['contact_no'], ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div class="quote-info-card">
                <span>Service</span>
                <strong><?php echo htmlspecialchars((string)$quotation['service_category'], ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <?php if ($showInspectionSchedule): ?>
                <div class="quote-info-card">
                    <span>Assigned Engineer</span>
                    <strong><?php echo htmlspecialchars((string)$quotation['engineer_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
                <div class="quote-info-card">
                    <span>Inspection Schedule</span>
                    <strong><?php echo htmlspecialchars(site_inspection_format_datetime($quotation['scheduled_at']), ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
            <?php endif; ?>
            <div class="quote-info-card">
                <span>Valid Until</span>
                <strong><?php echo htmlspecialchars($validUntil, ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div class="quote-info-card quote-info-card--wide">
                <span>Site Address</span>
                <strong>
                    <?php echo htmlspecialchars(trim((string)($quotation['site_address'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
                    <?php if (!empty($quotation['barangay']) || !empty($quotation['city_municipality']) || !empty($quotation['province'])): ?>
                        <br><?php echo htmlspecialchars(trim((string)($quotation['barangay'] ?? '') . ', ' . (string)($quotation['city_municipality'] ?? '') . ', ' . (string)($quotation['province'] ?? ''), ' ,'), ENT_QUOTES, 'UTF-8'); ?>
                    <?php endif; ?>
                </strong>
            </div>
        </section>

        <section class="quote-section">
            <h2>Scope Summary</h2>
            <p><?php echo nl2br(htmlspecialchars((string)($quotation['engineer_findings'] ?: $quotation['description']), ENT_QUOTES, 'UTF-8')); ?></p>
        </section>

        <section class="quote-section">
            <h2>Cost Breakdown</h2>
            <table class="quote-table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Unit Cost</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(ucfirst((string)$item['item_type']), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars((string)$item['item_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <?php if (!empty($item['notes'])): ?>
                                    <small><?php echo htmlspecialchars((string)$item['notes'], ENT_QUOTES, 'UTF-8'); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars(rtrim(rtrim(number_format((float)$item['quantity'], 2), '0'), '.') . ' ' . (string)$item['unit'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars(inquiry_quote_format_money((float)$item['unit_cost']), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars(inquiry_quote_format_money((float)$item['line_total']), ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <section class="quote-totals">
            <div><span>Subtotal</span><strong><?php echo htmlspecialchars(inquiry_quote_format_money((float)$quotation['subtotal']), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div><span>Margin</span><strong><?php echo htmlspecialchars(number_format((float)$quotation['profit_margin_percent'], 2), ENT_QUOTES, 'UTF-8'); ?>%</strong></div>
            <div><span>Profit Amount</span><strong><?php echo htmlspecialchars(inquiry_quote_format_money((float)$quotation['profit_amount']), ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="quote-grand-total"><span>Grand Total</span><strong><?php echo htmlspecialchars(inquiry_quote_format_money((float)$quotation['grand_total']), ENT_QUOTES, 'UTF-8'); ?></strong></div>
        </section>

        <footer class="quote-footer">
            <div>
                <strong>Notes</strong>
                <p>This quotation uses the validated scope and itemized costs. New findings during site inspection may require a revised quotation. The payment schedule will be confirmed in the written agreement.</p>
            </div>
            <div>
                <strong>Approval</strong>
                <p>
                    <?php if ($isFinalized): ?>
                        Approved by the client. Inspection schedule finalized by <?php echo htmlspecialchars($preparedByName, ENT_QUOTES, 'UTF-8'); ?>.
                    <?php elseif ($quoteStatus === 'sent'): ?>
                        Pending client review and approval.
                    <?php else: ?>
                        Draft quotation. Not yet sent to the client.
                    <?php endif; ?>
                </p>
            </div>
        </footer>
    </main>
    <script src="/codesamplecaps/ADMIN/sidebar/inquiries/js/inquiry-quotation-pdf.js"></script>
</body>
</html>
