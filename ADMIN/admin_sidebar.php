<?php
require_once __DIR__ . '/../config/profile_photo_storage.php';

$currentPath = str_replace('\\', '/', parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
$currentQuery = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY) ?? '';

$isDashboardPage = str_contains($currentPath, '/ADMIN/sidebar/overview/php/overview.php');
$isOverviewPage = false;
$isUserManagementPage = str_contains($currentPath, '/ADMIN/sidebar/user_management.php');
$isDashboard = $isOverviewPage || ($isDashboardPage && ($currentQuery === '' || str_contains($currentQuery, 'tab=dashboard')));
$isCreate = $isDashboardPage && str_contains($currentQuery, 'tab=create');
$isCreate = $isCreate || ($isUserManagementPage && str_contains($currentQuery, 'create=1'));
$isUsers = $isUserManagementPage || ($isDashboardPage && (str_contains($currentQuery, 'tab=users') || $isCreate));
$isProjects = str_contains($currentPath, '/ADMIN/sidebar/projects/php/projects.php');
$isProjectsTrash = $isProjects && str_contains($currentQuery, 'view=trash');
$isInventory = str_contains($currentPath, '/ADMIN/sidebar/inventory/php/inventory.php');
$isAssets = str_contains($currentPath, '/ADMIN/sidebar/assets/php/assets.php');
$isQuotations = str_contains($currentPath, '/ADMIN/sidebar/quotations/php/quotations.php');
$isReports = str_contains($currentPath, '/ADMIN/sidebar/reports/php/reports.php');
$isActivityHistory = str_contains($currentPath, '/ADMIN/sidebar/activity_history/php/activity_history.php');
$isInquiries = str_contains($currentPath, '/ADMIN/sidebar/inquiries/php/inquiries.php');
$superAdminProfileName = (string)($_SESSION['name'] ?? 'Admin');
$superAdminProfileRole = ucfirst(str_replace('_', ' ', (string)($_SESSION['role'] ?? 'super_admin')));
$superAdminProfilePhotoUrl = '';
$superAdminProfileInitials = '';

