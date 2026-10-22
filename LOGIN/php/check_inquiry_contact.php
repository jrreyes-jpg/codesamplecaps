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

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !is_valid_ph_mobile($phone)) {
    echo json_encode(['valid' => true, 'complete' => false, 'status' => 'incomplete_contact', 'can_verify_email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false]);
    exit;
}

$result = inquiry_contact_validation_result($conn, $name, $email, $phone);
$messages = [
    'contact_not_allowed' => 'This email or number belongs to a staff account.',
    'email_mismatch' => 'Email already used. Use another email.',
    'phone_mismatch' => 'Number already used. Use another number.',
    'contact_mismatch' => 'Email and number do not match.',
    'name_mismatch' => 'Name does not match this contact.',
    'server_error' => 'Unable to check the client details. Try again.',
];

echo json_encode([
    'valid' => (bool)($result['valid'] ?? false),
    'complete' => true,
    'status' => (string)($result['status'] ?? 'server_error'),
    'field' => (string)($result['field'] ?? ''),
    'email_verified' => !empty($result['email_verified']),
    'can_verify_email' => (bool)($result['valid'] ?? false) && empty($result['email_verified']),
    'message' => !empty($result['valid']) ? '' : ($messages[$result['status'] ?? ''] ?? 'Invalid client details.'),
]);
