<?php
define('AUTH_REQUIRED_ROLE', 'foreman');
require_once __DIR__ . '/../../config/auth_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../SHARED/account_settings/php/account_settings_actions.php';
require_once __DIR__ . '/../../SHARED/account_settings/php/account_settings_form.php';

$account = shared_account_settings_context($conn, [
    'role' => 'foreman',
    'csrf_scope' => 'foreman_account',
    'redirect_path' => '/codesamplecaps/FOREMAN/dashboards/account_settings.php',
    'flash_key' => 'foreman_account_flash',
    'default_name' => 'Foreman',
    'default_photo_url' => '/codesamplecaps/IMAGES/nodp.jpg',
]);
$activeSection = (string)($account['section'] ?? 'profile');
$foremanProfileName = (string)($account['fullName'] ?? 'Foreman');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings - Foreman</title>
    <script src="/codesamplecaps/SHARED/sidebar/js/sidebar-state.js"></script>
    <link rel="stylesheet" href="/codesamplecaps/SHARED/sidebar/css/sidebar.css">
    <link rel="stylesheet" href="/codesamplecaps/FOREMAN/css/foreman_dashboard.css">
    <link rel="stylesheet" href="/codesamplecaps/SHARED/account_settings/css/account-settings.css">
    <link rel="icon" type="image/png" href="/codesamplecaps/IMAGES/edge-favicon.png?v=1">
</head>
<body>
<?php include __DIR__ . '/../sidebar/sidebar_foreman.php'; ?>

<main class="main-content account-settings-content">
    <div class="page-shell account-settings-page">
        <header class="account-settings-page__heading">
            <div>
                <h1>Account Settings</h1>
                <p>Update your profile photo, details, and password.</p>
            </div>
        </header>
        <nav class="account-settings-tabs" aria-label="Account settings sections">
            <a class="<?php echo $activeSection === 'profile' ? 'is-active' : ''; ?>" href="/codesamplecaps/FOREMAN/dashboards/account_settings.php?section=profile">Profile Details</a>
            <a class="<?php echo $activeSection === 'security' ? 'is-active' : ''; ?>" href="/codesamplecaps/FOREMAN/dashboards/account_settings.php?section=security">Security</a>
        </nav>
        <?php shared_account_settings_render($account); ?>
    </div>
</main>

<script src="/codesamplecaps/SHARED/sidebar/js/sidebar.js"></script>
<script src="/codesamplecaps/FOREMAN/js/sidebar_foreman.js"></script>
<script src="/codesamplecaps/SHARED/account_settings/js/account-settings.js"></script>
</body>
</html>
