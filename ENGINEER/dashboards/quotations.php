<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/quotation_module.php';

require_role('engineer');

$userId = (int)($_SESSION['user_id'] ?? 0);
$flash = quotation_module_consume_flash();
$bootstrap = quotation_module_bootstrap_tables($conn);
$tablesReady = (bool)($bootstrap['ready'] ?? false);
$isFormMode = isset($_GET['form']) || isset($_GET['project_id']) || isset($_GET['id']);
$quotationId = (int)($_GET['id'] ?? 0);
$prefillProjectId = (int)($_GET['project_id'] ?? 0);
$quotations = $tablesReady ? quotation_module_fetch_quotations($conn, 'engineer', $userId) : [];
$projects = $tablesReady ? quotation_module_fetch_engineer_projects($conn, $userId) : [];
$csrfToken = quotation_module_csrf_token();
$foremen = [];
$inventoryOptions = [];
$assetOptions = [];
$quotation = null;
$items = [];
$reviews = [];
$history = [];

$statusCounts = [
    'draft' => 0,
    'under_review' => 0,
    'for_approval' => 0,
    'approved' => 0,
];

foreach ($quotations as $quotationRow) {
    $status = (string)($quotationRow['status'] ?? '');
    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    }
}

if ($isFormMode && $tablesReady) {
    $foremen = quotation_module_fetch_foremen($conn);
    $inventoryOptions = quotation_module_fetch_inventory_options($conn);
    $assetOptions = quotation_module_fetch_asset_options($conn);

    if ($quotationId > 0) {
        $quotation = quotation_module_fetch_quotation($conn, $quotationId);
        if (!$quotation || !quotation_module_user_can_access($quotation, 'engineer', $userId)) {
            quotation_module_set_flash('error', 'Quotation not found or inaccessible.');
            quotation_module_redirect('/codesamplecaps/ENGINEER/dashboards/quotations.php');
        }

        $items = quotation_module_fetch_quotation_items($conn, $quotationId);
        $reviews = quotation_module_fetch_reviews($conn, $quotationId);
        $history = quotation_module_fetch_history($conn, $quotationId);
    }
}

$canEditDraft = $quotation === null || (((string)($quotation['status'] ?? '') === 'draft') && (int)($quotation['is_locked'] ?? 0) === 0);
$canSubmitForApproval = $quotation !== null && in_array((string)$quotation['status'], ['draft', 'under_review'], true) && (int)($quotation['is_locked'] ?? 0) === 0;

if ($isFormMode && empty($items)) {
    $items = [[
        'item_type' => 'material',
        'source_table' => '',
        'source_id' => '',
        'item_name' => '',
        'description' => '',
        'unit' => 'unit',
        'quantity' => 1,
        'rate' => 0,
        'hours' => 0,
        'days' => 0,
        'line_total' => 0,
    ]];
}

$engineerPageTitle = ($isFormMode ? 'Engineer Quotation Form' : 'Engineer Quotations') . ' - Edge Automation';
$engineerCssFiles = $isFormMode
    ? ['/codesamplecaps/ENGINEER/css/quotations.css', '/codesamplecaps/ENGINEER/css/quotation-form.css']
    : ['/codesamplecaps/ENGINEER/css/quotations.css'];
