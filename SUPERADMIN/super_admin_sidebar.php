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
$isActivityHistory = str_contains($currentPath, '/SUPERADMIN/sidebar/audit_logs.php');
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
        if ($profilePhotoPath !== '' && profile_photo_resolve_absolute_path($profilePhotoPath) !== null) {
            $superAdminProfilePhotoUrl = profile_photo_public_url($profilePhotoPath);
        }
    }
}

$superAdminProfileInitials = super_admin_profile_initials($superAdminProfileName);
?>
<?php include __DIR__ . '/../SHARED/sidebar/php/sidebar.php'; ?>
<?php ob_start(); ?>
<?php
$headerProfileName = $superAdminProfileName;
$headerProfileRole = $superAdminProfileRole;
$headerProfilePhotoUrl = $superAdminProfilePhotoUrl;
$headerProfileInitials = $superAdminProfileInitials;
$headerProfileAlt = 'Super Admin profile picture';
$headerProfileLinks = [
    ['label' => 'Profile', 'href' => '/codesamplecaps/SUPERADMIN/sidebar/account_settings.php'],
    ['label' => 'Settings', 'href' => '/codesamplecaps/SUPERADMIN/sidebar/account_settings.php#security-settings'],
    ['label' => 'Logout', 'href' => '/codesamplecaps/LOGIN/php/logout.php'],
];
include __DIR__ . '/../SHARED/header/profile/php/profile.php';
?>

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
                        <a href="/codesamplecaps/SUPERADMIN/sidebar/audit_logs.php" class="notification-summary-chip notification-summary-chip--danger">
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
                            <a href="/codesamplecaps/SUPERADMIN/sidebar/audit_logs.php" class="notification-item notification-item--danger">
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

<?php
$operationsHeaderActionsHtml = ob_get_clean();
$operationsHeaderRole = 'super_admin';
$operationsHeaderClass = 'global-topbar';
$operationsHeaderBrandClass = 'global-topbar__copy global-topbar__brand-link';
$operationsHeaderActionsClass = 'global-topbar__actions';
$operationsHeaderClockClass = 'global-topbar__clock';
$operationsHeaderHomeHref = '/codesamplecaps/SUPERADMIN/sidebar/user_management.php';
$operationsHeaderBrandText = 'EDGE Automation';
$operationsHeaderLogoClass = 'global-topbar__brand-logo operations-topbar__brand-logo';
$operationsHeaderBrandLabel = 'Go to Super Admin user management';
$operationsHeaderTime = '--:--:--';
$operationsHeaderDate = 'Loading date...';
$operationsHeaderTimeAttr = 'class="global-topbar__time" data-ph-time';
$operationsHeaderDateAttr = 'class="global-topbar__date" data-ph-date';
$operationsHeaderAttrs = 'aria-live="polite"';
include __DIR__ . '/../SHARED/header/core/operations-header.php';
?>


