<?php
require_once __DIR__ . '/../../config/auth_middleware.php';

auth_start_session();
auth_apply_no_cache_headers();

header('Content-Type: application/json');

$role = $_SESSION['role'] ?? null;
$dashboardPath = auth_dashboard_path_for_role(is_string($role) ? $role : null);
$isLoggedIn = $dashboardPath !== null
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
]);
