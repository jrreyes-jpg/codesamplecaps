<?php

require_once __DIR__ . '/../config/database.php';

class PasswordResetAttemptRepository
{
    private $conn;

    public function __construct($database = null)
    {
        global $conn;
        $this->conn = $database ?? $conn;
    }

    /**
     * Save one forgot-password request attempt.
     */
    public function recordAttempt(string $ipAddress, string $email): bool
    {
        $emailHash = hash('sha256', strtolower(trim($email)));

        $stmt = $this->conn->prepare(
            "INSERT INTO password_reset_attempts
                (ip_address, email_hash, attempted_at)
             VALUES (?, ?, NOW())"
        );

        $stmt->bind_param('ss', $ipAddress, $emailHash);

        return $stmt->execute();
    }

    /**
     * Count requests from one IP within the given number of minutes.
     */
    public function countRecentByIp(string $ipAddress, int $minutes): int
    {
        $minutes = max(1, $minutes);

        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) AS total
             FROM password_reset_attempts
             WHERE ip_address = ?
             AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );

        $stmt->bind_param('si', $ipAddress, $minutes);
        $stmt->execute();

        $result = $stmt->get_result()->fetch_assoc();

        return (int)($result['total'] ?? 0);
    }

    /**
     * Count requests for one email within the given number of minutes.
     */
    public function countRecentByEmail(string $email, int $minutes): int
    {
        $minutes = max(1, $minutes);
        $emailHash = hash('sha256', strtolower(trim($email)));

        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) AS total
             FROM password_reset_attempts
             WHERE email_hash = ?
             AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );

        $stmt->bind_param('si', $emailHash, $minutes);
        $stmt->execute();

        $result = $stmt->get_result()->fetch_assoc();

        return (int)($result['total'] ?? 0);
    }

    /**
     * Remove old rate-limit records.
     */
    public function deleteOlderThanDays(int $days = 7): bool
    {
        $days = max(1, $days);

        $stmt = $this->conn->prepare(
            "DELETE FROM password_reset_attempts
             WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
        );

        $stmt->bind_param('i', $days);

        return $stmt->execute();
    }
}