<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/quotation_module.php';
require_once __DIR__ . '/../includes/foreman_helpers.php';

require_role('foreman');

$userId = (int)($_SESSION['user_id'] ?? 0);
$foremanProfileName = (string)($_SESSION['name'] ?? 'Foreman');
$foremanProfile = foreman_fetch_profile($conn, $userId);
$foremanProfileName = (string)($foremanProfile['full_name'] ?? $foremanProfileName);
$dashboardData = foreman_fetch_dashboard_data($conn, $userId);
$assetSummary = $dashboardData['asset_summary'];
$usageSummary = $dashboardData['usage_summary'];
$scanSummary = $dashboardData['scan_summary'];
$foremanNotifications = [
    'attention_count' => (int)($assetSummary['maintenance_assets'] ?? 0) + (int)($assetSummary['damaged_assets'] ?? 0),
    'logs_today' => (int)($usageSummary['logs_today'] ?? 0),
    'scans_today' => (int)($scanSummary['scans_today'] ?? 0),
];

$flash = quotation_module_consume_flash();
$csrfToken = quotation_module_csrf_token();
$tablesReady = quotation_module_tables_ready($conn);
$quotations = $tablesReady ? quotation_module_fetch_quotations($conn, 'foreman', $userId) : [];
$quotationId = (int)($_GET['id'] ?? 0);
$selectedQuotation = null;

if ($quotationId > 0 && $tablesReady) {
    $selectedQuotation = quotation_module_fetch_quotation($conn, $quotationId);
    if (!$selectedQuotation || !quotation_module_user_can_access($selectedQuotation, 'foreman', $userId)) {
        quotation_module_set_flash('error', 'Quotation not found for your review queue.');
        quotation_module_redirect('/codesamplecaps/FOREMAN/dashboards/quotation_reviews.php');
    }
} elseif (!empty($quotations)) {
    $selectedQuotation = quotation_module_fetch_quotation($conn, (int)$quotations[0]['id']);
}

$items = $selectedQuotation ? quotation_module_fetch_quotation_items($conn, (int)$selectedQuotation['id']) : [];
$isUnderReview = $selectedQuotation && (string)$selectedQuotation['status'] === 'under_review';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Foreman Quotation Reviews - Edge Automation</title>
    <link rel="stylesheet" href="../css/sidebar_foreman.css">
    <link rel="stylesheet" href="../css/foreman_dashboard.css">
    <link rel="stylesheet" href="../css/qr_scanner.css">
