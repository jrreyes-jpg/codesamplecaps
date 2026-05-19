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
$foremanNotifications = [
    'attention_count' => (int)($assetSummary['maintenance_assets'] ?? 0) + (int)($assetSummary['damaged_assets'] ?? 0),
    'logs_today' => (int)($usageSummary['logs_today'] ?? 0),
    'scans_today' => (int)($scanSummary['scans_today'] ?? 0),
];

$assetStatusExpression = foreman_asset_status_expression($conn);
$assetRows = [];

if (foreman_table_exists($conn, 'assets')) {
    $assetResult = $conn->query(
        "SELECT
            id,
            asset_name,
            asset_type,
            serial_number,
            {$assetStatusExpression} AS resolved_status
         FROM assets
         ORDER BY asset_name ASC"
    );

    if ($assetResult) {
        $assetRows = $assetResult->fetch_all(MYSQLI_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Foreman Asset Status - Edge Automation</title>
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
                <span class="page-hero__eyebrow">Asset Status</span>
                <h1 class="page-hero__title">Inventory Condition</h1>
                <p class="page-hero__copy">
                    This page is only for asset condition and current availability.
                </p>
                <div class="hero-actions">
                    <button class="btn-primary" type="button" data-open-qr-scanner>Scan Asset</button>
                    <a class="btn-secondary" href="/codesamplecaps/FOREMAN/dashboards/foreman_dashboard.php">Back To Overview</a>
                </div>
            </div>

            <aside class="page-hero__aside">
                <div class="aside-stat">
                    <span>Total Assets</span>
                    <strong><?php echo (int)($assetSummary['total_assets'] ?? 0); ?></strong>
                </div>
                <div class="aside-stat">
                    <span>Available</span>
                    <strong><?php echo (int)($assetSummary['available_assets'] ?? 0); ?></strong>
                </div>
                <div class="aside-stat">
                    <span>In Use</span>
                    <strong><?php echo (int)($assetSummary['in_use_assets'] ?? 0); ?></strong>
                </div>
                <div class="aside-stat">
                    <span>Needs Attention</span>
                    <strong><?php echo (int)$foremanNotifications['attention_count']; ?></strong>
                </div>
            </aside>
        </section>

        <section class="status-grid">
            <article class="status-card">
                <span>Available</span>
                <strong><?php echo (int)($assetSummary['available_assets'] ?? 0); ?></strong>
                <p>Ready for field use.</p>
            </article>
            <article class="status-card">
                <span>In Use</span>
                <strong><?php echo (int)($assetSummary['in_use_assets'] ?? 0); ?></strong>
                <p>Currently assigned or logged in the field.</p>
            </article>
            <article class="status-card">
                <span>Maintenance</span>
                <strong><?php echo (int)($assetSummary['maintenance_assets'] ?? 0); ?></strong>
                <p>Needs service before reuse.</p>
            </article>
            <article class="status-card">
                <span>Damaged / Lost</span>
                <strong><?php echo (int)($assetSummary['damaged_assets'] ?? 0); ?></strong>
                <p>Needs report and follow-up.</p>
            </article>
        </section>

        <section class="panel-card">
            <div class="section-heading">
                <div>
                    <span class="section-badge">All Assets</span>
                    <h2>Current Status List</h2>
                </div>
            </div>

            <?php if (!empty($assetRows)): ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Asset</th>
                                <th>Type</th>
                                <th>Serial</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assetRows as $asset): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)($asset['asset_name'] ?? 'Unknown Asset')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($asset['asset_type'] ?? 'No type')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($asset['serial_number'] ?? 'No serial')); ?></td>
                                    <td>
                                        <span class="status-badge status-badge--<?php echo htmlspecialchars((string)($asset['resolved_status'] ?? 'available')); ?>">
                                            <?php echo htmlspecialchars(foreman_status_label((string)($asset['resolved_status'] ?? 'available'))); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">No assets found.</div>
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
