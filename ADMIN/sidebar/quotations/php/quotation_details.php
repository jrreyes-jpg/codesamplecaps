<?php
require_once __DIR__ . '/../../../../config/auth_middleware.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/inquiry_quotation_module.php';

require_role('admin');

function admin_quote_details_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function admin_quote_details_date($value): string
{
    $time = strtotime((string)$value);
    return $time ? date('M d, Y g:i A', $time) : 'Not recorded';
}

function admin_quote_details_status_class(string $status): string
{
    return match ($status) {
        'accepted' => 'is-success',
        'sent' => 'is-active',
        'revision_requested' => 'is-warning',
        'rejected' => 'is-error',
        'approved' => 'is-neutral',
        default => 'is-neutral',
    };
}

$quotationId = (int)($_GET['id'] ?? 0);
$quotation = $quotationId > 0 ? inquiry_quote_fetch_full($conn, $quotationId) : null;
$items = $quotation ? inquiry_quote_fetch_items($conn, $quotationId) : [];
$history = $quotation ? inquiry_quote_fetch_history($conn, $quotationId) : [];
$visibleItems = array_values(array_filter($items, static function (array $item): bool {
    return (float)($item['line_total'] ?? 0) > 0;
}));
$materialsNote = '';
foreach ($items as $quotationItem) {
    if (inquiry_quote_normalize_item_type((string)($quotationItem['item_type'] ?? '')) === 'material'
        && trim((string)($quotationItem['notes'] ?? '')) !== '') {
        $materialsNote = trim((string)$quotationItem['notes']);
        break;
    }
}
$sendCsrfToken = auth_csrf_token('admin_inquiries');
$clientEmail = trim((string)($quotation['email'] ?? ''));
$statusLabels = [
    'draft' => 'Draft',
    'approved' => 'Draft',
    'sent' => 'Awaiting Client',
    'revision_requested' => 'Revision Requested',
    'accepted' => 'Accepted',
    'rejected' => 'Rejected',
];
$selectedStatus = $quotation
    ? inquiry_quote_normalize_status((string)($quotation['status'] ?? 'draft'))
    : 'draft';
$lastHistoryStatus = $history
    ? inquiry_quote_normalize_status((string)($history[count($history) - 1]['to_status'] ?? 'draft'))
    : '';
$quotationNumber = trim((string)($quotation['quotation_no'] ?? ''));
$quotationNumberLabel = $quotationNumber === '' || inquiry_quote_is_system_placeholder_number($quotationNumber)
    ? 'Quotation number not assigned'
    : $quotationNumber;

$adminPageTitle = 'Quotation Details - Edge Automation';
$adminCssFiles = [
    '/codesamplecaps/ADMIN/common/css/admin-common.css',
    '/codesamplecaps/SHARED/action-loading/css/action-loading.css?v=20261103-3',
    '/codesamplecaps/ADMIN/sidebar/quotations/css/quotations.css?v=20261103-7',
];
$adminJsFiles = [
    '/codesamplecaps/ADMIN/common/js/admin-common.js',
    '/codesamplecaps/SHARED/action-loading/js/action-loading.js?v=20261103-2',
    '/codesamplecaps/ADMIN/sidebar/quotations/js/quotation-details.js?v=20261110-1',
];

