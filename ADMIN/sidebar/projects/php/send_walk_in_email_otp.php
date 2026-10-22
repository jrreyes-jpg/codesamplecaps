<?php

require_once __DIR__ . '/../../../../config/auth_middleware.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/inquiry_otp.php';
require_once __DIR__ . '/../../../../config/inquiry_contact_validation.php';
require_once __DIR__ . '/../../../../config/inquiry_name_validation.php';
require_once __DIR__ . '/../../../../config/walk_in_email_verification.php';
require_once __DIR__ . '/../../../../services/EmailService.php';

require_role('admin');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'super_admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security check failed.']);
    exit();
}

$name = trim((string)($_POST['client_name'] ?? ''));
$email = mb_strtolower(trim((string)($_POST['email'] ?? '')), 'UTF-8');
$phone = normalize_ph_mobile(trim((string)($_POST['contact_no'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Enter a valid email first.']);
    exit();
}
if (!inquiry_name_is_valid($name)) {
    echo json_encode(['success' => false, 'field' => 'name', 'message' => 'Enter a valid full name.']);
    exit();
}
if (!is_valid_ph_mobile($phone)) {
    echo json_encode(['success' => false, 'field' => 'phone', 'message' => 'Enter a valid contact number.']);
    exit();
}

$contactValidation = inquiry_contact_validation_result($conn, $name, $email, $phone);
if (empty($contactValidation['valid'])) {
    $contactMessages = [
        'contact_not_allowed' => 'This contact belongs to a staff account.',
        'email_mismatch' => 'Email already used. Use another email.',
        'phone_mismatch' => 'Number already used. Use another number.',
        'contact_mismatch' => 'Email and number do not match.',
        'name_mismatch' => 'Name does not match this contact.',
    ];
    echo json_encode([
        'success' => false,
        'field' => (string)($contactValidation['field'] ?? ''),
        'message' => $contactMessages[$contactValidation['status'] ?? ''] ?? 'Check the contact details.',
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

// Huwag padalhan ng client OTP ang email ng staff.
$accountStmt = $conn->prepare('SELECT role, status FROM users WHERE deleted_at IS NULL AND LOWER(email) = ? LIMIT 1');
if (!$accountStmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to check this email right now.']);
    exit();
}
$accountStmt->bind_param('s', $email);
$accountStmt->execute();
$account = $accountStmt->get_result()->fetch_assoc();
if ($account && strtolower((string)($account['role'] ?? '')) !== 'client') {
    echo json_encode(['success' => false, 'message' => 'This email belongs to a staff account.']);
    exit();
}

$resendWait = walk_in_email_verification_resend_wait($email);
if ($resendWait > 0) {
    echo json_encode(['success' => false, 'message' => 'Wait ' . $resendWait . ' seconds before sending another code.']);
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
    'message' => 'Code sent.',
    'expires_in' => $validSeconds,
    'resend_after' => inquiry_otp_resend_cooldown_seconds(),
]);
