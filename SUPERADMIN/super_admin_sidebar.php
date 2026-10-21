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
$superAdminProfileName = (string)($_SESSION['name'] ?? 'Super Admin');
$superAdminProfileRole = ucfirst(str_replace('_', ' ', (string)($_SESSION['role'] ?? 'super_admin')));
$superAdminProfilePhotoUrl = '';
$superAdminProfileInitials = '';
$superAdminUserId = (int)($_SESSION['user_id'] ?? ($_SESSION['id'] ?? 0));

if (
    isset($conn)
    && $conn instanceof mysqli
    && super_admin_sidebar_column_exists($conn, 'users', 'profile_photo_path')
    && $superAdminUserId > 0
) {
    $profileStmt = $conn->prepare('SELECT profile_photo_path FROM users WHERE id = ? LIMIT 1');
    if ($profileStmt) {
        $profileStmt->bind_param('i', $superAdminUserId);
        $profileStmt->execute();
        $profileResult = $profileStmt->get_result();
        $profileRow = $profileResult ? $profileResult->fetch_assoc() : null;
        $profilePhotoPath = profile_photo_migrate_legacy_reference(
            $conn,
            $superAdminUserId,
            $profileRow['profile_photo_path'] ?? null
        );
        $profilePhotoPath = trim((string)$profilePhotoPath);
        if ($profilePhotoPath !== '') {
            $superAdminProfilePhotoUrl = profile_photo_public_url($profilePhotoPath, $superAdminUserId);
        }
    }
}

if ($superAdminProfilePhotoUrl === '') {
    $superAdminProfilePhotoUrl = build_default_profile_avatar_data_uri();
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
    ['label' => 'Profile', 'href' => '/codesamplecaps/SUPERADMIN/sidebar/account_settings.php?section=profile'],
    ['label' => 'Settings', 'href' => '/codesamplecaps/SUPERADMIN/sidebar/account_settings.php?section=security'],
    ['label' => 'Logout', 'href' => '/codesamplecaps/LOGIN/php/logout.php'],
];
ob_start();
include __DIR__ . '/../SHARED/header/profile/php/profile.php';
$operationsHeaderProfileHtml = ob_get_clean();
?>

<?php
echo $operationsHeaderProfileHtml;
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


