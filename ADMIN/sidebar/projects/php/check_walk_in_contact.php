<?php

require_once __DIR__ . '/../../../../config/auth_middleware.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/inquiry_contact_validation.php';

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

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !is_valid_ph_mobile($phone)) {
    echo json_encode(['valid' => false, 'message' => 'Enter a valid name, email, and number first.']);
    exit();
}

$result = inquiry_contact_validation_result($conn, $name, $email, $phone);
$messages = [
    'contact_not_allowed' => 'This email or number belongs to a staff account.',
    'email_mismatch' => 'This email does not match the existing client.',
    'phone_mismatch' => 'This number does not match the existing client.',
    'name_mismatch' => 'This name does not match the existing client.',
    'contact_mismatch' => 'Email and number belong to different clients.',
    'server_error' => 'Unable to check the client details. Try again.',
];

echo json_encode([
    'valid' => (bool)$result['valid'],
    'status' => (string)$result['status'],
    'field' => (string)($result['field'] ?? ''),
    'message' => $result['valid'] ? '' : ($messages[$result['status']] ?? 'Invalid client details.'),
]);
