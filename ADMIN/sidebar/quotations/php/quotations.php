<?php
require_once __DIR__ . '/../../../../config/auth_middleware.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/quotation_module.php';

require_role('admin');

$flash = quotation_module_consume_flash();
$csrfToken = quotation_module_csrf_token();
$tablesReady = quotation_module_tables_ready($conn);
$quotations = $tablesReady ? quotation_module_fetch_quotations($conn, 'admin', (int)($_SESSION['user_id'] ?? 0)) : [];
$quotationId = (int)($_GET['id'] ?? 0);
$selectedQuotation = null;

if ($quotationId > 0 && $tablesReady) {
    $selectedQuotation = quotation_module_fetch_quotation($conn, $quotationId);
} elseif (!empty($quotations)) {
    $selectedQuotation = quotation_module_fetch_quotation($conn, (int)$quotations[0]['id']);
}

$items = $selectedQuotation ? quotation_module_fetch_quotation_items($conn, (int)$selectedQuotation['id']) : [];
$history = $selectedQuotation ? quotation_module_fetch_history($conn, (int)$selectedQuotation['id']) : [];

$adminPageTitle = 'Quotation Approval Panel - Edge Automation';

$adminCssFiles = [
    '/codesamplecaps/ADMIN/css/super_admin_dashboard.css',
    '/codesamplecaps/ADMIN/sidebar/quotations/css/quotations.css',
];

