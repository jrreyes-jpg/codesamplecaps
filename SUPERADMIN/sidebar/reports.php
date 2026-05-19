<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/project_progress.php';
require_once __DIR__ . '/../../config/quotation_module.php';

require_role('super_admin');

function super_admin_reports_table_exists(mysqli $conn, string $tableName): bool
{
    static $tableCache = [];
    $cacheKey = $conn->thread_id . ':' . $tableName;

    if (array_key_exists($cacheKey, $tableCache)) {
        return $tableCache[$cacheKey];
    }

    $stmt = $conn->prepare(
        'SELECT 1
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = ?
         LIMIT 1'
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $tableName);
    $stmt->execute();
    $result = $stmt->get_result();

    $tableCache[$cacheKey] = (bool)($result && $result->fetch_assoc());
    return $tableCache[$cacheKey];
}

function super_admin_reports_scalar(mysqli $conn, string $sql): int
{
    $result = $conn->query($sql);
    if (!$result) {
        return 0;
    }

    $row = $result->fetch_row();
    return (int)($row[0] ?? 0);
}

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
$taskCount = super_admin_reports_scalar($conn, 'SELECT COUNT(*) FROM tasks');
$auditLogCount = super_admin_reports_table_exists($conn, 'audit_logs')
    ? super_admin_reports_scalar($conn, 'SELECT COUNT(*) FROM audit_logs')
    : 0;
$quotationCount = quotation_module_tables_ready($conn)
    ? count(quotation_module_fetch_quotations($conn, 'super_admin', (int)($_SESSION['user_id'] ?? 0)))
    : 0;
$pendingPurchaseOrders = super_admin_reports_table_exists($conn, 'purchase_orders')
    ? super_admin_reports_scalar($conn, "SELECT COUNT(*) FROM purchase_orders WHERE admin_approval_status = 'pending'")
    : 0;
$inventoryAlerts = super_admin_reports_table_exists($conn, 'inventory')
    ? super_admin_reports_scalar($conn, "SELECT COUNT(*) FROM inventory WHERE status IN ('low-stock', 'out-of-stock')")
    : 0;
