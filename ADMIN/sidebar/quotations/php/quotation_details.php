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
$inspectionId = (int)($quotation['inspection_id'] ?? 0);
$projectId = (int)($quotation['project_id'] ?? 0);
$postInspectionDecision = $inspectionId > 0
    ? inquiry_quote_fetch_post_inspection_decision($conn, $inspectionId)
    : null;
$hasFinalProjectBasis = $postInspectionDecision
    && (int)($postInspectionDecision['final_quotation_draft_id'] ?? 0) === $quotationId;
$lastHistoryStatus = $history
    ? inquiry_quote_normalize_status((string)($history[count($history) - 1]['to_status'] ?? 'draft'))
    : '';
$quotationNumber = trim((string)($quotation['quotation_no'] ?? ''));
$quotationNumberLabel = $quotationNumber === '' || inquiry_quote_is_system_placeholder_number($quotationNumber)
    ? 'Quotation number not assigned'
    : $quotationNumber;
$hasOfficialQuotationNumber = $quotationNumber !== '' && !inquiry_quote_is_system_placeholder_number($quotationNumber);
$canSendQuotation = in_array($selectedStatus, ['draft', 'approved'], true)
    && $clientEmail !== ''
    && $hasOfficialQuotationNumber;
$quotationReturnUrl = '/codesamplecaps/ADMIN/sidebar/quotations/php/quotation_details.php?id=' . $quotationId;
$openInquiryUrl = '/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?' . http_build_query([
    'details' => (int)($quotation['inquiry_id'] ?? 0),
    'tab' => 'inspection',
    'return_url' => $quotationReturnUrl,
]);

