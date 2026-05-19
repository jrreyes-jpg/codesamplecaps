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
$workerSummaryRows = $dashboardData['worker_summary_rows'];
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
    <title>Foreman Worker Summary - Edge Automation</title>
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
                <span class="page-hero__eyebrow">Worker Summary</span>
                <h1 class="page-hero__title">Personnel Activity</h1>
                <p class="page-hero__copy">
                    This page is focused only on worker activity based on usage logs from the last 7 days.
                </p>
                <div class="hero-actions">
                    <button class="btn-primary" type="button" data-open-qr-scanner>Scan Asset</button>
                    <a class="btn-secondary" href="/codesamplecaps/FOREMAN/dashboards/foreman_dashboard.php">Back To Overview</a>
                </div>
            </div>

            <aside class="page-hero__aside">
                <div class="aside-stat">
                    <span>Workers Today</span>
                    <strong><?php echo (int)($usageSummary['workers_today'] ?? 0); ?></strong>
                </div>
                <div class="aside-stat">
                    <span>Logs Today</span>
                    <strong><?php echo (int)($usageSummary['logs_today'] ?? 0); ?></strong>
                </div>
                <div class="aside-stat">
                    <span>Logs In 7 Days</span>
                    <strong><?php echo (int)($usageSummary['logs_last_7_days'] ?? 0); ?></strong>
                </div>
                <div class="aside-stat">
                    <span>Total Workers Shown</span>
                    <strong><?php echo count($workerSummaryRows); ?></strong>
                </div>
            </aside>
        </section>

        <section class="panel-card">
            <div class="section-heading">
                <div>
                    <span class="section-badge">Last 7 Days</span>
                    <h2>Worker Usage Ranking</h2>
                </div>
            </div>

            <?php if (!empty($workerSummaryRows)): ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Worker</th>
                                <th>Usage Count</th>
                                <th>Last Activity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($workerSummaryRows as $worker): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)($worker['worker_name'] ?? 'Unknown')); ?></td>
                                    <td><?php echo (int)($worker['usage_count'] ?? 0); ?></td>
                                    <td><?php echo htmlspecialchars(foreman_format_datetime($worker['last_used_at'] ?? null)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">No worker activity yet.</div>
            <?php endif; ?>
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
