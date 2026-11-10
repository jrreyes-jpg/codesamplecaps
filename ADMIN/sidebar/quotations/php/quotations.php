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
    return $revision > 0 ? 'V' . $revision : '';
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
        'page' => $_GET['page'] ?? 1,
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

$allowedStatuses = ['all', 'draft', 'sent', 'revision_requested', 'accepted', 'rejected'];
$activeStatus = strtolower(trim((string)($_GET['status'] ?? 'all')));
if ($activeStatus === 'approved') {
    $activeStatus = 'draft';
}
if (!in_array($activeStatus, $allowedStatuses, true)) {
    $activeStatus = 'all';
}
$search = trim((string)($_GET['q'] ?? ''));
$requestedId = (int)($_GET['id'] ?? 0);
if ($requestedId > 0) {
    header('Location: /codesamplecaps/ADMIN/sidebar/quotations/php/quotation_details.php?id=' . $requestedId);
    exit;
}
$requestedPage = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$allQuotations = [];

if ($tablesReady) {
    foreach (inquiry_quote_fetch_by_inquiry($conn) as $quotation) {
        $full = inquiry_quote_fetch_full($conn, (int)$quotation['id']);
        if ($full) {
            $allQuotations[] = $full;
        }
    }
}

usort($allQuotations, static function (array $left, array $right): int {
    return strtotime((string)($right['updated_at'] ?? '')) <=> strtotime((string)($left['updated_at'] ?? ''));
});

$counts = array_fill_keys($allowedStatuses, 0);
$counts['all'] = count($allQuotations);
foreach ($allQuotations as $quotation) {
    $status = inquiry_quote_normalize_status((string)($quotation['status'] ?? 'draft'));
    $status = $status === 'approved' ? 'draft' : $status;
    if (isset($counts[$status])) {
        $counts[$status]++;
    }
}

$quotations = array_values(array_filter($allQuotations, static function (array $quotation) use ($activeStatus, $search): bool {
    $status = inquiry_quote_normalize_status((string)($quotation['status'] ?? 'draft'));
    $status = $status === 'approved' ? 'draft' : $status;
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

$totalResults = count($quotations);
$totalPages = max(1, (int)ceil($totalResults / $perPage));
if ($requestedId > 0) {
    foreach ($quotations as $quotationIndex => $quotation) {
        if ((int)$quotation['id'] === $requestedId) {
            $requestedPage = (int)floor($quotationIndex / $perPage) + 1;
            break;
        }
    }
}
$currentPage = min($requestedPage, $totalPages);
$quotations = array_slice($quotations, ($currentPage - 1) * $perPage, $perPage);

$statusLabels = [
    'all' => 'All',
    'draft' => 'Draft',
    'sent' => 'Awaiting Client',
    'revision_requested' => 'Revision Requested',
    'accepted' => 'Accepted',
    'rejected' => 'Rejected',
];

$adminPageTitle = 'Quotations - Edge Automation';
$adminCssFiles = [
    '/codesamplecaps/ADMIN/common/css/admin-common.css',
    '/codesamplecaps/ADMIN/sidebar/quotations/css/quotations.css?v=20261110-1',
];
$adminJsFiles = [
    '/codesamplecaps/ADMIN/common/js/admin-common.js',
    '/codesamplecaps/ADMIN/common/js/search-clear.js?v=20261103-1',
];

include __DIR__ . '/../../../layout/header.php';
include __DIR__ . '/../../../admin_sidebar.php';
?>
<main class="main-content admin-dashboard-content">
    <div class="admin-quotation-page admin-quotation-list-page">
        <header class="admin-quotation-heading">
            <div>
                <h1>Quotations</h1>
            </div>
            <form class="admin-quotation-search" method="get">
                <input type="hidden" name="status" value="<?php echo admin_quote_e($activeStatus); ?>">
                <div>
                    <div class="admin-quotation-search__field" data-search-field>
                        <input id="quotationSearch" name="q" type="search" value="<?php echo admin_quote_e($search); ?>" placeholder="Search client or quotation number" aria-label="Search client or quotation number">
                        <button type="button" class="admin-quotation-search__x" data-search-clear aria-label="Clear quotation search"<?php echo $search === '' ? ' hidden' : ''; ?>>&times;</button>
                    </div>
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
                    <a class="<?php echo $activeStatus === $value ? 'is-selected' : ''; ?>" href="<?php echo admin_quote_e(admin_quote_page_url(['status' => $value, 'page' => 1, 'id' => null])); ?>">
                        <?php echo admin_quote_e($label); ?> <span><?php echo (int)$counts[$value]; ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

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
                        $quotationNumberLabel = $isNumberMissing
                            ? 'Quotation number not assigned'
                            : $quotationNumber;
                        $detailsUrl = '/codesamplecaps/ADMIN/sidebar/quotations/php/quotation_details.php?id=' . (int)$quotation['id'];
                    ?>
                        <article class="admin-quotation-card">
                            <div class="admin-quotation-card__main">
                                <strong class="admin-quotation-card__number<?php echo $isNumberMissing ? ' has-warning' : ''; ?>"><?php echo admin_quote_e($quotationNumberLabel); ?></strong>
                                <h2><?php echo admin_quote_e($quotation['client_name'] ?? 'Client'); ?></h2>
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
        <?php endif; ?>
    </div>
</main>
<?php include __DIR__ . '/../../../layout/footer.php'; ?>