$portfolioProgress = $totalProjects > 0
    ? project_progress_clamp(
        (($completedProjects / $totalProjects) * 100)
        + (($ongoingProjects / $totalProjects) * 35)
        - (($onHoldProjects / $totalProjects) * 10)
    )
    : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports Hub - Super Admin</title>
    <link rel="stylesheet" href="/codesamplecaps/SUPERADMIN/css/super_admin_dashboard.css">
    <style>
        .reports-shell {
            display: grid;
            gap: 24px;
        }

        .reports-hero,
        .reports-card,
        .report-link-card {
            border: 1px solid rgba(148, 163, 184, 0.18);
            background: rgba(255, 255, 255, 0.94);
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.08);
        }

        .reports-hero {
            display: grid;
            grid-template-columns: minmax(0, 1.5fr) minmax(280px, 0.9fr);
            gap: 20px;
            padding: 28px;
            border-radius: 28px;
        }

        .reports-hero h1,
        .reports-card h2,
        .report-link-card h3 {
            margin: 0;
            color: #0f172a;
        }

        .reports-hero p,
        .reports-card p,
        .report-link-card p {
            color: #475569;
        }

        .reports-kicker {
            margin: 0 0 10px;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: #047857;
        }

        .reports-hero h1 {
            font-size: clamp(2rem, 4vw, 2.8rem);
            line-height: 1.05;
        }

        .reports-chip-row,
        .reports-stat-grid,
        .report-links-grid {
            display: grid;
            gap: 16px;
        }

        .reports-chip-row {
            grid-template-columns: repeat(3, minmax(0, 1fr));
            margin-top: 18px;
        }

        .reports-chip {
            padding: 14px 16px;
            border-radius: 18px;
            background: linear-gradient(135deg, #ecfdf5, #f8fafc);
            color: #166534;
            font-weight: 700;
        }

        .reports-chip span,
        .report-stat-card span {
            display: block;
            font-size: 0.8rem;
            color: #64748b;
        }

        .reports-chip strong,
        .report-stat-card strong {
            display: block;
            margin-top: 4px;
            font-size: 1.6rem;
            color: #0f172a;
        }

        .reports-card {
            padding: 24px;
            border-radius: 24px;
        }

        .reports-stat-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .report-stat-card {
            padding: 18px;
            border-radius: 20px;
            background: #f8fafc;
        }

        .report-links-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .report-link-card {
            display: grid;
            gap: 12px;
            padding: 22px;
            border-radius: 22px;
            text-decoration: none;
            color: inherit;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .report-link-card:hover,
        .report-link-card:focus-visible {
            transform: translateY(-3px);
            box-shadow: 0 24px 48px rgba(15, 23, 42, 0.12);
            outline: none;
        }

        .report-link-card__meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            color: #166534;
            font-weight: 700;
        }

        @media (max-width: 1200px) {
            .reports-stat-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 900px) {
            .reports-hero,
            .report-links-grid,
            .reports-chip-row {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .reports-stat-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../super_admin_sidebar.php'; ?>

<main class="main-content">
    <div class="reports-shell">
        <section class="reports-hero">
            <div>
                <p class="reports-kicker">Reports Hub</p>
                <h1>System-wide reporting for decisions, approvals, and audit visibility.</h1>
                <p>Super Admin sees the full reporting layer: all projects, all user activity, quotation approvals, inventory signals, procurement approvals, and audit history.</p>
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
            <h2>Core reports for Super Admin</h2>
            <p>Each card matches a real admin reporting responsibility in your system.</p>
            <div class="report-links-grid">
                <a class="report-link-card" href="/codesamplecaps/SUPERADMIN/dashboards/super_admin_dashboard.php?tab=users">
                    <h3>User activity reports</h3>
                    <p>Monitor user base, role distribution, and account-level visibility across Engineer, Foreman, and Client users.</p>
                    <div class="report-link-card__meta">
                        <span><?php echo $activeUsers; ?> active users</span>
                        <span>Open</span>
                    </div>
                </a>
                <a class="report-link-card" href="/codesamplecaps/SUPERADMIN/sidebar/projects.php">
                    <h3>All project reports</h3>
                    <p>Track project pipeline, active execution, completed delivery, and overall workload across the system.</p>
                    <div class="report-link-card__meta">
                        <span><?php echo $completedProjects; ?> completed projects</span>
                        <span>Open</span>
                    </div>
                </a>
                <a class="report-link-card" href="/codesamplecaps/SUPERADMIN/sidebar/quotations.php">
                    <h3>Quotation and approval reports</h3>
                    <p>Review all quotations, approval states, and commercial records tied to project delivery.</p>
                    <div class="report-link-card__meta">
                        <span><?php echo $quotationCount; ?> quotations</span>
                        <span>Open</span>
                    </div>
                </a>
                <a class="report-link-card" href="/codesamplecaps/SUPERADMIN/sidebar/procurement.php">
                    <h3>Financial and procurement summary</h3>
                    <p>Use purchase-order approval counts as the current admin-level financial checkpoint inside the system.</p>
                    <div class="report-link-card__meta">
                        <span><?php echo $pendingPurchaseOrders; ?> pending approval</span>
                        <span>Open</span>
                    </div>
                </a>
                <a class="report-link-card" href="/codesamplecaps/SUPERADMIN/sidebar/inventory.php">
                    <h3>Inventory and asset reports</h3>
                    <p>Watch stock pressure and asset movement that may affect delivery schedules and procurement needs.</p>
                    <div class="report-link-card__meta">
                        <span><?php echo $inventoryAlerts; ?> stock alerts</span>
                        <span>Open</span>
                    </div>
                </a>
                <a class="report-link-card" href="/codesamplecaps/SUPERADMIN/sidebar/activity_history.php">
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

<script src="/codesamplecaps/SUPERADMIN/js/super_admin_dashboard.js"></script>
</body>
</html>
