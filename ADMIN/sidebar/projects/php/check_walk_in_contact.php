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
$normalizedPhone = normalize_ph_mobile($phone);
$emailIsValid = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
$phoneIsValid = is_valid_ph_mobile($phone);
$nameIsValid = $name !== '';
$sessionEmailVerified = $emailIsValid && walk_in_email_is_verified($normalizedEmail);

if (!$emailIsValid && !$phoneIsValid) {
    echo json_encode([
        'valid' => true,
        'complete' => false,
        'status' => 'incomplete_contact',
        'email_verified' => false,
        'can_verify_email' => false,
    ]);
    exit();
}

// I-check agad ang email o kumpletong number kahit hindi pa tapos ang form.
$lookupEmail = $emailIsValid ? $normalizedEmail : '';
$lookupPhone = $phoneIsValid ? $normalizedPhone : '';
$lookupStmt = $conn->prepare(
    'SELECT full_name, email, phone_normalized, role, status
     FROM users
     WHERE deleted_at IS NULL
       AND (LOWER(email) = ? OR phone_normalized = ?)'
);
if (!$lookupStmt) {
    echo json_encode(['valid' => false, 'complete' => false, 'status' => 'server_error', 'message' => 'Unable to check the client details. Try again.']);
    exit();
}
$lookupStmt->bind_param('ss', $lookupEmail, $lookupPhone);
$lookupStmt->execute();
$lookupResult = $lookupStmt->get_result();
$clientFound = false;
$clientMatchedField = '';
while ($user = $lookupResult->fetch_assoc()) {
    $emailMatches = $emailIsValid && mb_strtolower((string)($user['email'] ?? ''), 'UTF-8') === $normalizedEmail;
    $phoneMatches = $phoneIsValid && (string)($user['phone_normalized'] ?? '') === $normalizedPhone;
    $role = strtolower((string)($user['role'] ?? ''));

    if ($role !== 'client' && ($emailMatches || $phoneMatches)) {
        $field = $emailMatches && $phoneMatches ? 'both' : ($emailMatches ? 'email' : 'phone');
        $message = $field === 'email'
            ? 'This email belongs to a staff account.'
            : ($field === 'phone' ? 'This number belongs to a staff account.' : 'This email and number belong to a staff account.');
        echo json_encode([
            'valid' => false,
            'complete' => false,
            'status' => 'contact_not_allowed',
            'email_verified' => false,
            'can_verify_email' => false,
            'field' => $field,
            'message' => $message,
        ]);
        exit();
    }

    if ($role !== 'client') {
        continue;
    }

    if ($emailMatches || $phoneMatches) {
        $clientFound = true;
        $clientMatchedField = $emailMatches && $phoneMatches ? 'both' : ($emailMatches ? 'email' : 'phone');
    }
}

if (!$nameIsValid || !$emailIsValid || !$phoneIsValid) {
    echo json_encode([
        'valid' => true,
        'complete' => false,
        'status' => $clientFound ? 'existing_client_detected' : 'incomplete_contact',
        'email_verified' => $sessionEmailVerified,
        'can_verify_email' => $emailIsValid && !$clientFound,
        'field' => $clientMatchedField,
        'message' => $clientFound ? 'Existing client found.' : '',
    ]);
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
    'complete' => true,
    'status' => (string)$result['status'],
    'email_verified' => $sessionEmailVerified || !empty($result['email_verified']),
    'can_verify_email' => (bool)$result['valid'],
    'field' => (string)($result['field'] ?? ''),
    'message' => $result['valid'] ? '' : ($messages[$result['status']] ?? 'Invalid client details.'),
]);
