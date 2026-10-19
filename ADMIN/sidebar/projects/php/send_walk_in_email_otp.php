<?php

require_once __DIR__ . '/../../../../config/auth_middleware.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/inquiry_otp.php';
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
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Enter a valid email first.']);
    exit();
}
$name = $name !== '' ? $name : 'Client';

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

$otp = (string)random_int(100000, 999999);
$validSeconds = inquiry_otp_validity_seconds();
$expiresAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->modify('+' . $validSeconds . ' seconds');

if (!(new EmailService())->sendInquiryOtp($email, $name, $otp, $expiresAt)) {
    echo json_encode(['success' => false, 'message' => 'Email cannot be sent right now.']);
    exit();
}

walk_in_email_verification_start($email, $otp, $validSeconds);
echo json_encode(['success' => true, 'message' => 'Code sent.', 'expires_in' => $validSeconds]);
