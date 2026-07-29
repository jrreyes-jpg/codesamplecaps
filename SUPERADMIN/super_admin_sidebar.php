<?php
require_once __DIR__ . '/includes/superadmin_helpers.php';

$currentPath = str_replace('\\', '/', parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
$currentQuery = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY) ?? '';

$isDashboardPage = str_contains($currentPath, '/SUPERADMIN/dashboards/super_admin_dashboard.php');
$isOverviewPage = false;
$isUserManagementPage = str_contains($currentPath, '/SUPERADMIN/sidebar/user_management.php');
$isDashboard = false;
$isCreate = $isDashboardPage && str_contains($currentQuery, 'tab=create');
$isCreate = $isCreate || ($isUserManagementPage && str_contains($currentQuery, 'create=1'));
$isUsers = $isUserManagementPage || ($isDashboardPage && (str_contains($currentQuery, 'tab=users') || $isCreate));
$isActivityHistory = str_contains($currentPath, '/SUPERADMIN/sidebar/activity_history.php');
$isRolesPermissions = str_contains($currentPath, '/SUPERADMIN/sidebar/roles_permissions.php');
$isSecuritySettings = str_contains($currentPath, '/SUPERADMIN/sidebar/security_settings.php');
$isSystemSettings = str_contains($currentPath, '/SUPERADMIN/sidebar/system_settings.php');
$isBackupRestore = str_contains($currentPath, '/SUPERADMIN/sidebar/backup_restore.php');
$superAdminProfileName = (string)($_SESSION['name'] ?? 'Super Admin');
$superAdminProfileRole = ucfirst(str_replace('_', ' ', (string)($_SESSION['role'] ?? 'super_admin')));
$superAdminProfilePhotoUrl = '';
$superAdminProfileInitials = '';

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

if ($superAdminProfilePhotoUrl === '') {
    $superAdminProfilePhotoUrl = build_default_profile_avatar_data_uri();
}

$superAdminProfileInitials = super_admin_profile_initials($superAdminProfileName);
?>
<script>
try {
    if (window.innerWidth > 900 && window.localStorage.getItem('edgeSidebarCollapsed') === '1') {
        document.documentElement.classList.add('superadmin-sidebar-collapsed');
    }
} catch (error) {}
</script>
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
            <a href="/codesamplecaps/SUPERADMIN/sidebar/user_management.php" class="menu-link<?php echo $isUsers ? ' active' : ''; ?>">
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
                <span class="menu-text">Audit Logs</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/SUPERADMIN/sidebar/roles_permissions.php" class="menu-link<?php echo $isRolesPermissions ? ' active' : ''; ?>">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon"><svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M12 3l7 4v5c0 5-3 8-7 9-4-1-7-4-7-9V7l7-4z"></path><path d="M9 12l2 2 4-4"></path></svg></span>
                    <span class="menu-mini-label">Role</span>
                </span>
                <span class="menu-text">Roles & Permissions</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/SUPERADMIN/sidebar/security_settings.php" class="menu-link<?php echo $isSecuritySettings ? ' active' : ''; ?>">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon"><svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path></svg></span>
                    <span class="menu-mini-label">Sec</span>
                </span>
                <span class="menu-text">Security Settings</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/SUPERADMIN/sidebar/system_settings.php" class="menu-link<?php echo $isSystemSettings ? ' active' : ''; ?>">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon"><svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.8 1.8 0 0 0 .36 2l.05.05-2.12 2.12-.05-.05a1.8 1.8 0 0 0-2-.36 1.8 1.8 0 0 0-1.1 1.66V20h-3v-.08A1.8 1.8 0 0 0 10.4 18.3a1.8 1.8 0 0 0-2 .36l-.05.05-2.12-2.12.05-.05a1.8 1.8 0 0 0 .36-2A1.8 1.8 0 0 0 5 13.5H4v-3h1a1.8 1.8 0 0 0 1.66-1.1 1.8 1.8 0 0 0-.36-2l-.05-.05 2.12-2.12.05.05a1.8 1.8 0 0 0 2 .36A1.8 1.8 0 0 0 11.5 4h3a1.8 1.8 0 0 0 1.1 1.66 1.8 1.8 0 0 0 2-.36l.05-.05 2.12 2.12-.05.05a1.8 1.8 0 0 0-.36 2A1.8 1.8 0 0 0 21 10.5h1v3h-1A1.8 1.8 0 0 0 19.4 15z"></path></svg></span>
                    <span class="menu-mini-label">Sys</span>
                </span>
                <span class="menu-text">System Settings</span>
            </a>
        </li>
        <li>
            <a href="/codesamplecaps/SUPERADMIN/sidebar/backup_restore.php" class="menu-link<?php echo $isBackupRestore ? ' active' : ''; ?>">
                <span class="menu-visual" aria-hidden="true">
                    <span class="menu-icon"><svg class="menu-icon-svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M4 7h16v12H4z"></path><path d="M8 7V5h8v2"></path><path d="M12 11v5"></path><path d="M9.5 13.5 12 16l2.5-2.5"></path></svg></span>
                    <span class="menu-mini-label">Bak</span>
                </span>
                <span class="menu-text">Backup & Restore</span>
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
    <a href="/codesamplecaps/SUPERADMIN/sidebar/user_management.php" class="global-topbar__copy global-topbar__brand-link" aria-label="Go to Super Admin user management">
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
                        <a href="/codesamplecaps/SUPERADMIN/sidebar/activity_history.php" class="notification-summary-chip notification-summary-chip--danger">
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
                            <a href="/codesamplecaps/SUPERADMIN/sidebar/activity_history.php" class="notification-item notification-item--danger">
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

