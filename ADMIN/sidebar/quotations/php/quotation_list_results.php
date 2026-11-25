<div class="admin-quotation-layout is-list-only">
    <aside class="admin-quotation-list" aria-label="Quotation list">
        <?php if (!$quotations): ?>
            <div class="admin-quotation-empty compact"><p>No quotation found.</p></div>
        <?php endif; ?>
        <?php foreach ($quotations as $quotation):
            $status = inquiry_quote_normalize_status((string)($quotation['status'] ?? 'draft'));
            $displayStatus = $status === 'approved' ? 'draft' : $status;
            $quotationNumber = trim((string)($quotation['quotation_no'] ?? ''));
            $isNumberMissing = $quotationNumber === '' || inquiry_quote_is_system_placeholder_number($quotationNumber);
            $quotationNumberLabel = $isNumberMissing ? 'Quotation number not assigned' : $quotationNumber;
            $detailsUrl = '/codesamplecaps/ADMIN/sidebar/quotations/php/quotation_details.php?id=' . (int)$quotation['id'];
        ?>
            <article class="admin-quotation-card">
                <div class="admin-quotation-card__main">
                    <strong class="admin-quotation-card__number<?php echo $isNumberMissing ? ' has-warning' : ''; ?>"><?php echo admin_quote_e($quotationNumberLabel); ?></strong>
                    <h2><?php echo admin_quote_e($quotation['client_name'] ?? 'Client'); ?></h2>
                    <span class="admin-quotation-card__inquiry">INQ-<?php echo str_pad((string)(int)($quotation['inquiry_id'] ?? 0), 6, '0', STR_PAD_LEFT); ?></span>
                    <span class="admin-quotation-card__updated">Updated <?php echo admin_quote_e(admin_quote_date($quotation['updated_at'] ?? '')); ?></span>
                </div>
                <div class="admin-quotation-card__side">
                    <span class="quote-status <?php echo admin_quote_status_class($displayStatus); ?>"><?php echo admin_quote_e($statusLabels[$displayStatus] ?? inquiry_quote_status_label($displayStatus)); ?></span>
                    <span class="admin-quotation-card__total"><small>Total</small><strong><?php echo admin_quote_e(inquiry_quote_format_money((float)($quotation['grand_total'] ?? 0))); ?></strong></span>
                    <div class="admin-quotation-card__actions">
                        <a class="admin-quotation-card__view is-secondary" href="<?php echo admin_quote_e($detailsUrl); ?>">View Details</a>
                        <?php if ($displayStatus === 'draft'): ?>
                            <a class="admin-quotation-card__view" href="/codesamplecaps/ADMIN/sidebar/inquiries/php/create_quotation.php?edit_id=<?php echo (int)$quotation['id']; ?>">Edit Quotation</a>
                        <?php elseif ($displayStatus === 'revision_requested'): ?>
                            <a class="admin-quotation-card__view is-complete-draft" href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?open=inquiryModal<?php echo (int)$quotation['inquiry_id']; ?>&amp;tab=quotation">Revise Quotation</a>
                        <?php elseif ($displayStatus === 'accepted' && !empty($quotation['project_id'])): ?>
                            <a class="admin-quotation-card__view" href="/codesamplecaps/ADMIN/sidebar/projects/php/project_details.php?id=<?php echo (int)$quotation['project_id']; ?>">View Project</a>
                        <?php elseif ($displayStatus === 'accepted'): ?>
                            <form method="POST" action="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php" class="admin-quotation-card__action-form" data-quotation-create-project>
                                <input type="hidden" name="csrf_token" value="<?php echo admin_quote_e($quotationActionCsrfToken); ?>">
                                <input type="hidden" name="action" value="prepare_project_from_quote">
                                <input type="hidden" name="inquiry_id" value="<?php echo (int)$quotation['inquiry_id']; ?>">
                                <input type="hidden" name="draft_id" value="<?php echo (int)$quotation['id']; ?>">
                                <button type="submit" class="admin-quotation-card__view">Create Project</button>
                            </form>
                        <?php elseif ($displayStatus === 'rejected' && trim((string)($quotation['client_decision_note'] ?? '')) !== ''): ?>
                            <a class="admin-quotation-card__view is-rejected-reason" href="<?php echo admin_quote_e($detailsUrl); ?>#clientDecisionNote">View Reason</a>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if ($totalPages > 1): ?>
            <nav class="admin-quotation-pagination" aria-label="Quotation pages">
                <?php if ($currentPage > 1): ?>
                    <a href="<?php echo admin_quote_e(admin_quote_page_url(['page' => $currentPage - 1, 'id' => null])); ?>">Previous</a>
                <?php endif; ?>
                <span>Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?></span>
                <?php if ($currentPage < $totalPages): ?>
                    <a href="<?php echo admin_quote_e(admin_quote_page_url(['page' => $currentPage + 1, 'id' => null])); ?>">Next</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </aside>
</div>
