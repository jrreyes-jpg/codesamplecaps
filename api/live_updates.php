<?php
require_once __DIR__ . '/../config/auth_middleware.php';
require_once __DIR__ . '/../config/database.php';

require_role('super_admin');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function live_table_exists(mysqli $conn, string $tableName): bool
{
    static $cache = [];
    if (array_key_exists($tableName, $cache)) {
        return $cache[$tableName];
    }

    $stmt = $conn->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    if (!$stmt) {
        $cache[$tableName] = false;
        return false;
    }

    $stmt->bind_param('s', $tableName);
    $stmt->execute();
    $result = $stmt->get_result();
    $cache[$tableName] = (bool)($result && $result->fetch_assoc());
    return $cache[$tableName];
}

function live_column_exists(mysqli $conn, string $tableName, string $columnName): bool
{
    static $cache = [];
    $key = $tableName . '.' . $columnName;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $conn->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    if (!$stmt) {
        $cache[$key] = false;
        return false;
    }

    $stmt->bind_param('ss', $tableName, $columnName);
    $stmt->execute();
    $result = $stmt->get_result();
    $cache[$key] = (bool)($result && $result->fetch_assoc());
    return $cache[$key];
}

function live_scalar(mysqli $conn, string $sql, string $types = '', array $params = []): int
{
    if ($types !== '') {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($sql);
    }

    $row = $result ? $result->fetch_row() : null;
    return (int)($row[0] ?? 0);
}

function live_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    if ($types !== '') {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($sql);
    }

    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function live_sync_inventory_status(mysqli $conn): void
{
    if (!live_table_exists($conn, 'inventory')) {
        return;
    }

    $conn->query(
        "UPDATE inventory
         SET status = CASE
             WHEN quantity <= 0 THEN 'out-of-stock'
             WHEN min_stock IS NOT NULL AND quantity <= min_stock THEN 'low-stock'
             ELSE 'available'
         END
         WHERE status <> CASE
             WHEN quantity <= 0 THEN 'out-of-stock'
             WHEN min_stock IS NOT NULL AND quantity <= min_stock THEN 'low-stock'
             ELSE 'available'
         END"
    );
}

function live_project_visibility_sql(mysqli $conn, string $alias = ''): string
{
    if (!live_column_exists($conn, 'projects', 'deleted_at')) {
        return '';
    }

    $prefix = $alias !== '' ? $alias . '.' : '';
    return " AND {$prefix}deleted_at IS NULL";
}

function live_super_admin_payload(mysqli $conn): array
{
    $projectVisibility = live_project_visibility_sql($conn);
    $metrics = [
        'total_projects' => live_scalar($conn, "SELECT COUNT(*) FROM projects WHERE status <> 'draft'{$projectVisibility}"),
        'active_projects' => live_scalar($conn, "SELECT COUNT(*) FROM projects WHERE status IN ('pending', 'ongoing', 'on-hold'){$projectVisibility}"),
        'ongoing_projects' => live_scalar($conn, "SELECT COUNT(*) FROM projects WHERE status = 'ongoing'{$projectVisibility}"),
        'completed_projects' => live_scalar($conn, "SELECT COUNT(*) FROM projects WHERE status = 'completed'{$projectVisibility}"),
        'pending_projects' => live_scalar($conn, "SELECT COUNT(*) FROM projects WHERE status = 'pending'{$projectVisibility}"),
        'on_hold_projects' => live_scalar($conn, "SELECT COUNT(*) FROM projects WHERE status = 'on-hold'{$projectVisibility}"),
        'open_tasks' => live_scalar($conn, "SELECT COUNT(*) FROM tasks WHERE status IN ('pending', 'ongoing', 'delayed')"),
        'delayed_tasks' => live_scalar($conn, "SELECT COUNT(*) FROM tasks WHERE status = 'delayed'"),
        'inventory_alerts' => live_scalar($conn, "SELECT COUNT(*) FROM inventory WHERE status IN ('low-stock', 'out-of-stock')"),
        'total_assets' => live_scalar($conn, "SELECT COUNT(*) FROM assets"),
        'pending_quotations' => live_table_exists($conn, 'quotations')
            ? live_scalar($conn, "SELECT COUNT(*) FROM quotations WHERE status IN ('under_review', 'for_approval')")
            : 0,
        'scans_today' => live_table_exists($conn, 'asset_scan_history')
            ? live_scalar($conn, "SELECT COUNT(*) FROM asset_scan_history WHERE scan_time >= CURDATE() AND scan_time < CURDATE() + INTERVAL 1 DAY")
            : 0,
    ];

    $notifications = [];
    if ($metrics['delayed_tasks'] > 0) {
        $notifications[] = [
            'tone' => 'danger',
            'title' => $metrics['delayed_tasks'] . ' delayed task(s)',
            'detail' => 'Project delivery needs review.',
        ];
    }
    if ($metrics['inventory_alerts'] > 0) {
        $notifications[] = [
            'tone' => 'warning',
            'title' => $metrics['inventory_alerts'] . ' inventory alert(s)',
            'detail' => 'Low or out-of-stock items need attention.',
        ];
    }

    $recentActivity = [];
    if (live_table_exists($conn, 'audit_logs')) {
        $activityRows = live_rows(
            $conn,
            "SELECT
                l.action,
                l.entity_type,
                l.created_at,
                COALESCE(u.full_name, 'System') AS actor_name
             FROM audit_logs l
             LEFT JOIN users u ON u.id = l.user_id
             ORDER BY l.created_at DESC
             LIMIT 5"
        );

        foreach ($activityRows as $entry) {
            $action = ucwords(str_replace('_', ' ', (string)($entry['action'] ?? 'Activity')));
            $entityType = (string)($entry['entity_type'] ?? 'record');
            $actorName = (string)($entry['actor_name'] ?? 'System');
            $createdAt = (string)($entry['created_at'] ?? '');

            $recentActivity[] = [
                'title' => $action,
                'details' => ucfirst($entityType) . ' by ' . $actorName,
                'badge' => $entityType !== '' ? $entityType : 'audit',
                'created_at' => $createdAt,
                'relative_time' => live_relative_time($createdAt),
            ];
        }
    }

    return [$metrics, $notifications, $recentActivity];
}

