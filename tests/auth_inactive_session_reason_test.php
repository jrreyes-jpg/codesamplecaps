<?php
ini_set('session.save_path', sys_get_temp_dir());
require_once __DIR__ . '/../config/auth_middleware.php';

function auth_regression_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
        exit(1);
    }
}

auth_start_session();
$_SESSION = [];
$_SESSION['auth_session_end_reason'] = 'inactive';
$_SESSION['login_flash'] = [
    'error' => 'Your account is inactive. Please contact the administrator.',
    'class' => 'error-warning error-account-inactive',
];

auth_regression_assert(
    auth_pending_session_end_reason() === 'inactive',
    'First auth status check must return inactive.'
);
auth_regression_assert(
    auth_pending_session_end_reason() === 'inactive',
    'Second auth status check must still return inactive.'
);
auth_regression_assert(
    auth_login_flash_is_inactive($_SESSION['login_flash']),
    'Inactive flash must take priority over a logout query.'
);
$logoutQueryPresent = true;
$selectedNotice = auth_login_flash_is_inactive($_SESSION['login_flash'])
    ? 'inactive'
    : ($logoutQueryPresent ? 'logout' : 'none');
auth_regression_assert(
    $selectedNotice === 'inactive',
    'A logout query must not replace the server inactive warning.'
);

unset($_SESSION['login_flash']);
auth_consume_session_end_reason('inactive');

auth_regression_assert(
    auth_pending_session_end_reason() === null,
    'Login rendering must consume the pending inactive reason.'
);

auth_destroy_session();
fwrite(STDOUT, 'PASS: inactive session reason survives repeated checks and is consumed on login render.' . PHP_EOL);
