<?php
define('AUTH_REQUIRED_ROLE', 'foreman');
require_once __DIR__ . '/../../config/auth_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/project_access.php';
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
$recentUsageLogs = array_slice($dashboardData['recent_usage_logs'], 0, 4);
$recentScanRows = array_slice($dashboardData['recent_scan_rows'], 0, 5);
$workerSummaryRows = array_slice($dashboardData['worker_summary_rows'], 0, 4);
$foremanNotifications = [
    'attention_count' => (int)($assetSummary['maintenance_assets'] ?? 0) + (int)($assetSummary['damaged_assets'] ?? 0),
    'logs_today' => (int)($usageSummary['logs_today'] ?? 0),
    'scans_today' => (int)($scanSummary['scans_today'] ?? 0),
];
$projectRoleSummary = project_role_summary_label('foreman');
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
    <div class="page-shell">
        <section class="page-hero">
            <div class="page-hero__content">
                <span class="page-hero__eyebrow">Overview</span>
                <h1 class="page-hero__title"><?php echo htmlspecialchars($foremanProfileName); ?></h1>
                <p class="page-hero__copy page-hero__copy--compact"><?php echo htmlspecialchars($projectRoleSummary); ?></p>
                <div class="hero-actions">
                    <button class="btn-primary" type="button" data-open-qr-scanner>Scan Asset</button>
                    <a class="btn-secondary" href="/codesamplecaps/FOREMAN/dashboards/usage_logs.php">View Logs</a>
                </div>
            </div>

            <aside class="page-hero__aside">
                <div class="aside-stat">
                    <span>Account Status</span>
                    <strong><?php echo htmlspecialchars(foreman_status_label((string)($foremanProfile['status'] ?? 'active'))); ?></strong>
                </div>
                <div class="aside-stat">
                    <span>Usage Logs Today</span>
                    <strong><?php echo (int)($usageSummary['logs_today'] ?? 0); ?></strong>
                </div>
                <div class="aside-stat">
                    <span>Scans Today</span>
                    <strong><?php echo (int)($scanSummary['scans_today'] ?? 0); ?></strong>
                </div>
                <div class="aside-stat">
                    <span>Workers Today</span>
                    <strong><?php echo (int)($usageSummary['workers_today'] ?? 0); ?></strong>
                </div>
            </aside>
        </section>

        <section class="metrics-grid" aria-label="Foreman metrics">
            <article class="metric-card">
                <span>Total Assets</span>
                <strong><?php echo (int)($assetSummary['total_assets'] ?? 0); ?></strong>
                <small>Tracked inventory visible to field operations.</small>
            </article>
            <article class="metric-card">
                <span>Assets In Use</span>
                <strong><?php echo (int)($assetSummary['in_use_assets'] ?? 0); ?></strong>
                <small>Currently active based on asset status.</small>
            </article>
            <article class="metric-card">
                <span>Active Projects</span>
                <strong><?php echo (int)($supportSummary['active_projects'] ?? 0); ?></strong>
                <small>Projects still open under your assigned work.</small>
            </article>
            <article class="metric-card metric-card--danger">
                <span>Needs Attention</span>
                <strong><?php echo (int)$foremanNotifications['attention_count']; ?></strong>
                <small>Maintenance, damaged, or lost assets need checking.</small>
            </article>
        </section>

        <section class="content-grid">
            <article class="panel-card">
                <div class="section-heading">
                    <div>
                        <span class="section-badge">Quick Actions</span>
                        <h2>Scan And Check Assets</h2>
                        <p>Scan an asset for field work or check its current status.</p>
                    </div>
                </div>

                <div class="foreman-quick-actions" aria-label="Foreman quick actions">
                    <button class="foreman-quick-action foreman-quick-action--scan" type="button" data-open-qr-scanner>
                        <span class="foreman-quick-action__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" focusable="false">
                                <path d="M4 4h5v2H6v3H4V4Zm11 0h5v5h-2V6h-3V4ZM4 15h2v3h3v2H4v-5Zm14 0h2v5h-5v-2h3v-3ZM8 8h3v3H8V8Zm5 0h3v3h-3V8ZM8 13h3v3H8v-3Zm5 0h3v3h-3v-3Z" />
                            </svg>
                        </span>
                        <span class="foreman-quick-action__content">
                            <strong>Scan Asset</strong>
                            <span>Scan a QR code to log field asset use.</span>
                        </span>
                    </button>
                    <a class="foreman-quick-action foreman-quick-action--status" href="/codesamplecaps/FOREMAN/dashboards/asset_status.php">
                        <span class="foreman-quick-action__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" focusable="false">
                                <path d="M4 5.5A1.5 1.5 0 0 1 5.5 4h13A1.5 1.5 0 0 1 20 5.5v13a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 18.5v-13Zm2 1v11h12v-11H6Zm2 2h3v3H8v-3Zm5 0h3v3h-3v-3Zm-5 5h3v2H8v-2Zm5 0h3v2h-3v-2Z" />
                            </svg>
                        </span>
                        <span class="foreman-quick-action__content">
                            <strong>Asset Status</strong>
                            <span>Check available and active field assets.</span>
                        </span>
                    </a>
                </div>
            </article>

            <article class="panel-card">
                <div class="section-heading">
                    <div>
                        <span class="section-badge">Snapshot</span>
                        <h2>Today At A Glance</h2>
                    </div>
                </div>

                <div class="snapshot-list">
                    <div class="snapshot-item">
                        <span>Available assets</span>
                        <strong><?php echo (int)($assetSummary['available_assets'] ?? 0); ?></strong>
                    </div>
                    <div class="snapshot-item">
                        <span>Open tasks</span>
                        <strong><?php echo (int)($supportSummary['open_tasks'] ?? 0); ?></strong>
                    </div>
                    <div class="snapshot-item">
                        <span>Logs in 7 days</span>
                        <strong><?php echo (int)($usageSummary['logs_last_7_days'] ?? 0); ?></strong>
                    </div>
                    <div class="snapshot-item">
                        <span>Scans in 7 days</span>
                        <strong><?php echo (int)($scanSummary['scans_last_7_days'] ?? 0); ?></strong>
                    </div>
                </div>
            </article>
        </section>

        <section class="scan-automation-panel" aria-label="Scan activity automation">
            <div class="scan-automation-panel__lead">
                <span class="section-badge">Automation</span>
                <h2>Scan Activity Command Center</h2>
                <p>Every successful QR log automatically records usage, scan timestamp, unit code, foreman account, and device details for audit review.</p>
                <div class="scan-automation-panel__actions">
                    <button class="btn-primary" type="button" data-open-qr-scanner>Run Scan</button>
                    <a class="btn-secondary" href="/codesamplecaps/FOREMAN/dashboards/usage_logs.php">Open Activity Log</a>
                </div>
            </div>

            <div class="scan-automation-panel__metrics" aria-label="Scan automation metrics">
                <div class="automation-metric">
                    <span>Scans Today</span>
                    <strong><?php echo (int)($scanSummary['scans_today'] ?? 0); ?></strong>
                </div>
                <div class="automation-metric">
                    <span>7-Day Scans</span>
                    <strong><?php echo (int)($scanSummary['scans_last_7_days'] ?? 0); ?></strong>
                </div>
                <div class="automation-metric">
                    <span>Usage Logs Today</span>
                    <strong><?php echo (int)($usageSummary['logs_today'] ?? 0); ?></strong>
                </div>
            </div>

            <div class="scan-automation-panel__stream">
                <div class="scan-stream-head">
                    <strong>Latest Scan Events</strong>
                    <span>Auto-captured by QR workflow</span>
                </div>

                <?php if (!empty($recentScanRows)): ?>
                    <div class="scan-timeline">
                        <?php foreach ($recentScanRows as $scan): ?>
                            <article class="scan-timeline__item">
                                <span class="scan-timeline__pulse" aria-hidden="true"></span>
                                <div>
                                    <strong><?php echo htmlspecialchars((string)($scan['asset_name'] ?? 'Unknown Asset')); ?></strong>
                                    <span>
                                        <?php echo htmlspecialchars((string)($scan['unit_code'] ?? 'General asset QR')); ?>
                                        | <?php echo htmlspecialchars(foreman_format_datetime($scan['scan_time'] ?? null)); ?>
                                    </span>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state empty-state--inline">No scan activity yet. Start with Run Scan to capture the first event.</div>
                <?php endif; ?>
            </div>
        </section>

        <section class="content-grid">
            <article class="panel-card">
                <div class="section-heading">
                    <div>
                        <span class="section-badge">Recent Logs</span>
                        <h2>Latest Field Entries</h2>
                        <p>Short preview only. Full list is in Usage Logs.</p>
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
                                        <p><?php echo htmlspecialchars((string)($log['worker_name'] ?? 'Unknown Worker')); ?></p>
                                    </div>
                                    <span class="status-badge status-badge--<?php echo htmlspecialchars((string)($log['resolved_status'] ?? 'available')); ?>">
                                        <?php echo htmlspecialchars(foreman_status_label((string)($log['resolved_status'] ?? 'available'))); ?>
                                    </span>
                                </div>
                                <div class="activity-meta">
                                    <span>Type: <?php echo htmlspecialchars((string)($log['asset_type'] ?? 'No type')); ?></span>
                                    <span><?php echo htmlspecialchars(foreman_format_datetime($log['used_at'] ?? null)); ?></span>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">No usage logs yet.</div>
                <?php endif; ?>
            </article>

            <article class="panel-card">
                <div class="section-heading">
                    <div>
                        <span class="section-badge">Workers</span>
                        <h2>Top Active This Week</h2>
                        <p>Preview only. Full list is in Worker Summary.</p>
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
                                <p>Last activity: <?php echo htmlspecialchars(foreman_format_datetime($worker['last_used_at'] ?? null)); ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">No worker activity yet.</div>
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
