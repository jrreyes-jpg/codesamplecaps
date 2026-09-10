<?php

function normalize_ph_mobile(?string $phone): string
{
    $digits = preg_replace('/\D+/', '', (string)$phone);
    if ($digits === '') {
        return '';
    }

    if (str_starts_with($digits, '639')) {
        $digits = '09' . substr($digits, 3);
    } elseif (str_starts_with($digits, '9')) {
        $digits = '0' . $digits;
    }

    return $digits;
}

function is_valid_ph_mobile(?string $phone): bool
{
    return (bool)preg_match('/^09\d{9}$/', normalize_ph_mobile($phone));
}

function user_phone_normalized_exists(mysqli $conn, string $phone, int $exceptUserId = 0): bool
{
    $normalizedPhone = normalize_ph_mobile($phone);
    if ($normalizedPhone === '') {
        return false;
    }

    $sql = 'SELECT id FROM users WHERE phone_normalized = ?';
    if ($exceptUserId > 0) {
        $sql .= ' AND id != ?';
    }
    $sql .= ' LIMIT 1';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    if ($exceptUserId > 0) {
        $stmt->bind_param('si', $normalizedPhone, $exceptUserId);
    } else {
        $stmt->bind_param('s', $normalizedPhone);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    return (bool)($result && $result->fetch_assoc());
}
