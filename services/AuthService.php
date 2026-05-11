<?php
/**
 * Authentication Service
 * 
 * Core authentication business logic
 * - User login with security (attempt tracking, lockout)
 * - Account creation by super admin
 * - Password reset flow
 * 
 * Controllers call these methods - they contain ALL the business logic
 */

require_once __DIR__ . '/../repositories/UserRepository.php';
require_once __DIR__ . '/../services/EmailService.php';
require_once __DIR__ . '/../config/Config.php';

class AuthService {
    private $userRepo;
    private $emailService;
    private $config;
    private $error = '';
    private $success = '';

    public function __construct() {
        $this->userRepo = new UserRepository();
        $this->emailService = new EmailService();
        $this->config = Config::getInstance();
    }

    /**
     * Authenticate user login
     * 
     * Handles:
     * - Failed attempt tracking
     * - Account lockout after X attempts
     * - Password verification
     * - Session setup
     * 
     * @param string $email User email
     * @param string $password User password
     * @return array ['success' => bool, 'user' => array, 'error' => string]
     */
    public function login($email, $password) {
        // Validation
        if (empty($email) || empty($password)) {
            return ['success' => false, 'error' => 'Please provide email and password.'];
        }

        $email = filter_var($email, FILTER_SANITIZE_EMAIL);

        // Get user with login credentials
        $user = $this->userRepo->getLoginUser($email);

        if (!$user) {
            // User not found - generic message for security
            return ['success' => false, 'error' => 'Invalid email or password.'];
        }

        // Check if account is locked
        $maxAttempts = $this->config->get('LOGIN_MAX_ATTEMPTS');
        $lockoutMinutes = $this->config->get('LOGIN_LOCKOUT_MINUTES');
        
        if ($user['failed_attempts'] >= $maxAttempts) {
            $lastFailedTime = strtotime($user['last_failed_login']);
            $lockoutExpires = $lastFailedTime + ($lockoutMinutes * 60);
            
            if (time() < $lockoutExpires) {
                return [
                    'success' => false, 
                    'error' => 'Too many failed attempts. Please try again later.',
                    'locked' => true
                ];
            }
        }

        // Verify password
        if (!password_verify($password, $user['password'])) {
            // Wrong password - record attempt
            $this->userRepo->recordFailedLogin($user['id']);
            return ['success' => false, 'error' => 'Invalid email or password.'];
        }

        // Success - reset attempts and return user
        $this->userRepo->resetFailedAttempts($user['id']);

        return [
            'success' => true,
            'user' => $user,
            'error' => null
        ];
    }

    /**
     * Register new user (Public signup - clients only)
     * 
     * Only allows registration as 'client' role
     * Handles validation and account creation
     * 
     * @param string $fullName User full name
     * @param string $email User email
     * @param string $password User password (plain text - will be hashed)
     * @param string $role User role (defaults to 'client')
     * @return array ['success' => bool, 'error' => string, 'message' => string]
     */
    public function registerUser($fullName, $email, $password, $role = 'client') {
        // Validation
        $fullName = trim($fullName);
        $email = filter_var($email, FILTER_SANITIZE_EMAIL);
        $password = trim($password);

        // Validate full name
        if (empty($fullName)) {
            return ['success' => false, 'error' => 'Full name is required.'];
        }

        if (strlen($fullName) < 3) {
            return ['success' => false, 'error' => 'Full name must be at least 3 characters.'];
        }

        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid email format.'];
        }

        // Validate password
        if (strlen($password) < 8) {
            return ['success' => false, 'error' => 'Password must be at least 8 characters.'];
        }

        // Check if email already exists
        if ($this->userRepo->emailExists($email)) {
            return ['success' => false, 'error' => 'Email address already registered. Please use another email or login.'];
        }

        // Only allow 'client' role for public registration
        if ($role !== 'client') {
            $role = 'client';
        }

        // Hash password
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        // Create user (no created_by for public signup)
        $userId = $this->userRepo->create($fullName, $email, $passwordHash, $role, null, null);

        if (!$userId) {
            return ['success' => false, 'error' => 'Failed to create account. Please try again.'];
        }

        // Send welcome email
        $this->emailService->sendAccountCreated($email, $fullName, $role);