function live_relative_time(?string $dateTime): string
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
        return (string)$dateTime;
    }
}

function live_engineer_payload(mysqli $conn, int $userId): array
{
    $projectVisibility = live_project_visibility_sql($conn, 'p');
    $metrics = [
        'assigned_projects' => live_scalar(
            $conn,
            "SELECT COUNT(DISTINCT p.id)
             FROM projects p
             INNER JOIN project_assignments pa ON pa.project_id = p.id
             WHERE pa.engineer_id = ?{$projectVisibility}",
            'i',
            [$userId]
        ),
        'active_projects' => live_scalar(
            $conn,
            "SELECT COUNT(DISTINCT p.id)
             FROM projects p
             INNER JOIN project_assignments pa ON pa.project_id = p.id
             WHERE pa.engineer_id = ? AND p.status IN ('pending', 'ongoing', 'on-hold'){$projectVisibility}",
            'i',
            [$userId]
        ),
        'completed_projects' => live_scalar(
            $conn,
            "SELECT COUNT(DISTINCT p.id)
             FROM projects p
             INNER JOIN project_assignments pa ON pa.project_id = p.id
             WHERE pa.engineer_id = ? AND p.status = 'completed'{$projectVisibility}",
            'i',
            [$userId]
        ),
        'open_tasks' => live_scalar(
            $conn,
            "SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status IN ('pending', 'ongoing', 'delayed')",
            'i',
            [$userId]
        ),
        'delayed_tasks' => live_scalar(
            $conn,
            "SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status = 'delayed'",
            'i',
            [$userId]
        ),
    ];

    $notifications = [];
    if ($metrics['delayed_tasks'] > 0) {
        $notifications[] = [
            'tone' => 'danger',
            'title' => $metrics['delayed_tasks'] . ' delayed task(s)',
            'detail' => 'Update status or progress notes when ready.',
        ];
    }
    if ($metrics['open_tasks'] > 0) {
        $notifications[] = [
            'tone' => 'neutral',
            'title' => $metrics['open_tasks'] . ' open task(s)',
            'detail' => 'Current assigned workload.',
        ];
    }

    return [$metrics, $notifications];
}

