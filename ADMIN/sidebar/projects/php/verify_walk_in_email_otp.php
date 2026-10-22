<?php

require_once __DIR__ . '/../../../../config/auth_middleware.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/inquiry_otp.php';
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

if (!inquiry_otp_prepare_rate_limit_table($conn)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not check the code. Try again.']);
    exit();
}

$verificationLock = inquiry_otp_rate_status($conn, 'walk_in_email_verify', $email);
if (!$verificationLock['ok']) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => 'Too many wrong codes. Try again after 15 minutes.',
        'retry_after' => (int)$verificationLock['retry_after'],
        'locked' => true,
    ]);
    exit();
}

$result = walk_in_email_verification_verify($email, $otp);
if (!$result['valid']) {
    $state = $_SESSION[walk_in_email_verification_key()] ?? [];
    $attempts = (int)($state['attempts'] ?? 0);
    if ($attempts >= inquiry_otp_max_attempts()) {
        inquiry_otp_lock_scope($conn, 'walk_in_email_verify', $email, inquiry_otp_lock_seconds());
        walk_in_email_verification_clear();
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'message' => 'Too many wrong codes. Email verification is locked for 15 minutes.',
            'retry_after' => inquiry_otp_lock_seconds(),
            'locked' => true,
        ]);
        exit();
    }

    echo json_encode([
        'success' => false,
        'message' => (string)$result['message'],
        'attempts_left' => max(0, inquiry_otp_max_attempts() - $attempts),
    ]);
    exit();
}

inquiry_otp_clear_scope($conn, 'walk_in_email_verify', $email);
echo json_encode(['success' => true, 'message' => (string)$result['message']]);

