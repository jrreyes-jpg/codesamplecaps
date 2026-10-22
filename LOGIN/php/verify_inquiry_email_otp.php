<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/inquiry_otp.php';

function inquiry_verify_json(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    inquiry_verify_json(['success' => false, 'message' => 'Invalid request.'], 405);
}

$formToken = trim((string)($_POST['inquiry_form_token'] ?? ''));
$otp = trim((string)($_POST['otp'] ?? ''));
$pendingToken = (string)($_SESSION['public_inquiry_otp_token'] ?? '');

if ($formToken === '' || empty($_SESSION['inquiry_form_token']) || !hash_equals((string)$_SESSION['inquiry_form_token'], $formToken)) {
    inquiry_verify_json(['success' => false, 'message' => 'Please refresh the form and try again.'], 403);
}

if ($pendingToken === '' || !preg_match('/^\d{6}$/', $otp)) {
    inquiry_verify_json(['success' => false, 'message' => 'Enter the 6-digit code.'], 422);
}

$stmt = $conn->prepare('SELECT id, otp_hash, payload_json, attempts, expires_at FROM pending_service_inquiries WHERE token = ? AND verified_at IS NULL LIMIT 1');
$stmt->bind_param('s', $pendingToken);
$stmt->execute();
$pending = $stmt->get_result()->fetch_assoc();

if (!$pending) {
    inquiry_verify_json(['success' => false, 'message' => 'Code is no longer active. Send a new code.'], 422);
}

if (!inquiry_otp_prepare_rate_limit_table($conn)) {
    inquiry_verify_json(['success' => false, 'message' => 'Could not check the code. Try again.'], 500);
}

$payload = json_decode((string)$pending['payload_json'], true);
$email = strtolower(trim((string)($payload['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    inquiry_verify_json(['success' => false, 'message' => 'Verification failed. Send a new code.'], 422);
}

$verificationLock = inquiry_otp_rate_status($conn, 'email_verify', $email);
if (!$verificationLock['ok']) {
    inquiry_verify_json([
        'success' => false,
        'message' => 'Too many wrong codes. Try again after 15 minutes.',
        'retry_after' => (int)$verificationLock['retry_after'],
        'locked' => true,
    ], 429);
}

$expiresAt = new DateTimeImmutable((string)$pending['expires_at'], new DateTimeZone('Asia/Manila'));
if ($expiresAt->getTimestamp() < time()) {
    inquiry_verify_json(['success' => false, 'message' => 'Code expired. Send a new code.'], 422);
}

if ((int)$pending['attempts'] >= inquiry_otp_max_attempts()) {
    inquiry_otp_lock_scope($conn, 'email_verify', $email, inquiry_otp_lock_seconds());
    inquiry_verify_json([
        'success' => false,
        'message' => 'Too many wrong codes. Email verification is locked for 15 minutes.',
        'retry_after' => inquiry_otp_lock_seconds(),
        'locked' => true,
    ], 429);
}

if (!password_verify($otp, (string)$pending['otp_hash'])) {
    $update = $conn->prepare('UPDATE pending_service_inquiries SET attempts = attempts + 1 WHERE id = ? AND attempts < ?');
    $id = (int)$pending['id'];
    $limit = inquiry_otp_max_attempts();
    $update->bind_param('ii', $id, $limit);
    $update->execute();
    $newAttempts = (int)$pending['attempts'] + 1;
    if ($newAttempts >= $limit) {
        inquiry_otp_lock_scope($conn, 'email_verify', $email, inquiry_otp_lock_seconds());
        inquiry_verify_json([
            'success' => false,
            'message' => 'Too many wrong codes. Email verification is locked for 15 minutes.',
            'retry_after' => inquiry_otp_lock_seconds(),
            'locked' => true,
        ], 429);
    }

    inquiry_verify_json([
        'success' => false,
        'message' => 'Wrong code. Please try again.',
        'attempts_left' => max(0, $limit - $newAttempts),
    ], 422);
}

$id = (int)$pending['id'];
$done = $conn->prepare('UPDATE pending_service_inquiries SET verified_at = NOW() WHERE id = ? AND verified_at IS NULL');
$done->bind_param('i', $id);
$done->execute();

$_SESSION['public_inquiry_email_verified'] = [
    'email' => $email,
    'verified_at' => time(),
];
inquiry_otp_clear_scope($conn, 'email_verify', $email);
unset($_SESSION['public_inquiry_otp_token'], $_SESSION['public_inquiry_otp_expires_at'], $_SESSION['public_inquiry_otp_resend_count']);

inquiry_verify_json(['success' => true, 'message' => 'Email verified. You can submit your inquiry.']);
