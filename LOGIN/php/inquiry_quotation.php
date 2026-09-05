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

    try {
        if ($action === 'client_accept') {
            inquiry_quote_public_respond($conn, $token, 'accepted', $note);
            quotation_module_set_flash('success', 'Quotation accepted successfully.');
        } elseif ($action === 'client_revision') {
            inquiry_quote_public_respond($conn, $token, 'revision_requested', $note);
            quotation_module_set_flash('success', 'Revision request submitted.');
        } elseif ($action === 'client_reject') {
            inquiry_quote_public_respond($conn, $token, 'rejected', $note);
            quotation_module_set_flash('success', 'Quotation rejected.');
        } else {
            throw new RuntimeException('Invalid quotation action.');
        }

        if ($isAjaxRequest) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'success' => true,
                'message' => $action === 'client_accept'
                    ? 'Quotation approved and finalized.'
                    : 'Quotation response saved.',
            ]);
            exit();
        }
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
$canRespond = $status === 'sent';
$isFinalized = in_array($status, ['approved', 'accepted'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation Review - Edge Automation</title>
    <link rel="icon" type="image/x-icon" href="/codesamplecaps/IMAGES/edge.jpg">
    <link rel="stylesheet" href="/codesamplecaps/LOGIN/css/inquiry_quotation.css">
    <script src="/codesamplecaps/LOGIN/js/inquiry_quotation.js" defer></script>
</head>
<body>
    <main class="public-quote-shell">
        <section class="public-quote-card">
            <div class="public-quote-brand">
                <img src="/codesamplecaps/IMAGES/edge.jpg" alt="Edge Automation logo">
                <div>
                    <span>EDGE AUTOMATION</span>
                    <strong>Quotation Review</strong>
                </div>
            </div>

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
                <header class="public-quote-header">
                    <div>
                        <span><?php echo htmlspecialchars((string)$quotation['quotation_no'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <h1><?php echo htmlspecialchars((string)$quotation['service_category'], ENT_QUOTES, 'UTF-8'); ?></h1>
                        <p><?php echo htmlspecialchars((string)$quotation['client_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                    <strong class="public-quote-status public-quote-status--<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo $isFinalized
                            ? 'Status: Approved / Finalized'
                            : htmlspecialchars(inquiry_quote_status_label($status), ENT_QUOTES, 'UTF-8'); ?>
                    </strong>
                </header>

                <div class="public-quote-grid">
                    <div><span>Email</span><strong><?php echo htmlspecialchars((string)$quotation['email'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    <div><span>Contact</span><strong><?php echo htmlspecialchars((string)$quotation['contact_no'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    <div><span>Inspection</span><strong><?php echo htmlspecialchars(site_inspection_format_datetime($quotation['scheduled_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    <div><span>Total</span><strong><?php echo htmlspecialchars(inquiry_quote_format_money((float)$quotation['grand_total']), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                </div>

                <section class="public-quote-section">
                    <h2>Scope Summary</h2>
                    <p><?php echo nl2br(htmlspecialchars((string)($quotation['engineer_findings'] ?: $quotation['description']), ENT_QUOTES, 'UTF-8')); ?></p>
                </section>

                <section class="public-quote-section">
                    <h2>Cost Breakdown</h2>
                    <div class="public-quote-table">
                        <div class="public-quote-row public-quote-row--head">
                            <span>Type</span><span>Item</span><span>Qty</span><span>Total</span>
                        </div>
                        <?php foreach ($items as $item): ?>
                            <div class="public-quote-row">
                                <span><?php echo htmlspecialchars(ucfirst((string)$item['item_type']), ENT_QUOTES, 'UTF-8'); ?></span>
                                <span><?php echo htmlspecialchars((string)$item['item_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span><?php echo htmlspecialchars(rtrim(rtrim(number_format((float)$item['quantity'], 2), '0'), '.') . ' ' . (string)$item['unit'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span><?php echo htmlspecialchars(inquiry_quote_format_money((float)$item['line_total']), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <?php if (!empty($quotation['client_decision_note'])): ?>
                    <section class="public-quote-section">
                        <h2>Your Note</h2>
                        <p><?php echo nl2br(htmlspecialchars((string)$quotation['client_decision_note'], ENT_QUOTES, 'UTF-8')); ?></p>
                    </section>
                <?php endif; ?>

                <?php if ($canRespond): ?>
                    <form method="POST" class="public-quote-form" data-public-quotation-form>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                        <label>
                            <span>Decision Note</span>
                            <textarea name="note" rows="4" placeholder="Required for revision or rejection."></textarea>
                        </label>
                        <div class="public-quote-actions">
                            <button type="submit" name="action" value="client_accept" class="public-quote-button public-quote-button--accept">Approve Quotation</button>
                            <button type="submit" name="action" value="client_revision" class="public-quote-button public-quote-button--revision">Request Revision</button>
                            <button type="submit" name="action" value="client_reject" class="public-quote-button public-quote-button--reject">Reject</button>
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
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
