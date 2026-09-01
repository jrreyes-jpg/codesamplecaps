<?php
define('AUTH_REQUIRED_ROLE', 'engineer');
require_once __DIR__ . '/../../config/auth_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../includes/account_settings_actions.php';
require_once __DIR__ . '/../../SHARED/account_settings/php/account_settings_form.php';

$account = engineer_account_context($conn);
$engineerPageTitle = 'Account Settings - Engineer';
$engineerCssFiles = ['/codesamplecaps/SHARED/account_settings/css/account-settings.css'];
require __DIR__ . '/../layout/header.php';
?>
<?php include __DIR__ . '/../../SHARED/sidebar/php/sidebar.php'; ?>

<div class="main-content">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <main class="engineer-page-body">
        <?php shared_account_settings_render($account); ?>
    </main>
</div>

<?php
$engineerJsFiles = ['/codesamplecaps/SHARED/account_settings/js/account-settings.js'];
require __DIR__ . '/../layout/footer.php';
?>
