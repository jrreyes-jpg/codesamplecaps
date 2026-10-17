<?php

require_once __DIR__ . '/../../../../config/auth_middleware.php';
require_once __DIR__ . '/../../../../config/walk_in_email_verification.php';

require_role('admin');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'super_admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security check failed.']);
    exit();
}

$email = trim((string)($_POST['email'] ?? ''));
$otp = trim((string)($_POST['otp'] ?? ''));
if (!preg_match('/^\d{6}$/', $otp)) {
    echo json_encode(['success' => false, 'message' => 'Enter the 6-digit code.']);
    exit();
}

$result = walk_in_email_verification_verify($email, $otp);
echo json_encode(['success' => (bool)$result['valid'], 'message' => (string)$result['message']]);

