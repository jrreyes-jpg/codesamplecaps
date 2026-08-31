<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_role('super_admin');

$tab = (string)($_GET['tab'] ?? '');

if ($tab === 'profile') {
    header('Location: /codesamplecaps/SUPERADMIN/sidebar/account_settings.php');
    exit;
}

$location = '/codesamplecaps/SUPERADMIN/sidebar/user_management.php';
if ($tab === 'create') {
    $location .= '?create=1';
}

header('Location: ' . $location);
exit;
