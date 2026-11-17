<?php

require_once __DIR__ . '/../../../../config/auth_middleware.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/inquiry_otp.php';
require_once __DIR__ . '/../../../../config/inquiry_contact_validation.php';
require_once __DIR__ . '/../../../../config/walk_in_email_verification.php';
require_once __DIR__ . '/../../../../services/EmailService.php';

require_role('admin');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'super_admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security check failed.']);
    exit();
}

$name = trim((string)($_POST['client_name'] ?? '')) ?: 'Client';
$email = mb_strtolower(trim((string)($_POST['email'] ?? '')), 'UTF-8');
$phone = normalize_ph_mobile(trim((string)($_POST['contact_no'] ?? '')));
if (!inquiry_email_is_valid_format($email)) {
    echo json_encode(['success' => false, 'message' => inquiry_email_invalid_message()]);
    exit();
}
$contactValidation = inquiry_contact_validation_result($conn, $name, $email, $phone);
if (empty($contactValidation['valid'])) {
    echo json_encode([
        'success' => false,
        'field' => (string)($contactValidation['field'] ?? ''),
        'message' => inquiry_contact_validation_message($contactValidation),
    ]);
    exit();
}

if (!inquiry_otp_prepare_rate_limit_table($conn)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not start email verification. Try again.']);
    exit();
}

$verificationLock = inquiry_otp_rate_status($conn, 'walk_in_email_verify', $email);
if (!$verificationLock['ok']) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => 'Email verification is locked for 15 minutes. Please try again later.',
        'retry_after' => (int)$verificationLock['retry_after'],
        'locked' => true,
    ]);
    exit();
}

$resendWait = walk_in_email_verification_resend_wait($email);
if ($resendWait > 0) {
    $pendingState = $_SESSION[walk_in_email_otp_pending_key()] ?? [];
    echo json_encode([
        'success' => true,
        'status' => 'already_sent',
        'email' => $email,
        'message' => 'A code was already sent. Enter it below.',
        'expires_in' => max(0, (int)($pendingState['expires_at'] ?? 0) - time()),
        'resend_after' => $resendWait,
    ]);
    exit();
}

$emailLimit = inquiry_otp_consume_rate_limit(
    $conn,
    'walk_in_email_send',
    $email,
    inquiry_otp_email_send_limit(),
    inquiry_otp_email_window_seconds(),
    inquiry_otp_lock_seconds()
);
if (!$emailLimit['ok']) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => 'Too many code requests. Email verification is locked for 15 minutes.',
        'retry_after' => (int)$emailLimit['retry_after'],
        'locked' => true,
    ]);
    exit();
}

$ipLimit = inquiry_otp_consume_rate_limit(
    $conn,
    'walk_in_ip_send',
    inquiry_otp_client_ip(),
    inquiry_otp_ip_send_limit(),
    inquiry_otp_ip_window_seconds(),
    inquiry_otp_lock_seconds()
);
if (!$ipLimit['ok']) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => 'Too many code requests. Email verification is locked for 15 minutes.',
        'retry_after' => (int)$ipLimit['retry_after'],
        'locked' => true,
    ]);
    exit();
}

$otp = (string)random_int(100000, 999999);
$validSeconds = inquiry_otp_validity_seconds();
$expiresAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->modify('+' . $validSeconds . ' seconds');

if (!(new EmailService())->sendInquiryOtp($email, $name, $otp, $expiresAt)) {
    echo json_encode(['success' => false, 'message' => 'Email cannot be sent right now.']);
    exit();
}

walk_in_email_verification_start($email, $otp, $validSeconds);
echo json_encode([
    'success' => true,
    'status' => 'sent',
    'email' => $email,
    'message' => 'Code sent.',
    'expires_in' => $validSeconds,
    'resend_after' => inquiry_otp_resend_cooldown_seconds(),
]);
