<?php
require_once dirname(__DIR__) . '/config/database.php';

$result = $conn->query(
    "SELECT
        NOW() AS database_now,
        UTC_TIMESTAMP() AS utc_now,
        @@session.time_zone AS session_time_zone,
        DATE_ADD(NOW(), INTERVAL 10 MINUTE) AS otp_expires_at,
        TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(NOW(), INTERVAL 10 MINUTE)) AS otp_seconds,
        DATE_SUB(NOW(), INTERVAL 1 SECOND) < NOW() AS expired_is_rejected"
);
$row = $result?->fetch_assoc();

if (!is_array($row)
    || ($row['session_time_zone'] ?? '') !== '+08:00'
    || (int)($row['otp_seconds'] ?? 0) !== 600
    || (int)($row['expired_is_rejected'] ?? 0) !== 1) {
    throw new RuntimeException('Database timezone or OTP expiry check failed.');
}

$timezone = new DateTimeZone('Asia/Manila');
$databaseNow = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$row['database_now'], $timezone);
$expiresAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$row['otp_expires_at'], $timezone);
$phpNow = new DateTimeImmutable('now', $timezone);

if (!$databaseNow || !$expiresAt
    || abs($databaseNow->getTimestamp() - $phpNow->getTimestamp()) > 5
    || ($expiresAt->getTimestamp() - $databaseNow->getTimestamp()) !== 600) {
    throw new RuntimeException('PHP and database OTP clocks do not agree.');
}

echo "database_timezone_test: OK\n";
