<?php
require_once __DIR__ . '/../config/profile_photo_storage.php';

$currentPath = str_replace('\\', '/', parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
$currentQuery = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY) ?? '';

$isDashboardPage = str_contains($currentPath, '/SUPERADMIN/dashboards/super_admin_dashboard.php');
$isDashboard = $isDashboardPage && ($currentQuery === '' || str_contains($currentQuery, 'tab=dashboard'));
$isCreate = $isDashboardPage && str_contains($currentQuery, 'tab=create');
$isUsers = $isDashboardPage && (str_contains($currentQuery, 'tab=users') || $isCreate);
$isProjects = str_contains($currentPath, '/SUPERADMIN/sidebar/projects.php');
$isProjectsTrash = $isProjects && str_contains($currentQuery, 'view=trash');
$isInventory = str_contains($currentPath, '/SUPERADMIN/sidebar/inventory.php');
$isAssets = str_contains($currentPath, '/SUPERADMIN/sidebar/assets.php');
$isQuotations = str_contains($currentPath, '/SUPERADMIN/sidebar/quotations.php');
$isReports = str_contains($currentPath, '/SUPERADMIN/sidebar/reports.php');
$isScanHistory = str_contains($currentPath, '/SUPERADMIN/sidebar/scan_history.php');
$isActivityHistory = str_contains($currentPath, '/SUPERADMIN/sidebar/activity_history.php');
$superAdminProfileName = (string)($_SESSION['name'] ?? 'Super Admin');
$superAdminProfileRole = ucfirst(str_replace('_', ' ', (string)($_SESSION['role'] ?? 'super_admin')));
$superAdminProfilePhotoUrl = '';
$superAdminProfileInitials = '';

