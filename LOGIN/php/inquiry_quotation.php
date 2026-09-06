<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/quotation_module.php';
require_once __DIR__ . '/../../config/inquiry_quotation_module.php';
require_once __DIR__ . '/../../config/site_inspections.php';

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$flash = quotation_module_consume_flash();
$csrfToken = quotation_module_csrf_token();
$isAjaxRequest = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!quotation_module_is_valid_csrf($_POST['csrf_token'] ?? null)) {
        if ($isAjaxRequest) {
            http_response_code(419);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'message' => 'Security check failed. Please refresh and try again.']);
            exit();
        }
        quotation_module_set_flash('error', 'Security check failed. Please try again.');
        quotation_module_redirect('/codesamplecaps/LOGIN/php/inquiry_quotation.php?token=' . urlencode($token));
    }

    $action = trim((string)($_POST['action'] ?? ''));
    $note = quotation_module_normalize_text($_POST['note'] ?? '');
    $successMessage = '';

    try {
        if ($action === 'client_accept') {
            inquiry_quote_public_respond($conn, $token, 'accepted', $note);
            $successMessage = 'Quotation accepted successfully.';
        } elseif ($action === 'client_revision') {
            inquiry_quote_public_respond($conn, $token, 'revision_requested', $note);
            $successMessage = 'Revision request submitted.';
        } elseif ($action === 'client_reject') {
            inquiry_quote_public_respond($conn, $token, 'rejected', $note);
            $successMessage = 'Quotation rejected.';
        } else {
            throw new RuntimeException('Invalid quotation action.');
        }

        if ($isAjaxRequest) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'success' => true,
                'message' => $successMessage,
                'action' => $action,
            ]);
            exit();
        }

        quotation_module_set_flash('success', $successMessage);
    } catch (Throwable $throwable) {
        if ($isAjaxRequest) {
            http_response_code(422);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'message' => $throwable->getMessage()]);
            exit();
        }
        quotation_module_set_flash('error', $throwable->getMessage());
    }

    quotation_module_redirect('/codesamplecaps/LOGIN/php/inquiry_quotation.php?token=' . urlencode($token));
}

$quotation = inquiry_quote_fetch_by_public_token($conn, $token);
$items = $quotation ? inquiry_quote_fetch_items($conn, (int)$quotation['id']) : [];
$status = $quotation ? inquiry_quote_normalize_status((string)$quotation['status']) : '';
$statusIndicator = $quotation ? inquiry_quote_status_indicator($status) : null;
$canRespond = $status === 'sent';
$hasInspectionSchedule = $quotation && !empty($quotation['scheduled_at']) && !empty($quotation['engineer_name']);
$isApprovedAwaitingSchedule = $status === 'accepted' && !$hasInspectionSchedule;
$isFinalized = $status === 'accepted' && $hasInspectionSchedule;
$quotationTimestamp = $quotation ? (strtotime((string)($quotation['created_at'] ?? '')) ?: time()) : time();
$quotationDate = date('M j, Y', $quotationTimestamp);
$validUntil = date('M j, Y', strtotime('+14 days', $quotationTimestamp));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation Review - Edge Automation</title>
    <link rel="icon" type="image/x-icon" href="/codesamplecaps/IMAGES/edge.jpg">
    <link rel="stylesheet" href="/codesamplecaps/SHARED/quotation/css/inquiry-quotation-document.css">
    <link rel="stylesheet" href="/codesamplecaps/LOGIN/css/inquiry_quotation.css">
    <script src="/codesamplecaps/LOGIN/js/inquiry_quotation.js" defer></script>