$adminPageTitle = 'Quotation Details - Edge Automation';
$adminCssFiles = [
    '/codesamplecaps/ADMIN/common/css/admin-common.css',
    '/codesamplecaps/SHARED/action-loading/css/action-loading.css?v=20261103-3',
    '/codesamplecaps/ADMIN/sidebar/quotations/css/quotations.css?v=20261110-7',
];
$adminJsFiles = [
    '/codesamplecaps/ADMIN/common/js/admin-common.js',
    '/codesamplecaps/SHARED/action-loading/js/action-loading.js?v=20261103-2',
    '/codesamplecaps/ADMIN/sidebar/quotations/js/quotation-details.js?v=20261110-4',
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
            <section class="admin-quotation-detail" data-quotation-live-status data-quotation-id="<?php echo $quotationId; ?>" data-current-status="<?php echo admin_quote_details_e($selectedStatus); ?>" data-current-updated-at="<?php echo admin_quote_details_e($quotation['updated_at'] ?? ''); ?>">
                <div class="admin-quotation-detail-head">
                    <div>
                        <h1><?php echo admin_quote_details_e($quotation['client_name'] ?? 'Client'); ?></h1>
                        <p><?php echo admin_quote_details_e($quotation['service_category'] ?? 'Service not provided'); ?></p>
                    </div>
                    <div class="admin-quotation-detail-status">
                        <span class="quote-status <?php echo admin_quote_details_status_class($selectedStatus); ?>" data-quotation-status-badge><?php echo admin_quote_details_e($statusLabels[$selectedStatus] ?? inquiry_quote_status_label($selectedStatus)); ?></span>
                        <p class="admin-quotation-kicker">Quotation No. <?php echo admin_quote_details_e($quotationNumberLabel); ?></p>
                    </div>
                </div>

                <div class="admin-quotation-summary">
                    <article><span>Client Email</span><strong><?php echo admin_quote_details_e($quotation['email'] ?? 'Not provided'); ?></strong></article>
                    <article><span>Total</span><strong><?php echo admin_quote_details_e(inquiry_quote_format_money((float)($quotation['grand_total'] ?? 0))); ?></strong></article>
                    <article><span>Prepared By</span><strong><?php echo admin_quote_details_e($quotation['preparer_name'] ?? 'Admin'); ?></strong></article>
                </div>

                <div class="admin-quotation-revision-note" data-quotation-revision-note <?php echo $selectedStatus === 'revision_requested' && trim((string)($quotation['client_decision_note'] ?? '')) !== '' ? '' : 'hidden'; ?>>
                    <strong>Client revision request</strong>
                    <p data-quotation-revision-note-text><?php echo admin_quote_details_e($quotation['client_decision_note'] ?? ''); ?></p>
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

                <div class="admin-quotation-actions">
                    <?php if (in_array($selectedStatus, ['draft', 'approved'], true)): ?>
                        <a class="<?php echo $canSendQuotation ? 'button-secondary' : 'button-primary'; ?>" href="/codesamplecaps/ADMIN/sidebar/inquiries/php/create_quotation.php?edit_id=<?php echo $quotationId; ?>" data-quotation-edit-draft>Edit Quotation</a>
                    <?php endif; ?>
                    <a class="button-primary" data-quotation-revise-action href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?open=inquiryModal<?php echo (int)$quotation['inquiry_id']; ?>&amp;tab=quotation" <?php echo $selectedStatus === 'revision_requested' ? '' : 'hidden'; ?>>Revise Quotation</a>
                    <a class="button-secondary" href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiry_quotation_pdf.php?id=<?php echo $quotationId; ?>">Preview Quotation</a>
                    <button type="button" class="button-secondary" data-quotation-history-open>View History</button>
                    <a class="button-secondary" href="<?php echo admin_quote_details_e($openInquiryUrl); ?>">Open Inquiry</a>
                    <?php if ($canSendQuotation): ?>
                        <form method="POST" action="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php" class="admin-quotation-send-form" data-quotation-send-form>
                            <input type="hidden" name="csrf_token" value="<?php echo admin_quote_details_e($sendCsrfToken); ?>">
                            <input type="hidden" name="action" value="send_quotation_to_client">
                            <input type="hidden" name="inquiry_id" value="<?php echo (int)$quotation['inquiry_id']; ?>">
                            <input type="hidden" name="draft_id" value="<?php echo $quotationId; ?>">
                            <button type="submit" class="button-primary">Send to Client</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($projectId <= 0): ?>
                        <?php if ($hasFinalProjectBasis): ?>
                            <form method="POST" action="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php" class="admin-quotation-project-form" data-quotation-create-project <?php echo $selectedStatus === 'accepted' ? '' : 'hidden'; ?>>
                                <input type="hidden" name="csrf_token" value="<?php echo admin_quote_details_e($sendCsrfToken); ?>">
                                <input type="hidden" name="action" value="prepare_project_from_quote">
                                <input type="hidden" name="inquiry_id" value="<?php echo (int)$quotation['inquiry_id']; ?>">
                                <input type="hidden" name="draft_id" value="<?php echo $quotationId; ?>">
                                <button type="submit" class="button-primary">Create Project</button>
                            </form>
                        <?php else: ?>
                            <a class="button-primary" data-quotation-create-project href="<?php echo admin_quote_details_e($openInquiryUrl); ?>" <?php echo $selectedStatus === 'accepted' ? '' : 'hidden'; ?>>Create Project</a>
                        <?php endif; ?>
                    <?php elseif ($projectId > 0): ?>
                        <a class="button-primary admin-quotation-project-action" href="/codesamplecaps/ADMIN/sidebar/projects/php/project_details.php?id=<?php echo $projectId; ?>">View Project</a>
                    <?php endif; ?>
                </div>

                <div class="admin-quotation-history-modal" data-quotation-history-modal hidden>
                    <div class="admin-quotation-history-modal__backdrop" data-quotation-history-close></div>
                    <section class="admin-quotation-history-modal__card" role="dialog" aria-modal="true" aria-labelledby="quotationHistoryTitle">
                        <div class="admin-quotation-history-modal__head">
                            <h2 id="quotationHistoryTitle">Status History</h2>
                            <button type="button" class="admin-quotation-history-modal__close" data-quotation-history-close aria-label="Close status history">&times;</button>
                        </div>
                        <div class="admin-quotation-history" data-quotation-status-history>
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
                    </section>
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
