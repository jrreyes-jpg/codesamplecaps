<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/inquiry_otp.php';
require_once __DIR__ . '/../../config/inquiry_contact_validation.php';
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
$email = strtolower(trim((string)($_POST['email'] ?? '')));
$isResend = (string)($_POST['resend'] ?? '') === '1';

if ($token === '' || empty($_SESSION['inquiry_form_token']) || !hash_equals((string)$_SESSION['inquiry_form_token'], $token)) {
    inquiry_otp_json(['success' => false, 'message' => 'Please refresh the form and try again.'], 403);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    inquiry_otp_json(['success' => false, 'message' => 'Enter a valid email address.'], 422);
}

// Hindi puwedeng gamitin ang staff email sa public client inquiry.
$userCheck = $conn->prepare('SELECT role FROM users WHERE deleted_at IS NULL AND LOWER(email) = ? LIMIT 1');
if (!$userCheck) {
    inquiry_otp_json(['success' => false, 'message' => 'Could not check the email. Try again.'], 500);
}
$userCheck->bind_param('s', $email);
$userCheck->execute();
$existingUser = $userCheck->get_result()->fetch_assoc();
if ($existingUser && strtolower((string)($existingUser['role'] ?? '')) !== 'client') {
    inquiry_otp_json(['success' => false, 'message' => 'This email cannot be used for a client inquiry.'], 422);
}

$lastSentAt = (int)($_SESSION['public_inquiry_otp_sent_at'] ?? 0);
$cooldown = inquiry_otp_resend_cooldown_seconds();
if ($lastSentAt > 0 && (time() - $lastSentAt) < $cooldown) {
    $expiresAt = (int)($_SESSION['public_inquiry_otp_expires_at'] ?? 0);
    inquiry_otp_json([
        'success' => false,
        'message' => 'Please wait before sending another code.',
        'retry_after' => $cooldown - (time() - $lastSentAt),
        'expires_in' => max(0, $expiresAt - time()),
    ], 429);
}

$resendCount = (int)($_SESSION['public_inquiry_otp_resend_count'] ?? 0);
if ($isResend && $resendCount >= inquiry_otp_max_resends()) {
    inquiry_otp_json(['success' => false, 'message' => 'Resend limit reached. Please refresh the form and try again.'], 429);
}

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
$_SESSION['public_inquiry_otp_resend_count'] = $isResend ? $resendCount + 1 : 0;
unset($_SESSION['public_inquiry_email_verified']);

inquiry_otp_json([
    'success' => true,
    'message' => 'Verification code sent.',
    'expires_in' => $validity,
    'resend_after' => $cooldown,
]);
