<?php
define('AUTH_REQUIRED_ROLE', 'foreman');
require_once __DIR__ . '/../../config/auth_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/project_access.php';
require_once __DIR__ . '/../../config/project_progress.php';
require_once __DIR__ . '/../includes/foreman_helpers.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$foremanProfileName = (string)($_SESSION['name'] ?? 'Foreman');
$foremanProfile = foreman_fetch_profile($conn, $userId);
$foremanProfileName = (string)($foremanProfile['full_name'] ?? $foremanProfileName);
$dashboardData = foreman_fetch_dashboard_data($conn, $userId);
$assetSummary = $dashboardData['asset_summary'];
$usageSummary = $dashboardData['usage_summary'];
$scanSummary = $dashboardData['scan_summary'];
$supportSummary = $dashboardData['support_summary'];
$assignedProjectRows = array_slice($dashboardData['assigned_projects'], 0, 3);
$recentUsageLogs = array_slice($dashboardData['recent_usage_logs'], 0, 5);
$workerSummaryRows = array_slice($dashboardData['worker_summary_rows'], 0, 5);
$foremanNotifications = [
    'attention_count' => (int)($assetSummary['maintenance_assets'] ?? 0) + (int)($assetSummary['damaged_assets'] ?? 0),
    'logs_today' => (int)($usageSummary['logs_today'] ?? 0),
    'scans_today' => (int)($scanSummary['scans_today'] ?? 0),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Foreman Overview - Edge Automation</title>
    <script src="/codesamplecaps/SHARED/sidebar/js/sidebar-state.js"></script>
    <link rel="stylesheet" href="/codesamplecaps/SHARED/sidebar/css/sidebar.css">
    <link rel="stylesheet" href="../css/foreman_dashboard.css">
    <link rel="stylesheet" href="../css/qr_scanner.css">
</head>
<body>
<?php include __DIR__ . '/../sidebar/sidebar_foreman.php'; ?>

<main class="main-content">
    <div class="page-shell foreman-overview">
        <section class="page-hero page-hero--overview">
            <div class="page-hero__content">
                <h1 class="page-hero__title">Hello, <?php echo htmlspecialchars($foremanProfileName); ?></h1>
                <div class="hero-actions">
                    <button class="btn-primary" type="button" data-open-qr-scanner>Scan Asset</button>
                    <a class="btn-secondary" href="/codesamplecaps/FOREMAN/dashboards/asset_status.php">Asset Status</a>
                </div>
            </div>
        </section>

        <section class="metrics-grid" aria-label="Foreman metrics">
            <article class="metric-card">
                <span>Available Assets</span>
                <strong><?php echo (int)($assetSummary['available_assets'] ?? 0); ?></strong>
            </article>
            <article class="metric-card">
                <span>Assets In Use</span>
                <strong><?php echo (int)($assetSummary['in_use_assets'] ?? 0); ?></strong>
            </article>
            <article class="metric-card metric-card--warning">
                <span>Maintenance</span>
                <strong><?php echo (int)($assetSummary['maintenance_assets'] ?? 0); ?></strong>
            </article>
            <article class="metric-card metric-card--danger">
                <span>Damaged Or Lost</span>
                <strong><?php echo (int)($assetSummary['damaged_assets'] ?? 0); ?></strong>
            </article>
        </section>

        <section class="panel-card" aria-label="Today at a glance">
            <div class="section-heading">
                <h2>Today At A Glance</h2>
            </div>
            <div class="snapshot-list">
                <div class="snapshot-item">
                    <span>Usage Logs Today</span>
                    <strong><?php echo (int)($usageSummary['logs_today'] ?? 0); ?></strong>
                </div>
                <div class="snapshot-item">
                    <span>Open Tasks</span>
                    <strong><?php echo (int)($supportSummary['open_tasks'] ?? 0); ?></strong>
                </div>
                <div class="snapshot-item">
                    <span>Scans In 7 Days</span>
                    <strong><?php echo (int)($scanSummary['scans_last_7_days'] ?? 0); ?></strong>
                </div>
            </div>
        </section>

        <section class="panel-card" aria-label="Assigned projects preview">
            <div class="section-heading">
                <div>
                    <h2>My Projects</h2>
                </div>
                <a class="btn-secondary" href="/codesamplecaps/FOREMAN/dashboards/projects.php">Open My Projects</a>
            </div>

            <?php if (!empty($assignedProjectRows)): ?>
                <div class="project-list project-list--preview">
                    <?php foreach ($assignedProjectRows as $project): ?>
                        <?php
                        $projectStatus = (string)($project['status'] ?? 'pending');
                        $projectProgress = build_role_project_progress($project, 'foreman');
                        ?>
                        <article class="project-card">
                            <div class="project-card__header">
                                <h3><?php echo htmlspecialchars((string)($project['project_name'] ?? 'Untitled Project')); ?></h3>
                                <span class="status-badge status-badge--<?php echo htmlspecialchars($projectStatus); ?>">
                                    <?php echo htmlspecialchars(foreman_status_label($projectStatus)); ?>
                                </span>
                            </div>
                            <div class="project-progress">
                                <div class="project-progress__meta">
                                    <span>Progress</span>
                                    <strong><?php echo (int)($projectProgress['percent'] ?? 0); ?>%</strong>
                                </div>
                                <progress value="<?php echo (int)($projectProgress['percent'] ?? 0); ?>" max="100">
                                    <?php echo (int)($projectProgress['percent'] ?? 0); ?>%
                                </progress>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">No projects yet.</div>
            <?php endif; ?>
        </section>

        <section class="content-grid">
            <article class="panel-card">
                <div class="section-heading">
                    <div>
                        <h2>Recent Logs</h2>
                    </div>
                    <a class="btn-secondary" href="/codesamplecaps/FOREMAN/dashboards/usage_logs.php">Open Usage Logs</a>
                </div>

                <?php if (!empty($recentUsageLogs)): ?>
                    <div class="activity-list">
                        <?php foreach ($recentUsageLogs as $log): ?>
                            <article class="activity-card">
                                <div class="activity-card__header">
                                    <div>
                                        <h3><?php echo htmlspecialchars((string)($log['asset_name'] ?? 'Unknown Asset')); ?></h3>
                                        <p><?php echo htmlspecialchars(foreman_format_datetime($log['used_at'] ?? null)); ?></p>
                                    </div>
                                    <span class="status-badge status-badge--<?php echo htmlspecialchars((string)($log['resolved_status'] ?? 'available')); ?>">
                                        <?php echo htmlspecialchars(foreman_status_label((string)($log['resolved_status'] ?? 'available'))); ?>
                                    </span>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">No logs yet.</div>
                <?php endif; ?>
            </article>

            <article class="panel-card">
                <div class="section-heading">
                    <div>
                        <h2>Worker Summary</h2>
                    </div>
                    <a class="btn-secondary" href="/codesamplecaps/FOREMAN/dashboards/worker_summary.php">Open Worker Summary</a>
                </div>

                <?php if (!empty($workerSummaryRows)): ?>
                    <div class="worker-grid">
                        <?php foreach ($workerSummaryRows as $worker): ?>
                            <article class="worker-card">
                                <div class="worker-card__header">
                                    <h3><?php echo htmlspecialchars((string)($worker['worker_name'] ?? 'Unknown')); ?></h3>
                                    <span class="status-badge status-badge--ok"><?php echo (int)($worker['usage_count'] ?? 0); ?> logs</span>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">No workers yet.</div>
                <?php endif; ?>
            </article>
        </section>
    </div>
</main>

<div class="qr-modal" id="qrScannerModal" aria-hidden="true">
    <div class="qr-modal-content">
        <div class="qr-modal-header">
            <h2>QR Asset Scanner</h2>
            <button id="qrScannerClose" class="qr-close" type="button" aria-label="Close">X</button>
        </div>
        <div class="qr-modal-body">
            <div class="qr-status" id="qrStatus">Ready to scan.</div>
            <div class="qr-scanner-area" id="qr-reader"></div>
            <div class="qr-error" id="qrScannerError"></div>
            <div class="qr-asset-info" id="qrAssetInfo"></div>
            <div class="qr-input-row">
                <input id="qrWorkerName" placeholder="Worker / personnel name" aria-label="Worker name">
                <textarea id="qrNotes" rows="2" placeholder="Optional notes" aria-label="Notes"></textarea>
            </div>
            <div class="qr-actions">
                <button class="btn-primary" id="qrLogUsage" type="button">Log Usage</button>
                <button class="btn-secondary" id="qrScannerCloseSecondary" type="button">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="/codesamplecaps/SHARED/sidebar/js/sidebar.js"></script>
<script src="/codesamplecaps/FOREMAN/js/sidebar_foreman.js"></script>
<script src="../js/html5-qrcode.min.js"></script>
<script src="../js/qr_scanner_foreman.js"></script>
</body>
</html>
