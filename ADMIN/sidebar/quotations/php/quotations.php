<?php
require_once __DIR__ . '/../../../../config/auth_middleware.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/inquiry_quotation_module.php';

require_role('admin');

function admin_quote_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function admin_quote_date($value): string
{
    $time = strtotime((string)$value);
    return $time ? date('M d, Y g:i A', $time) : 'Not recorded';
}

function admin_quote_version(array $quotation): string
{
    $revision = (int)($quotation['revision_no'] ?? 0);
    return $revision > 0 ? 'V' . $revision : 'Initial';
}

function admin_quote_status_class(string $status): string
{
    return match ($status) {
        'accepted' => 'is-success',
        'sent' => 'is-active',
        'revision_requested' => 'is-warning',
        'rejected' => 'is-error',
        'approved' => 'is-ready',
        default => 'is-neutral',
    };
}

function admin_quote_page_url(array $changes = []): string
{
    $query = array_merge([
        'status' => $_GET['status'] ?? 'all',
        'q' => $_GET['q'] ?? '',
    ], $changes);
    return '/codesamplecaps/ADMIN/sidebar/quotations/php/quotations.php?' . http_build_query(array_filter(
        $query,
        static fn($value) => $value !== '' && $value !== null
    ));
}

$requiredTables = ['inquiry_quotation_drafts', 'inquiry_quotation_items'];
$tablesReady = true;
foreach ($requiredTables as $table) {
    if (!inquiry_quote_table_exists($conn, $table)) {
        $tablesReady = false;
        break;
    }
}

$allowedStatuses = ['all', 'draft', 'approved', 'sent', 'revision_requested', 'accepted', 'rejected'];
$activeStatus = strtolower(trim((string)($_GET['status'] ?? 'all')));
if (!in_array($activeStatus, $allowedStatuses, true)) {
    $activeStatus = 'all';
}
$search = trim((string)($_GET['q'] ?? ''));
$requestedId = (int)($_GET['id'] ?? 0);
$allQuotations = [];

if ($tablesReady) {
    foreach (inquiry_quote_fetch_by_inquiry($conn) as $quotation) {
        $full = inquiry_quote_fetch_full($conn, (int)$quotation['id']);
        if ($full) {
            $allQuotations[] = $full;
        }
    }
}

$counts = array_fill_keys($allowedStatuses, 0);
$counts['all'] = count($allQuotations);
foreach ($allQuotations as $quotation) {
    $status = inquiry_quote_normalize_status((string)($quotation['status'] ?? 'draft'));
    if (isset($counts[$status])) {
        $counts[$status]++;
    }
}

$quotations = array_values(array_filter($allQuotations, static function (array $quotation) use ($activeStatus, $search): bool {
    $status = inquiry_quote_normalize_status((string)($quotation['status'] ?? 'draft'));
    if ($activeStatus !== 'all' && $status !== $activeStatus) {
        return false;
    }
    if ($search === '') {
        return true;
    }
    $haystack = implode(' ', [
        $quotation['quotation_no'] ?? '',
        $quotation['client_name'] ?? '',
        $quotation['company_name'] ?? '',
        $quotation['service_category'] ?? '',
    ]);
    return stripos($haystack, $search) !== false;
}));

$selectedQuotation = null;
foreach ($quotations as $quotation) {
    if (($requestedId > 0 && (int)$quotation['id'] === $requestedId) || ($requestedId === 0 && $selectedQuotation === null)) {
        $selectedQuotation = $quotation;
        if ($requestedId > 0) {
            break;
        }
    }
}

$items = $selectedQuotation ? inquiry_quote_fetch_items($conn, (int)$selectedQuotation['id']) : [];
$history = $selectedQuotation ? inquiry_quote_fetch_history($conn, (int)$selectedQuotation['id']) : [];

$statusLabels = [
    'all' => 'All',
    'draft' => 'Draft',
    'approved' => 'Ready to Send',
    'sent' => 'Awaiting Client',
    'revision_requested' => 'Revision Requested',
    'accepted' => 'Accepted',
    'rejected' => 'Rejected',
];

