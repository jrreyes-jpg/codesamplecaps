<?php
require_once __DIR__ . '/../includes/page_shell.php';
require_once __DIR__ . '/../includes/account_settings_actions.php';
require_once __DIR__ . '/../../SHARED/account_settings/php/account_settings_form.php';

$account = superadmin_account_context($conn);

superadmin_render_page(
    'Account Settings',
    function () use ($account): void {
        shared_account_settings_render($account);
    },
    ['/codesamplecaps/SHARED/account_settings/css/account-settings.css'],
    ['/codesamplecaps/SHARED/account_settings/js/account-settings.js'],
    'account-settings-content'
);
