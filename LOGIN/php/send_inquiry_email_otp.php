<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/inquiry_otp.php';
require_once __DIR__ . '/../../config/inquiry_contact_validation.php';
require_once __DIR__ . '/../../config/inquiry_email_verification.php';
require_once __DIR__ . '/../../services/EmailService.php';

function inquiry_otp_json(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    inquiry_otp_json(['success' => false, 'message' => 'Invalid request.'], 405);
}

$token = trim((string)($_POST['inquiry_form_token'] ?? ''));
$name = trim((string)($_POST['client_name'] ?? '')) ?: 'Client';
$email = strtolower(trim((string)($_POST['email'] ?? '')));
$phone = normalize_ph_mobile(trim((string)($_POST['contact_no'] ?? '')));
$isResend = (string)($_POST['resend'] ?? '') === '1';

if ($token === '' || empty($_SESSION['inquiry_form_token']) || !hash_equals((string)$_SESSION['inquiry_form_token'], $token)) {
    inquiry_otp_json(['success' => false, 'message' => 'Please refresh the form and try again.'], 403);
}

if (!inquiry_email_is_valid_format($email)) {
    inquiry_otp_json(['success' => false, 'message' => inquiry_email_invalid_message()], 422);
}
$contactValidation = inquiry_contact_validation_result($conn, $name, $email, $phone);
if (empty($contactValidation['valid'])) {
    inquiry_otp_json([
        'success' => false,
        'field' => (string)($contactValidation['field'] ?? ''),
        'message' => inquiry_contact_validation_message($contactValidation),
    ], 422);
}

if (!inquiry_otp_prepare_rate_limit_table($conn)) {
    inquiry_otp_json(['success' => false, 'message' => 'Could not start email verification. Try again.'], 500);
}

$verificationLock = inquiry_otp_rate_status($conn, 'email_verify', $email);
if (!$verificationLock['ok']) {
    inquiry_otp_json([
        'success' => false,
        'message' => 'Email verification is locked for 15 minutes. Please try again later.',
        'retry_after' => (int)$verificationLock['retry_after'],
        'locked' => true,
    ], 429);
}

$lastSentAt = (int)($_SESSION['public_inquiry_otp_sent_at'] ?? 0);
$expiresAt = (int)($_SESSION['public_inquiry_otp_expires_at'] ?? 0);
$pendingEmail = strtolower((string)($_SESSION['public_inquiry_otp_email'] ?? ''));
$hasPendingCode = !empty($_SESSION['public_inquiry_otp_token']) && $pendingEmail === $email;
$cooldown = inquiry_otp_resend_cooldown_seconds();

// Kapag binuksan ulit, gamitin pa rin ang dating OTP state.
// Resend lang ang puwedeng gumawa ng bagong code.
if (!$isResend && $hasPendingCode) {
    inquiry_otp_json([
        'success' => true,
        'status' => 'already_sent',
        'email' => $email,
        'message' => $expiresAt > time()
            ? 'A code was already sent. Enter it below.'
            : 'Code expired. Resend a new code.',
        'existing_code' => true,
        'expired' => $expiresAt <= time(),
        'expires_in' => max(0, $expiresAt - time()),
        'resend_after' => max(0, $cooldown - (time() - $lastSentAt)),
    ]);
}

if ($lastSentAt > 0 && (time() - $lastSentAt) < $cooldown) {
    inquiry_otp_json([
        'success' => false,
        'message' => 'Please wait before sending another code.',
        'retry_after' => $cooldown - (time() - $lastSentAt),
        'expires_in' => max(0, $expiresAt - time()),
    ], 429);
}

$emailLimit = inquiry_otp_consume_rate_limit(
    $conn,
    'email_send',
    $email,
    inquiry_otp_email_send_limit(),
    inquiry_otp_email_window_seconds(),
    inquiry_otp_lock_seconds()
);
if (!$emailLimit['ok']) {
    inquiry_otp_json([
        'success' => false,
        'message' => 'Too many code requests. Email verification is locked for 15 minutes.',
        'retry_after' => (int)$emailLimit['retry_after'],
        'locked' => true,
    ], 429);
}

$ipLimit = inquiry_otp_consume_rate_limit(
    $conn,
    'ip_send',
    inquiry_otp_client_ip(),
    inquiry_otp_ip_send_limit(),
    inquiry_otp_ip_window_seconds(),
    inquiry_otp_lock_seconds()
);
if (!$ipLimit['ok']) {
    inquiry_otp_json([
        'success' => false,
        'message' => 'Too many code requests. Email verification is locked for 15 minutes.',
        'retry_after' => (int)$ipLimit['retry_after'],
        'locked' => true,
    ], 429);
}

$resendCount = (int)($_SESSION['public_inquiry_otp_resend_count'] ?? 0);

$otp = (string)random_int(100000, 999999);
$pendingToken = bin2hex(random_bytes(32));
$validity = inquiry_otp_validity_seconds();
$otpHash = password_hash($otp, PASSWORD_DEFAULT);
$payload = json_encode(['email' => $email], JSON_UNESCAPED_SLASHES);

$insert = $conn->prepare(
    'INSERT INTO pending_service_inquiries (token, otp_hash, payload_json, expires_at)
     VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . $validity . ' SECOND))'
);
if (!$insert) {
    inquiry_otp_json(['success' => false, 'message' => 'Could not prepare verification. Try again.'], 500);
}

$insert->bind_param('sss', $pendingToken, $otpHash, $payload);
if (!$insert->execute()) {
    inquiry_otp_json(['success' => false, 'message' => 'Could not prepare verification. Try again.'], 500);
}

$expiresAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->modify('+' . $validity . ' seconds');
if (!(new EmailService())->sendInquiryOtp($email, 'Client', $otp, $expiresAt)) {
    inquiry_otp_json(['success' => false, 'message' => 'Could not send the code. Check your internet and try again.'], 502);
}

$_SESSION['public_inquiry_otp_token'] = $pendingToken;
$_SESSION['public_inquiry_otp_sent_at'] = time();
$_SESSION['public_inquiry_otp_expires_at'] = time() + $validity;
$_SESSION['public_inquiry_otp_email'] = $email;
$_SESSION['public_inquiry_otp_resend_count'] = $isResend ? $resendCount + 1 : 0;
inquiry_otp_json([
    'success' => true,
    'status' => 'sent',
    'email' => $email,
    'message' => 'Verification code sent.',
    'expires_in' => $validity,
    'resend_after' => $cooldown,
]);
