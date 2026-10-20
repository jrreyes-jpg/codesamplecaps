<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/inquiry_otp.php';
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

if ($token === '' || empty($_SESSION['inquiry_form_token']) || !hash_equals((string)$_SESSION['inquiry_form_token'], $token)) {
    inquiry_otp_json(['success' => false, 'message' => 'Please refresh the form and try again.'], 403);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    inquiry_otp_json(['success' => false, 'message' => 'Enter a valid email address.'], 422);
}

$lastSentAt = (int)($_SESSION['public_inquiry_otp_sent_at'] ?? 0);
$cooldown = inquiry_otp_resend_cooldown_seconds();
if ($lastSentAt > 0 && (time() - $lastSentAt) < $cooldown) {
    inquiry_otp_json([
        'success' => false,
        'message' => 'Please wait before sending another code.',
        'retry_after' => $cooldown - (time() - $lastSentAt),
    ], 429);
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
unset($_SESSION['public_inquiry_email_verified']);

inquiry_otp_json([
    'success' => true,
    'message' => 'Verification code sent.',
    'expires_in' => $validity,
    'resend_after' => $cooldown,
]);