if (!function_exists('build_default_profile_avatar_data_uri')) {
    function build_default_profile_avatar_data_uri(): string {
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
}

if (!function_exists('super_admin_sidebar_column_exists')) {
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
}

if (!function_exists('super_admin_notification_relative_time')) {
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
}

if (!function_exists('super_admin_notification_action_label')) {
    function super_admin_notification_action_label(string $action): string {
        return ucwords(str_replace('_', ' ', $action));
    }
}

if (!function_exists('super_admin_fetch_notification_data')) {
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
}

$superAdminNotificationData = isset($conn) && $conn instanceof mysqli
    ? super_admin_fetch_notification_data($conn)
    : [
        'project_risk_count' => 0,
        'stock_alert_count' => 0,
        'inactive_assignment_count' => 0,
        'urgent_count' => 0,
        'project_risk_alerts' => [],
        'stock_alerts' => [],
        'inactive_assignment_alerts' => [],
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
}

if ($superAdminProfilePhotoUrl === '') {
    $superAdminProfilePhotoUrl = build_default_profile_avatar_data_uri();
}

$superAdminProfileInitials = super_admin_profile_initials($superAdminProfileName);
?>
<?php auth_render_back_button_logout_script(); ?>
<button id="sidebarMobileToggle" class="sidebar-mobile-toggle" type="button" aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false">
    <span></span>
    <span></span>
    <span></span>
</button>
<nav class="sidebar" id="sidebar">
    <div class="sidebar-toggle-row">
        <button id="sidebarToggle" class="sidebar-toggle" type="button" aria-label="Collapse sidebar" aria-controls="sidebar" aria-expanded="true">
            <span id="toggleIcon" class="sidebar-toggle-icon" aria-hidden="true">
                <svg class="sidebar-toggle-svg" viewBox="0 0 20 20" focusable="false" aria-hidden="true">
                    <path d="M11.75 4.75L6.5 10l5.25 5.25"></path>
                </svg>
            </span>
        </button>
        <div class="sidebar-toggle-title" aria-hidden="true">
            <span class="sidebar-toggle-title__shine">Super Admin</span>
        </div>
    </div>

    <div class="nav-divider"></div>

    <ul class="nav-menu">
        <li>
            <a href="/codesamplecaps/SUPERADMIN/dashboards/super_admin_dashboard.php?tab=dashboard" class="menu-link<?php echo $isDashboard ? ' active' : ''; ?>">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon">
                        <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <rect x="3" y="3" width="7" height="7" rx="2"></rect>
                            <rect x="14" y="3" width="7" height="5" rx="2"></rect>
                            <rect x="14" y="10" width="7" height="11" rx="2"></rect>
                            <rect x="3" y="12" width="7" height="9" rx="2"></rect>
                        </svg>
                    </span>
                    <span class="menu-mini-label">Home</span>
                </span>
                <span class="menu-text">Overview</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/SUPERADMIN/dashboards/super_admin_dashboard.php?tab=users" class="menu-link<?php echo $isUsers ? ' active' : ''; ?>">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon">
                        <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <path d="M16 20a4 4 0 0 0-8 0"></path>
                            <circle cx="12" cy="9" r="3.5"></circle>
                            <path d="M19 20a3 3 0 0 0-3-3"></path>
                            <path d="M5 20a3 3 0 0 1 3-3"></path>
                        </svg>
                    </span>
                    <span class="menu-mini-label">Users</span>
                </span>
                <span class="menu-text">User Management</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/SUPERADMIN/sidebar/projects.php" class="menu-link<?php echo $isProjects && !$isProjectsTrash ? ' active' : ''; ?>">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon">
                        <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <path d="M3.5 7.5a2 2 0 0 1 2-2h4l1.6 1.8H18.5a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2z"></path>
                            <path d="M3.5 10.5h17"></path>
                        </svg>
                    </span>
                    <span class="menu-mini-label">Proj</span>
                </span>
                <span class="menu-text">Projects</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/SUPERADMIN/sidebar/inventory.php" class="menu-link<?php echo $isInventory ? ' active' : ''; ?>">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon">
                        <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <path d="M4 8.5 12 4l8 4.5"></path>
                            <path d="M4 8.5V16l8 4 8-4V8.5"></path>
                            <path d="M12 12l8-3.5"></path>
                            <path d="M12 12 4 8.5"></path>
                            <path d="M12 12v8"></path>
                        </svg>
                    </span>
                    <span class="menu-mini-label">Stock</span>
                </span>
                <span class="menu-text">Inventory</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/SUPERADMIN/sidebar/assets.php" class="menu-link<?php echo $isAssets ? ' active' : ''; ?>">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon">
                        <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <path d="M4 19h16"></path>
                            <path d="M6 19V9l6-4 6 4v10"></path>
                            <path d="M9 19v-4h6v4"></path>
                        </svg>
                    </span>
                    <span class="menu-mini-label">Asset</span>
                </span>
                <span class="menu-text">Assets</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/SUPERADMIN/sidebar/quotations.php" class="menu-link<?php echo $isQuotations ? ' active' : ''; ?>">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon">
                        <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <path d="M7 4h10"></path>
                            <path d="M7 8h10"></path>
                            <path d="M7 12h6"></path>
                            <path d="M6 20h12a2 2 0 0 0 2-2V6l-4-4H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2z"></path>
                        </svg>
                    </span>
                    <span class="menu-mini-label">Quote</span>
                </span>
                <span class="menu-text">Quotations</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/SUPERADMIN/sidebar/reports.php" class="menu-link<?php echo $isReports ? ' active' : ''; ?>">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon">
                        <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <path d="M5 19h14"></path>
                            <path d="M7 16V9"></path>
                            <path d="M12 16V5"></path>
                            <path d="M17 16v-4"></path>
                        </svg>
                    </span>
                    <span class="menu-mini-label">Rpt</span>
                </span>
                <span class="menu-text">Reports</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/SUPERADMIN/sidebar/scan_history.php" class="menu-link<?php echo $isScanHistory ? ' active' : ''; ?>">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon">
                        <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <path d="M7 4H5a1 1 0 0 0-1 1v2"></path>
                            <path d="M17 4h2a1 1 0 0 1 1 1v2"></path>
                            <path d="M20 17v2a1 1 0 0 1-1 1h-2"></path>
                            <path d="M4 17v2a1 1 0 0 0 1 1h2"></path>
                            <path d="M7 12h10"></path>
                            <path d="M12 7v10"></path>
                        </svg>
                    </span>
                    <span class="menu-mini-label">Scan</span>
                </span>
                <span class="menu-text">Scan History</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/SUPERADMIN/sidebar/activity_history.php" class="menu-link<?php echo $isActivityHistory ? ' active' : ''; ?>">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon">
                        <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <path d="M12 8v5l3 2"></path>
                            <circle cx="12" cy="12" r="8"></circle>
                        </svg>
                    </span>
                    <span class="menu-mini-label">Audit</span>
                </span>
                <span class="menu-text">Activity History</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/SUPERADMIN/sidebar/projects.php?view=trash" class="menu-link<?php echo $isProjectsTrash ? ' active' : ''; ?>">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon">
                        <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <path d="M5 7h14"></path>
                            <path d="M9 7V5.5A1.5 1.5 0 0 1 10.5 4h3A1.5 1.5 0 0 1 15 5.5V7"></path>
                            <path d="M7 7l.8 11a2 2 0 0 0 2 1.85h4.4a2 2 0 0 0 2-1.85L17 7"></path>
                            <path d="M10 11v5"></path>
                            <path d="M14 11v5"></path>
                        </svg>
                    </span>
                    <span class="menu-mini-label">Trash</span>
                </span>
                <span class="menu-text">Trash Bin</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/LOGIN/php/logout.php" class="menu-link logout">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon">
                        <svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                            <path d="M10 5H6.5A1.5 1.5 0 0 0 5 6.5v11A1.5 1.5 0 0 0 6.5 19H10"></path>
                            <path d="M13 8l4 4-4 4"></path>
                            <path d="M9 12h8"></path>
                        </svg>
                    </span>
                    <span class="menu-mini-label">Exit</span>
                </span>
                <span class="menu-text">Logout</span>
            </a>
        </li>
    </ul>
</nav>
<div id="sidebarOverlay" class="sidebar-overlay"></div>
<header class="global-topbar" aria-live="polite">
    <a href="/codesamplecaps/SUPERADMIN/dashboards/super_admin_dashboard.php?tab=dashboard" class="global-topbar__copy global-topbar__brand-link" aria-label="Go to Super Admin overview">
        <img src="/codesamplecaps/IMAGES/edge.jpg" alt="Edge Automation logo" class="global-topbar__brand-logo">
        <strong>EDGE Automation</strong>
    </a>
    <div class="global-topbar__actions">
        <div class="topbar-profile" data-profile-root>
            <button 
                title="Account"
                id="topbarProfileToggle"
                class="topbar-profile__toggle"
                type="button"
                aria-label="Open profile menu"
                aria-controls="topbarProfileDropdown"
                aria-expanded="false"
            >
                <span class="topbar-profile__avatar-shell" aria-hidden="true">
                    <img src="<?php echo htmlspecialchars($superAdminProfilePhotoUrl); ?>" alt="Super admin profile picture" class="topbar-profile__avatar-image">
                    <span class="topbar-profile__avatar-fallback"><?php echo htmlspecialchars($superAdminProfileInitials); ?></span>
                    <span class="topbar-profile__chevron-badge">
                        <span class="topbar-profile__chevron" aria-hidden="true">
                            <svg viewBox="0 0 20 20" focusable="false">
                                <path d="M5 7.5 10 12.5 15 7.5"></path>
                            </svg>
                        </span>
                    </span>
                </span>
            </button>

            <div id="topbarProfileDropdown" class="topbar-profile__dropdown" hidden>
                <div class="topbar-profile__panel-head">
                    <span class="topbar-profile__avatar-shell topbar-profile__avatar-shell--panel" aria-hidden="true">
                        <img src="<?php echo htmlspecialchars($superAdminProfilePhotoUrl); ?>" alt="Super admin profile picture" class="topbar-profile__avatar-image topbar-profile__avatar-image--panel">
                        <span class="topbar-profile__avatar-fallback topbar-profile__avatar-fallback--panel"><?php echo htmlspecialchars($superAdminProfileInitials); ?></span>
                    </span>
                    <div>
                        <strong><?php echo htmlspecialchars($superAdminProfileName); ?></strong>
                        <span><?php echo htmlspecialchars($superAdminProfileRole); ?></span>
                    </div>
                </div>
                <div class="topbar-profile__links">
                    <a href="/codesamplecaps/SUPERADMIN/dashboards/super_admin_dashboard.php?tab=profile">Profile</a>
                    <a href="/codesamplecaps/SUPERADMIN/dashboards/super_admin_dashboard.php?tab=profile#security-settings">Settings</a>
                    <a href="/codesamplecaps/LOGIN/php/logout.php">Logout</a>
                </div>
            </div>
        </div>

        <div class="topbar-notifications" data-notification-root>
            <button
            title="Notifications"
                id="topbarNotificationToggle"
                class="topbar-notifications__toggle"
                type="button"
                aria-label="Open notifications"
                aria-controls="topbarNotificationDropdown"
                aria-expanded="false"
            >
                <span class="topbar-notifications__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" focusable="false">
                        <path d="M12 3a4 4 0 0 0-4 4v1.1a7 7 0 0 1-1.52 4.33L5 14.5V16h14v-1.5l-1.48-2.07A7 7 0 0 1 16 8.1V7a4 4 0 0 0-4-4Zm0 18a3 3 0 0 0 2.83-2H9.17A3 3 0 0 0 12 21Z" fill="currentColor"/>
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
                        <a href="/codesamplecaps/SUPERADMIN/sidebar/projects.php?status=ongoing" class="notification-summary-chip notification-summary-chip--danger">
                            <strong><?php echo (int)($superAdminNotificationData['project_risk_count'] ?? 0); ?></strong>
                            <span>Project risks</span>
                        </a>
                    </div>
                <?php endif; ?>

                <div class="topbar-notifications__section">
                    <div class="topbar-notifications__section-title">Needs attention</div>
                    <?php if (($superAdminNotificationData['urgent_count'] ?? 0) === 0): ?>
                        <div class="topbar-notifications__empty">
                            No project alerts right now.
                        </div>
                    <?php else: ?>
                        <?php foreach ($superAdminNotificationData['project_risk_alerts'] as $projectAlert): ?>
                            <a href="/codesamplecaps/SUPERADMIN/sidebar/project_details.php?id=<?php echo (int)($projectAlert['id'] ?? 0); ?>" class="notification-item notification-item--danger">
                                <span class="notification-item__dot"></span>
                                <div class="notification-item__copy">
                                    <strong><?php echo htmlspecialchars((string)$projectAlert['project_name']); ?></strong>
                                    <span>
                                        <?php
                                        $parts = [];
                                        if ((int)($projectAlert['delayed_tasks'] ?? 0) > 0) {
                                            $parts[] = (int)$projectAlert['delayed_tasks'] . ' delayed task(s)';
                                        }
                                        echo htmlspecialchars(implode(' | ', $parts) ?: 'Needs checking');
                                        ?>
                                    </span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            </div>
        </div>

        <div class="global-topbar__clock">
            <span class="global-topbar__clock-label">Philippines Time</span>
            <strong class="global-topbar__time" data-ph-time>--:--:--</strong>
            <span class="global-topbar__date" data-ph-date>Loading date...</span>
        </div>
    </div>
</header>
