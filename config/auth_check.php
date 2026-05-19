<?php
// Reusable protected-page entry point.
//
// Usage:
// define('AUTH_REQUIRED_ROLE', 'engineer');
// require_once __DIR__ . '/../../config/auth_check.php';
//
// Or:
// define('AUTH_ALLOWED_ROLES', ['super_admin', 'engineer']);
// require_once __DIR__ . '/../../config/auth_check.php';

require_once __DIR__ . '/auth_middleware.php';

if (defined('AUTH_REQUIRED_ROLE')) {
    require_role((string)AUTH_REQUIRED_ROLE);
    return;
}

if (defined('AUTH_ALLOWED_ROLES') && is_array(AUTH_ALLOWED_ROLES)) {
    require_any_role(AUTH_ALLOWED_ROLES);
    return;
}

require_any_role(['super_admin', 'engineer', 'foreman', 'client']);
