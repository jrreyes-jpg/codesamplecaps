<?php
require_once __DIR__ . '/../../../../config/auth_middleware.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/inquiry_quotation_module.php';
require_once __DIR__ . '/../../../../SHARED/quotation-project-confirm/php/modal.php';

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
$quotationActionCsrfToken = auth_csrf_token('admin_inquiries');
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
        'INQ-' . str_pad((string)($quotation['inquiry_id'] ?? 0), 6, '0', STR_PAD_LEFT),
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
$statusTones = [
    'all' => 'green',
    'draft' => 'gray',
    'sent' => 'blue',
    'revision_requested' => 'orange',
    'accepted' => 'green',
    'rejected' => 'red',
];

if (($_GET['ajax'] ?? '') === '1') {
    ob_start();
    include __DIR__ . '/quotation_list_results.php';
    $resultsHtml = ob_get_clean();

    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok' => true,
        'html' => $resultsHtml,
        'status' => $activeStatus,
        'status_label' => $activeStatus === 'all' ? 'Status' : 'Status: ' . ($statusLabels[$activeStatus] ?? 'Status'),
        'counts' => $counts,
    ]);
    exit;
}

$adminPageTitle = 'Quotations - Edge Automation';
$adminBodyClass = 'admin-light-page';
$adminCssFiles = [
    '/codesamplecaps/ADMIN/common/css/admin-common.css?v=20261112-3',
    '/codesamplecaps/SHARED/action-loading/css/action-loading.css?v=20261103-3',
    '/codesamplecaps/SHARED/quotation-project-confirm/css/modal.css?v=20261123-1',
    '/codesamplecaps/ADMIN/sidebar/quotations/css/quotations.css?v=20261125-7',
];
$adminJsFiles = [
    '/codesamplecaps/ADMIN/common/js/admin-common.js',
    '/codesamplecaps/ADMIN/common/js/search-clear.js?v=20261103-1',
    '/codesamplecaps/SHARED/action-loading/js/action-loading.js?v=20261103-2',
    '/codesamplecaps/SHARED/quotation-project-confirm/js/modal.js?v=20261123-1',
    '/codesamplecaps/ADMIN/sidebar/quotations/js/quotations.js?v=20261125-1',
];

include __DIR__ . '/../../../layout/header.php';
include __DIR__ . '/../../../admin_sidebar.php';
?>
<main class="main-content admin-dashboard-content admin-quotation-list-main shared-page-surface">
    <div class="admin-quotation-page admin-quotation-list-page">
        <header class="admin-quotation-heading">
            <div>
                <h1>Quotations</h1>
            </div>
            <form class="admin-quotation-search" method="get" data-quotation-live-filter data-shared-list-controls>
                <div>
                    <div class="admin-quotation-search__field" data-search-field>
                        <input id="quotationSearch" name="q" type="search" value="<?php echo admin_quote_e($search); ?>" placeholder="Search client, inquiry, or quotation number" aria-label="Search client, inquiry, or quotation number">
                        <button type="button" class="admin-quotation-search__x" data-search-clear aria-label="Clear quotation search"<?php echo $search === '' ? ' hidden' : ''; ?>>&times;</button>
                        <span class="admin-quotation-search__icon" aria-hidden="true"></span>
                    </div>
                    <input type="hidden" name="status" value="<?php echo admin_quote_e($activeStatus); ?>" data-quotation-status-value>
                    <div class="admin-quotation-status-menu" data-quotation-status-menu>
                        <button type="button" class="admin-quotation-status-menu__toggle" data-quotation-status-toggle aria-haspopup="menu" aria-expanded="false">
                            <span class="admin-quotation-status-menu__icon" aria-hidden="true"></span>
                            <span data-quotation-status-label><?php echo $activeStatus === 'all' ? 'Status' : 'Status: ' . admin_quote_e($statusLabels[$activeStatus]); ?></span>
                            <span class="admin-quotation-status-menu__arrow" aria-hidden="true"></span>
                        </button>
                        <div class="admin-quotation-status-menu__panel" data-quotation-status-panel role="menu" hidden>
                            <?php foreach ($statusLabels as $value => $label): ?>
                                <button type="button" class="admin-quotation-status-menu__option<?php echo $activeStatus === $value ? ' is-selected' : ''; ?>" data-quotation-status-option data-status-value="<?php echo admin_quote_e($value); ?>" data-status-label="<?php echo admin_quote_e($label); ?>" role="menuitemradio" aria-checked="<?php echo $activeStatus === $value ? 'true' : 'false'; ?>">
                                    <span><?php echo admin_quote_e($value === 'all' ? 'All statuses' : $label); ?></span>
                                    <small data-quotation-status-count="<?php echo admin_quote_e($value); ?>"><?php echo (int)$counts[$value]; ?></small>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <span class="admin-quotation-search__feedback" data-quotation-search-feedback role="status" aria-live="polite"></span>
                </div>
            </form>
        </header>

        <?php if (!$tablesReady): ?>
            <section class="admin-quotation-empty">
                <h2>Quotation data is not ready</h2>
                <p>Please check the existing inquiry quotation migration.</p>
            </section>
        <?php else: ?>
            <div data-quotation-results>
                <?php include __DIR__ . '/quotation_list_results.php'; ?>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php render_quotation_project_confirm_modal(); ?>
<?php include __DIR__ . '/../../../layout/footer.php'; ?>
