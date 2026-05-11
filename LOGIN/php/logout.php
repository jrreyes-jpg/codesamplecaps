<?php
require_once __DIR__ . '/../../config/auth_middleware.php';

auth_apply_logout_headers();
auth_destroy_session();
header('Location: login.php?logout=1');
exit();