</head>
<body>
<?php include __DIR__ . '/../sidebar/sidebar_foreman.php'; ?>
<main class="main-content">
    <div class="page-shell quotation-review-page">
        <?php if ($flash): ?>
            <div class="empty-state empty-state--inline empty-state--<?php echo htmlspecialchars((string)$flash['type']); ?>">
                <?php echo htmlspecialchars((string)$flash['message']); ?>
            </div>
        <?php endif; ?>

        <section class="page-hero page-hero--review">
            <div class="page-hero__content">
                <span class="page-hero__eyebrow">Quotation Review</span>
                <h1 class="page-hero__title">Foreman Review Queue</h1>
                <p class="page-hero__copy">
                    Dito review-only ang responsibility mo. Engineer pa rin ang may-ari ng quotation draft at Super Admin pa rin ang final approver.
                </p>
                <div class="hero-actions">
                    <a class="btn-primary" href="/codesamplecaps/FOREMAN/dashboards/foreman_dashboard.php">Back To Overview</a>
                    <a class="btn-secondary" href="/codesamplecaps/FOREMAN/dashboards/usage_logs.php">Open Usage Logs</a>
                </div>
            </div>

            <aside class="page-hero__aside">
                <div class="aside-stat">
                    <span>Assigned Reviews</span>
                    <strong><?php echo count($quotations); ?></strong>
                </div>
                <div class="aside-stat">
                    <span>Pending Review</span>
                    <strong><?php echo count(array_filter($quotations, static function ($quotation): bool {
                        return (string)($quotation['status'] ?? '') === 'under_review';
                    })); ?></strong>
                </div>
                <div class="aside-stat">
                    <span>Logs Today</span>
                    <strong><?php echo (int)($usageSummary['logs_today'] ?? 0); ?></strong>
                </div>
                <div class="aside-stat">
                    <span>Scans Today</span>
                    <strong><?php echo (int)($scanSummary['scans_today'] ?? 0); ?></strong>
                </div>
            </aside>
        </section>

        <?php if (!$tablesReady): ?>
            <section class="panel-card">
                <div class="empty-state">Run <code>scripts/setup_quotation_tables.php</code> first to enable quotation reviews.</div>
            </section>
        <?php else: ?>
            <section class="review-workspace">
                <aside class="panel-card review-queue-panel">
                    <div class="section-heading section-heading--stack">
                        <div>
                            <span class="section-badge">Queue</span>
                            <h2>My Review Queue</h2>
                            <p>Open a quotation, review execution feasibility, then leave a note or return it to Engineer.</p>
                        </div>
                    </div>

                    <div class="queue-list">
                        <?php if (!empty($quotations)): ?>
                            <?php foreach ($quotations as $quotation): ?>
                                <article class="queue-card <?php echo $selectedQuotation && (int)$selectedQuotation['id'] === (int)$quotation['id'] ? 'active' : ''; ?>">
                                    <a href="/codesamplecaps/FOREMAN/dashboards/quotation_reviews.php?id=<?php echo (int)$quotation['id']; ?>">
                                        <div class="queue-card__topline">
                                            <strong><?php echo htmlspecialchars((string)$quotation['quotation_no']); ?></strong>
                                            <span class="status-pill <?php echo htmlspecialchars(quotation_module_status_class((string)$quotation['status'])); ?>">
                                                <?php echo htmlspecialchars(quotation_module_status_label((string)$quotation['status'])); ?>
                                            </span>
                                        </div>
                                        <span><?php echo htmlspecialchars((string)$quotation['project_name']); ?></span>
                                        <small>Engineer: <?php echo htmlspecialchars((string)$quotation['engineer_name']); ?></small>
                                        <small>Updated: <?php echo htmlspecialchars(quotation_module_format_datetime((string)($quotation['updated_at'] ?? ''))); ?></small>
                                    </a>
                                </article>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">No quotations are assigned to you yet.</div>
                        <?php endif; ?>
                    </div>
                </aside>

                <section class="review-detail-stack">
                    <?php if ($selectedQuotation): ?>
                        <article class="panel-card">
                            <div class="section-heading section-heading--responsive">
                                <div>
                                    <span class="section-badge">Review Scope</span>
                                    <h2><?php echo htmlspecialchars((string)$selectedQuotation['quotation_no']); ?></h2>
                                    <p><?php echo htmlspecialchars((string)$selectedQuotation['project_name']); ?></p>
                                </div>
                                <div class="header-meta">
                                    <span class="status-pill <?php echo htmlspecialchars(quotation_module_status_class((string)$selectedQuotation['status'])); ?>">
                                        <?php echo htmlspecialchars(quotation_module_status_label((string)$selectedQuotation['status'])); ?>
                                    </span>
                                    <span class="header-meta__item">Engineer: <?php echo htmlspecialchars((string)$selectedQuotation['engineer_name']); ?></span>
                                    <span class="header-meta__item">Duration: <?php echo htmlspecialchars((string)($selectedQuotation['estimated_duration_days'] ?? 'N/A')); ?> day(s)</span>
                                    <span class="header-meta__item">Last Updated: <?php echo htmlspecialchars(quotation_module_format_datetime((string)($selectedQuotation['updated_at'] ?? ''))); ?></span>
                                </div>
                            </div>


                            <div class="summary-grid">
                                <article class="summary-card">
                                    <strong>Scope Summary</strong>
                                    <p><?php echo nl2br(htmlspecialchars((string)($selectedQuotation['scope_summary'] ?? 'No scope summary provided.'))); ?></p>
                                </article>
                                <article class="summary-card">
                                    <strong>Manpower Hours</strong>
                                    <p><?php echo htmlspecialchars((string)($selectedQuotation['manpower_hours'] ?? 0)); ?> total estimated hours</p>
                                </article>
                                <article class="summary-card">
                                    <strong>Total Cost Snapshot</strong>
                                    <p><?php echo htmlspecialchars(quotation_module_format_currency($selectedQuotation['total_cost'] ?? 0)); ?></p>
                                </article>
                            </div>
                        </article>

                        <?php if ($isUnderReview): ?>
                            <form method="POST" action="/codesamplecaps/controllers/QuotationReviewController.php" class="panel-card review-form-panel review-form-panel--priority">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="quotation_id" value="<?php echo (int)$selectedQuotation['id']; ?>">

                                <div class="section-heading section-heading--responsive">
                                    <div>
                                        <span class="section-badge">Action Required</span>
                                        <h2>Foreman Feedback Panel</h2>
                                        <p>Dito ka mag-iinput ng feedback. Isulat ang note sa textbox sa ibaba, tapos piliin kung suggestion lang ba ito o ibabalik sa Engineer.</p>
                                    </div>
                                </div>

                                <div class="review-action-guide">
                                    <article class="review-action-card">
                                        <strong>Save Suggestion</strong>
                                        <p>Mag-type ng feedback sa textbox, then pindutin ito kung recommendation lang at hindi kailangan ibalik ang quotation.</p>
                                    </article>
                                    <article class="review-action-card review-action-card--danger">
                                        <strong>Return To Engineer</strong>
                                        <p>Mag-type ng reason sa textbox, then pindutin ito kung may issue na kailangan talagang i-revise ni Engineer.</p>
                                    </article>
                                </div>

                                <label class="review-form-label" for="reviewMessage">Input Your Feedback Here</label>
                                <textarea id="reviewMessage" name="message" class="review-textarea" placeholder="Halimbawa: Kulang ang manpower para matapos sa 7 days. Recommend additional 2 helpers for conduit installation." required></textarea>
                                <p class="review-form-helper">Textbox ito para sa note ni Foreman. Pagkatapos magsulat, piliin ang button sa ibaba.</p>
                                <div class="review-form-actions">
                                    <button class="btn-primary" type="submit" name="action" value="save_suggestion">Save Suggestion</button>
                                    <button class="btn-secondary btn-secondary--danger" type="submit" name="action" value="return_to_engineer">Return To Engineer</button>
                                </div>
                            </form>
                        <?php else: ?>
                            <article class="panel-card review-status-panel">
                                <div class="review-status-banner">
                                    <strong>Feedback actions are currently unavailable</strong>
                                    <p>Makikita lang ang input box at ang buttons kapag ang quotation status ay <code>Under Review</code>. Ang current status nito ay <strong><?php echo htmlspecialchars(quotation_module_status_label((string)$selectedQuotation['status'])); ?></strong>.</p>
                                </div>
                            </article>
                        <?php endif; ?>

                        <article class="panel-card">
                            <div class="section-heading section-heading--responsive">
                                <div>
                                    <span class="section-badge">Breakdown</span>
                                    <h2>Quotation Items</h2>
                                    <p>Large tables stay scrollable on smaller screens so the backend structure remains intact.</p>
                                </div>
                            </div>

                            <div class="table-shell">
                                <table class="data-table review-items-table">
                                    <thead>
                                        <tr>
                                            <th>Type</th>
                                            <th>Item</th>
                                            <th>Unit</th>
                                            <th>Qty</th>
                                            <th>Hours</th>
                                            <th>Rate</th>
                                            <th>Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($items as $item): ?>
                                        <tr>
                                            <td data-label="Type"><?php echo htmlspecialchars(ucfirst((string)$item['item_type'])); ?></td>
                                            <td data-label="Item"><?php echo htmlspecialchars((string)$item['item_name']); ?></td>
                                            <td data-label="Unit"><?php echo htmlspecialchars((string)$item['unit']); ?></td>
                                            <td data-label="Qty"><?php echo htmlspecialchars((string)$item['quantity']); ?></td>
                                            <td data-label="Hours"><?php echo htmlspecialchars((string)$item['hours']); ?></td>
                                            <td data-label="Rate"><?php echo htmlspecialchars(quotation_module_format_currency($item['rate'] ?? 0)); ?></td>
                                            <td data-label="Total"><?php echo htmlspecialchars(quotation_module_format_currency($item['line_total'] ?? 0)); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </article>
                    <?php else: ?>
                        <article class="panel-card">
                            <div class="empty-state">Select a quotation from your queue to review it.</div>
                        </article>
                    <?php endif; ?>
                </section>
            </section>
        <?php endif; ?>
    </div>
</main>
<script src="../js/sidebar_foreman.js"></script>
</body>
</html>
