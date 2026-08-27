<?php
function hasColumn(mysqli $conn, string $tableName, string $columnName): bool
{
    $stmt = $conn->prepare(
        'SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = ?
         AND COLUMN_NAME = ?
         LIMIT 1'
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $tableName, $columnName);
    $stmt->execute();
    $result = $stmt->get_result();

    return (bool)($result && $result->fetch_assoc());
}

function getScalarInt(mysqli $conn, string $sql): int
{
    $result = $conn->query($sql);
    if (!$result) {
        return 0;
    }

    $row = $result->fetch_assoc();
    if (!$row) {
        return 0;
    }

    return (int)array_values($row)[0];
}

function hasTable(mysqli $conn, string $tableName): bool
{
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

    return (bool)($result && $result->fetch_assoc());
}

function formatRelativeDate(?string $dateTime): string
{
    if (!$dateTime) {
        return 'Unknown time';
    }

    try {
        $date = new DateTimeImmutable($dateTime);
        $now = new DateTimeImmutable();
        $diff = $now->getTimestamp() - $date->getTimestamp();

        if ($diff < 60) {
            return 'Just now';
        }

        if ($diff < 3600) {
            return floor($diff / 60) . ' min ago';
        }

        if ($diff < 86400) {
            return floor($diff / 3600) . ' hr ago';
        }

        if ($diff < 604800) {
            return floor($diff / 86400) . ' day(s) ago';
        }

        return $date->format('M d, Y g:i A');
    } catch (Throwable $exception) {
        return $dateTime;
    }
}

function getDateRangeTrend(mysqli $conn, string $tableName, string $dateColumn, int $rangeDays, ?string $whereSql = null): array
{
    $days = [];
    for ($offset = $rangeDays - 1; $offset >= 0; $offset--) {
        $date = new DateTimeImmutable("-{$offset} days");
        $key = $date->format('Y-m-d');
        $days[$key] = [
            'date' => $key,
            'label' => $date->format($rangeDays > 14 ? 'M d' : 'D'),
            'value' => 0,
        ];
    }

    $whereClause = $whereSql ? " AND {$whereSql}" : '';
    $result = $conn->query(
        "SELECT DATE({$dateColumn}) AS metric_date, COUNT(*) AS total
         FROM {$tableName}
         WHERE DATE({$dateColumn}) >= DATE_SUB(CURDATE(), INTERVAL " . ($rangeDays - 1) . " DAY)
         {$whereClause}
         GROUP BY DATE({$dateColumn})"
    );

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $metricDate = (string)($row['metric_date'] ?? '');
            if (isset($days[$metricDate])) {
                $days[$metricDate]['value'] = (int)($row['total'] ?? 0);
            }
        }
    }

    return array_values($days);
}

function getTrendPeak(array $trend): int
{
    $values = array_map(static fn(array $item): int => (int)($item['value'] ?? 0), $trend);
    $peak = max($values ?: [0]);

    return $peak > 0 ? $peak : 1;
}

function buildAuditSummaryClean(array $entry): array
{
    $summary = buildAuditSummary($entry);
    $summary['details'] = str_replace(
        ['Ã¢â‚¬Â¢', 'â€¢'],
        '|',
        (string)($summary['details'] ?? '')
    );

    return $summary;
}

function decodeAuditPayload(?string $payload): array
{
    if (!$payload) {
        return [];
    }

    $decoded = json_decode($payload, true);

    return is_array($decoded) ? $decoded : [];
}

function formatAuditActionLabel(string $action): string
{
    return ucwords(str_replace('_', ' ', $action));
}

