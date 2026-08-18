<?php
require_once __DIR__ . '/../../config/auth_middleware.php';

auth_start_session();
auth_apply_no_cache_headers();

header('Content-Type: application/json');

$timedOut = false;
$userId = $_SESSION['user_id'] ?? null;

if ($userId !== null) {
    $now = time();
    $lastActivity = (int)($_SESSION['last_activity_at'] ?? $now);

    // Kapag idle na ng 15 minutes, server mismo ang mag-iinvalidate ng session.
    if (($now - $lastActivity) > 900) {
        $timedOut = true;
        auth_destroy_session();
    } else {
        $_SESSION['last_activity_at'] = $now;
    }
}

$role = $_SESSION['role'] ?? null;
$dashboardPath = auth_dashboard_path_for_role(is_string($role) ? $role : null);
$isLoggedIn = !$timedOut
    && $dashboardPath !== null
    && auth_session_is_valid_for_roles([
        'super_admin',
        'admin',
        'inventory_clerk',
        'engineer',
        'foreman',
        'client',
    ]);

echo json_encode([
    'authenticated' => $isLoggedIn,
    'dashboard' => $isLoggedIn ? $dashboardPath : null,
    'timeout' => $timedOut,
]);
