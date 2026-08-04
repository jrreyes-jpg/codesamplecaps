<?php
// Service ng Admin metrics. Dito kinukuha ang counts/trends para sa overview.
function admin_load_dashboard_metrics(mysqli $conn, array $engineers, array $foremen, array $clients): array
{
    $projectVisibilitySql = hasColumn($conn, 'projects', 'deleted_at') ? ' WHERE deleted_at IS NULL' : '';

    $projectMetrics = $conn->query(
        "SELECT
            COUNT(*) AS total_projects,
            SUM(CASE WHEN status = 'ongoing' THEN 1 ELSE 0 END) AS ongoing_projects,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_projects,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_projects,
            SUM(CASE WHEN status = 'on-hold' THEN 1 ELSE 0 END) AS on_hold_projects
         FROM projects" . $projectVisibilitySql
    );
    $projectMetricRow = $projectMetrics ? $projectMetrics->fetch_assoc() : [];

    $taskMetrics = $conn->query(
        "SELECT
            COUNT(*) AS total_tasks,
            SUM(CASE WHEN status IN ('pending', 'ongoing', 'delayed') THEN 1 ELSE 0 END) AS open_tasks,
            SUM(CASE WHEN status = 'delayed' THEN 1 ELSE 0 END) AS delayed_tasks
         FROM tasks"
    );
    $taskMetricRow = $taskMetrics ? $taskMetrics->fetch_assoc() : [];

    $inventoryMetrics = $conn->query(
        "SELECT
            COUNT(*) AS inventory_items,
            COALESCE(SUM(quantity), 0) AS total_units,
            SUM(CASE WHEN status = 'low-stock' THEN 1 ELSE 0 END) AS low_stock_items,
            SUM(CASE WHEN status = 'out-of-stock' THEN 1 ELSE 0 END) AS out_of_stock_items
         FROM inventory"
    );
    $inventoryMetricRow = $inventoryMetrics ? $inventoryMetrics->fetch_assoc() : [];

    $assetMetrics = $conn->query(
        "SELECT
            COUNT(*) AS total_assets,
            SUM(CASE WHEN created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 ELSE 0 END) AS assets_this_month,
            SUM(CASE WHEN serial_number IS NULL OR TRIM(serial_number) = '' THEN 1 ELSE 0 END) AS assets_missing_serial
         FROM assets"
    );
    $assetMetricRow = $assetMetrics ? $assetMetrics->fetch_assoc() : [];

    $totalProjects = (int)($projectMetricRow['total_projects'] ?? 0);
    $ongoingProjects = (int)($projectMetricRow['ongoing_projects'] ?? 0);
    $completedProjects = (int)($projectMetricRow['completed_projects'] ?? 0);
    $pendingProjects = (int)($projectMetricRow['pending_projects'] ?? 0);
    $onHoldProjects = (int)($projectMetricRow['on_hold_projects'] ?? 0);
    $totalTasks = (int)($taskMetricRow['total_tasks'] ?? 0);
    $openTasks = (int)($taskMetricRow['open_tasks'] ?? 0);
    $delayedTasks = (int)($taskMetricRow['delayed_tasks'] ?? 0);
    $inventoryItems = (int)($inventoryMetricRow['inventory_items'] ?? 0);
    $lowStockItems = (int)($inventoryMetricRow['low_stock_items'] ?? 0);
    $outOfStockItems = (int)($inventoryMetricRow['out_of_stock_items'] ?? 0);

    $projectTrend = getDateRangeTrend($conn, 'projects', 'created_at', 7);
    $taskTrend = hasTable($conn, 'tasks') ? getDateRangeTrend($conn, 'tasks', 'created_at', 7) : [];
    $scanTrend = hasTable($conn, 'asset_scan_history') ? getDateRangeTrend($conn, 'asset_scan_history', 'scan_time', 7) : [];

    return [
        'totalProjects' => $totalProjects,
        'ongoingProjects' => $ongoingProjects,
        'completedProjects' => $completedProjects,
        'pendingProjects' => $pendingProjects,
        'onHoldProjects' => $onHoldProjects,
        'totalTasks' => $totalTasks,
        'openTasks' => $openTasks,
        'delayedTasks' => $delayedTasks,
        'inventoryItems' => $inventoryItems,
        'totalUnits' => (int)($inventoryMetricRow['total_units'] ?? 0),
        'lowStockItems' => $lowStockItems,
        'outOfStockItems' => $outOfStockItems,
        'totalAssets' => (int)($assetMetricRow['total_assets'] ?? 0),
        'assetsThisMonth' => (int)($assetMetricRow['assets_this_month'] ?? 0),
        'scansToday' => getScalarInt($conn, "SELECT COUNT(*) FROM asset_scan_history WHERE scan_time >= CURDATE() AND scan_time < (CURDATE() + INTERVAL 1 DAY)"),
        'pendingQuotations' => hasTable($conn, 'quotations')
            ? getScalarInt($conn, "SELECT COUNT(*) FROM quotations WHERE status IN ('under_review', 'for_approval')")
            : 0,
        'pendingInquiries' => hasTable($conn, 'service_inquiries')
            ? getScalarInt($conn, "SELECT COUNT(*) FROM service_inquiries WHERE status = 'Pending Review'")
            : 0,
        'activeDeployments' => hasTable($conn, 'project_inventory_deployments')
            ? getScalarInt(
                $conn,
                "SELECT COUNT(*)
                 FROM (
                     SELECT pid.id
                     FROM project_inventory_deployments pid
                     LEFT JOIN (
                         SELECT deployment_id, SUM(quantity) AS returned_quantity
                         FROM project_inventory_return_logs
                         GROUP BY deployment_id
                     ) returns ON returns.deployment_id = pid.id
                     WHERE (pid.quantity - COALESCE(returns.returned_quantity, 0)) > 0
                 ) active_deployments"
            )
            : 0,
        'activeEngineerCount' => count(array_filter($engineers, static fn(array $user): bool => ($user['status'] ?? 'inactive') === 'active')),
        'activeForemanCount' => count(array_filter($foremen, static fn(array $user): bool => ($user['status'] ?? 'inactive') === 'active')),
        'activeClientCount' => count(array_filter($clients, static fn(array $user): bool => ($user['status'] ?? 'inactive') === 'active')),
        'projectCompletionRate' => $totalProjects > 0
            ? project_progress_clamp((($completedProjects / $totalProjects) * 100) + (($ongoingProjects / $totalProjects) * 35) - (($onHoldProjects / $totalProjects) * 10))
            : 0,
        'taskDelayRate' => $totalTasks > 0 ? (int)round(($delayedTasks / $totalTasks) * 100) : 0,
        'inventoryAlertCount' => $lowStockItems + $outOfStockItems,
        'inventoryAlertRate' => $inventoryItems > 0 ? (int)round((($lowStockItems + $outOfStockItems) / $inventoryItems) * 100) : 0,
        'projectTrend' => $projectTrend,
        'taskTrend' => $taskTrend,
        'scanTrend' => $scanTrend,
        'projectsCreatedThisWeek' => array_sum(array_map(static fn(array $item): int => (int)($item['value'] ?? 0), $projectTrend)),
        'tasksCreatedThisWeek' => array_sum(array_map(static fn(array $item): int => (int)($item['value'] ?? 0), $taskTrend)),
        'scansThisWeek' => array_sum(array_map(static fn(array $item): int => (int)($item['value'] ?? 0), $scanTrend)),
        'scanTrendPeak' => !empty($scanTrend) ? getTrendPeak($scanTrend) : 0,
        'recentDashboardActivity' => fetchRecentDashboardActivity($conn, 5),
    ];
}
