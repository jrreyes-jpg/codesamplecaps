<?php
require_once __DIR__ . '/../../config/auth_middleware.php';

$isTimeout = isset($_GET['timeout']) && $_GET['timeout'] === '1';

auth_apply_logout_headers();
auth_destroy_session();
header('Location: login.php?' . ($isTimeout ? 'timeout=1' : 'logout=1'));
exit();