function live_foreman_payload(mysqli $conn, int $userId): array
{
    $metrics = [
        'total_assets' => live_scalar($conn, 'SELECT COUNT(*) FROM assets'),
        'assets_in_use' => live_scalar($conn, "SELECT COUNT(*) FROM assets WHERE asset_status = 'in_use' OR status = 'in_use'"),
        'needs_attention' => live_scalar($conn, "SELECT COUNT(*) FROM assets WHERE asset_status IN ('maintenance', 'damaged', 'lost') OR status IN ('maintenance', 'damaged', 'lost')"),
        'usage_logs_today' => live_table_exists($conn, 'asset_usage_logs')
            ? live_scalar($conn, "SELECT COUNT(*) FROM asset_usage_logs WHERE foreman_id = ? AND used_at >= CURDATE() AND used_at < CURDATE() + INTERVAL 1 DAY", 'i', [$userId])
            : 0,
        'scans_today' => live_table_exists($conn, 'asset_scan_history')
            ? live_scalar($conn, "SELECT COUNT(*) FROM asset_scan_history WHERE foreman_id = ? AND scan_time >= CURDATE() AND scan_time < CURDATE() + INTERVAL 1 DAY", 'i', [$userId])
            : 0,
        'workers_today' => live_table_exists($conn, 'asset_usage_logs')
            ? live_scalar($conn, "SELECT COUNT(DISTINCT worker_name) FROM asset_usage_logs WHERE foreman_id = ? AND used_at >= CURDATE() AND used_at < CURDATE() + INTERVAL 1 DAY", 'i', [$userId])
            : 0,
    ];

    $notifications = [
        [
            'tone' => 'neutral',
            'title' => $metrics['usage_logs_today'] . ' usage log(s)',
            'detail' => 'Field asset usage recorded today.',
        ],
        [
            'tone' => $metrics['needs_attention'] > 0 ? 'warning' : 'neutral',
            'title' => $metrics['needs_attention'] . ' asset(s) need follow-up',
            'detail' => 'Maintenance, damaged, or lost assets require checking.',
        ],
    ];

    return [$metrics, $notifications];
}

function live_client_payload(mysqli $conn, int $userId): array
{
    $visibility = live_project_visibility_sql($conn);
    $metrics = [
        'total_projects' => live_scalar($conn, "SELECT COUNT(*) FROM projects WHERE client_id = ? AND status <> 'draft'{$visibility}", 'i', [$userId]),
        'ongoing_projects' => live_scalar($conn, "SELECT COUNT(*) FROM projects WHERE client_id = ? AND status = 'ongoing'{$visibility}", 'i', [$userId]),
        'completed_projects' => live_scalar($conn, "SELECT COUNT(*) FROM projects WHERE client_id = ? AND status = 'completed'{$visibility}", 'i', [$userId]),
        'pending_projects' => live_scalar($conn, "SELECT COUNT(*) FROM projects WHERE client_id = ? AND status = 'pending'{$visibility}", 'i', [$userId]),
        'on_hold_projects' => live_scalar($conn, "SELECT COUNT(*) FROM projects WHERE client_id = ? AND status = 'on-hold'{$visibility}", 'i', [$userId]),
    ];
    $metrics['active_projects'] = $metrics['ongoing_projects'] + $metrics['pending_projects'] + $metrics['on_hold_projects'];

    $notifications = [];
    if ($metrics['active_projects'] > 0) {
        $notifications[] = [
            'tone' => 'neutral',
            'title' => $metrics['active_projects'] . ' active project(s)',
            'detail' => 'Pending, ongoing, and on-hold work visible to your account.',
        ];
    }

    return [$metrics, $notifications];
}

try {
    live_sync_inventory_status($conn);

    $role = (string)($_SESSION['role'] ?? '');
    $userId = (int)($_SESSION['user_id'] ?? 0);

    if ($role === 'super_admin') {
        [$metrics, $notifications, $recentActivity] = live_super_admin_payload($conn);
    } elseif ($role === 'engineer') {
        [$metrics, $notifications] = live_engineer_payload($conn, $userId);
    } elseif ($role === 'foreman') {
        [$metrics, $notifications] = live_foreman_payload($conn, $userId);
    } elseif ($role === 'client') {
        [$metrics, $notifications] = live_client_payload($conn, $userId);
    } else {
        $metrics = [];
        $notifications = [];
    }

    echo json_encode([
        'status' => 'success',
        'role' => $role,
        'metrics' => $metrics,
        'notification_count' => count($notifications),
        'notifications' => $notifications,
        'recent_activity' => $recentActivity ?? [],
        'updated_at' => date(DATE_ATOM),
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unable to load live updates.',
    ]);
}