if (!function_exists('build_default_profile_avatar_data_uri')) {
    function build_default_profile_avatar_data_uri(): string
    {
        $relativePath = '/codesamplecaps/IMAGES/nodp.jpg';
        $absoluteFile = __DIR__ . '/../IMAGES/nodp.jpg';

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

if (!function_exists('super_admin_sidebar_table_exists')) {
    function super_admin_sidebar_table_exists(mysqli $conn, string $tableName): bool
    {
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
}

if (!function_exists('super_admin_sidebar_column_exists')) {
    function super_admin_sidebar_column_exists(mysqli $conn, string $tableName, string $columnName): bool
    {
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
}

if (!function_exists('super_admin_notification_relative_time')) {
    function super_admin_notification_relative_time(?string $dateTime): string
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

            return $date->format('M d, Y');
        } catch (Throwable $exception) {
            return (string)$dateTime;
        }
    }
}

if (!function_exists('super_admin_notification_action_label')) {
    function super_admin_notification_action_label(string $action): string
    {
        return ucwords(str_replace('_', ' ', $action));
    }
}

if (!function_exists('super_admin_fetch_notification_data')) {
    function super_admin_fetch_notification_data(mysqli $conn): array
    {
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

        $inquiryCount = 0;
        $inquiryAlerts = [];
        if (super_admin_sidebar_table_exists($conn, 'service_inquiries')) {
            if (!super_admin_sidebar_column_exists($conn, 'service_inquiries', 'viewed_at')) {
                $conn->query('ALTER TABLE service_inquiries ADD COLUMN viewed_at TIMESTAMP NULL AFTER reviewed_at');
            }

            $inquiryCountResult = $conn->query(
                "SELECT COUNT(*) AS total
                 FROM service_inquiries
                 WHERE status = 'Pending Review'
                 AND viewed_at IS NULL"
            );
            if ($inquiryCountResult) {
                $inquiryCount = (int)(($inquiryCountResult->fetch_assoc()['total'] ?? 0));
            }

            $inquiryAlertResult = $conn->query(
                "SELECT id, client_name, service_category, created_at
                 FROM service_inquiries
                 WHERE status = 'Pending Review'
                 AND viewed_at IS NULL
                 ORDER BY created_at DESC
                 LIMIT 4"
            );
            if ($inquiryAlertResult) {
                $inquiryAlerts = $inquiryAlertResult->fetch_all(MYSQLI_ASSOC);
            }
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
            'inquiry_count' => $inquiryCount,
            'urgent_count' => $projectRiskCount + $inquiryCount,
            'project_risk_alerts' => $projectRiskAlerts,
            'stock_alerts' => $stockAlerts,
            'inactive_assignment_alerts' => $inactiveAssignmentAlerts,
            'inquiry_alerts' => $inquiryAlerts,
            'recent_activity' => $recentActivity,
        ];

        $_SESSION[$cacheKey] = [
            'expires_at' => time() + $cacheTtlSeconds,
            'data' => $data,
        ];

        return $data;
    }
}

$superAdminNotificationData = isset($conn) && $conn instanceof mysqli
    ? super_admin_fetch_notification_data($conn)
    : [
        'project_risk_count' => 0,
        'stock_alert_count' => 0,
        'inactive_assignment_count' => 0,
        'inquiry_count' => 0,
        'urgent_count' => 0,
        'project_risk_alerts' => [],
        'stock_alerts' => [],
        'inactive_assignment_alerts' => [],
        'inquiry_alerts' => [],
        'recent_activity' => [],
    ];

if (
    isset($conn)
    && $conn instanceof mysqli
    && super_admin_sidebar_column_exists($conn, 'users', 'profile_photo_path')
    && isset($_SESSION['user_id'])
) {
    $profileStmt = $conn->prepare('SELECT profile_photo_path FROM users WHERE id = ? LIMIT 1');
    if ($profileStmt) {
        $profileStmt->bind_param('i', $_SESSION['user_id']);
        $profileStmt->execute();
        $profileResult = $profileStmt->get_result();
        $profileRow = $profileResult ? $profileResult->fetch_assoc() : null;
        $profilePhotoPath = profile_photo_migrate_legacy_reference(
            $conn,
            (int)$_SESSION['user_id'],
            $profileRow['profile_photo_path'] ?? null
        );
        $profilePhotoPath = trim((string)$profilePhotoPath);
        if ($profilePhotoPath !== '') {
            $superAdminProfilePhotoUrl = profile_photo_public_url($profilePhotoPath);
        }
    }
}

if (!function_exists('super_admin_profile_initials')) {
    function super_admin_profile_initials(string $name): string
    {
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
}

if ($superAdminProfilePhotoUrl === '') {
    $superAdminProfilePhotoUrl = build_default_profile_avatar_data_uri();
}

$superAdminProfileInitials = super_admin_profile_initials($superAdminProfileName);
?>
<?php include __DIR__ . '/../SHARED/layout/sidebar.php'; ?>
<?php ob_start(); ?>
<?php
$headerProfileName = $superAdminProfileName;
$headerProfileRole = $superAdminProfileRole;
$headerProfilePhotoUrl = $superAdminProfilePhotoUrl;
$headerProfileInitials = $superAdminProfileInitials;
$headerProfileAlt = 'Admin profile picture';
$headerProfileLinks = [
    ['label' => 'Profile', 'href' => '/codesamplecaps/ADMIN/sidebar/overview/php/overview.php?tab=profile'],
    ['label' => 'Settings', 'href' => '/codesamplecaps/ADMIN/sidebar/overview/php/overview.php?tab=profile#security-settings'],
    ['label' => 'Logout', 'href' => '/codesamplecaps/LOGIN/php/logout.php'],
];
include __DIR__ . '/../SHARED/header/profile/profile.php';
?>

<div class="topbar-notifications" data-notification-root>
    <button
        title="Notifications"
        id="topbarNotificationToggle"
        class="topbar-notifications__toggle"
        type="button"
        aria-label="Open notifications"
        aria-controls="topbarNotificationDropdown"
        aria-expanded="false">
        <span class="topbar-notifications__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
                <path d="M12 3a4 4 0 0 0-4 4v1.1a7 7 0 0 1-1.52 4.33L5 14.5V16h14v-1.5l-1.48-2.07A7 7 0 0 1 16 8.1V7a4 4 0 0 0-4-4Zm0 18a3 3 0 0 0 2.83-2H9.17A3 3 0 0 0 12 21Z" fill="currentColor" />
            </svg>
        </span>
        <?php if (($superAdminNotificationData['urgent_count'] ?? 0) > 0): ?>
            <span class="topbar-notifications__badge">
                <?php echo $superAdminNotificationData['urgent_count'] > 99 ? '99+' : (int)$superAdminNotificationData['urgent_count']; ?>
            </span>
        <?php endif; ?>
    </button>

    <div id="topbarNotificationDropdown" class="topbar-notifications__dropdown" hidden>
        <div class="topbar-notifications__panel-head">
            <div>
                <strong>Notifications</strong>
                <span>
                    <?php echo (int)($superAdminNotificationData['urgent_count'] ?? 0); ?> need attention
                </span>
            </div>
        </div>

        <?php if (($superAdminNotificationData['project_risk_count'] ?? 0) > 0): ?>
            <div class="topbar-notifications__summary">
                <a href="/codesamplecaps/ADMIN/sidebar/projects/php/projects.php?status=ongoing" class="notification-summary-chip notification-summary-chip--danger">
                    <strong><?php echo (int)($superAdminNotificationData['project_risk_count'] ?? 0); ?></strong>
                    <span>Project risks</span>
                </a>
            </div>
        <?php endif; ?>
        <?php if (($superAdminNotificationData['inquiry_count'] ?? 0) > 0): ?>
            <div class="topbar-notifications__summary">
                <a href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php" class="notification-summary-chip notification-summary-chip--info">
                    <strong><?php echo (int)($superAdminNotificationData['inquiry_count'] ?? 0); ?></strong>
                    <span>New inquiries</span>
                </a>
            </div>
        <?php endif; ?>

        <div class="topbar-notifications__section">
            <div class="topbar-notifications__section-title">Needs attention</div>
            <?php if (($superAdminNotificationData['urgent_count'] ?? 0) === 0): ?>
                <div class="topbar-notifications__empty">
                    No urgent alerts right now.
                </div>
            <?php else: ?>
                <?php foreach ($superAdminNotificationData['project_risk_alerts'] as $projectAlert): ?>
                    <a href="/codesamplecaps/ADMIN/sidebar/projects/php/project_details.php?id=<?php echo (int)($projectAlert['id'] ?? 0); ?>" class="notification-item notification-item--danger">
                        <span class="notification-item__dot"></span>
                        <div class="notification-item__copy">
                            <strong><?php echo htmlspecialchars((string)$projectAlert['project_name']); ?></strong>
                            <span>
                                <?php
                                $parts = [];
                                if ((int)($projectAlert['delayed_tasks'] ?? 0) > 0) {
                                    $parts[] = (int)($projectAlert['delayed_tasks'] . ' delayed task(s)');
                                }
                                echo htmlspecialchars(implode(' | ', $parts) ?: 'Needs checking');
                                ?>
                            </span>
                        </div>
                    </a>
                <?php endforeach; ?>
                <?php foreach ($superAdminNotificationData['inquiry_alerts'] as $inquiryAlert): ?>
                    <a href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?viewed_inquiry=<?php echo (int)($inquiryAlert['id'] ?? 0); ?>" class="notification-item notification-item--inquiry-unviewed">
                        <span class="notification-item__dot"></span>
                        <div class="notification-item__copy">
                            <strong><?php echo htmlspecialchars((string)$inquiryAlert['client_name']); ?></strong>
                            <span><?php echo htmlspecialchars((string)$inquiryAlert['service_category']); ?> &bull; New inquiry</span>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php
$operationsHeaderActionsHtml = ob_get_clean();
$operationsHeaderRole = 'admin';
$operationsHeaderClass = 'global-topbar';
$operationsHeaderBrandClass = 'global-topbar__copy global-topbar__brand-link';
$operationsHeaderActionsClass = 'global-topbar__actions';
$operationsHeaderClockClass = 'global-topbar__clock';
$operationsHeaderHomeHref = '/codesamplecaps/ADMIN/sidebar/overview/php/overview.php';
$operationsHeaderBrandText = 'EDGE Automation';
$operationsHeaderLogoClass = 'global-topbar__brand-logo operations-topbar__brand-logo';
$operationsHeaderBrandLabel = 'Go to Admin overview';
$operationsHeaderTime = '--:--:--';
$operationsHeaderDate = 'Loading date...';
$operationsHeaderTimeAttr = 'class="global-topbar__time" data-ph-time';
$operationsHeaderDateAttr = 'class="global-topbar__date" data-ph-date';
$operationsHeaderAttrs = 'aria-live="polite"';
include __DIR__ . '/../SHARED/layout/operations_header.php';
?>