function buildAuditSummary(array $entry): array
{
    $action = (string)($entry['action'] ?? 'activity');
    $entityType = (string)($entry['entity_type'] ?? 'record');
    $actorName = (string)($entry['actor_name'] ?? 'System');
    $oldValues = decodeAuditPayload($entry['old_values'] ?? null);
    $newValues = decodeAuditPayload($entry['new_values'] ?? null);
    $title = formatAuditActionLabel($action);
    $details = 'Actor: ' . $actorName;

    if ($action === 'create_user') {
        $title = 'User created';
        $details = ($newValues['full_name'] ?? 'Unknown user') . ' â€¢ ' . ucwords(str_replace('_', ' ', (string)($newValues['role'] ?? 'user')));
    } elseif ($action === 'update_user_status') {
        $title = 'User status updated';
        $details = 'Status: ' . ucfirst((string)($oldValues['status'] ?? 'unknown')) . ' -> ' . ucfirst((string)($newValues['status'] ?? 'unknown'));
    } elseif ($action === 'update_user_profile') {
        $title = 'User profile updated';
        $details = ($newValues['full_name'] ?? 'User record updated') . ' â€¢ by ' . $actorName;
    } elseif ($action === 'create_project') {
        $title = 'Project created';
        $details = (string)($newValues['project_name'] ?? 'New project') . ' â€¢ ' . ucfirst((string)($newValues['status'] ?? 'pending'));
    } elseif ($action === 'update_project_status') {
        $title = 'Project status changed';
        $details = (string)($newValues['project_name'] ?? 'Project') . ' â€¢ ' . ucfirst((string)($oldValues['status'] ?? '')) . ' -> ' . ucfirst((string)($newValues['status'] ?? ''));
    } elseif ($action === 'update_project_details') {
        $title = 'Project details updated';
        $details = (string)($newValues['project_name'] ?? 'Project') . ' â€¢ by ' . $actorName;
    } elseif ($action === 'delete_project') {
        $title = 'Project moved to trash';
        $details = (string)($oldValues['project_name'] ?? 'Project') . ' â€¢ by ' . $actorName;
    } elseif ($action === 'restore_project') {
        $title = 'Project restored';
        $details = (string)($newValues['project_name'] ?? $oldValues['project_name'] ?? 'Project') . ' â€¢ by ' . $actorName;
    } elseif ($action === 'permanently_delete_project') {
        $title = 'Project permanently deleted';
        $details = (string)($oldValues['project_name'] ?? 'Project') . ' â€¢ by ' . $actorName;
    } elseif ($action === 'update_project_budget') {
        $title = 'Project budget updated';
        $details = (string)($newValues['project_name'] ?? 'Project') . ' Ã¢â‚¬Â¢ ' . number_format((float)($newValues['budget_amount'] ?? 0), 2);
    } elseif ($action === 'add_project_cost') {
        $title = 'Project cost logged';
        $details = (string)($newValues['project_name'] ?? 'Project') . ' Ã¢â‚¬Â¢ ' . (string)($newValues['cost_category'] ?? 'Cost');
    } elseif ($action === 'add_task') {
        $title = 'Task added';
        $details = (string)($newValues['task_name'] ?? 'Task') . ' â€¢ ' . (string)($newValues['project_name'] ?? 'Project');
    } elseif ($action === 'deploy_inventory_to_project') {
        $title = 'Inventory deployed';
        $details = (string)($newValues['asset_name'] ?? 'Inventory item') . ' â€¢ Qty ' . (int)($newValues['quantity'] ?? 0);
    } elseif ($action === 'return_project_inventory') {
        $title = 'Inventory returned';
        $details = (string)($newValues['asset_name'] ?? 'Inventory item') . ' â€¢ Qty ' . (int)($newValues['quantity'] ?? 0);
    } elseif ($action === 'create_inventory_item') {
        $title = 'Inventory record created';
        $details = (string)($newValues['asset_name'] ?? 'Inventory item') . ' â€¢ Qty ' . (int)($newValues['quantity'] ?? 0);
    } elseif ($action === 'update_inventory_item') {
        $title = 'Inventory updated';
        $details = (string)($newValues['asset_name'] ?? 'Inventory item') . ' â€¢ Qty ' . (int)($newValues['quantity'] ?? 0);
    } elseif ($action === 'create_asset') {
        $title = 'Asset created';
        $details = (string)($newValues['asset_name'] ?? 'Asset') . ' â€¢ ' . (string)($newValues['asset_type'] ?? 'Unspecified');
    } elseif ($action === 'delete_asset') {
        $title = 'Asset deleted';
        $details = (string)($oldValues['asset_name'] ?? 'Asset') . ' â€¢ by ' . $actorName;
    } elseif ($action === 'return_asset') {
        $title = 'Asset returned';
        $details = (string)($newValues['asset_name'] ?? 'Asset') . ' â€¢ status available';
    } else {
        $title = formatAuditActionLabel($action);
        $details = ucfirst($entityType) . ' â€¢ by ' . $actorName;
    }

    return [
        'title' => $title,
        'details' => $details,
        'badge' => $entityType !== '' ? $entityType : 'audit',
    ];
}

function fetchRecentDashboardActivity(mysqli $conn, int $limit = 5): array
{
    if (!audit_log_table_exists($conn)) {
        return [];
    }

    $limit = max(1, min(10, $limit));
    $result = $conn->query(
        "SELECT
            l.action,
            l.entity_type,
            l.entity_id,
            l.old_values,
            l.new_values,
            l.created_at,
            COALESCE(u.full_name, 'System') AS actor_name
         FROM audit_logs l
         LEFT JOIN users u ON u.id = l.user_id
         ORDER BY l.created_at DESC
         LIMIT {$limit}"
    );

    if (!$result) {
        return [];
    }

    $activities = [];
    while ($entry = $result->fetch_assoc()) {
        $summary = buildAuditSummaryClean($entry);
        $activities[] = [
            'title' => $summary['title'],
            'details' => $summary['details'],
            'badge' => $summary['badge'],
            'created_at' => $entry['created_at'] ?? null,
            'relative_time' => formatRelativeDate($entry['created_at'] ?? null),
        ];
    }

    return $activities;
}


// Dashboard metrics. Dito kinukuha ang counts, trends, at recent activity.
function admin_load_dashboard_metrics(mysqli $conn): array
{
    $projectVisibilitySql = hasColumn($conn, 'projects', 'deleted_at') ? ' WHERE deleted_at IS NULL' : '';
    $userVisibilitySql = hasColumn($conn, 'users', 'deleted_at') ? ' AND deleted_at IS NULL' : '';

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

    $workforceMetrics = $conn->query(
        "SELECT
            SUM(CASE WHEN role = 'engineer' AND status = 'active' THEN 1 ELSE 0 END) AS active_engineers,
            SUM(CASE WHEN role IN ('foreman', 'foremen') AND status = 'active' THEN 1 ELSE 0 END) AS active_foremen,
            SUM(CASE WHEN role = 'client' AND status = 'active' THEN 1 ELSE 0 END) AS active_clients
         FROM users
         WHERE role IN ('engineer', 'foreman', 'foremen', 'client')" . $userVisibilitySql
    );
    $workforceMetricRow = $workforceMetrics ? $workforceMetrics->fetch_assoc() : [];

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
        'activeEngineerCount' => (int)($workforceMetricRow['active_engineers'] ?? 0),
        'activeForemanCount' => (int)($workforceMetricRow['active_foremen'] ?? 0),
        'activeClientCount' => (int)($workforceMetricRow['active_clients'] ?? 0),
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
