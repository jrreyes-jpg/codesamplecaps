<?php

require_once __DIR__ . '/phone_normalization.php';

function inquiry_contact_normalize_name(string $name): string
{
    $name = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
    return mb_strtolower($name, 'UTF-8');
}

function inquiry_contact_validation_result(mysqli $conn, string $name, string $email, string $phone): array
{
    $email = mb_strtolower(trim($email), 'UTF-8');
    $phone = normalize_ph_mobile($phone);

    $stmt = $conn->prepare(
        'SELECT id, full_name, email, phone_normalized, role
         FROM users
         WHERE deleted_at IS NULL
           AND (LOWER(email) = ? OR phone_normalized = ?)'
    );
    if (!$stmt) {
        return ['valid' => false, 'status' => 'server_error'];
    }

    $stmt->bind_param('ss', $email, $phone);
    if (!$stmt->execute()) {
        return ['valid' => false, 'status' => 'server_error'];
    }

    $internalRoles = ['super_admin', 'admin', 'engineer', 'foreman', 'inventory_clerk'];
    $emailClient = null;
    $phoneClient = null;
    $result = $stmt->get_result();

    while ($user = $result->fetch_assoc()) {
        $role = strtolower((string)($user['role'] ?? ''));
        if (in_array($role, $internalRoles, true)) {
            return ['valid' => false, 'status' => 'contact_not_allowed'];
        }

        if ($role !== 'client') {
            continue;
        }

        if (mb_strtolower((string)($user['email'] ?? ''), 'UTF-8') === $email) {
            $emailClient = $user;
        }
        // Normalized na ang phone bago ikumpara para pareho ang 09 at +63 format.
        if ((string)($user['phone_normalized'] ?? '') === $phone) {
            $phoneClient = $user;
        }
    }

    if ($emailClient === null && $phoneClient === null) {
        return ['valid' => true, 'status' => ''];
    }

    if (
        $emailClient === null
        || $phoneClient === null
        || (int)$emailClient['id'] !== (int)$phoneClient['id']
        || inquiry_contact_normalize_name((string)$emailClient['full_name']) !== inquiry_contact_normalize_name($name)
    ) {
        return ['valid' => false, 'status' => 'contact_mismatch'];
    }

    return ['valid' => true, 'status' => ''];
}
