<?php
/**
 * User Repository (Data Access Layer)
 * 
 * ALL database operations for users go here
 * Controllers only call these methods - they don't write SQL
 * 
 * This is the "DAO" pattern - Data Access Object
 */

require_once __DIR__ . '/../config/database.php';

class UserRepository {
    private $conn;

    public function __construct($database = null) {
        global $conn;
        $this->conn = $database ?? $conn;
    }

    /**
     * Find user by email
     */
    public function findByEmail($email) {

        $stmt = $this->conn->prepare(
            "SELECT id, full_name, email, password, role, status, 
                    failed_attempts, last_failed_login, reset_requested_at 
             FROM users WHERE email = ? LIMIT 1"
        );
        $stmt->bind_param("s", $email);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Find user by ID
     */
    public function findById($id) {
        $stmt = $this->conn->prepare(
            "SELECT id, full_name, email, phone, role, status, created_at 
             FROM users WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Find user by reset token
     */
    public function findByResetToken($token) {
    $tokenHash = hash('sha256', $token);

    $stmt = $this->conn->prepare(
        "SELECT u.id, u.full_name, u.email
         FROM password_reset_tokens prt
         INNER JOIN users u ON u.id = prt.user_id
         WHERE prt.token = ?
         AND prt.used = 0
         AND prt.expires_at > NOW()
         LIMIT 1"
    );

    $stmt->bind_param("s", $tokenHash);
    $stmt->execute();

    return $stmt->get_result()->fetch_assoc();
}

    /**
     * Create new user
     * 
     * @return int User ID if successful, false otherwise
     */
    public function create($fullName, $email, $passwordHash, $role, $phone = null, $createdBy = null) {
        $stmt = $this->conn->prepare(
            "INSERT INTO users (full_name, email, password, role, phone, created_by, status) 
             VALUES (?, ?, ?, ?, ?, ?, 'active')"
        );
        $stmt->bind_param("sssssi", $fullName, $email, $passwordHash, $role, $phone, $createdBy);
        
        if ($stmt->execute()) {
            return $this->conn->insert_id;
        }
        return false;
    }

    /**
     * Update user password
     */
    public function updatePassword($userId, $passwordHash) {
    $stmt = $this->conn->prepare(
        "UPDATE users
         SET password = ?
         WHERE id = ?"
    );

    $stmt->bind_param("si", $passwordHash, $userId);

    return $stmt->execute();
}
/**
 * Reset password using a valid one-time reset token.
 */
public function resetPasswordByToken($token, $passwordHash) {
    $tokenHash = hash('sha256', $token);

    $this->conn->begin_transaction();

    try {
        $tokenStmt = $this->conn->prepare(
            "SELECT prt.id AS token_id, prt.user_id
             FROM password_reset_tokens prt
             WHERE prt.token = ?
             AND prt.used = 0
             AND prt.expires_at > NOW()
             LIMIT 1
             FOR UPDATE"
        );

        $tokenStmt->bind_param("s", $tokenHash);
        $tokenStmt->execute();

        $resetToken = $tokenStmt->get_result()->fetch_assoc();

        if (!$resetToken) {
            $this->conn->rollback();
            return false;
        }

        $userId = (int)$resetToken['user_id'];
        $tokenId = (int)$resetToken['token_id'];

        $passwordStmt = $this->conn->prepare(
            "UPDATE users
             SET password = ?, reset_token = NULL, token_expiry = NULL
             WHERE id = ?"
        );

        $passwordStmt->bind_param("si", $passwordHash, $userId);

        if (!$passwordStmt->execute()) {
            throw new RuntimeException('Failed to update password.');
        }

        $usedStmt = $this->conn->prepare(
            "UPDATE password_reset_tokens
             SET used = 1, used_at = NOW()
             WHERE id = ?
             AND used = 0"
        );

        $usedStmt->bind_param("i", $tokenId);

        if (!$usedStmt->execute() || $usedStmt->affected_rows !== 1) {
            throw new RuntimeException('Failed to consume reset token.');
        }

        $this->conn->commit();

        return true;
    } catch (Throwable $error) {
        $this->conn->rollback();
        return false;
    }
}
    /**
     * Set password reset token
     */
public function setResetToken($userId, $token, $expiryMinutes = 60) {
    $tokenHash = hash('sha256', $token);
    $expiry = date("Y-m-d H:i:s", strtotime("+$expiryMinutes minutes"));

    $this->conn->begin_transaction();

    try {
        // Invalidate previous unused reset links for this user.
        $invalidateStmt = $this->conn->prepare(
            "UPDATE password_reset_tokens
             SET used = 1, used_at = NOW()
             WHERE user_id = ?
             AND used = 0"
        );
        $invalidateStmt->bind_param("i", $userId);

        if (!$invalidateStmt->execute()) {
            throw new RuntimeException('Failed to invalidate previous reset tokens.');
        }

        // Save only the SHA-256 hash, never the raw token from the email link.
        $insertStmt = $this->conn->prepare(
            "INSERT INTO password_reset_tokens
                (user_id, token, expires_at, used, used_at)
             VALUES (?, ?, ?, 0, NULL)"
        );
        $insertStmt->bind_param("iss", $userId, $tokenHash, $expiry);

        if (!$insertStmt->execute()) {
            throw new RuntimeException('Failed to save reset token.');
        }

        // Keep this timestamp for the forgot-password cooldown.
        $cooldownStmt = $this->conn->prepare(
            "UPDATE users
             SET reset_requested_at = NOW()
             WHERE id = ?"
        );
        $cooldownStmt->bind_param("i", $userId);

        if (!$cooldownStmt->execute()) {
            throw new RuntimeException('Failed to update reset cooldown.');
        }

        $this->conn->commit();
        return true;
    } catch (Throwable $error) {
        $this->conn->rollback();
        return false;
    }
}

    /**
     * Record failed login attempt
     */
    public function recordFailedLogin($userId) {
        $stmt = $this->conn->prepare(
            "UPDATE users SET failed_attempts = failed_attempts + 1, 
             last_failed_login = NOW() WHERE id = ?"
        );
        $stmt->bind_param("i", $userId);
        return $stmt->execute();
    }

    /**
     * Reset failed login attempts
     */
    public function resetFailedAttempts($userId) {
        $stmt = $this->conn->prepare(
            "UPDATE users SET failed_attempts = 0, last_failed_login = NULL WHERE id = ?"
        );
        $stmt->bind_param("i", $userId);
        return $stmt->execute();
    }

    /**
     * Get user by email with failed attempts check
     */
    public function getLoginUser($email) {
        $stmt = $this->conn->prepare(
            "SELECT id, full_name, email, password, role, failed_attempts, last_failed_login 
             FROM users WHERE email = ? AND status = 'active' LIMIT 1"
        );
        $stmt->bind_param("s", $email);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Count users by role
     */
    public function countByRole($role) {
        $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM users WHERE role = ?");
        $stmt->bind_param("s", $role);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result['count'] ?? 0;
    }

    /**
     * Get all users (with pagination)
     */
    public function getAllUsers($limit = 50, $offset = 0, $role = null) {
        if ($role) {
            $stmt = $this->conn->prepare(
                "SELECT id, full_name, email, phone, role, status, created_at, updated_at 
                 FROM users WHERE role = ? ORDER BY created_at DESC LIMIT ? OFFSET ?"
            );
            $stmt->bind_param("sii", $role, $limit, $offset);
        } else {
            $stmt = $this->conn->prepare(
                "SELECT id, full_name, email, phone, role, status, created_at, updated_at 
                 FROM users ORDER BY created_at DESC LIMIT ? OFFSET ?"
            );
            $stmt->bind_param("ii", $limit, $offset);
        }
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Get users by role (simple list)
     */
    public function getUsersByRole($role) {
        $stmt = $this->conn->prepare(
            "SELECT id, full_name, email, phone, status 
             FROM users WHERE role = ? AND status = 'active' 
             ORDER BY full_name ASC"
        );
        $stmt->bind_param("s", $role);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Update user status
     */
    public function updateStatus($userId, $status) {
        $stmt = $this->conn->prepare(
            "UPDATE users SET status = ? WHERE id = ?"
        );
        $stmt->bind_param("si", $status, $userId);
        return $stmt->execute();
    }

    /**
     * Update user profile
     */
    public function updateProfile($userId, $fullName, $phone = null) {
        $stmt = $this->conn->prepare(
            "UPDATE users SET full_name = ?, phone = ? WHERE id = ?"
        );
        $stmt->bind_param("ssi", $fullName, $phone, $userId);
        return $stmt->execute();
    }

    /**
     * Check if email exists
     */
    public function emailExists($email, $excludeId = null) {
        if ($excludeId) {
            $stmt = $this->conn->prepare(
                "SELECT COUNT(*) as count FROM users WHERE email = ? AND id != ?"
            );
            $stmt->bind_param("si", $email, $excludeId);
        } else {
            $stmt = $this->conn->prepare(
                "SELECT COUNT(*) as count FROM users WHERE email = ?"
            );
            $stmt->bind_param("s", $email);
        }
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result['count'] > 0;
    }

    /**
     * Delete user (soft delete - mark inactive)
     */
    public function delete($userId) {
        return $this->updateStatus($userId, 'inactive');
    }

    /**
     * Get database error
     */
    public function getError() {
        return $this->conn->error;
    }
}