require __DIR__ . '/../layout/header.php';
?>
<?php include __DIR__ . '/../../SHARED/sidebar/php/sidebar.php'; ?>
<main class="main-content">
    <?php
    include __DIR__ . '/../includes/header.php';
    ?>

    <?php if ($isFormMode): ?>
        <?php include __DIR__ . '/../common/php/quotation-form-panel.php'; ?>
    <?php else: ?>
    <div class="quotation-shell">
        <?php if ($flash): ?>
            <div class="flash <?php echo htmlspecialchars((string)$flash['type']); ?>">
                <?php echo htmlspecialchars((string)$flash['message']); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($bootstrap['errors'])): ?>
            <div class="flash error">
                Quotation setup failed: <?php echo htmlspecialchars(implode(' | ', array_unique(array_map('strval', $bootstrap['errors'])))); ?>
            </div>
        <?php endif; ?>

        <section class="quotation-hero">
            <div>
                <p class="quotation-hero__eyebrow">Engineer Quotation Workspace</p>
                <h1>Quotation Workspace</h1>
                <p>Create project quotations, prepare material and manpower estimates, then submit them for Admin review from the Engineer dashboard.</p>
            </div>
            <div class="quotation-actions">
                <a class="btn-primary" href="/codesamplecaps/ENGINEER/dashboards/quotations.php?form=1">Create Quotation</a>
            </div>
        </section>

        <?php if (!$tablesReady): ?>
            <section class="quotation-panel">
                <h2>Setup Needed</h2>
                <p class="helper-copy">The system tried to prepare quotation tables automatically, but they are still unavailable. Run <code>scripts/setup_quotation_tables.php</code> if the database user cannot create tables from the app.</p>
            </section>
        <?php else: ?>
            <section class="stats-grid" aria-label="Quotation summary">
                <article class="stat-card stat-card--draft"><span>Draft Quotations</span><strong><?php echo (int)$statusCounts['draft']; ?></strong></article>
                <article class="stat-card stat-card--review"><span>Legacy Review</span><strong><?php echo (int)$statusCounts['under_review']; ?></strong></article>
                <article class="stat-card stat-card--approval"><span>Ready For Approval</span><strong><?php echo (int)$statusCounts['for_approval']; ?></strong></article>
                <article class="stat-card stat-card--approved"><span>Approved / Locked</span><strong><?php echo (int)$statusCounts['approved']; ?></strong></article>
            </section>

            <section class="quotation-panel">
                <h2>Assigned Projects</h2>
                <p class="helper-copy">These are the projects where you can prepare quotations.</p>
                <?php if (!empty($projects)): ?>
                    <div class="quotation-table-wrap">
                        <table class="quotation-table">
                            <thead><tr><th>Project</th><th>Client</th><th>Project Duration</th><th>Status</th><th>Action</th></tr></thead>
                            <tbody>
                            <?php foreach ($projects as $project): ?>
                                <tr>
                                    <td data-label="Project"><?php echo htmlspecialchars((string)$project['project_name']); ?></td>
                                    <td data-label="Client"><?php echo htmlspecialchars((string)$project['client_name']); ?></td>
                                    <td data-label="Project Duration">
                                        <?php if (!empty($project['project_duration_days'])): ?>
                                            <span class="project-duration-badge"><?php echo (int)$project['project_duration_days']; ?> day(s)</span>
                                        <?php else: ?>
                                            <span class="helper-copy">Missing project timeline</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Status"><span class="project-status-pill status-<?php echo htmlspecialchars((string)$project['status']); ?>"><?php echo htmlspecialchars(ucfirst((string)$project['status'])); ?></span></td>
                                    <td data-label="Action"><a class="btn-secondary" href="/codesamplecaps/ENGINEER/dashboards/quotations.php?form=1&project_id=<?php echo (int)$project['id']; ?>">Start Quotation</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">No assigned projects are available for quotation creation yet.</div>
                <?php endif; ?>
            </section>

            <section class="quotation-panel">
                <h2>My Quotations</h2>
                <p class="helper-copy">Track which quotations are still in draft, waiting for Admin approval, or already sent to the client.</p>
                <?php if (!empty($quotations)): ?>
                    <div class="quotation-table-wrap">
                        <table class="quotation-table">
                            <thead>
                            <tr>
                                <th>Quotation No.</th>
                                <th>Project</th>
                                <th>Reviewer</th>
                                <th>Total Cost</th>
                                <th>Selling Price</th>
                                <th>Status</th>
                                <th>Updated</th>
                                <th>Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($quotations as $quotation): ?>
                                <tr>
                                    <td data-label="Quotation No."><?php echo htmlspecialchars((string)$quotation['quotation_no']); ?></td>
                                    <td data-label="Project"><?php echo htmlspecialchars((string)$quotation['project_name']); ?></td>
                                    <td data-label="Reviewer">Admin</td>
                                    <td data-label="Total Cost"><?php echo htmlspecialchars(quotation_module_format_currency($quotation['total_cost'] ?? 0)); ?></td>
                                    <td data-label="Selling Price"><?php echo htmlspecialchars(quotation_module_format_currency($quotation['selling_price'] ?? 0)); ?></td>
                                    <td data-label="Status"><span class="status-pill <?php echo htmlspecialchars(quotation_module_status_class((string)$quotation['status'])); ?>"><?php echo htmlspecialchars(quotation_module_status_label((string)$quotation['status'])); ?></span></td>
                                    <td data-label="Updated"><?php echo htmlspecialchars(quotation_module_format_datetime((string)$quotation['updated_at'])); ?></td>
                                    <td data-label="Action"><a class="btn-secondary" href="/codesamplecaps/ENGINEER/dashboards/quotations.php?form=1&id=<?php echo (int)$quotation['id']; ?>">Open</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">No quotations created yet. Start from one of your assigned projects.</div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</main>
<?php
$engineerJsFiles = $isFormMode ? ['/codesamplecaps/ENGINEER/js/quotations.js'] : [];
require __DIR__ . '/../layout/footer.php';
?>
