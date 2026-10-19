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
        'SELECT id, full_name, email, phone_normalized, role, status
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
            $emailMatches = mb_strtolower((string)($user['email'] ?? ''), 'UTF-8') === $email;
            $phoneMatches = (string)($user['phone_normalized'] ?? '') === $phone;
            return [
                'valid' => false,
                'status' => 'contact_not_allowed',
                'field' => $emailMatches && $phoneMatches ? 'both' : ($emailMatches ? 'email' : 'phone'),
            ];
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

    if ($emailClient !== null || $phoneClient !== null) {
        if ($emailClient === null) return ['valid' => false, 'status' => 'email_mismatch', 'field' => 'email'];
        if ($phoneClient === null) return ['valid' => false, 'status' => 'phone_mismatch', 'field' => 'phone'];
        if ((int)$emailClient['id'] !== (int)$phoneClient['id']) {
            return ['valid' => false, 'status' => 'phone_mismatch', 'field' => 'phone'];
        }
        if (inquiry_contact_normalize_name((string)$emailClient['full_name']) !== inquiry_contact_normalize_name($name)) {
            return ['valid' => false, 'status' => 'name_mismatch', 'field' => 'name'];
        }

        return [
            'valid' => true,
            'status' => 'existing_client',
            'email_verified' => strtolower((string)($emailClient['status'] ?? '')) === 'active',
        ];
    }

    // Tingnan din ang dating inquiry para pareho ang detalye ng walk-in at online client.
    $inquiryStmt = $conn->prepare(
        'SELECT id, client_name, email, contact_no
         FROM service_inquiries
         WHERE LOWER(email) = ? OR contact_no = ?
         ORDER BY id DESC'
    );
    if (!$inquiryStmt) {
        return ['valid' => false, 'status' => 'server_error'];
    }

    $inquiryStmt->bind_param('ss', $email, $phone);
    if (!$inquiryStmt->execute()) {
        return ['valid' => false, 'status' => 'server_error'];
    }

    $emailInquiry = null;
    $phoneInquiry = null;
    $inquiryResult = $inquiryStmt->get_result();
    while ($inquiry = $inquiryResult->fetch_assoc()) {
        if ($emailInquiry === null && mb_strtolower((string)$inquiry['email'], 'UTF-8') === $email) {
            $emailInquiry = $inquiry;
        }
        if ($phoneInquiry === null && normalize_ph_mobile((string)$inquiry['contact_no']) === $phone) {
            $phoneInquiry = $inquiry;
        }
    }

    if ($emailInquiry === null && $phoneInquiry === null) {
        return ['valid' => true, 'status' => 'new_contact', 'email_verified' => false];
    }

    if ($emailInquiry === null) return ['valid' => false, 'status' => 'email_mismatch', 'field' => 'email'];
    if ($phoneInquiry === null) return ['valid' => false, 'status' => 'phone_mismatch', 'field' => 'phone'];
    if ((int)$emailInquiry['id'] !== (int)$phoneInquiry['id']) {
        return ['valid' => false, 'status' => 'phone_mismatch', 'field' => 'phone'];
    }
    if (inquiry_contact_normalize_name((string)$emailInquiry['client_name']) !== inquiry_contact_normalize_name($name)) {
        return ['valid' => false, 'status' => 'name_mismatch', 'field' => 'name'];
    }

    return ['valid' => true, 'status' => 'existing_inquiry', 'email_verified' => false];
}
