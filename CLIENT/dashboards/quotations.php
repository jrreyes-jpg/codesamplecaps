<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/quotation_module.php';
require_once __DIR__ . '/../../config/inquiry_quotation_module.php';
require_once __DIR__ . '/../includes/client_shell.php';

require_role('client');

$userId = (int)($_SESSION['user_id'] ?? 0);
$clientName = trim((string)($_SESSION['name'] ?? 'Client User'));
$clientEmail = trim((string)($_SESSION['email'] ?? ''));
$clientEmailDisplay = $clientEmail !== '' ? $clientEmail : 'No email on record';
$shellContext = client_shell_build_topbar_context($conn, $userId, $clientName, $clientEmailDisplay);
$flash = quotation_module_consume_flash();
$csrfToken = quotation_module_csrf_token();
$tablesReady = quotation_module_tables_ready($conn);
$quotations = $tablesReady ? quotation_module_fetch_quotations($conn, 'client', $userId) : [];
$inquiryQuotations = inquiry_quote_fetch_for_client($conn, $userId);
$quotationId = (int)($_GET['id'] ?? 0);
$selectedQuotationSource = (string)($_GET['source'] ?? 'generic');
$selectedQuotation = null;
$selectedInquiryItems = [];

if ($quotationId > 0 && $selectedQuotationSource === 'inquiry') {
    $selectedQuotation = inquiry_quote_fetch_full($conn, $quotationId);
    if (!$selectedQuotation || !inquiry_quote_client_can_access($conn, $quotationId, $userId)) {
        quotation_module_set_flash('error', 'Quotation not found in your account.');
        quotation_module_redirect('/codesamplecaps/CLIENT/dashboards/quotations.php');
    }
    $selectedInquiryItems = inquiry_quote_fetch_items($conn, $quotationId);
} elseif ($quotationId > 0 && $tablesReady) {
    $selectedQuotation = quotation_module_fetch_quotation($conn, $quotationId);
    if (!$selectedQuotation || !quotation_module_user_can_access($selectedQuotation, 'client', $userId)) {
        quotation_module_set_flash('error', 'Quotation not found in your account.');
        quotation_module_redirect('/codesamplecaps/CLIENT/dashboards/quotations.php');
    }
    $selectedQuotationSource = 'generic';
} elseif (!empty($inquiryQuotations)) {
    $selectedQuotationSource = 'inquiry';
    $selectedQuotation = inquiry_quote_fetch_full($conn, (int)$inquiryQuotations[0]['id']);
    $selectedInquiryItems = $selectedQuotation ? inquiry_quote_fetch_items($conn, (int)$selectedQuotation['id']) : [];
} elseif (!empty($quotations)) {
    $selectedQuotationSource = 'generic';
    $selectedQuotation = quotation_module_fetch_quotation($conn, (int)$quotations[0]['id']);
}

$items = $selectedQuotation && $selectedQuotationSource === 'generic'
    ? quotation_module_fetch_quotation_items($conn, (int)$selectedQuotation['id'])
    : $selectedInquiryItems;
$responseStatus = $selectedQuotationSource === 'inquiry'
    ? inquiry_quote_normalize_status((string)($selectedQuotation['status'] ?? ''))
    : (string)($selectedQuotation['status'] ?? '');
$isAwaitingClientDecision = $responseStatus === 'sent';
$totalQuotationCount = count($quotations) + count($inquiryQuotations);
$waitingQuotationCount = count(array_filter($quotations, static fn($quotation) => (string)($quotation['status'] ?? '') === 'sent'))
    + count(array_filter($inquiryQuotations, static fn($quotation) => inquiry_quote_normalize_status((string)($quotation['status'] ?? '')) === 'sent'));
$clientPageTitle = 'My Quotations - Edge Automation';
$clientCssFiles = [
    '/codesamplecaps/CLIENT/css/quotations.css',
];

require_once __DIR__ . '/../layout/header.php';
?>