include __DIR__ . '/../../../layout/header.php';
include __DIR__ . '/../../../admin_sidebar.php';
?>
<main class="main-content">
    <div class="admin-quotation-page admin-quotation-details-page">
        <a class="admin-quotation-back" href="/codesamplecaps/ADMIN/sidebar/quotations/php/quotations.php">&larr; Back to Quotation List</a>

        <?php if (!$quotation): ?>
            <section class="admin-quotation-detail">
                <div class="admin-quotation-empty">
                    <h1>Quotation not found</h1>
                    <p>The quotation may have been removed or is not available.</p>
                </div>
            </section>
        <?php else: ?>
            <section class="admin-quotation-detail">
                <div class="admin-quotation-detail-head">
                    <div>
                        <p class="admin-quotation-kicker"><?php echo admin_quote_details_e($quotationNumberLabel); ?></p>
                        <h1><?php echo admin_quote_details_e($quotation['client_name'] ?? 'Client'); ?></h1>
                        <p><?php echo admin_quote_details_e($quotation['service_category'] ?? 'Service not provided'); ?></p>
                    </div>
                    <span class="quote-status <?php echo admin_quote_details_status_class($selectedStatus); ?>"><?php echo admin_quote_details_e($statusLabels[$selectedStatus] ?? inquiry_quote_status_label($selectedStatus)); ?></span>
                </div>

                <div class="admin-quotation-summary">
                    <article><span>Client Email</span><strong><?php echo admin_quote_details_e($quotation['email'] ?? 'Not provided'); ?></strong></article>
                    <article><span>Total</span><strong><?php echo admin_quote_details_e(inquiry_quote_format_money((float)($quotation['grand_total'] ?? 0))); ?></strong></article>
                    <article><span>Prepared By</span><strong><?php echo admin_quote_details_e($quotation['preparer_name'] ?? 'Admin'); ?></strong></article>
                </div>

                <div class="admin-quotation-actions">
                    <?php if (in_array($selectedStatus, ['draft', 'approved'], true)): ?>
                        <a class="button-primary" href="/codesamplecaps/ADMIN/sidebar/inquiries/php/create_quotation.php?edit_id=<?php echo $quotationId; ?>" data-quotation-edit-draft>Edit Quotation</a>
                    <?php endif; ?>
                    <a class="button-secondary" href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiry_quotation_pdf.php?id=<?php echo $quotationId; ?>">Preview Quotation</a>
                    <?php if (in_array($selectedStatus, ['draft', 'approved'], true) && $clientEmail !== ''): ?>
                        <form method="POST" action="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php" class="admin-quotation-send-form" data-quotation-send-form>
                            <input type="hidden" name="csrf_token" value="<?php echo admin_quote_details_e($sendCsrfToken); ?>">
                            <input type="hidden" name="action" value="send_quotation_to_client">
                            <input type="hidden" name="inquiry_id" value="<?php echo (int)$quotation['inquiry_id']; ?>">
                            <input type="hidden" name="draft_id" value="<?php echo $quotationId; ?>">
                            <button type="submit" class="button-primary">Send to Client</button>
                        </form>
                    <?php endif; ?>
                    <a class="button-secondary" href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?open=inquiryModal<?php echo (int)$quotation['inquiry_id']; ?>&amp;tab=inspection">Open Inquiry</a>
                </div>

                <div class="admin-quotation-section">
                    <h2>Quotation Items</h2>
                    <?php if ($materialsNote !== ''): ?>
                        <p class="admin-quotation-materials-note"><strong>Materials Note:</strong> <?php echo admin_quote_details_e($materialsNote); ?></p>
                    <?php endif; ?>
                    <div class="admin-quotation-table-wrap">
                        <table>
                            <thead><tr><th>Item</th><th>Qty</th><th>Unit</th><th>Unit Cost</th><th>Line Total</th></tr></thead>
                            <tbody>
                            <?php if (!$visibleItems): ?><tr><td colspan="5">No charged quotation items.</td></tr><?php endif; ?>
                            <?php foreach ($visibleItems as $item): ?>
                                <tr>
                                    <td data-label="Item">
                                        <strong><?php echo admin_quote_details_e($item['item_name'] ?? 'Item'); ?></strong>
                                        <small><?php echo admin_quote_details_e(inquiry_quote_item_type_label((string)($item['item_type'] ?? ''))); ?></small>
                                        <?php if (inquiry_quote_normalize_item_type((string)($item['item_type'] ?? '')) !== 'material' && trim((string)($item['notes'] ?? '')) !== ''): ?><small>Note: <?php echo admin_quote_details_e($item['notes']); ?></small><?php endif; ?>
                                    </td>
                                    <td data-label="Qty"><?php echo admin_quote_details_e($item['quantity'] ?? 0); ?></td>
                                    <td data-label="Unit"><?php echo admin_quote_details_e($item['unit'] ?? 'unit'); ?></td>
                                    <td data-label="Unit Cost"><?php echo admin_quote_details_e(inquiry_quote_format_money((float)($item['unit_cost'] ?? 0))); ?></td>
                                    <td data-label="Line Total"><?php echo admin_quote_details_e(inquiry_quote_format_money((float)($item['line_total'] ?? 0))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="admin-quotation-totals">
                        <p><span>Subtotal</span><strong><?php echo admin_quote_details_e(inquiry_quote_format_money((float)($quotation['subtotal'] ?? 0))); ?></strong></p>
                        <p><span>Markup (<?php echo admin_quote_details_e(number_format((float)($quotation['profit_margin_percent'] ?? 0), 2)); ?>%)</span><strong><?php echo admin_quote_details_e(inquiry_quote_format_money((float)($quotation['profit_amount'] ?? 0))); ?></strong></p>
                        <p class="is-total"><span>Grand Total</span><strong><?php echo admin_quote_details_e(inquiry_quote_format_money((float)($quotation['grand_total'] ?? 0))); ?></strong></p>
                    </div>
                </div>

                <div class="admin-quotation-section">
                    <h2>Status History</h2>
                    <div class="admin-quotation-history">
                        <?php if (!$history && $selectedStatus === 'draft'): ?><p>No status history yet.</p><?php endif; ?>
                        <?php foreach ($history as $entry): ?>
                            <?php $entry['status'] = $entry['to_status'] ?? 'draft'; ?>
                            <article>
                                <span class="history-dot" aria-hidden="true"></span>
                                <div><strong><?php echo admin_quote_details_e(inquiry_quote_status_label((string)($entry['status'] ?? 'draft'))); ?></strong><p><?php echo admin_quote_details_e($entry['full_name'] ?? 'System'); ?> · <?php echo admin_quote_details_e(admin_quote_details_date($entry['created_at'] ?? '')); ?></p></div>
                            </article>
                        <?php endforeach; ?>
                        <?php if ($selectedStatus !== 'draft' && $lastHistoryStatus !== $selectedStatus): ?>
                            <article>
                                <span class="history-dot" aria-hidden="true"></span>
                                <div><strong><?php echo admin_quote_details_e($statusLabels[$selectedStatus] ?? inquiry_quote_status_label($selectedStatus)); ?></strong><p>Current quotation status</p></div>
                            </article>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <div class="admin-quotation-send-modal" data-quotation-send-modal hidden>
                <div class="admin-quotation-send-modal__backdrop" data-quotation-send-cancel></div>
                <section class="admin-quotation-send-modal__card" role="dialog" aria-modal="true" aria-labelledby="quotationSendTitle">
                    <h2 id="quotationSendTitle">Send quotation to client?</h2>
                    <p>Review the saved quotation before sending it by email.</p>
                    <dl>
                        <div><dt>Client</dt><dd><?php echo admin_quote_details_e($quotation['client_name'] ?? 'Client'); ?></dd></div>
                        <div><dt>Email</dt><dd><?php echo admin_quote_details_e($clientEmail); ?></dd></div>
                        <div><dt>Total</dt><dd><?php echo admin_quote_details_e(inquiry_quote_format_money((float)($quotation['grand_total'] ?? 0))); ?></dd></div>
                    </dl>
                    <div class="admin-quotation-send-modal__actions">
                        <button type="button" class="button-secondary" data-quotation-send-cancel>Cancel</button>
                        <button type="button" class="button-primary" data-quotation-send-confirm>Send Quotation</button>
                    </div>
                </section>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php include __DIR__ . '/../../../layout/footer.php'; ?>
