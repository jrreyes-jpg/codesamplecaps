<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../includes/foreman_helpers.php';

require_role('foreman');

$userId = (int)($_SESSION['user_id'] ?? 0);
$foremanProfileName = (string)($_SESSION['name'] ?? 'Foreman');
$foremanProfile = foreman_fetch_profile($conn, $userId);
$foremanProfileName = (string)($foremanProfile['full_name'] ?? $foremanProfileName);
$dashboardData = foreman_fetch_dashboard_data($conn, $userId);
$assetSummary = $dashboardData['asset_summary'];
$usageSummary = $dashboardData['usage_summary'];
$scanSummary = $dashboardData['scan_summary'];
$recentUsageLogs = $dashboardData['recent_usage_logs'];
$recentScanRows = $dashboardData['recent_scan_rows'];
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
    <title>Foreman Usage Logs - Edge Automation</title>
    <link rel="stylesheet" href="../css/sidebar_foreman.css">
    <link rel="stylesheet" href="../css/foreman_dashboard.css">
    <link rel="stylesheet" href="../css/qr_scanner.css">
</head>
<body>
<?php include __DIR__ . '/../sidebar/sidebar_foreman.php'; ?>

<main class="main-content">
    <div class="page-shell">
        <section class="page-hero">
            <div class="page-hero__content">
                <span class="page-hero__eyebrow">Usage Logs</span>
                <h1 class="page-hero__title">Asset Activity Records</h1>
                <p class="page-hero__copy">
                    This page keeps the full field usage list and the QR scan history.
                </p>
                <div class="hero-actions">
                    <button class="btn-primary" type="button" data-open-qr-scanner>Scan Asset</button>
                    <a class="btn-secondary" href="/codesamplecaps/FOREMAN/dashboards/foreman_dashboard.php">Back To Overview</a>
                </div>
            </div>

            <aside class="page-hero__aside">
                <div class="aside-stat">
                    <span>Usage Logs Today</span>
                    <strong><?php echo (int)($usageSummary['logs_today'] ?? 0); ?></strong>
                </div>
                <div class="aside-stat">
                    <span>Scans Today</span>
                    <strong><?php echo (int)($scanSummary['scans_today'] ?? 0); ?></strong>
                </div>
                <div class="aside-stat">
                    <span>Logs In 7 Days</span>
                    <strong><?php echo (int)($usageSummary['logs_last_7_days'] ?? 0); ?></strong>
                </div>
                <div class="aside-stat">
                    <span>Scans In 7 Days</span>
                    <strong><?php echo (int)($scanSummary['scans_last_7_days'] ?? 0); ?></strong>
                </div>
            </aside>
        </section>

        <section class="content-grid">
            <article class="panel-card">
                <div class="section-heading">
                    <div>
                        <span class="section-badge">Usage History</span>
                        <h2>Recent Usage Entries</h2>
                    </div>
                </div>

                <?php if (!empty($recentUsageLogs)): ?>
                    <div class="activity-list">
                        <?php foreach ($recentUsageLogs as $log): ?>
                            <article class="activity-card">
                                <div class="activity-card__header">
                                    <div>
                                        <h3><?php echo htmlspecialchars((string)($log['asset_name'] ?? 'Unknown Asset')); ?></h3>
                                        <p>Worker: <?php echo htmlspecialchars((string)($log['worker_name'] ?? 'Unknown')); ?></p>
                                    </div>
                                    <span class="status-badge status-badge--<?php echo htmlspecialchars((string)($log['resolved_status'] ?? 'available')); ?>">
                                        <?php echo htmlspecialchars(foreman_status_label((string)($log['resolved_status'] ?? 'available'))); ?>
                                    </span>
                                </div>
                                <div class="activity-meta">
                                    <span>Type: <?php echo htmlspecialchars((string)($log['asset_type'] ?? 'No type')); ?></span>
                                    <span>Unit: <?php echo htmlspecialchars((string)($log['unit_code'] ?? 'General asset QR')); ?></span>
                                    <span><?php echo htmlspecialchars(foreman_format_datetime($log['used_at'] ?? null)); ?></span>
                                </div>
                                <p><?php echo htmlspecialchars(foreman_excerpt($log['notes'] ?? null, 180)); ?></p>
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
                        <span class="section-badge">Scan History</span>
                        <h2>Recent QR Scans</h2>
                    </div>
                </div>

                <?php if (!empty($recentScanRows)): ?>
                    <div class="scan-list">
                        <?php foreach ($recentScanRows as $scan): ?>
                            <article class="scan-card">
                                <h3><?php echo htmlspecialchars((string)($scan['asset_name'] ?? 'Unknown Asset')); ?></h3>
                                <div class="scan-meta">
                                    <span>Type: <?php echo htmlspecialchars((string)($scan['asset_type'] ?? 'No type')); ?></span>
                                    <span>Unit: <?php echo htmlspecialchars((string)($scan['unit_code'] ?? 'General asset QR')); ?></span>
                                    <span><?php echo htmlspecialchars(foreman_format_datetime($scan['scan_time'] ?? null)); ?></span>
                                </div>
                                <p><?php echo htmlspecialchars(foreman_excerpt($scan['scan_device'] ?? 'No device recorded', 140)); ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">No scan history yet.</div>
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

<script src="../js/sidebar_foreman.js"></script>
<script src="../js/html5-qrcode.min.js"></script>
<script src="../js/qr_scanner_foreman.js"></script>
</body>
</html>
