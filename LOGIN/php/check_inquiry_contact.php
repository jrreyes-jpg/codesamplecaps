<?php

session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/inquiry_contact_validation.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['valid' => false, 'message' => 'Invalid request.']);
    exit;
}

$token = trim((string)($_POST['inquiry_form_token'] ?? ''));
if ($token === '' || empty($_SESSION['inquiry_form_token']) || !hash_equals((string)$_SESSION['inquiry_form_token'], $token)) {
    http_response_code(403);
    echo json_encode(['valid' => false, 'message' => 'Security check failed.']);
    exit;
}

// Simple limit para hindi paulit-ulit ang public contact check.
$now = microtime(true);
$lastCheck = (float)($_SESSION['public_inquiry_contact_check_at'] ?? 0);
if (($now - $lastCheck) < 0.35) {
    echo json_encode(['valid' => true, 'complete' => false, 'status' => 'checking']);
    exit;
}
$_SESSION['public_inquiry_contact_check_at'] = $now;

$name = trim((string)($_POST['client_name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$phone = normalize_ph_mobile(trim((string)($_POST['contact_no'] ?? '')));
$normalizedEmail = mb_strtolower($email, 'UTF-8');
$verifiedSessionEmail = mb_strtolower(trim((string)($_SESSION['public_inquiry_email_verified']['email'] ?? '')), 'UTF-8');
$sessionEmailVerified = $normalizedEmail !== '' && hash_equals($verifiedSessionEmail, $normalizedEmail);

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !is_valid_ph_mobile($phone)) {
    echo json_encode([
        'valid' => true,
        'complete' => false,
        'status' => 'incomplete_contact',
        'email_verified' => false,
        'can_verify_email' => false,
        'message' => '',
    ]);
    exit;
}

$result = inquiry_contact_validation_result($conn, $name, $email, $phone);
$valid = (bool)($result['valid'] ?? false);
$existing = $valid && inquiry_contact_is_existing($result);

echo json_encode([
    'valid' => $valid,
    'complete' => true,
    'status' => (string)($result['status'] ?? 'server_error'),
    'field' => (string)($result['field'] ?? ''),
    'email_verified' => $sessionEmailVerified,
    'can_verify_email' => $valid && !$sessionEmailVerified,
    'message' => !$valid
        ? inquiry_contact_validation_message($result)
        : ($existing && !$sessionEmailVerified ? 'Existing client found. Verify email.' : ''),
]);
