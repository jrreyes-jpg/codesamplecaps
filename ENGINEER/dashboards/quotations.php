<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/quotation_module.php';

require_role('engineer');

$userId = (int)($_SESSION['user_id'] ?? 0);
$flash = quotation_module_consume_flash();
$bootstrap = quotation_module_bootstrap_tables($conn);
$tablesReady = (bool)($bootstrap['ready'] ?? false);
$quotations = $tablesReady ? quotation_module_fetch_quotations($conn, 'engineer', $userId) : [];
$projects = $tablesReady ? quotation_module_fetch_engineer_projects($conn, $userId) : [];

$statusCounts = [
    'draft' => 0,
    'under_review' => 0,
    'for_approval' => 0,
    'approved' => 0,
];

foreach ($quotations as $quotation) {
    $status = (string)($quotation['status'] ?? '');
    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Engineer Quotations - Edge Automation</title>
    <link rel="stylesheet" href="../css/engineer-sidebar.css">
    <link rel="stylesheet" href="../css/engineer.css">
    <link rel="stylesheet" href="../css/quotations.css">
</head>
<body>
<?php include __DIR__ . '/../sidebar/sidebar_engineer.php'; ?>
<main class="main-content">
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
                <p>Create project quotations, prepare material and manpower estimates, send them through foreman review, and submit them for super admin approval from the Engineer dashboard.</p>
            </div>
            <div class="quotation-actions">
                <a class="btn-primary" href="/codesamplecaps/ENGINEER/dashboards/quotation_form.php">Create Quotation</a>
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
                <article class="stat-card stat-card--review"><span>Under Foreman Review</span><strong><?php echo (int)$statusCounts['under_review']; ?></strong></article>
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
                                    <td data-label="Action"><a class="btn-secondary" href="/codesamplecaps/ENGINEER/dashboards/quotation_form.php?project_id=<?php echo (int)$project['id']; ?>">Start Quotation</a></td>
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
                <p class="helper-copy">Track which quotations are still in draft, under foreman review, or already waiting for super admin approval.</p>
                <?php if (!empty($quotations)): ?>
                    <div class="quotation-table-wrap">
                        <table class="quotation-table">
                            <thead>
                            <tr>
                                <th>Quotation No.</th>
                                <th>Project</th>
                                <th>Foreman Reviewer</th>
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
                                    <td data-label="Foreman Reviewer"><?php echo htmlspecialchars((string)($quotation['foreman_name'] ?? 'Not assigned')); ?></td>
                                    <td data-label="Total Cost"><?php echo htmlspecialchars(quotation_module_format_currency($quotation['total_cost'] ?? 0)); ?></td>
                                    <td data-label="Selling Price"><?php echo htmlspecialchars(quotation_module_format_currency($quotation['selling_price'] ?? 0)); ?></td>
                                    <td data-label="Status"><span class="status-pill <?php echo htmlspecialchars(quotation_module_status_class((string)$quotation['status'])); ?>"><?php echo htmlspecialchars(quotation_module_status_label((string)$quotation['status'])); ?></span></td>
                                    <td data-label="Updated"><?php echo htmlspecialchars(quotation_module_format_datetime((string)$quotation['updated_at'])); ?></td>
                                    <td data-label="Action"><a class="btn-secondary" href="/codesamplecaps/ENGINEER/dashboards/quotation_form.php?id=<?php echo (int)$quotation['id']; ?>">Open</a></td>
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
</main>
<script src="../js/engineer.js"></script>
</body>
</html>