<?php include __DIR__ . '/../sidebar/client_sidebar.php'; ?>
<?php client_shell_render_topbar($shellContext); ?>
<main class="main-content" id="mainContent">
    <div class="quotation-shell">
        <?php if ($flash): ?><div class="flash <?php echo htmlspecialchars((string)$flash['type']); ?>"><?php echo htmlspecialchars((string)$flash['message']); ?></div><?php endif; ?>

        <section class="quotation-hero">
            <div>
                <p class="quotation-kicker">Client Quotations</p>
                <h1>Review pricing, scope, and delivery before you commit.</h1>
                <p>If the quotation looks high or the scope does not feel right, the safest flow is to reject it with a clear note so the engineer can revise and resend a cleaner version.</p>
            </div>
            <div class="quotation-stat-grid">
                <div class="quotation-stat">
                    <span>Total quotations</span>
                    <strong><?php echo $totalQuotationCount; ?></strong>
                </div>
                <div class="quotation-stat">
                    <span>Waiting for your review</span>
                    <strong><?php echo $waitingQuotationCount; ?></strong>
                </div>
                <div class="quotation-stat">
                    <span>Current quotation</span>
                    <strong><?php echo $selectedQuotation ? htmlspecialchars((string)$selectedQuotation['quotation_no']) : 'None'; ?></strong>
                </div>
                <div class="quotation-stat">
                    <span>Status</span>
                    <strong><?php echo $selectedQuotation ? htmlspecialchars($selectedQuotationSource === 'inquiry' ? inquiry_quote_status_label($responseStatus) : quotation_module_status_label($responseStatus)) : 'N/A'; ?></strong>
                </div>
            </div>
        </section>

        <?php if (!$tablesReady): ?>
            <section class="quotation-panel"><div class="empty-state">Quotation records are not available yet. Please contact the system administrator.</div></section>
        <?php else: ?>
            <div class="quotation-grid">
                <aside class="quotation-panel quotation-list-panel">
                    <h2>Quotation Queue</h2>
                    <p>Open one quotation at a time and decide with a documented reason when changes are needed.</p>
                    <div class="quotation-list-stack">
                        <?php if (!empty($inquiryQuotations) || !empty($quotations)): ?>
                            <?php foreach ($inquiryQuotations as $quotation): ?>
                                <a class="quotation-list-card<?php echo $selectedQuotationSource === 'inquiry' && $selectedQuotation && (int)$selectedQuotation['id'] === (int)$quotation['id'] ? ' is-active' : ''; ?>" href="/codesamplecaps/CLIENT/dashboards/quotations.php?source=inquiry&id=<?php echo (int)$quotation['id']; ?>">
                                    <div class="quotation-list-card__top">
                                        <strong><?php echo htmlspecialchars((string)$quotation['quotation_no']); ?></strong>
                                        <span class="status-pill <?php echo htmlspecialchars(inquiry_quote_status_class((string)$quotation['status'])); ?>"><?php echo htmlspecialchars(inquiry_quote_status_label((string)$quotation['status'])); ?></span>
                                    </div>
                                    <span><?php echo htmlspecialchars((string)$quotation['service_category']); ?></span>
                                    <p><?php echo htmlspecialchars((string)($quotation['engineer_name'] ?? 'Assigned engineer')); ?></p>
                                </a>
                            <?php endforeach; ?>
                            <?php foreach ($quotations as $quotation): ?>
                                <a class="quotation-list-card<?php echo $selectedQuotationSource === 'generic' && $selectedQuotation && (int)$selectedQuotation['id'] === (int)$quotation['id'] ? ' is-active' : ''; ?>" href="/codesamplecaps/CLIENT/dashboards/quotations.php?id=<?php echo (int)$quotation['id']; ?>">
                                    <div class="quotation-list-card__top">
                                        <strong><?php echo htmlspecialchars((string)$quotation['quotation_no']); ?></strong>
                                        <span class="status-pill <?php echo htmlspecialchars(quotation_module_status_class((string)$quotation['status'])); ?>"><?php echo htmlspecialchars(quotation_module_status_label((string)$quotation['status'])); ?></span>
                                    </div>
                                    <span><?php echo htmlspecialchars((string)$quotation['project_name']); ?></span>
                                    <p><?php echo htmlspecialchars((string)($quotation['engineer_name'] ?? 'Assigned engineer')); ?></p>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">No quotations have been sent to you yet.</div>
                        <?php endif; ?>
                    </div>
                </aside>

                <section class="quotation-panel">
                    <?php if ($selectedQuotation): ?>
                        <h2><?php echo htmlspecialchars((string)$selectedQuotation['quotation_no']); ?> | <?php echo htmlspecialchars((string)($selectedQuotationSource === 'inquiry' ? $selectedQuotation['service_category'] : $selectedQuotation['project_name'])); ?></h2>
                        <p>This is the client-facing commercial breakdown. Review the scope, compare the price to your budget, then either approve or send it back with a clear revision note.</p>

                        <div class="quotation-meta-grid">
                            <div class="quotation-meta-card">
                                <span>Prepared by</span>
                                <strong><?php echo htmlspecialchars((string)$selectedQuotation['engineer_name']); ?></strong>
                            </div>
                            <div class="quotation-meta-card">
                                <span>Selling price</span>
                                <strong><?php echo htmlspecialchars($selectedQuotationSource === 'inquiry' ? inquiry_quote_format_money((float)($selectedQuotation['grand_total'] ?? 0)) : quotation_module_format_currency($selectedQuotation['selling_price'] ?? 0)); ?></strong>
                            </div>
                            <div class="quotation-meta-card">
                                <span>Status</span>
                                <strong><?php echo htmlspecialchars($selectedQuotationSource === 'inquiry' ? inquiry_quote_status_label($responseStatus) : quotation_module_status_label($responseStatus)); ?></strong>
                            </div>
                        </div>

                        <?php if (!empty($selectedQuotation['client_decision_note'])): ?>
                            <div class="empty-state">
                                Client note: <?php echo nl2br(htmlspecialchars((string)$selectedQuotation['client_decision_note'])); ?>
                            </div>
                        <?php endif; ?>

                        <table class="items-table">
                            <thead>
                                <?php if ($selectedQuotationSource === 'inquiry'): ?>
                                    <tr><th>Type</th><th>Item</th><th>Unit</th><th>Qty</th><th>Unit Cost</th><th>Total</th></tr>
                                <?php else: ?>
                                    <tr><th>Type</th><th>Item</th><th>Unit</th><th>Qty</th><th>Hours</th><th>Rate</th><th>Total</th></tr>
                                <?php endif; ?>
                            </thead>
                            <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(ucfirst((string)$item['item_type'])); ?></td>
                                    <td><?php echo htmlspecialchars((string)$item['item_name']); ?></td>
                                    <td><?php echo htmlspecialchars((string)$item['unit']); ?></td>
                                    <td><?php echo htmlspecialchars((string)$item['quantity']); ?></td>
                                    <?php if ($selectedQuotationSource === 'inquiry'): ?>
                                        <td><?php echo htmlspecialchars(inquiry_quote_format_money((float)($item['unit_cost'] ?? 0))); ?></td>
                                        <td><?php echo htmlspecialchars(inquiry_quote_format_money((float)($item['line_total'] ?? 0))); ?></td>
                                    <?php else: ?>
                                        <td><?php echo htmlspecialchars((string)$item['hours']); ?></td>
                                        <td><?php echo htmlspecialchars(quotation_module_format_currency($item['rate'] ?? 0)); ?></td>
                                        <td><?php echo htmlspecialchars(quotation_module_format_currency($item['line_total'] ?? 0)); ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>

                        <div class="quotation-guidance-grid">
                            <article class="quotation-guidance-card quotation-guidance-card--ok">
                                <h3>Accept</h3>
                                <p>Use this when scope, price, and delivery expectations are already aligned.</p>
                            </article>
                            <article class="quotation-guidance-card quotation-guidance-card--warn">
                                <h3>Too high?</h3>
                                <p>Request revision with a note that includes your target budget, preferred alternatives, or items you want reduced.</p>
                            </article>
                            <article class="quotation-guidance-card quotation-guidance-card--danger">
                                <h3>Wrong scope?</h3>
                                <p>Reject with a note if you do not want to proceed with this quotation.</p>
                            </article>
                        </div>

                        <?php if ($isAwaitingClientDecision): ?>
                            <form method="POST" action="/codesamplecaps/controllers/QuotationClientController.php" class="quotation-response-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="quotation_id" value="<?php echo (int)$selectedQuotation['id']; ?>">
                                <input type="hidden" name="source" value="<?php echo htmlspecialchars($selectedQuotationSource); ?>">
                                <div class="form-group">
                                    <label for="note">Response Note</label>
                                    <textarea id="note" name="note" placeholder="Required for revision or rejection. Example: Reduce labor scope, remove item X, or adjust the total closer to our budget."></textarea>
                                </div>
                                <div class="decision-hint">
                                    Best workflow if the price feels high: request a revision with a clear note. Reject only if you do not want to continue.
                                </div>
                                <div class="quotation-response-row">
                                    <button class="btn-primary" type="submit" name="action" value="client_accept">Accept</button>
                                    <?php if ($selectedQuotationSource === 'inquiry'): ?>
                                        <button class="btn-danger" type="submit" name="action" value="client_revision">Request Revision</button>
                                    <?php endif; ?>
                                    <button class="btn-danger" type="submit" name="action" value="client_reject">Reject</button>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="empty-state empty-state--spaced">This quotation is currently <strong><?php echo htmlspecialchars($selectedQuotationSource === 'inquiry' ? inquiry_quote_status_label($responseStatus) : quotation_module_status_label($responseStatus)); ?></strong>. Client response becomes available only after it is sent.</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="empty-state">Select a quotation to view the full breakdown.</div>
                    <?php endif; ?>
                </section>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php require_once __DIR__ . '/../layout/footer.php'; ?>