include __DIR__ . '/../../../layout/header.php';
include __DIR__ . '/../../../admin_sidebar.php';
?>
<main class="main-content">
    <div class="quotation-shell">
        <?php if ($flash): ?><div class="flash <?php echo htmlspecialchars((string)$flash['type']); ?>"><?php echo htmlspecialchars((string)$flash['message']); ?></div><?php endif; ?>
        <section class="quotation-hero">
            <h1>Quotation Approval Panel</h1>
            <p>Review engineer quotations, confirm margins, and release approved pricing to the client.</p>
        </section>

        <?php if (!$tablesReady): ?>
            <section class="quotation-panel"><div class="empty-state">Run <code>scripts/setup_quotation_tables.php</code> first to enable quotation approvals.</div></section>
        <?php else: ?>
            <div class="quotation-grid">
                <aside class="quotation-panel">
                    <h2>Approval Queue</h2>
                    <div class="queue-list">
                        <?php if (!empty($quotations)): ?>
                            <?php foreach ($quotations as $quotation): ?>
                                <article class="queue-card <?php echo $selectedQuotation && (int)$selectedQuotation['id'] === (int)$quotation['id'] ? 'active' : ''; ?>">
                                    <a href="/codesamplecaps/ADMIN/sidebar/quotations/php/quotations.php?id=<?php echo (int)$quotation['id']; ?>">
                                        <strong><?php echo htmlspecialchars((string)$quotation['quotation_no']); ?></strong>
                                        <span><?php echo htmlspecialchars((string)$quotation['project_name']); ?></span>
                                        <span><?php echo htmlspecialchars((string)$quotation['engineer_name']); ?></span>
                                        <span class="status-pill <?php echo htmlspecialchars(quotation_module_status_class((string)$quotation['status'])); ?>"><?php echo htmlspecialchars(quotation_module_status_label((string)$quotation['status'])); ?></span>
                                    </a>
                                </article>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">No quotations in the workflow yet.</div>
                        <?php endif; ?>
                    </div>
                </aside>

                <section class="quotation-panel quotation-detail">
                    <?php if ($selectedQuotation): ?>
                        <h2><?php echo htmlspecialchars((string)$selectedQuotation['quotation_no']); ?> | <?php echo htmlspecialchars((string)$selectedQuotation['project_name']); ?></h2>
                        <p class="quotation-meta">Engineer: <?php echo htmlspecialchars((string)$selectedQuotation['engineer_name']); ?> | Client: <?php echo htmlspecialchars((string)$selectedQuotation['client_name']); ?></p>

                        <div class="metrics">
                            <article class="metric-card"><span>Total Cost</span><strong><?php echo htmlspecialchars(quotation_module_format_currency($selectedQuotation['total_cost'] ?? 0)); ?></strong></article>
                            <article class="metric-card"><span>Current Margin</span><strong><?php echo htmlspecialchars(number_format((float)($selectedQuotation['profit_margin_percent'] ?? 0), 2)); ?>%</strong></article>
                            <article class="metric-card"><span>Selling Price</span><strong><?php echo htmlspecialchars(quotation_module_format_currency($selectedQuotation['selling_price'] ?? 0)); ?></strong></article>
                        </div>

                        <div class="section-block">
                            <h2>Line Items</h2>
                            <div class="items-table-wrap">
                                <table class="items-table">
                                    <thead><tr><th>Type</th><th>Item</th><th>Unit</th><th>Qty</th><th>Hours</th><th>Rate</th><th>Total</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($items as $item): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars(ucfirst((string)$item['item_type'])); ?></td>
                                            <td><?php echo htmlspecialchars((string)$item['item_name']); ?></td>
                                            <td><?php echo htmlspecialchars((string)$item['unit']); ?></td>
                                            <td><?php echo htmlspecialchars((string)$item['quantity']); ?></td>
                                            <td><?php echo htmlspecialchars((string)$item['hours']); ?></td>
                                            <td><?php echo htmlspecialchars(quotation_module_format_currency($item['rate'] ?? 0)); ?></td>
                                            <td><?php echo htmlspecialchars(quotation_module_format_currency($item['line_total'] ?? 0)); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="section-block">
                            <h2>Status History</h2>
                            <div class="history-list">
                                <?php foreach ($history as $entry): ?>
                                    <article class="history-card">
                                        <small><?php echo htmlspecialchars(quotation_module_format_datetime((string)$entry['created_at'])); ?></small>
                                        <strong><?php echo htmlspecialchars((string)$entry['full_name']); ?></strong>
                                        <p><?php echo htmlspecialchars(quotation_module_status_label((string)($entry['from_status'] ?? 'draft')) . ' -> ' . quotation_module_status_label((string)$entry['to_status'])); ?></p>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <?php if ((string)$selectedQuotation['status'] === 'for_approval'): ?>
                            <form method="POST" action="/codesamplecaps/controllers/QuotationAdminController.php" class="history-list">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="quotation_id" value="<?php echo (int)$selectedQuotation['id']; ?>">
                                <div class="form-group">
                                    <label for="profit_margin_percent">Profit Margin (%)</label>
                                    <input id="profit_margin_percent" type="number" step="0.01" min="0" name="profit_margin_percent" value="<?php echo htmlspecialchars((string)($selectedQuotation['profit_margin_percent'] ?? 0)); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="remarks">Approval Note</label>
                                    <textarea id="remarks" name="remarks" rows="4" placeholder="Optional approval note for audit trail"></textarea>
                                </div>
                                <button class="btn-primary" type="submit" name="action" value="approve">Approve & Lock</button>
                                <button class="btn-secondary" type="submit" name="action" value="return_to_engineer">Return To Engineer</button>
                            </form>
                        <?php elseif ((string)$selectedQuotation['status'] === 'approved'): ?>
                            <form method="POST" action="/codesamplecaps/controllers/QuotationAdminController.php" class="history-list">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="quotation_id" value="<?php echo (int)$selectedQuotation['id']; ?>">
                                <input type="hidden" name="profit_margin_percent" value="<?php echo htmlspecialchars((string)($selectedQuotation['profit_margin_percent'] ?? 0)); ?>">
                                <div class="form-group">
                                    <label for="send_remarks">Send Note</label>
                                    <textarea id="send_remarks" name="remarks" rows="4" placeholder="Optional note before sending to client"></textarea>
                                </div>
                                <button class="btn-primary" type="submit" name="action" value="send_to_client">Finalize & Send</button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="empty-state">Select a quotation from the approval queue to continue.</div>
                    <?php endif; ?>
                </section>
            </div>
        <?php endif; ?>
    </div>
</main>
</div>
<script src="/codesamplecaps/SHARED/js/operations-sidebar.js"></script>
<script src="/codesamplecaps/ADMIN/js/super_admin_dashboard.js"></script>
</body>
</html>
