<?php
require_once __DIR__ . '/../../config/profile_photo_storage.php';

if (!function_exists('build_default_profile_avatar_data_uri')) {
function build_default_profile_avatar_data_uri(): string {
    $relativePath = '/codesamplecaps/IMAGES/nodp.jpg';
    $absoluteFile = __DIR__ . '/../../IMAGES/nodp.jpg';

    if (is_file($absoluteFile) && is_readable($absoluteFile)) {
        return $relativePath;
    }

    $svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200">
  <defs>
    <linearGradient id="avatarBg" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" style="stop-color:#ffffff;stop-opacity:1" />
      <stop offset="100%" style="stop-color:#f0f2f5;stop-opacity:1" />
    </linearGradient>
  </defs>
  <rect width="200" height="200" fill="url(#avatarBg)"/>
  <circle cx="100" cy="70" r="35" fill="#ccc"/>
  <path d="M 30 180 Q 30 140 100 140 Q 170 140 170 180 L 170 200 L 30 200 Z" fill="#ccc"/>
</svg>
SVG;

    return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
}
}

function super_admin_sidebar_table_exists(mysqli $conn, string $tableName): bool {
    static $tableCache = [];
    $cacheKey = $conn->thread_id . ':' . $tableName;

    if (array_key_exists($cacheKey, $tableCache)) {
        return $tableCache[$cacheKey];
    }

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

    $tableCache[$cacheKey] = (bool)($result && $result->fetch_assoc());
    return $tableCache[$cacheKey];
}

function super_admin_sidebar_column_exists(mysqli $conn, string $tableName, string $columnName): bool {
    static $columnCache = [];
    $cacheKey = $conn->thread_id . ':' . $tableName . ':' . $columnName;

    if (array_key_exists($cacheKey, $columnCache)) {
        return $columnCache[$cacheKey];
    }

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

    $columnCache[$cacheKey] = (bool)($result && $result->fetch_assoc());
    return $columnCache[$cacheKey];
}

function super_admin_notification_relative_time(?string $dateTime): string {
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

        return $date->format('M d, Y');
    } catch (Throwable $exception) {
        return (string)$dateTime;
    }
}

function super_admin_notification_action_label(string $action): string {
    return ucwords(str_replace('_', ' ', $action));
}

function super_admin_profile_initials(string $name): string {
    $name = trim($name);
    if ($name === '') {
        return 'SA';
    }

    $parts = preg_split('/\s+/', $name) ?: [];
    $initials = '';

    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }

        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) {
            break;
        }
    }

    return $initials !== '' ? $initials : 'SA';
}

