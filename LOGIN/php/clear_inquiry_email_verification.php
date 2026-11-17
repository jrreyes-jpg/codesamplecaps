<?php

session_start();

require_once __DIR__ . '/../../config/inquiry_email_verification.php';

header('Content-Type: application/json; charset=UTF-8');

$token = trim((string)($_POST['inquiry_form_token'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || $token === ''
    || empty($_SESSION['inquiry_form_token'])
    || !hash_equals((string)$_SESSION['inquiry_form_token'], $token)) {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit;
}

unset(
    $_SESSION['public_inquiry_otp_token'],
    $_SESSION['public_inquiry_otp_sent_at'],
    $_SESSION['public_inquiry_otp_expires_at'],
    $_SESSION['public_inquiry_otp_email'],
    $_SESSION['public_inquiry_otp_resend_count']
);
echo json_encode(['success' => true]);