$adminPageTitle = 'Quotations - Edge Automation';
$adminCssFiles = [
    '/codesamplecaps/ADMIN/common/css/admin-common.css',
    '/codesamplecaps/ADMIN/sidebar/quotations/css/quotations.css',
];
$adminJsFiles = ['/codesamplecaps/ADMIN/common/js/admin-common.js'];

include __DIR__ . '/../../../layout/header.php';
include __DIR__ . '/../../../admin_sidebar.php';
?>
<main class="main-content">
    <div class="admin-quotation-page">
        <header class="admin-quotation-heading">
            <div>
                <h1>Quotations</h1>
                <p>Track Admin drafts and Client responses in one place.</p>
            </div>
            <form class="admin-quotation-search" method="get">
                <input type="hidden" name="status" value="<?php echo admin_quote_e($activeStatus); ?>">
                <label for="quotationSearch">Search quotations</label>
                <div>
                    <input id="quotationSearch" name="q" type="search" value="<?php echo admin_quote_e($search); ?>" placeholder="Client or quotation number">
                    <button type="submit">Search</button>
                </div>
            </form>
        </header>

        <?php if (!$tablesReady): ?>
            <section class="admin-quotation-empty">
                <h2>Quotation data is not ready</h2>
                <p>Please check the existing inquiry quotation migration.</p>
            </section>
        <?php else: ?>
            <nav class="admin-quotation-filters" aria-label="Quotation status filters">
                <?php foreach ($statusLabels as $value => $label): ?>
                    <a class="<?php echo $activeStatus === $value ? 'is-selected' : ''; ?>" href="<?php echo admin_quote_e(admin_quote_page_url(['status' => $value, 'id' => null])); ?>">
                        <?php echo admin_quote_e($label); ?> <span><?php echo (int)$counts[$value]; ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="admin-quotation-layout">
                <aside class="admin-quotation-list" aria-label="Quotation list">
                    <?php if (!$quotations): ?>
                        <div class="admin-quotation-empty compact"><p>No quotation found.</p></div>
                    <?php endif; ?>
                    <?php foreach ($quotations as $quotation):
                        $status = inquiry_quote_normalize_status((string)($quotation['status'] ?? 'draft'));
                        $isActive = $selectedQuotation && (int)$selectedQuotation['id'] === (int)$quotation['id'];
                    ?>
                        <a class="admin-quotation-card <?php echo $isActive ? 'is-selected' : ''; ?>" href="<?php echo admin_quote_e(admin_quote_page_url(['id' => (int)$quotation['id']])); ?>">
                            <div class="admin-quotation-card-top">
                                <strong><?php echo admin_quote_e($quotation['quotation_no'] ?? 'Quotation'); ?></strong>
                                <span class="quote-status <?php echo admin_quote_status_class($status); ?>"><?php echo admin_quote_e($statusLabels[$status] ?? inquiry_quote_status_label($status)); ?></span>
                            </div>
                            <h2><?php echo admin_quote_e($quotation['client_name'] ?? 'Client'); ?></h2>
                            <p><?php echo admin_quote_e($quotation['service_category'] ?? 'Service not provided'); ?></p>
                            <div class="admin-quotation-card-meta">
                                <span><?php echo admin_quote_e(admin_quote_version($quotation)); ?></span>
                                <strong><?php echo admin_quote_e(inquiry_quote_format_money((float)($quotation['grand_total'] ?? 0))); ?></strong>
                            </div>
                            <small>Updated <?php echo admin_quote_e(admin_quote_date($quotation['updated_at'] ?? '')); ?></small>
                        </a>
                    <?php endforeach; ?>
                </aside>

                <section class="admin-quotation-detail">
                    <?php if (!$selectedQuotation): ?>
                        <div class="admin-quotation-empty"><h2>Select a quotation</h2><p>Choose one from the list to view its details.</p></div>
                    <?php else:
                        $selectedStatus = inquiry_quote_normalize_status((string)($selectedQuotation['status'] ?? 'draft'));
                    ?>
                        <div class="admin-quotation-detail-head">
                            <div>
                                <p class="admin-quotation-kicker"><?php echo admin_quote_e($selectedQuotation['quotation_no'] ?? 'Quotation'); ?> · <?php echo admin_quote_e(admin_quote_version($selectedQuotation)); ?></p>
                                <h2><?php echo admin_quote_e($selectedQuotation['client_name'] ?? 'Client'); ?></h2>
                                <p><?php echo admin_quote_e($selectedQuotation['service_category'] ?? 'Service not provided'); ?></p>
                            </div>
                            <span class="quote-status <?php echo admin_quote_status_class($selectedStatus); ?>"><?php echo admin_quote_e($statusLabels[$selectedStatus] ?? inquiry_quote_status_label($selectedStatus)); ?></span>
                        </div>

                        <div class="admin-quotation-summary">
                            <article><span>Client Email</span><strong><?php echo admin_quote_e($selectedQuotation['email'] ?? 'Not provided'); ?></strong></article>
                            <article><span>Total</span><strong><?php echo admin_quote_e(inquiry_quote_format_money((float)($selectedQuotation['grand_total'] ?? 0))); ?></strong></article>
                            <article><span>Prepared By</span><strong><?php echo admin_quote_e($selectedQuotation['preparer_name'] ?? 'Admin'); ?></strong></article>
                        </div>

                        <div class="admin-quotation-actions">
                            <?php if ($selectedStatus === 'draft'): ?>
                                <a class="button-primary" href="/codesamplecaps/ADMIN/sidebar/inquiries/php/create_quotation.php?edit_id=<?php echo (int)$selectedQuotation['id']; ?>">Edit Draft</a>
                            <?php endif; ?>
                            <a class="button-secondary" href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?open=inquiryModal<?php echo (int)$selectedQuotation['inquiry_id']; ?>&tab=inspection">Open Inquiry</a>
                        </div>

                        <div class="admin-quotation-section">
                            <h3>Quotation Items</h3>
                            <div class="admin-quotation-table-wrap">
                                <table>
                                    <thead><tr><th>Item</th><th>Qty</th><th>Unit</th><th>Unit Cost</th><th>Line Total</th></tr></thead>
                                    <tbody>
                                    <?php if (!$items): ?><tr><td colspan="5">No quotation items.</td></tr><?php endif; ?>
                                    <?php foreach ($items as $item): ?>
                                        <tr>
                                            <td data-label="Item"><?php echo admin_quote_e($item['item_name'] ?? 'Item'); ?></td>
                                            <td data-label="Qty"><?php echo admin_quote_e($item['quantity'] ?? 0); ?></td>
                                            <td data-label="Unit"><?php echo admin_quote_e($item['unit'] ?? 'unit'); ?></td>
                                            <td data-label="Unit Cost"><?php echo admin_quote_e(inquiry_quote_format_money((float)($item['unit_cost'] ?? 0))); ?></td>
                                            <td data-label="Line Total"><?php echo admin_quote_e(inquiry_quote_format_money((float)($item['line_total'] ?? 0))); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="admin-quotation-section">
                            <h3>Status History</h3>
                            <div class="admin-quotation-history">
                                <?php if (!$history): ?><p>No status history yet.</p><?php endif; ?>
                                <?php foreach ($history as $entry): ?>
                                    <article>
                                        <span class="history-dot" aria-hidden="true"></span>
                                        <div><strong><?php echo admin_quote_e(inquiry_quote_status_label((string)($entry['status'] ?? 'draft'))); ?></strong><p><?php echo admin_quote_e($entry['full_name'] ?? 'System'); ?> · <?php echo admin_quote_e(admin_quote_date($entry['created_at'] ?? '')); ?></p></div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php include __DIR__ . '/../../../layout/footer.php'; ?>
