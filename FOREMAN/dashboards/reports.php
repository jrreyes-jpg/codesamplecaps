<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../includes/foreman_helpers.php';

require_role('foreman');

$userId = (int)($_SESSION['user_id'] ?? 0);
$foremanProfileName = (string)($_SESSION['name'] ?? 'Foreman');
$foremanProfile = foreman_fetch_profile($conn, $userId);
$foremanProfileName = (string)($foremanProfile['full_name'] ?? $foremanProfileName);
$summary = foreman_fetch_report_summary($conn, $userId);
$criticalItems = $summary['critical_items'];
$foremanNotifications = [
    'attention_count' => 0,
    'logs_today' => 0,
    'scans_today' => 0,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Foreman Reports Dashboard - Edge Automation</title>
    <link rel="stylesheet" href="../css/sidebar_foreman.css">
    <link rel="stylesheet" href="../css/foreman_dashboard.css">
    <link rel="stylesheet" href="../css/foreman_reports.css">
</head>
<body>
<?php include __DIR__ . '/../sidebar/sidebar_foreman.php'; ?>

<main class="main-content">
    <div class="page-shell">
        <section class="page-hero reports-page-hero">
            <div class="page-hero__content">
                <span class="page-hero__eyebrow">Reports</span>
                <h1 class="page-hero__title">Foreman Report Center</h1>
                <p class="page-hero__copy page-hero__copy--compact">Start with the critical queue, then move into the full report list when you need to search, filter, and process more records.</p>
                <div class="hero-actions">
                    <a class="btn-primary" href="/codesamplecaps/FOREMAN/dashboards/report_list.php?quick=overdue">Open Critical Queue</a>
                    <a class="btn-secondary" href="/codesamplecaps/FOREMAN/dashboards/report_list.php">Open All Reports</a>
                </div>
            </div>

            <aside class="page-hero__aside reports-page-hero__aside">
                <div class="aside-stat">
                    <span>Overdue</span>
                    <strong><?php echo (int)$summary['overdue']; ?></strong>
                </div>
                <div class="aside-stat">
                    <span>Due Today</span>
                    <strong><?php echo (int)$summary['due_today']; ?></strong>
                </div>
                <div class="aside-stat">
                    <span>Blocked</span>
                    <strong><?php echo (int)$summary['blocked']; ?></strong>
                </div>
                <div class="aside-stat">
                    <span>Completed This Week</span>
                    <strong><?php echo (int)$summary['completed_this_week']; ?></strong>
                </div>
            </aside>
        </section>

        <section class="metrics-grid reports-metrics-grid" aria-label="Report summary">
            <a class="metric-card report-stat-card report-stat-card--danger" href="/codesamplecaps/FOREMAN/dashboards/report_list.php?quick=overdue">
                <span>Overdue</span>
                <strong><?php echo (int)$summary['overdue']; ?></strong>
                <small>Open reports past due and needing attention.</small>
            </a>
            <a class="metric-card report-stat-card report-stat-card--warning" href="/codesamplecaps/FOREMAN/dashboards/report_list.php?quick=due_today">
                <span>Due Today</span>
                <strong><?php echo (int)$summary['due_today']; ?></strong>
                <small>Items that should be handled before the day ends.</small>
            </a>
            <a class="metric-card report-stat-card report-stat-card--blocked" href="/codesamplecaps/FOREMAN/dashboards/report_list.php?quick=blocked">
                <span>Blocked</span>
                <strong><?php echo (int)$summary['blocked']; ?></strong>
                <small>Reports that cannot move without follow-up.</small>
            </a>
            <a class="metric-card report-stat-card" href="/codesamplecaps/FOREMAN/dashboards/report_list.php?quick=completed">
                <span>Completed This Week</span>
                <strong><?php echo (int)$summary['completed_this_week']; ?></strong>
                <small>Recently closed work for reporting and export.</small>
            </a>
        </section>

        <section class="content-grid reports-dashboard-grid">
            <article class="panel-card">
                <div class="section-heading">
                    <div>
                        <span class="section-badge">Foreman Scope</span>
                        <h2>Reports You Actually Need</h2>
                        <p>Foreman reporting stays focused on field execution, manpower, materials, and assigned-project follow-through.</p>
                    </div>
                </div>

                <div class="reports-preview-list">
                    <article class="reports-preview-item">
                        <div class="reports-preview-item__main">
                            <div class="reports-preview-item__top">
                                <span class="report-pill report-pill--neutral">Daily Site</span>
                            </div>
                            <h3>Daily site and progress reports</h3>
                            <p>Use this workspace for assigned-project field updates and issue follow-up.</p>
                        </div>
                        <a class="btn-secondary reports-inline-action" href="/codesamplecaps/FOREMAN/dashboards/report_list.php">Open</a>
                    </article>
                    <article class="reports-preview-item">
                        <div class="reports-preview-item__main">
                            <div class="reports-preview-item__top">
                                <span class="report-pill report-pill--neutral">Manpower</span>
                            </div>
                            <h3>Attendance and worker summary</h3>
                            <p>Review manpower visibility and team-level field status.</p>
                        </div>
                        <a class="btn-secondary reports-inline-action" href="/codesamplecaps/FOREMAN/dashboards/worker_summary.php">Open</a>
                    </article>
                    <article class="reports-preview-item">
                        <div class="reports-preview-item__main">
                            <div class="reports-preview-item__top">
                                <span class="report-pill report-pill--neutral">Materials</span>
                            </div>
                            <h3>Material usage and site asset logs</h3>
                            <p>Track actual field consumption and on-site movement.</p>
                        </div>
                        <a class="btn-secondary reports-inline-action" href="/codesamplecaps/FOREMAN/dashboards/usage_logs.php">Open</a>
                    </article>
                    <article class="reports-preview-item">
                        <div class="reports-preview-item__main">
                            <div class="reports-preview-item__top">
                                <span class="report-pill report-pill--neutral">Procurement</span>
                            </div>
                            <h3>Material requests and task completion flow</h3>
                            <p>Follow requests that support site delivery and execution.</p>
                        </div>
                        <a class="btn-secondary reports-inline-action" href="/codesamplecaps/FOREMAN/dashboards/procurement.php">Open</a>
                    </article>
                </div>
            </article>
        </section>

        <section class="content-grid reports-dashboard-grid">
            <article class="panel-card">
                <div class="section-heading">
                    <div>
                        <span class="section-badge">Critical Queue</span>
                        <h2>Top 5 Priority Items</h2>
                        <p>This preview stays intentionally short so the dashboard remains useful even when the report list grows.</p>
                    </div>
                    <a class="btn-secondary reports-inline-action" href="/codesamplecaps/FOREMAN/dashboards/report_list.php?quick=overdue">Open List</a>
                </div>

                <?php if (empty($criticalItems)): ?>
                    <div class="empty-state">No critical or due-today reports right now.</div>
                <?php else: ?>
                    <div class="reports-preview-list">
                        <?php foreach ($criticalItems as $item): ?>
                            <article class="reports-preview-item">
                                <div class="reports-preview-item__main">
                                    <div class="reports-preview-item__top">
                                        <span class="report-pill <?php echo htmlspecialchars(foreman_report_priority_class($item)); ?>">
                                            <?php echo htmlspecialchars(foreman_report_priority_label($item)); ?>
                                        </span>
                                        <span class="report-pill report-pill--neutral">
                                            <?php echo htmlspecialchars(ucfirst((string)$item['type'])); ?>
                                        </span>
                                    </div>
                                    <h3><?php echo htmlspecialchars((string)$item['title']); ?></h3>
                                    <p><?php echo htmlspecialchars((string)$item['project_name']); ?></p>
                                    <div class="reports-preview-item__meta">
                                        <span><?php echo htmlspecialchars(foreman_status_label((string)$item['status'])); ?></span>
                                        <span class="<?php echo htmlspecialchars(foreman_report_due_class($item)); ?>">
                                            <?php echo htmlspecialchars(foreman_format_date($item['due_date'] ?? null)); ?>
                                        </span>
                                    </div>
                                </div>
                                <a class="btn-secondary reports-inline-action" href="/codesamplecaps/FOREMAN/dashboards/report_detail.php?id=<?php echo (int)$item['id']; ?>&type=<?php echo urlencode((string)$item['type']); ?>">View</a>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>
        </section>
    </div>
</main>
<script src="../js/sidebar_foreman.js"></script>
</body>
</html>