function super_admin_fetch_notification_data(mysqli $conn): array {
    $cacheKey = 'super_admin_sidebar_notification_data';
    $cacheTtlSeconds = 30;
    $cached = $_SESSION[$cacheKey] ?? null;

    if (
        is_array($cached)
        && isset($cached['expires_at'], $cached['data'])
        && (int)$cached['expires_at'] >= time()
        && is_array($cached['data'])
    ) {
        return $cached['data'];
    }

    $projectRiskCount = 0;
    $stockAlertCount = 0;
    $inactiveAssignmentCount = 0;
    $projectRiskAlerts = [];
    $stockAlerts = [];
    $inactiveAssignmentAlerts = [];
    $recentActivity = [];
    $projectsSoftDeleteSupported = super_admin_sidebar_column_exists($conn, 'projects', 'deleted_at');
    $projectVisibilityWhere = $projectsSoftDeleteSupported ? ' AND p.deleted_at IS NULL' : '';

    $projectRiskCountResult = $conn->query(
        "SELECT COUNT(*) AS total
         FROM projects p
         LEFT JOIN (
             SELECT
                 project_id,
                 SUM(CASE WHEN status IN ('pending', 'ongoing', 'delayed') THEN 1 ELSE 0 END) AS open_tasks,
                 SUM(CASE WHEN status = 'delayed' THEN 1 ELSE 0 END) AS delayed_tasks
             FROM tasks
             GROUP BY project_id
         ) task_totals ON task_totals.project_id = p.id
         WHERE p.status IN ('pending', 'ongoing', 'on-hold')" . $projectVisibilityWhere . "
         AND COALESCE(task_totals.delayed_tasks, 0) > 0"
    );
    if ($projectRiskCountResult) {
        $projectRiskCount = (int)(($projectRiskCountResult->fetch_assoc()['total'] ?? 0));
    }

    $projectRiskResult = $conn->query(
        "SELECT
            p.id,
            p.project_name,
            p.status,
            COALESCE(task_totals.delayed_tasks, 0) AS delayed_tasks
         FROM projects p
         LEFT JOIN (
             SELECT
                 project_id,
                 SUM(CASE WHEN status = 'delayed' THEN 1 ELSE 0 END) AS delayed_tasks
             FROM tasks
             GROUP BY project_id
         ) task_totals ON task_totals.project_id = p.id
         WHERE p.status IN ('pending', 'ongoing', 'on-hold')" . $projectVisibilityWhere . "
         AND COALESCE(task_totals.delayed_tasks, 0) > 0
         ORDER BY COALESCE(task_totals.delayed_tasks, 0) DESC, p.updated_at DESC
         LIMIT 4"
    );
    if ($projectRiskResult) {
        $projectRiskAlerts = $projectRiskResult->fetch_all(MYSQLI_ASSOC);
    }

    $stockCountResult = $conn->query(
        "SELECT COUNT(*) AS total
         FROM inventory
         WHERE status IN ('low-stock', 'out-of-stock')"
    );
    if ($stockCountResult) {
        $stockAlertCount = (int)(($stockCountResult->fetch_assoc()['total'] ?? 0));
    }

    $stockResult = $conn->query(
        "SELECT a.asset_name, i.quantity, i.min_stock, i.status
         FROM inventory i
         INNER JOIN assets a ON a.id = i.asset_id
         WHERE i.status IN ('low-stock', 'out-of-stock')
         ORDER BY FIELD(i.status, 'out-of-stock', 'low-stock'), i.quantity ASC, a.asset_name ASC
         LIMIT 4"
    );
    if ($stockResult) {
        $stockAlerts = $stockResult->fetch_all(MYSQLI_ASSOC);
    }

    $inactiveCountResult = $conn->query(
        "SELECT COUNT(*) AS total
         FROM (
             SELECT u.id
             FROM users u
             LEFT JOIN project_assignments pa ON pa.engineer_id = u.id
             LEFT JOIN projects p ON p.id = pa.project_id AND p.status IN ('pending', 'ongoing', 'on-hold')" . ($projectsSoftDeleteSupported ? " AND p.deleted_at IS NULL" : '') . "
             WHERE u.status = 'inactive'
             AND u.role IN ('engineer', 'foreman', 'client')
             GROUP BY u.id
             HAVING COUNT(DISTINCT p.id) > 0
         ) flagged_users"
    );
    if ($inactiveCountResult) {
        $inactiveAssignmentCount = (int)(($inactiveCountResult->fetch_assoc()['total'] ?? 0));
    }

    $inactiveResult = $conn->query(
        "SELECT
            u.full_name,
            u.role,
            COUNT(DISTINCT p.id) AS active_projects
         FROM users u
         LEFT JOIN project_assignments pa ON pa.engineer_id = u.id
         LEFT JOIN projects p ON p.id = pa.project_id AND p.status IN ('pending', 'ongoing', 'on-hold')" . ($projectsSoftDeleteSupported ? " AND p.deleted_at IS NULL" : '') . "
         WHERE u.status = 'inactive'
         AND u.role IN ('engineer', 'foreman', 'client')
         GROUP BY u.id, u.full_name, u.role
         HAVING active_projects > 0
         ORDER BY active_projects DESC, u.full_name ASC
         LIMIT 4"
    );
    if ($inactiveResult) {
        $inactiveAssignmentAlerts = $inactiveResult->fetch_all(MYSQLI_ASSOC);
    }

    $hasAuditTable = function_exists('audit_log_table_exists')
        ? audit_log_table_exists($conn)
        : super_admin_sidebar_table_exists($conn, 'audit_logs');

    if ($hasAuditTable) {
        $recentActivityResult = $conn->query(
            "SELECT
                l.created_at,
                l.action,
                l.entity_type,
                actor.full_name AS actor_name
             FROM audit_logs l
             LEFT JOIN users actor ON actor.id = l.user_id
             ORDER BY l.created_at DESC
             LIMIT 4"
        );

        if ($recentActivityResult) {
            $recentActivity = $recentActivityResult->fetch_all(MYSQLI_ASSOC);
        }
    }

    $data = [
        'project_risk_count' => $projectRiskCount,
        'stock_alert_count' => $stockAlertCount,
        'inactive_assignment_count' => $inactiveAssignmentCount,
        'urgent_count' => $projectRiskCount,
        'project_risk_alerts' => $projectRiskAlerts,
        'stock_alerts' => $stockAlerts,
        'inactive_assignment_alerts' => $inactiveAssignmentAlerts,
        'recent_activity' => $recentActivity,
    ];

    $_SESSION[$cacheKey] = [
        'expires_at' => time() + $cacheTtlSeconds,
        'data' => $data,
    ];

    return $data;
}
