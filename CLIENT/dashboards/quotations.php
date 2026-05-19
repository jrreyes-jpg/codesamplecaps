<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/quotation_module.php';
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
$quotationId = (int)($_GET['id'] ?? 0);
$selectedQuotation = null;

if ($quotationId > 0 && $tablesReady) {
    $selectedQuotation = quotation_module_fetch_quotation($conn, $quotationId);
    if (!$selectedQuotation || !quotation_module_user_can_access($selectedQuotation, 'client', $userId)) {
        quotation_module_set_flash('error', 'Quotation not found in your account.');
        quotation_module_redirect('/codesamplecaps/CLIENT/dashboards/quotations.php');
    }
} elseif (!empty($quotations)) {
    $selectedQuotation = quotation_module_fetch_quotation($conn, (int)$quotations[0]['id']);
}

$items = $selectedQuotation ? quotation_module_fetch_quotation_items($conn, (int)$selectedQuotation['id']) : [];
$responseStatus = (string)($selectedQuotation['status'] ?? '');
$isAwaitingClientDecision = $responseStatus === 'sent';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Quotations - Edge Automation</title>
    <link rel="stylesheet" href="../css/client_sidebar.css">
    <link rel="stylesheet" href="../css/client_dashboard.css">
    <style>
        .quotation-shell,
        .quotation-grid,
        .quotation-stat-grid,
        .quotation-guidance-grid,
        .quotation-meta-grid,
        .quotation-response-row {
            display: grid;
            gap: 20px;
        }

        .quotation-shell {
            padding: 0;
        }

        .quotation-hero,
        .quotation-panel,
        .quotation-list-card,
        .quotation-guidance-card {
            background: #fff;
            border: 1px solid #d9e6df;
            box-shadow: var(--shadow-soft);
        }

        .quotation-hero {
            display: grid;
            grid-template-columns: minmax(0, 1.45fr) minmax(280px, 0.95fr);
            gap: 20px;
            padding: 28px;
            border-radius: 28px;
        }

        .quotation-kicker {
            margin: 0 0 10px;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: var(--brand-deep);
        }

        .quotation-hero h1,
        .quotation-panel h2,
        .quotation-panel h3,
        .quotation-list-card h3,
        .quotation-guidance-card h3 {
            margin: 0;
            color: var(--text-primary);
        }

        .quotation-hero p,
        .quotation-panel p,
        .quotation-list-card p,
        .quotation-guidance-card p,
        .quotation-panel label {
            color: var(--text-muted);
        }

        .quotation-hero h1 {
            font-size: clamp(2rem, 4vw, 2.8rem);
            line-height: 1.05;
        }

        .quotation-stat-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .quotation-stat {
            padding: 18px;
            border-radius: 20px;
            background: var(--surface-soft);
        }

        .quotation-stat span {
            display: block;
            font-size: 0.82rem;
            color: var(--text-muted);
        }

        .quotation-stat strong {
            display: block;
            margin-top: 6px;
            font-size: 1.7rem;
            color: var(--text-primary);
        }

        .quotation-grid {
            grid-template-columns: 340px minmax(0, 1fr);
            align-items: start;
        }

        .quotation-panel {
            padding: 24px;
            border-radius: 24px;
        }

        .quotation-list-panel {
            position: sticky;
            top: 124px;
        }

        .quotation-list-stack {
            display: grid;
            gap: 14px;
            margin-top: 18px;
        }

        .quotation-list-card {
            display: grid;
            gap: 8px;
            padding: 16px;
            border-radius: 18px;
            text-decoration: none;
            color: inherit;
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
        }

        .quotation-list-card:hover,
        .quotation-list-card:focus-visible {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lift);
            outline: none;
        }

        .quotation-list-card.is-active {
            border-color: rgba(15, 157, 112, 0.38);
            background: linear-gradient(135deg, #ffffff, #f2fbf7);
        }

        .quotation-list-card__top,
        .quotation-response-row {
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 700;
            width: fit-content;
        }

        .status-pill.is-sent { background: #ede9fe; color: #6d28d9; }
        .status-pill.is-accepted { background: #ccfbf1; color: #115e59; }
        .status-pill.is-rejected { background: #fee2e2; color: #b91c1c; }
        .status-pill.is-approved { background: #dcfce7; color: #166534; }

        .quotation-meta-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
            margin: 18px 0 22px;
        }

        .quotation-meta-card {
            padding: 16px;
            border-radius: 18px;
            background: var(--surface-soft);
        }

        .quotation-meta-card span {
            display: block;
            font-size: 0.78rem;
            color: var(--text-muted);
        }

        .quotation-meta-card strong {
            display: block;
            margin-top: 5px;
            color: var(--text-primary);
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            overflow: hidden;
            border-radius: 18px;
            background: #fff;
        }

        .items-table th,
        .items-table td {
            border-bottom: 1px solid #e2e8f0;
            padding: 12px 10px;
            text-align: left;
            vertical-align: top;
        }

        .items-table th {
            font-size: 0.78rem;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            background: #f8fafc;
        }

        .quotation-guidance-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
            margin-top: 22px;
        }

        .quotation-guidance-card {
            padding: 18px;
            border-radius: 20px;
        }

        .quotation-guidance-card--warn {
            background: linear-gradient(135deg, #fff8e1, #ffffff);
        }

        .quotation-guidance-card--danger {
            background: linear-gradient(135deg, #fff1f2, #ffffff);
        }

        .quotation-guidance-card--ok {
            background: linear-gradient(135deg, #ecfdf5, #ffffff);
        }

        .form-group {
            display: grid;
            gap: 8px;
            margin-top: 20px;
        }

        .form-group textarea {
            width: 100%;
            min-height: 132px;
            border: 1px solid #cbd5e1;
            border-radius: 16px;
            padding: 14px 16px;
            font: inherit;
            resize: vertical;
            background: #fff;
        }

        .decision-hint {
            margin-top: 8px;
            padding: 14px 16px;
            border-radius: 16px;
            background: #f8fafc;
            color: #475569;
            font-size: 0.92rem;
        }

        .btn-primary,
        .btn-danger {
            border: 0;
            border-radius: 14px;
            padding: 13px 18px;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, #0f9d70, #0c6b4f);
            color: #fff;
            box-shadow: 0 16px 28px rgba(12, 107, 79, 0.22);
        }

        .btn-danger {
            background: linear-gradient(135deg, #fff1f2, #ffe4e6);
            color: #b42318;
        }

        .btn-primary:hover,
        .btn-danger:hover,
        .btn-primary:focus-visible,
        .btn-danger:focus-visible {
            transform: translateY(-1px);
            outline: none;
        }

        .flash {
            padding: 14px 16px;
            border-radius: 16px;
            font-weight: 600;
        }

        .flash.success { background: #dcfce7; color: #166534; }
        .flash.error { background: #fee2e2; color: #b91c1c; }

        .empty-state {
            border: 1px dashed #cbd5e1;
            border-radius: 18px;
            padding: 24px;
            color: #64748b;
            text-align: center;
            background: #fff;
        }

        @media (max-width: 1100px) {
            .quotation-hero,
            .quotation-grid,
            .quotation-guidance-grid,
            .quotation-meta-grid {
                grid-template-columns: 1fr;
            }

            .quotation-list-panel {
                position: static;
            }
        }

        @media (max-width: 768px) {
            .quotation-stat-grid,
            .quotation-response-row {
                grid-template-columns: 1fr;
            }

            .items-table {
                display: block;
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>
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
                    <strong><?php echo count($quotations); ?></strong>
                </div>
                <div class="quotation-stat">
                    <span>Waiting for your review</span>
                    <strong><?php echo count(array_filter($quotations, static fn($quotation) => (string)($quotation['status'] ?? '') === 'sent')); ?></strong>
                </div>
                <div class="quotation-stat">
                    <span>Current quotation</span>
                    <strong><?php echo $selectedQuotation ? htmlspecialchars((string)$selectedQuotation['quotation_no']) : 'None'; ?></strong>
                </div>
                <div class="quotation-stat">
                    <span>Status</span>
                    <strong><?php echo $selectedQuotation ? htmlspecialchars(quotation_module_status_label($responseStatus)) : 'N/A'; ?></strong>
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
                        <?php if (!empty($quotations)): ?>
                            <?php foreach ($quotations as $quotation): ?>
                                <a class="quotation-list-card<?php echo $selectedQuotation && (int)$selectedQuotation['id'] === (int)$quotation['id'] ? ' is-active' : ''; ?>" href="/codesamplecaps/CLIENT/dashboards/quotations.php?id=<?php echo (int)$quotation['id']; ?>">
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
                        <h2><?php echo htmlspecialchars((string)$selectedQuotation['quotation_no']); ?> | <?php echo htmlspecialchars((string)$selectedQuotation['project_name']); ?></h2>
                        <p>This is the client-facing commercial breakdown. Review the scope, compare the price to your budget, then either approve or send it back with a clear revision note.</p>

                        <div class="quotation-meta-grid">
                            <div class="quotation-meta-card">
                                <span>Prepared by</span>
                                <strong><?php echo htmlspecialchars((string)$selectedQuotation['engineer_name']); ?></strong>
                            </div>
                            <div class="quotation-meta-card">
                                <span>Selling price</span>
                                <strong><?php echo htmlspecialchars(quotation_module_format_currency($selectedQuotation['selling_price'] ?? 0)); ?></strong>
                            </div>
                            <div class="quotation-meta-card">
                                <span>Status</span>
                                <strong><?php echo htmlspecialchars(quotation_module_status_label($responseStatus)); ?></strong>
                            </div>
                        </div>

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

                        <div class="quotation-guidance-grid">
                            <article class="quotation-guidance-card quotation-guidance-card--ok">
                                <h3>Accept</h3>
                                <p>Use this when scope, price, and delivery expectations are already aligned.</p>
                            </article>
                            <article class="quotation-guidance-card quotation-guidance-card--warn">
                                <h3>Too high?</h3>
                                <p>Reject with a note that includes your target budget, preferred alternatives, or items you want reduced.</p>
                            </article>
                            <article class="quotation-guidance-card quotation-guidance-card--danger">
                                <h3>Wrong scope?</h3>
                                <p>Reject with a note listing the exact line items, quantities, or inclusions that should be revised.</p>
                            </article>
                        </div>

                        <?php if ($isAwaitingClientDecision): ?>
                            <form method="POST" action="/codesamplecaps/controllers/QuotationClientController.php" style="margin-top:20px; display:grid; gap:12px;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="quotation_id" value="<?php echo (int)$selectedQuotation['id']; ?>">
                                <div class="form-group">
                                    <label for="note">Response Note</label>
                                    <textarea id="note" name="note" placeholder="If you want revisions, write the reason clearly here. Example: Reduce labor scope, remove item X, or adjust the total closer to our budget."></textarea>
                                </div>
                                <div class="decision-hint">
                                    Best workflow if the price feels high: use `Reject` with a specific note, then let the engineer revise and resend the quotation instead of approving a quote you are not comfortable with.
                                </div>
                                <div class="quotation-response-row">
                                    <button class="btn-primary" type="submit" name="action" value="client_accept">Accept</button>
                                    <button class="btn-danger" type="submit" name="action" value="client_reject">Reject</button>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="empty-state" style="margin-top:20px;">This quotation is currently <strong><?php echo htmlspecialchars(quotation_module_status_label($responseStatus)); ?></strong>. Client response becomes available only after it is sent.</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="empty-state">Select a quotation to view the full breakdown.</div>
                    <?php endif; ?>
                </section>
            </div>
        <?php endif; ?>
    </div>
</main>
<script src="../js/client_dashboard.js"></script>
</body>
</html>
