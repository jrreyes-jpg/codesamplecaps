<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';

auth_start_session();
auth_apply_no_cache_headers();
header('Content-Type: application/json');

$config = Config::getInstance();
$max_attempts = (int)$config->get('LOGIN_MAX_ATTEMPTS', 5);
$max_ip_attempts = 15;
$lockout_minutes = (int)$config->get('LOGIN_LOCKOUT_MINUTES', 15);
$lockout_time = $lockout_minutes * 60;
$ip_address = $_SERVER['REMOTE_ADDR'] ?? '';

function lock_status_remaining_seconds(?string $lastAttempt, int $lockoutTime): int
{
    $lastAttemptTime = $lastAttempt ? strtotime($lastAttempt) : false;
    if ($lastAttemptTime === false) {
        return 0;
    }

    return max(0, ($lastAttemptTime + $lockoutTime) - time());
}

function lock_status_get_attempt(mysqli $conn, string $email, string $ipAddress): ?array
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

function lock_status_get_ip_attempt_summary(mysqli $conn, string $ipAddress): array
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

function lock_status_clear_attempts(mysqli $conn, string $email, string $ipAddress): void
{
    $stmt = $conn->prepare('DELETE FROM login_attempts WHERE email = ? AND ip_address = ?');
    $stmt->bind_param('ss', $email, $ipAddress);
    $stmt->execute();
}

function lock_status_clear_ip_attempts(mysqli $conn, string $ipAddress): void
{
    $stmt = $conn->prepare('DELETE FROM login_attempts WHERE ip_address = ?');
    $stmt->bind_param('s', $ipAddress);
    $stmt->execute();
}

function lock_status_json(array $payload): void
{
    echo json_encode($payload);
    exit;
}

$ip_attempt_summary = lock_status_get_ip_attempt_summary($conn, $ip_address);
if ((int)$ip_attempt_summary['attempts'] >= $max_ip_attempts) {
    $remaining_seconds = lock_status_remaining_seconds($ip_attempt_summary['last_attempt'] ?? null, $lockout_time);

    if ($remaining_seconds > 0) {
        lock_status_json([
            'locked' => true,
            'type' => 'ip',
            'seconds' => $remaining_seconds,
            'unlockAt' => time() + $remaining_seconds,
            'message' => 'This device has been temporarily locked due to multiple failed login attempts.',
        ]);
    }

    lock_status_clear_ip_attempts($conn, $ip_address);
}

$email = strtolower(trim((string)($_GET['email'] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    lock_status_json(['locked' => false]);
}

$stmt = $conn->prepare('SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Kapag walang account, huwag gumawa ng email attempt at huwag magbigay ng clue.
if (!$user) {
    lock_status_json(['locked' => false]);
}

$attempt = lock_status_get_attempt($conn, $email, $ip_address);
$attempts = (int)($attempt['attempts'] ?? 0);

if ($attempts < $max_attempts) {
    lock_status_json(['locked' => false]);
}

$remaining_seconds = lock_status_remaining_seconds($attempt['last_attempt'] ?? null, $lockout_time);
if ($remaining_seconds <= 0) {
    lock_status_clear_attempts($conn, $email, $ip_address);
    lock_status_json(['locked' => false]);
}

lock_status_json([
    'locked' => true,
    'type' => 'email',
    'seconds' => $remaining_seconds,
    'unlockAt' => time() + $remaining_seconds,
    'message' => 'This login is temporarily locked.',
]);
