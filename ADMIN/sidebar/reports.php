<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/project_progress.php';
require_once __DIR__ . '/../../config/quotation_module.php';
require_once __DIR__ . '/../includes/db_helpers.php';

require_role('admin');

$userSummaryResult = $conn->query(
    "SELECT
        COUNT(*) AS total_users,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_users
     FROM users
     WHERE role IN ('engineer', 'foreman', 'client')"
);
$userSummary = $userSummaryResult ? $userSummaryResult->fetch_assoc() : [];
$totalUsers = (int)($userSummary['total_users'] ?? 0);
$activeUsers = (int)($userSummary['active_users'] ?? 0);

$projectSummaryResult = $conn->query(
    "SELECT
        COUNT(*) AS total_projects,
        SUM(CASE WHEN status IN ('pending', 'ongoing', 'on-hold') THEN 1 ELSE 0 END) AS active_projects,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_projects,
        SUM(CASE WHEN status = 'ongoing' THEN 1 ELSE 0 END) AS ongoing_projects,
        SUM(CASE WHEN status = 'on-hold' THEN 1 ELSE 0 END) AS on_hold_projects
     FROM projects"
);
$projectSummary = $projectSummaryResult ? $projectSummaryResult->fetch_assoc() : [];
$totalProjects = (int)($projectSummary['total_projects'] ?? 0);
$activeProjects = (int)($projectSummary['active_projects'] ?? 0);
$completedProjects = (int)($projectSummary['completed_projects'] ?? 0);
$ongoingProjects = (int)($projectSummary['ongoing_projects'] ?? 0);
$onHoldProjects = (int)($projectSummary['on_hold_projects'] ?? 0);
$taskCount = admin_db_scalar_int($conn, 'SELECT COUNT(*) FROM tasks');
$auditLogCount = admin_db_table_exists($conn, 'audit_logs')
    ? admin_db_scalar_int($conn, 'SELECT COUNT(*) FROM audit_logs')
    : 0;
$quotationCount = quotation_module_tables_ready($conn)
    ? count(quotation_module_fetch_quotations($conn, 'super_admin', (int)($_SESSION['user_id'] ?? 0)))
    : 0;
$pendingPurchaseOrders = admin_db_table_exists($conn, 'purchase_orders')
    ? admin_db_scalar_int($conn, "SELECT COUNT(*) FROM purchase_orders WHERE admin_approval_status = 'pending'")
    : 0;
$inventoryAlerts = admin_db_table_exists($conn, 'inventory')
    ? admin_db_scalar_int($conn, "SELECT COUNT(*) FROM inventory WHERE status IN ('low-stock', 'out-of-stock')")
    : 0;
$portfolioProgress = $totalProjects > 0
    ? project_progress_clamp(
        (($completedProjects / $totalProjects) * 100)
        + (($ongoingProjects / $totalProjects) * 35)
        - (($onHoldProjects / $totalProjects) * 10)
    )
    : 0;

$adminPageTitle = 'Reports Hub - Admin';
$adminCssFiles = [
    '/codesamplecaps/ADMIN/css/super_admin_dashboard.css',
    '/codesamplecaps/ADMIN/css/reports.css',
];
$adminJsFiles = [
    '/codesamplecaps/ADMIN/js/super_admin_dashboard.js',
];
include __DIR__ . '/../layout/header.php';
include __DIR__ . '/../admin_sidebar.php';
?>

