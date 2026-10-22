<?php
// Pending inquiry OTP flow bago ilagay sa final service_inquiries table.

function inquiry_otp_max_attempts(): int
{
    return 5;
}

function inquiry_otp_max_resends(): int
{
    return 3;
}

function inquiry_otp_resend_cooldown_seconds(): int
{
    return 60;
}

function inquiry_otp_validity_seconds(): int
{
    return 180;
}

function inquiry_otp_email_send_limit(): int
{
    return 3;
}

function inquiry_otp_email_window_seconds(): int
{
    return 900;
}

function inquiry_otp_ip_send_limit(): int
{
    return 10;
}

function inquiry_otp_ip_window_seconds(): int
{
    return 3600;
}

function inquiry_otp_lock_seconds(): int
{
    return 900;
}

function inquiry_otp_client_ip(): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    return $ip !== '' ? $ip : 'unknown';
}

function inquiry_otp_prepare_rate_limit_table(mysqli $conn): bool
{
    return $conn->query(
        "CREATE TABLE IF NOT EXISTS inquiry_otp_rate_limits (
            scope_key CHAR(64) NOT NULL PRIMARY KEY,
            scope_type VARCHAR(30) NOT NULL,
            attempt_count INT NOT NULL DEFAULT 0,
            window_started_at DATETIME NOT NULL,
            blocked_until DATETIME NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_inquiry_otp_scope_type (scope_type),
            KEY idx_inquiry_otp_blocked_until (blocked_until)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ) === true;
}

function inquiry_otp_scope_key(string $scopeType, string $identity): string
{
    return hash('sha256', $scopeType . '|' . strtolower(trim($identity)));
}

function inquiry_otp_rate_status(mysqli $conn, string $scopeType, string $identity): array
{
    $scopeKey = inquiry_otp_scope_key($scopeType, $identity);
    $stmt = $conn->prepare(
        'SELECT attempt_count, window_started_at, blocked_until
         FROM inquiry_otp_rate_limits
         WHERE scope_key = ? LIMIT 1'
    );
    if (!$stmt) {
        return ['ok' => false, 'retry_after' => 0];
    }

    $stmt->bind_param('s', $scopeKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row || empty($row['blocked_until'])) {
        return ['ok' => true, 'retry_after' => 0, 'row' => $row];
    }

    $blockedUntil = strtotime((string)$row['blocked_until']);
    if ($blockedUntil !== false && $blockedUntil > time()) {
        return ['ok' => false, 'retry_after' => $blockedUntil - time(), 'row' => $row];
    }

    return ['ok' => true, 'retry_after' => 0, 'row' => $row];
}

function inquiry_otp_consume_rate_limit(
    mysqli $conn,
    string $scopeType,
    string $identity,
    int $limit,
    int $windowSeconds,
    int $lockSeconds
): array {
    $status = inquiry_otp_rate_status($conn, $scopeType, $identity);
    if (!$status['ok']) {
        return $status;
    }

    $scopeKey = inquiry_otp_scope_key($scopeType, $identity);
    $row = $status['row'] ?? null;
    $windowStartedAt = $row ? strtotime((string)$row['window_started_at']) : false;
    $windowExpired = $windowStartedAt === false || (time() - $windowStartedAt) >= $windowSeconds;
    $attemptCount = $windowExpired ? 0 : (int)($row['attempt_count'] ?? 0);

    if ($attemptCount >= $limit) {
        $blockedUntil = date('Y-m-d H:i:s', time() + $lockSeconds);
        $update = $conn->prepare(
            'UPDATE inquiry_otp_rate_limits
             SET attempt_count = 0, window_started_at = NOW(), blocked_until = ?
             WHERE scope_key = ?'
        );
        if ($update) {
            $update->bind_param('ss', $blockedUntil, $scopeKey);
            $update->execute();
        }
        return ['ok' => false, 'retry_after' => $lockSeconds];
    }

    if (!$row) {
        $attemptCount = 1;
        $insert = $conn->prepare(
            'INSERT INTO inquiry_otp_rate_limits
             (scope_key, scope_type, attempt_count, window_started_at, blocked_until)
             VALUES (?, ?, ?, NOW(), NULL)'
        );
        if (!$insert) {
            return ['ok' => false, 'retry_after' => 0];
        }
        $insert->bind_param('ssi', $scopeKey, $scopeType, $attemptCount);
        $insert->execute();
    } elseif ($windowExpired) {
        $attemptCount = 1;
        $update = $conn->prepare(
            'UPDATE inquiry_otp_rate_limits
             SET attempt_count = ?, window_started_at = NOW(), blocked_until = NULL
             WHERE scope_key = ?'
        );
        $update->bind_param('is', $attemptCount, $scopeKey);
        $update->execute();
    } else {
        $attemptCount++;
        $update = $conn->prepare(
            'UPDATE inquiry_otp_rate_limits SET attempt_count = ?, blocked_until = NULL WHERE scope_key = ?'
        );
        $update->bind_param('is', $attemptCount, $scopeKey);
        $update->execute();
    }

    return ['ok' => true, 'retry_after' => 0];
}

function inquiry_otp_lock_scope(mysqli $conn, string $scopeType, string $identity, int $lockSeconds): void
{
    $scopeKey = inquiry_otp_scope_key($scopeType, $identity);
    $blockedUntil = date('Y-m-d H:i:s', time() + $lockSeconds);
    $attemptCount = 0;
    $stmt = $conn->prepare(
        'INSERT INTO inquiry_otp_rate_limits
         (scope_key, scope_type, attempt_count, window_started_at, blocked_until)
         VALUES (?, ?, ?, NOW(), ?)
         ON DUPLICATE KEY UPDATE attempt_count = 0, window_started_at = NOW(), blocked_until = VALUES(blocked_until)'
    );
    if ($stmt) {
        $stmt->bind_param('ssis', $scopeKey, $scopeType, $attemptCount, $blockedUntil);
        $stmt->execute();
    }
}

function inquiry_otp_clear_scope(mysqli $conn, string $scopeType, string $identity): void
{
    $scopeKey = inquiry_otp_scope_key($scopeType, $identity);
    $stmt = $conn->prepare('DELETE FROM inquiry_otp_rate_limits WHERE scope_key = ?');
    if ($stmt) {
        $stmt->bind_param('s', $scopeKey);
        $stmt->execute();
    }
}

function inquiry_otp_redirect(string $status, string $token = '', array $extraQuery = []): void
{
    $query = array_merge(['inquiry' => $status], $extraQuery);
    if ($token !== '') {
        $query['token'] = $token;
    }

    header('Location: /codesamplecaps/LOGIN/php/verify_inquiry.php?' . http_build_query($query));
    exit();
}
