<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/asset_unit_helpers.php';

require_role('super_admin');

function scan_history_table_exists(mysqli $conn, string $table): bool {
    $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    if ($safeTable === '') {
        return false;
    }

    $result = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

$history = [];
$hasScanHistoryTable = scan_history_table_exists($conn, 'asset_scan_history');
$hasUsageLogsTable = scan_history_table_exists($conn, 'asset_usage_logs');
$hasDeploymentTables = scan_history_table_exists($conn, 'inventory')
    && scan_history_table_exists($conn, 'project_inventory_deployments')
    && scan_history_table_exists($conn, 'project_inventory_return_logs')
    && scan_history_table_exists($conn, 'projects');

ensure_asset_unit_tracking_schema($conn);

if ($hasScanHistoryTable) {
    $scanHistorySql = 'SELECT
        h.id,
        h.scan_time,
        h.scan_device,
        a.asset_name,
        a.asset_type,
        a.serial_number,
        u.full_name AS foreman_name,
        au.unit_code';

    if ($hasUsageLogsTable) {
        $scanHistorySql .= ',
        latest_usage_log.worker_name,
        latest_usage_log.used_at';
    } else {
        $scanHistorySql .= ',
        NULL AS worker_name,
        NULL AS used_at';
    }

    if ($hasDeploymentTables) {
        $scanHistorySql .= ',
        current_unit_deployment.project_name,
        current_unit_deployment.remaining_quantity,
        deployment.project_name AS asset_level_project_name,
        deployment.remaining_quantity AS asset_level_remaining_quantity';
    } else {
        $scanHistorySql .= ',
        NULL AS project_name,
        NULL AS remaining_quantity,
        NULL AS asset_level_project_name,
        NULL AS asset_level_remaining_quantity';
    }

    $scanHistorySql .= '
     FROM asset_scan_history h
     LEFT JOIN assets a ON a.id = h.asset_id
     LEFT JOIN users u ON u.id = h.foreman_id
     LEFT JOIN asset_units au ON au.id = h.asset_unit_id';

    if ($hasUsageLogsTable) {
        $scanHistorySql .= '
     LEFT JOIN (
        SELECT
            aul.asset_id,
            aul.worker_name,
            aul.used_at
        FROM asset_usage_logs aul
        INNER JOIN (
            SELECT asset_id, MAX(id) AS latest_usage_id
            FROM asset_usage_logs
            GROUP BY asset_id
        ) latest_usage ON latest_usage.latest_usage_id = aul.id
     ) latest_usage_log ON latest_usage_log.asset_id = h.asset_id';
    }

    if ($hasDeploymentTables) {
        $scanHistorySql .= '
     LEFT JOIN (
        SELECT
            pdu.asset_unit_id,
            p.project_name,
            1 AS remaining_quantity
        FROM project_inventory_deployment_units pdu
        INNER JOIN project_inventory_deployments pid ON pid.id = pdu.deployment_id
        INNER JOIN projects p ON p.id = pid.project_id
        WHERE pdu.returned_at IS NULL
     ) current_unit_deployment ON current_unit_deployment.asset_unit_id = h.asset_unit_id
     LEFT JOIN (
        SELECT
            pid.inventory_id,
            p.project_name,
            (pid.quantity - COALESCE(returns.returned_quantity, 0)) AS remaining_quantity,
            i.asset_id
        FROM project_inventory_deployments pid
        INNER JOIN inventory i ON i.id = pid.inventory_id
        INNER JOIN projects p ON p.id = pid.project_id
        LEFT JOIN (
            SELECT deployment_id, SUM(quantity) AS returned_quantity
            FROM project_inventory_return_logs
            GROUP BY deployment_id
        ) returns ON returns.deployment_id = pid.id
        INNER JOIN (
            SELECT
                pid_inner.inventory_id,
                MAX(pid_inner.id) AS latest_deployment_id
            FROM project_inventory_deployments pid_inner
            LEFT JOIN (
                SELECT deployment_id, SUM(quantity) AS returned_quantity
                FROM project_inventory_return_logs
                GROUP BY deployment_id
            ) inner_returns ON inner_returns.deployment_id = pid_inner.id
            WHERE (pid_inner.quantity - COALESCE(inner_returns.returned_quantity, 0)) > 0
            GROUP BY pid_inner.inventory_id
        ) latest_active_deployment ON latest_active_deployment.latest_deployment_id = pid.id
        WHERE (pid.quantity - COALESCE(returns.returned_quantity, 0)) > 0
     ) deployment ON deployment.asset_id = h.asset_id
     ORDER BY h.scan_time DESC
     LIMIT 200';
    } else {
        $scanHistorySql .= '
     ORDER BY h.scan_time DESC
     LIMIT 200';
    }

    $stmt = $conn->prepare($scanHistorySql);
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            $history = $result->fetch_all(MYSQLI_ASSOC);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scan History - Edge Automation</title>
    <link rel="stylesheet" href="../css/super_admin_dashboard.css">
</head>
<body>
<div class="container">
    <?php include __DIR__ . '/../super_admin_sidebar.php'; ?>

    <main class="main-content scan-history-content">
        <div class="header page-header-card">
            <div class="header-copy">
                <h1>Scan History</h1>
                <p>Review the latest 200 QR scans with asset, foreman, and device details.</p>
            </div>
        </div>

        <div class="table-wrapper">
            <table class="responsive-table mobile-card-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Asset</th>
                        <th>Foreman</th>
                        <th>Device</th>
                        <th>Current Holder</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($history) === 0): ?>
                        <tr><td colspan="5" class="table-empty-cell">No scan history yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($history as $row): ?>
                            <tr>
                                <td data-label="Time"><?php echo htmlspecialchars($row['scan_time']); ?></td>
                                <td data-label="Asset">
                                    <strong><?php echo htmlspecialchars($row['asset_name'] ?? 'Unknown'); ?></strong><br>
                                    <small>Unit: <?php echo htmlspecialchars((string)($row['unit_code'] ?? 'General asset QR')); ?></small><br>
                                    <small><?php echo htmlspecialchars($row['asset_type'] ?? ''); ?></small><br>
                                    <small>SN: <?php echo htmlspecialchars($row['serial_number'] ?? ''); ?></small>
                                </td>
                                <td data-label="Foreman"><?php echo htmlspecialchars($row['foreman_name'] ?? 'Unknown'); ?></td>
                                <td data-label="Device"><?php echo htmlspecialchars($row['scan_device'] ?? 'Unknown'); ?></td>
                                <td data-label="Current Holder">
                                    <?php if (!empty($row['project_name'])): ?>
                                        <strong><?php echo htmlspecialchars((string)$row['project_name']); ?></strong><br>
                                        <small>Project deployment | Qty: <?php echo (int)($row['remaining_quantity'] ?? 0); ?></small>
                                    <?php elseif (!empty($row['asset_level_project_name'])): ?>
                                        <strong><?php echo htmlspecialchars((string)$row['asset_level_project_name']); ?></strong><br>
                                        <small>Asset-level deployment view | Qty: <?php echo (int)($row['asset_level_remaining_quantity'] ?? 0); ?></small>
                                    <?php elseif (!empty($row['worker_name'])): ?>
                                        <strong><?php echo htmlspecialchars((string)$row['worker_name']); ?></strong><br>
                                        <small>Latest usage log: <?php echo htmlspecialchars((string)($row['used_at'] ?? '')); ?></small>
                                    <?php else: ?>
                                        <span>Available in stock</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>
<script src="../js/super_admin_dashboard.js"></script>
</body>
</html>
