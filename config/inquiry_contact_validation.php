<?php

require_once __DIR__ . '/phone_normalization.php';
require_once __DIR__ . '/inquiry_email_verification.php';

function inquiry_contact_normalize_name(string $name): string
{
    $name = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
    return mb_strtolower($name, 'UTF-8');
}

function inquiry_contact_validation_message(array $result): string
{
    $status = (string)($result['status'] ?? '');
    if ($status === 'account_email_exists') {
        return 'This email is already registered. Please use another email.';
    }
    if ($status === 'invalid_email') {
        return inquiry_email_invalid_message();
    }

    return $status === 'server_error'
        ? 'Unable to check the client details. Try again.'
        : 'Invalid client details.';
}

function inquiry_contact_validation_result(
    mysqli $conn,
    string $name,
    string $email,
    string $phone,
    bool $allowExistingClient = false
): array
{
    $email = mb_strtolower(trim($email), 'UTF-8');
    if (!inquiry_email_is_valid_format($email)) {
        return ['valid' => false, 'status' => 'invalid_email', 'field' => 'email'];
    }

    // Email lang ang account duplicate check. Independent ang name at phone ng inquiry.
    $stmt = $conn->prepare('SELECT id, role, status, deleted_at FROM users WHERE LOWER(email) = ? LIMIT 1');
    if (!$stmt) {
        return ['valid' => false, 'status' => 'server_error'];
    }

    $stmt->bind_param('s', $email);
    if (!$stmt->execute()) {
        return ['valid' => false, 'status' => 'server_error'];
    }

    $account = $stmt->get_result()->fetch_assoc();
    if ($account) {
        $isUsableClient = $allowExistingClient
            && (string)$account['role'] === 'client'
            && in_array((string)$account['status'], ['active', 'pending_activation'], true)
            && empty($account['deleted_at']);
        if ($isUsableClient) {
            return [
                'valid' => true,
                'status' => 'existing_client',
                'client_id' => (int)$account['id'],
                'email_verified' => false,
            ];
        }
        return ['valid' => false, 'status' => 'account_email_exists', 'field' => 'email'];
    }

    return ['valid' => true, 'status' => 'new_contact', 'email_verified' => false];
}
