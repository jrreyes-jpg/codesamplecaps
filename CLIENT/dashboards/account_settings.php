<?php
define('AUTH_REQUIRED_ROLE', 'client');
require_once __DIR__ . '/../../config/auth_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../SHARED/account_settings/php/account_settings_actions.php';
require_once __DIR__ . '/../../SHARED/account_settings/php/account_settings_form.php';
require_once __DIR__ . '/../includes/client_shell.php';

$account = shared_account_settings_context($conn, [
    'role' => 'client',
    'csrf_scope' => 'client_account',
    'redirect_path' => '/codesamplecaps/CLIENT/dashboards/account_settings.php',
    'flash_key' => 'client_account_flash',
    'default_name' => 'Client User',
    'default_photo_url' => '/codesamplecaps/IMAGES/nodp.jpg',
]);
$activeSection = (string)($account['section'] ?? 'profile');
$clientName = (string)($account['fullName'] ?? 'Client User');
$clientEmailDisplay = (string)($account['email'] ?? '');
$shellContext = client_shell_build_topbar_context($conn, (int)($_SESSION['user_id'] ?? 0), $clientName, $clientEmailDisplay);

$clientPageTitle = 'Account Settings - Client';
$clientCssFiles = [
    '/codesamplecaps/CLIENT/css/client_dashboard.css',
    '/codesamplecaps/SHARED/account_settings/css/account-settings.css',
];
$clientJsFiles = ['/codesamplecaps/SHARED/account_settings/js/account-settings.js'];
require_once __DIR__ . '/../layout/header.php';
?>

<?php include __DIR__ . '/../sidebar/client_sidebar.php'; ?>
<main class="main-content account-settings-content" id="mainContent">
    <?php client_shell_render_topbar($shellContext); ?>
    <section class="account-settings-page">
        <header class="account-settings-page__heading">
            <div>
                <h1>Account Settings</h1>
                <p>Update your profile photo, details, and password.</p>
            </div>
        </header>
        <nav class="account-settings-tabs" aria-label="Account settings sections">
            <a class="<?php echo $activeSection === 'profile' ? 'is-active' : ''; ?>" href="/codesamplecaps/CLIENT/dashboards/account_settings.php?section=profile">Profile Details</a>
            <a class="<?php echo $activeSection === 'security' ? 'is-active' : ''; ?>" href="/codesamplecaps/CLIENT/dashboards/account_settings.php?section=security">Security</a>
        </nav>
        <?php shared_account_settings_render($account); ?>
    </section>
</main>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