        return [
            'success' => true,
            'message' => 'Account created successfully. Please login with your email and password.'
        ];
    }

    /**
     * Create new user account (Super Admin only)
     * 
     * @param string $fullName User full name
     * @param string $email User email
     * @param string $role User role (engineer, manpower, client)
     * @param string $phone User phone (optional)
     * @param int $createdBy User ID of admin creating this account
     * @return array ['success' => bool, 'userId' => id, 'error' => string]
     */
    public function createAccount($fullName, $email, $role, $phone = null, $createdBy = null) {
        // Validation
        $fullName = trim($fullName);
        $email = filter_var($email, FILTER_SANITIZE_EMAIL);
        $phone = $phone ? trim($phone) : null;

        if (empty($fullName)) {
            return ['success' => false, 'error' => 'Full name is required.'];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid email format.'];
        }

        if (!in_array($role, ['engineer', 'manpower', 'client'])) {
            return ['success' => false, 'error' => 'Invalid role.'];
        }

        // Check if email already exists
        if ($this->userRepo->emailExists($email)) {
            return ['success' => false, 'error' => 'Email already exists.'];
        }

        // Generate temporary password (user will reset on first login)
        $tempPassword = bin2hex(random_bytes(8));
        $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);

        // Create user
        $userId = $this->userRepo->create($fullName, $email, $passwordHash, $role, $phone, $createdBy);

        if (!$userId) {
            return ['success' => false, 'error' => 'Failed to create account. ' . $this->userRepo->getError()];
        }

        // Send welcome email
        $this->emailService->sendAccountCreated($email, $fullName, $role);

        return [
            'success' => true,
            'userId' => $userId,
            'message' => 'Account created successfully. Welcome email sent to ' . $email
        ];
    }

    /**
     * Initiate password reset (Forgot Password)
     * 
     * Generates reset token and sends email
     * 
     * @param string $email User email
     * @return array ['success' => bool, 'error' => string]
     */
    public function requestPasswordReset($email) {
        $email = filter_var($email, FILTER_SANITIZE_EMAIL);

        if (empty($email)) {
            // Generic message for security
            return ['success' => false, 'error' => 'Please provide your email address.'];
        }

        $user = $this->userRepo->findByEmail($email);

        // Prevent reset spam (60 seconds cooldown)
if ($user && !empty($user['reset_requested_at'])) {
    $lastRequest = strtotime($user['reset_requested_at']);
    if ((time() - $lastRequest) < 60) {
        return [
            'success' => false,
            'error' => 'Please wait before requesting another reset link.'
        ];
    }
}


        if (!$user) {
            // User not found - don't reveal this
            return ['success' => true, 'message' => 'If the email exists, a reset link will be sent.'];
        }

        // Generate reset token
        $resetToken = bin2hex(random_bytes(50));
        $expiryMinutes = $this->config->get('PASSWORD_RESET_EXPIRY_MINUTES');

        // Save token in database
        if (!$this->userRepo->setResetToken($user['id'], $resetToken, $expiryMinutes)) {
            return ['success' => false, 'error' => 'Failed to generate reset token.'];
        }

        // Send reset email
        if (!$this->emailService->sendPasswordReset($email, $user['full_name'], $resetToken, $expiryMinutes)) {
            return ['success' => false, 'error' => 'Failed to send reset email. ' . $this->emailService->getError()];
        }

        return ['success' => true, 'message' => 'Password reset link sent to your email.'];
    }

    /**
     * Reset password with token
     * 
     * @param string $token Reset token from email
     * @param string $newPassword New password
     * @return array ['success' => bool, 'error' => string]
     */
    public function resetPassword($token, $newPassword) {
        // Validation
        $token = trim($token);
        $newPassword = trim($newPassword);

        if (empty($token)) {
            return ['success' => false, 'error' => 'Invalid or expired reset link.'];
        }

        if (strlen($newPassword) < 8) {
            return ['success' => false, 'error' => 'Password must be at least 8 characters.'];
        }

        // Find user with valid (non-expired) token
        $user = $this->userRepo->findByResetToken($token);

        if (!$user) {
            return ['success' => false, 'error' => 'Invalid or expired reset link.'];
        }

        // Hash new password
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

        // Update password and clear token
        if (!$this->userRepo->updatePassword($user['id'], $passwordHash)) {
            return ['success' => false, 'error' => 'Failed to reset password.'];
        }

        return ['success' => true, 'message' => 'Password reset successfully. You can now login.'];
    }

    /**
     * Change password for logged-in user
     * 
     * @param int $userId User ID
     * @param string $currentPassword Current password for verification
     * @param string $newPassword New password
     * @return array ['success' => bool, 'error' => string]
     */
    public function changePassword($userId, $currentPassword, $newPassword) {
        // Validation
        if (empty($currentPassword) || empty($newPassword)) {
            return ['success' => false, 'error' => 'All fields are required.'];
        }

        if (strlen($newPassword) < 8) {
            return ['success' => false, 'error' => 'New password must be at least 8 characters.'];
        }

        if ($currentPassword === $newPassword) {
            return ['success' => false, 'error' => 'New password must be different from current password.'];
        }

        // Get user
        $user = $this->userRepo->findById($userId);
        if (!$user) {
            return ['success' => false, 'error' => 'User not found.'];
        }

        // Verify current password
        if (!password_verify($currentPassword, $user['password'])) {
            return ['success' => false, 'error' => 'Current password is incorrect.'];
        }

        // Hash new password and update
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if (!$this->userRepo->updatePassword($userId, $passwordHash)) {
            return ['success' => false, 'error' => 'Failed to change password.'];
        }

        return ['success' => true, 'message' => 'Password changed successfully.'];
    }

    /**
     * Get error message
     */
    public function getError() {
        return $this->error;
    }

    /**
     * Get success message
     */
    public function getSuccess() {
        return $this->success;
    }
}
