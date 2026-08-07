<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/engineer_password_otp.php';
require_once __DIR__ . '/../../services/EmailService.php';
require_once __DIR__ . '/../includes/engineer_helpers.php';

require_role('engineer');
header('Content-Type: application/json');

engineer_password_otp_ensure_table($conn);

function engineer_password_json(bool $ok, string $message, array $extra = []): void
{
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !engineer_is_valid_csrf_token($_POST['csrf_token'] ?? null)) {
    engineer_password_json(false, 'Invalid request. Please refresh and try again.');
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$action = (string)($_POST['password_action'] ?? '');
$stmt = $conn->prepare("SELECT id, full_name, email, password FROM users WHERE id = ? AND role = 'engineer' LIMIT 1");
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user || empty($user['email'])) {
    engineer_password_json(false, 'Engineer account email is not available. Contact Super Admin.');
}

if ($action === 'send_otp') {
    $existing = $conn->prepare('SELECT sent_at, expires_at FROM engineer_password_otps WHERE user_id = ? LIMIT 1');
    $existing->bind_param('i', $userId);
    $existing->execute();
    $row = $existing->get_result()->fetch_assoc();

    if ($row && strtotime((string)$row['expires_at']) > time()) {
        $wait = max(0, 60 - (time() - strtotime((string)$row['sent_at'])));
        if ($wait > 0) {
            engineer_password_json(false, "Please wait {$wait} seconds before sending another code.", ['cooldown' => $wait]);
        }
    }

    $otp = (string)random_int(100000, 999999);
    $otpHash = password_hash($otp, PASSWORD_DEFAULT);
    $save = $conn->prepare(
        'INSERT INTO engineer_password_otps (user_id, otp_hash, attempts, expires_at, sent_at, verified_at)
         VALUES (?, ?, 0, DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW(), NULL)
         ON DUPLICATE KEY UPDATE otp_hash = VALUES(otp_hash), attempts = 0, expires_at = VALUES(expires_at), sent_at = NOW(), verified_at = NULL'
    );
    $save->bind_param('is', $userId, $otpHash);
    $save->execute();

    $emailService = new EmailService();
    if (!$emailService->sendEngineerPasswordOtp((string)$user['email'], (string)$user['full_name'], $otp, 10)) {
        engineer_password_json(false, 'Could not send code right now. Check email setup or contact Super Admin.');
    }

    engineer_password_json(true, 'Verification code sent to your registered email.', ['expiresIn' => 600]);
}

if ($action === 'change_password') {
    $otp = trim((string)($_POST['otp'] ?? ''));
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if (!preg_match('/^\d{6}$/', $otp)) {
        engineer_password_json(false, 'Enter the 6-digit verification code.');
    }

    if ($newPassword !== $confirmPassword) {
        engineer_password_json(false, 'Passwords do not match.');
    }

    if (!engineer_password_is_strong($newPassword)) {
        engineer_password_json(false, 'Password must be 8+ chars with uppercase, lowercase, number, and symbol.');
    }

    if (password_verify($newPassword, (string)$user['password'])) {
        engineer_password_json(false, 'New password must be different from your current password.');
    }

    $otpStmt = $conn->prepare('SELECT otp_hash, attempts, expires_at FROM engineer_password_otps WHERE user_id = ? LIMIT 1');
    $otpStmt->bind_param('i', $userId);
    $otpStmt->execute();
    $otpRow = $otpStmt->get_result()->fetch_assoc();

    if (!$otpRow || strtotime((string)$otpRow['expires_at']) < time()) {
        engineer_password_json(false, 'Code expired. Please send a new code.');
    }

    if ((int)$otpRow['attempts'] >= 5) {
        engineer_password_json(false, 'Too many tries. Please send a new code.');
    }

    if (!password_verify($otp, (string)$otpRow['otp_hash'])) {
        $fail = $conn->prepare('UPDATE engineer_password_otps SET attempts = attempts + 1 WHERE user_id = ?');
        $fail->bind_param('i', $userId);
        $fail->execute();
        engineer_password_json(false, 'Invalid or expired code. Please try again.');
    }

    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $update = $conn->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'engineer'");
    $update->bind_param('si', $passwordHash, $userId);
    $update->execute();

    $delete = $conn->prepare('DELETE FROM engineer_password_otps WHERE user_id = ?');
    $delete->bind_param('i', $userId);
    $delete->execute();

    engineer_password_json(true, 'Password changed successfully.');
}

engineer_password_json(false, 'Unknown password action.');
