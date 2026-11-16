<?php

require_once __DIR__ . '/../../../../config/auth_middleware.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/inquiry_contact_validation.php';
require_once __DIR__ . '/../../../../config/walk_in_email_verification.php';

require_role('admin');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['valid' => false, 'message' => 'Invalid request.']);
    exit();
}

if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'super_admin')) {
    http_response_code(403);
    echo json_encode(['valid' => false, 'message' => 'Security check failed.']);
    exit();
}

$name = trim((string)($_POST['client_name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$phone = trim((string)($_POST['contact_no'] ?? ''));
$normalizedEmail = mb_strtolower($email, 'UTF-8');
$emailIsValid = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
$phoneIsValid = is_valid_ph_mobile($phone);
$nameIsValid = $name !== '';
$sessionEmailVerified = $emailIsValid && walk_in_email_is_verified($normalizedEmail);

if (!$nameIsValid || !$emailIsValid || !$phoneIsValid) {
    echo json_encode([
        'valid' => true,
        'complete' => false,
        'status' => 'incomplete_contact',
        'email_verified' => false,
        'can_verify_email' => false,
        'message' => '',
    ]);
    exit();
}

$result = inquiry_contact_validation_result($conn, $name, $email, $phone);
$valid = (bool)($result['valid'] ?? false);
$existing = $valid && inquiry_contact_is_existing($result);

echo json_encode([
    'valid' => $valid,
    'complete' => true,
    'status' => (string)($result['status'] ?? 'server_error'),
    'email_verified' => $sessionEmailVerified,
    'can_verify_email' => $valid && !$sessionEmailVerified,
    'field' => (string)($result['field'] ?? ''),
    'message' => !$valid
        ? inquiry_contact_validation_message($result)
        : ($existing && !$sessionEmailVerified ? 'Existing client found. Verify email.' : ''),
]);