</head>
<body>
    <?php if ($quotation): ?>
        <div class="sticky-toolbar">
            <div class="sticky-toolbar__inner">
                <button type="button" class="sticky-toolbar__download" data-download-review-pdf>Download Review PDF</button>
            </div>
        </div>
    <?php endif; ?>
    <main class="public-quote-shell">
        <section class="public-quote-card">
            <?php if ($flash): ?>
                <div class="public-quote-alert public-quote-alert--<?php echo htmlspecialchars((string)$flash['type'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars((string)$flash['message'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <?php if (!$quotation): ?>
                <div class="public-quote-empty">
                    This quotation link is invalid or expired.
                </div>
            <?php else: ?>
                <article class="public-quote-document">
                    <?php if ($statusIndicator): ?>
                        <div class="public-quote-watermark public-quote-watermark--<?php echo htmlspecialchars((string)$statusIndicator['class'], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true">
                            <?php echo htmlspecialchars((string)$statusIndicator['text'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php endif; ?>

                    <header class="public-quote-header">
                        <div class="public-quote-brand">
                            <img src="/codesamplecaps/IMAGES/edge.jpg" alt="Edge Automation logo">
                            <div>
                                <strong>EDGE AUTOMATION TECHNOLOGY SERVICES, CO.</strong>
                                <span>Project Management, Asset Tracking, Inventory, and Quotation System</span>
                            </div>
                        </div>
                        <div class="public-quote-meta-box">
                            <span>Quotation No.</span>
                            <strong><?php echo htmlspecialchars((string)$quotation['quotation_no'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            <small class="public-quote-status public-quote-status--<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo $isFinalized
                                    ? 'Approved / Finalized'
                                    : ($isApprovedAwaitingSchedule
                                        ? 'Approved - Schedule Pending'
                                        : htmlspecialchars($status === 'sent' ? 'Pending Review' : inquiry_quote_status_label($status), ENT_QUOTES, 'UTF-8')); ?>
                            </small>
                        </div>
                    </header>

                    <section class="public-quote-title-row">
                        <div>
                            <span>Prepared For</span>
                            <h1><?php echo htmlspecialchars((string)$quotation['client_name'], ENT_QUOTES, 'UTF-8'); ?></h1>
                            <p><?php echo htmlspecialchars((string)($quotation['company_name'] ?: 'Individual Client'), ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>
                        <div class="public-quote-prepared-by">
                            <span>Date</span>
                            <strong><?php echo htmlspecialchars($quotationDate, ENT_QUOTES, 'UTF-8'); ?></strong>
                            <span>Prepared By</span>
                            <strong>Engr. Erika Jeanne P. Jimenez</strong>
                            <small>CEO / General Manager</small>
                        </div>
                    </section>

                    <section class="public-quote-grid">
                        <div><span>Email</span><strong><?php echo htmlspecialchars((string)$quotation['email'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>Contact</span><strong><?php echo htmlspecialchars((string)$quotation['contact_no'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>Service</span><strong><?php echo htmlspecialchars((string)$quotation['service_category'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><span>Valid Until</span><strong><?php echo htmlspecialchars($validUntil, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <?php if ($hasInspectionSchedule): ?>
                            <div><span>Assigned Engineer</span><strong><?php echo htmlspecialchars((string)$quotation['engineer_name'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                            <div><span>Inspection Schedule</span><strong><?php echo htmlspecialchars(site_inspection_format_datetime($quotation['scheduled_at']), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <?php endif; ?>
                        <div class="public-quote-grid__wide">
                            <span>Site Address</span>
                            <strong>
                                <?php echo htmlspecialchars(trim((string)($quotation['site_address'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
                                <?php if (!empty($quotation['barangay']) || !empty($quotation['city_municipality']) || !empty($quotation['province'])): ?>
                                    <br><?php echo htmlspecialchars(trim((string)($quotation['barangay'] ?? '') . ', ' . (string)($quotation['city_municipality'] ?? '') . ', ' . (string)($quotation['province'] ?? ''), ' ,'), ENT_QUOTES, 'UTF-8'); ?>
                                <?php endif; ?>
                            </strong>
                        </div>
                    </section>

                    <section class="public-quote-section">
                        <h2>Scope Summary</h2>
                        <p><?php echo nl2br(htmlspecialchars((string)($quotation['engineer_findings'] ?: $quotation['description']), ENT_QUOTES, 'UTF-8')); ?></p>
                    </section>

                    <section class="public-quote-section">
                        <h2>Cost Breakdown</h2>
                        <div class="public-quote-table-wrap">
                            <table class="public-quote-table">
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
                        </div>

                        <div class="public-quote-totals">
                            <div><span>Subtotal</span><strong><?php echo htmlspecialchars(inquiry_quote_format_money((float)$quotation['subtotal']), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                            <div><span>Margin</span><strong><?php echo htmlspecialchars(number_format((float)$quotation['profit_margin_percent'], 2), ENT_QUOTES, 'UTF-8'); ?>%</strong></div>
                            <div><span>Profit Amount</span><strong><?php echo htmlspecialchars(inquiry_quote_format_money((float)$quotation['profit_amount']), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                            <div class="public-quote-grand-total"><span>Grand Total</span><strong><?php echo htmlspecialchars(inquiry_quote_format_money((float)$quotation['grand_total']), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        </div>
                    </section>

                    <?php if (!empty($quotation['client_decision_note'])): ?>
                        <section class="public-quote-section">
                            <h2>Client Note</h2>
                            <p><?php echo nl2br(htmlspecialchars((string)$quotation['client_decision_note'], ENT_QUOTES, 'UTF-8')); ?></p>
                        </section>
                    <?php endif; ?>

                    <footer class="public-quote-footer">
                        <div>
                            <strong>Notes</strong>
                            <p>This quotation is based on the listed scope and costs. Any approved changes may require a revised quotation.</p>
                        </div>
                        <div>
                            <strong>Client Status</strong>
                            <p><?php echo $isFinalized
                                ? 'Approved and finalized with an inspection schedule.'
                                : ($isApprovedAwaitingSchedule ? 'Approved. Waiting for the Admin inspection schedule.' : 'Waiting for client review.'); ?></p>
                        </div>
                    </footer>
                </article>

                <?php if (!$isApprovedAwaitingSchedule): ?>
                <section class="public-quote-controls" aria-label="Quotation response">
                    <?php if ($canRespond): ?>
                        <form method="POST" class="public-quote-form" data-public-quotation-form>
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                            <label class="public-quote-decision-note" data-decision-note>
                                <span>Decision Note</span>
                                <textarea name="note" rows="4" placeholder="Please select Request Revision or Reject to provide details..." disabled></textarea>
                            </label>
                            <div class="public-quote-actions">
                                <button type="submit" name="action" value="client_accept" class="public-quote-button public-quote-button--accept" data-quotation-decision>Approve Quotation</button>
                                <button type="submit" name="action" value="client_revision" class="public-quote-button public-quote-button--revision" data-quotation-decision>Request Revision</button>
                                <button type="submit" name="action" value="client_reject" class="public-quote-button public-quote-button--reject" data-quotation-decision>Reject</button>
                            </div>
                        </form>
                    <?php elseif ($isFinalized): ?>
                        <div class="public-quote-finalized">
                            <strong>Status: Approved / Finalized</strong>
                            <button type="button" class="public-quote-button public-quote-button--print" data-print-final-quotation>Print / Save Final PDF</button>
                        </div>
                    <?php else: ?>
                        <div class="public-quote-empty">
                            This quotation is already <?php echo htmlspecialchars(inquiry_quote_status_label($status), ENT_QUOTES, 'UTF-8'); ?>.
                        </div>
                    <?php endif; ?>
                </section>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