<main class="main-content">
    <div class="reports-shell">
        <section class="reports-hero">
            <div>
                <p class="reports-kicker">Reports Hub</p>
                <h1>System-wide reporting for decisions, approvals, and audit visibility.</h1>
                <p>Admin sees the full reporting layer: all projects, all user activity, quotation approvals, inventory signals, procurement approvals, and audit history.</p>
                <div class="reports-chip-row">
                    <div class="reports-chip">
                        <span>Operational Coverage</span>
                        <strong><?php echo $activeProjects; ?> active projects</strong>
                    </div>
                    <div class="reports-chip">
                        <span>People Visibility</span>
                        <strong><?php echo $activeUsers; ?> active users</strong>
                    </div>
                    <div class="reports-chip">
                        <span>Pending Decisions</span>
                        <strong><?php echo $pendingPurchaseOrders; ?> procurement approvals</strong>
                    </div>
                </div>
            </div>
            <div class="reports-card">
                <h2>Admin summary</h2>
                <p>Everything here is full-scope by design, unlike Engineer, Foreman, and Client reporting.</p>
                <div class="reports-stat-grid">
                    <div class="report-stat-card">
                        <span>Total users</span>
                        <strong><?php echo $totalUsers; ?></strong>
                    </div>
                    <div class="report-stat-card">
                        <span>Total projects</span>
                        <strong><?php echo $totalProjects; ?></strong>
                    </div>
                    <div class="report-stat-card">
                        <span>Portfolio progress</span>
                        <strong><?php echo $portfolioProgress; ?>%</strong>
                    </div>
                    <div class="report-stat-card">
                        <span>Audit rows</span>
                        <strong><?php echo $auditLogCount; ?></strong>
                    </div>
                    <div class="report-stat-card">
                        <span>Total tasks</span>
                        <strong><?php echo $taskCount; ?></strong>
                    </div>
                </div>
            </div>
        </section>

        <section class="reports-card">
            <h2>Core reports for Admin</h2>
            <p>Each card matches a real admin reporting responsibility in your system.</p>
            <div class="report-links-grid">
                <a class="report-link-card" href="/codesamplecaps/ADMIN/sidebar/user_management.php">
                    <h3>User activity reports</h3>
                    <p>Monitor user base, role distribution, and account-level visibility across Engineer, Foreman, and Client users.</p>
                    <div class="report-link-card__meta">
                        <span><?php echo $activeUsers; ?> active users</span>
                        <span>Open</span>
                    </div>
                </a>
                <a class="report-link-card" href="/codesamplecaps/ADMIN/sidebar/projects.php">
                    <h3>All project reports</h3>
                    <p>Track project pipeline, active execution, completed delivery, and overall workload across the system.</p>
                    <div class="report-link-card__meta">
                        <span><?php echo $completedProjects; ?> completed projects</span>
                        <span>Open</span>
                    </div>
                </a>
                <a class="report-link-card" href="/codesamplecaps/ADMIN/sidebar/quotations.php">
                    <h3>Quotation and approval reports</h3>
                    <p>Review all quotations, approval states, and commercial records tied to project delivery.</p>
                    <div class="report-link-card__meta">
                        <span><?php echo $quotationCount; ?> quotations</span>
                        <span>Open</span>
                    </div>
                </a>
                <a class="report-link-card" href="/codesamplecaps/ADMIN/sidebar/procurement.php">
                    <h3>Financial and procurement summary</h3>
                    <p>Use purchase-order approval counts as the current admin-level financial checkpoint inside the system.</p>
                    <div class="report-link-card__meta">
                        <span><?php echo $pendingPurchaseOrders; ?> pending approval</span>
                        <span>Open</span>
                    </div>
                </a>
                <a class="report-link-card" href="/codesamplecaps/ADMIN/sidebar/inventory.php">
                    <h3>Inventory and asset reports</h3>
                    <p>Watch stock pressure and asset movement that may affect delivery schedules and procurement needs.</p>
                    <div class="report-link-card__meta">
                        <span><?php echo $inventoryAlerts; ?> stock alerts</span>
                        <span>Open</span>
                    </div>
                </a>
                <a class="report-link-card" href="/codesamplecaps/ADMIN/sidebar/activity_history.php">
                    <h3>Audit logs and traceability</h3>
                    <p>Check system history, approvals, changes, and accountability trails when you need full oversight.</p>
                    <div class="report-link-card__meta">
                        <span><?php echo $auditLogCount; ?> audit events</span>
                        <span>Open</span>
                    </div>
                </a>
            </div>
        </section>
    </div>
</main>

<?php include __DIR__ . '/../layout/footer.php'; ?>
