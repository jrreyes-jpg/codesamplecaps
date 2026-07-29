<?php
// Shared failed-login helpers para hindi paulit-ulit sa login.php at login_lock_status.php.

function login_remaining_seconds(?string $lastAttempt, int $lockoutTime): int
{
    $lastAttemptTime = $lastAttempt ? strtotime($lastAttempt) : false;
    if ($lastAttemptTime === false) {
        return 0;
    }

    return max(0, ($lastAttemptTime + $lockoutTime) - time());
}

function login_get_attempt(mysqli $conn, string $email, string $ipAddress): ?array
{
    $stmt = $conn->prepare(
        'SELECT attempts, last_attempt
         FROM login_attempts
         WHERE email = ? AND ip_address = ?
         ORDER BY last_attempt DESC, id DESC
         LIMIT 1'
    );
    $stmt->bind_param('ss', $email, $ipAddress);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return $row ?: null;
}

function login_get_ip_attempt_summary(mysqli $conn, string $ipAddress): array
{
    $stmt = $conn->prepare(
        'SELECT SUM(attempts) AS total_attempts, MAX(last_attempt) AS last_attempt
         FROM login_attempts
         WHERE ip_address = ?'
    );
    $stmt->bind_param('s', $ipAddress);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];

    return [
        'attempts' => (int)($row['total_attempts'] ?? 0),
        'last_attempt' => $row['last_attempt'] ?? null,
    ];
}

function login_clear_ip_attempts(mysqli $conn, string $ipAddress): void
{
    $stmt = $conn->prepare('DELETE FROM login_attempts WHERE ip_address = ?');
    $stmt->bind_param('s', $ipAddress);
    $stmt->execute();
}

function login_clear_expired_ip_attempts(mysqli $conn, string $ipAddress, int $lockoutTime): void
{
    // Kapag walang activity for lockout time, burahin na ang lumang failed attempts ng device.
    $stmt = $conn->prepare('DELETE FROM login_attempts WHERE ip_address = ? AND last_attempt < DATE_SUB(NOW(), INTERVAL ? SECOND)');
    $stmt->bind_param('si', $ipAddress, $lockoutTime);
    $stmt->execute();
}

function login_clear_attempts(mysqli $conn, string $email, string $ipAddress): void
{
    $stmt = $conn->prepare('DELETE FROM login_attempts WHERE email = ? AND ip_address = ?');
    $stmt->bind_param('ss', $email, $ipAddress);
    $stmt->execute();
}

function login_save_attempt(mysqli $conn, string $email, string $ipAddress, int $attempts): void
{
    $stmt = $conn->prepare('UPDATE login_attempts SET attempts = ?, last_attempt = NOW() WHERE email = ? AND ip_address = ?');
    $stmt->bind_param('iss', $attempts, $email, $ipAddress);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        return;
    }

    $stmt = $conn->prepare('INSERT INTO login_attempts (email, ip_address, attempts, last_attempt) VALUES (?, ?, ?, NOW())');
    $stmt->bind_param('ssi', $email, $ipAddress, $attempts);
    $stmt->execute();
}

function login_save_ip_only_attempt(mysqli $conn, string $ipAddress, string $ipOnlyKey): void
{
    // Para sa fake/non-existing email: device counter lang, walang fake email record.
    $attempt = login_get_attempt($conn, $ipOnlyKey, $ipAddress);
    $attempts = (int)($attempt['attempts'] ?? 0) + 1;
    login_save_attempt($conn, $ipOnlyKey, $ipAddress, $attempts);
}
