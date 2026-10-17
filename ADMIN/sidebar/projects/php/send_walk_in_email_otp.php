<?php

require_once __DIR__ . '/../../../../config/auth_middleware.php';
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
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Enter a valid name and email first.']);
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
