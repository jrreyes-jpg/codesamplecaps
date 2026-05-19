<?php
/**
 * User Controller
 * 
 * Handles user-related HTTP requests
 * Calls services for business logic - NO business logic here
 * 
 * Controllers = HTTP layer
 * Services = Business logic
 * Repositories = Data access
 */

require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../repositories/UserRepository.php';
require_once __DIR__ . '/../config/auth_middleware.php';

class UserController {
    private $authService;
    private $userRepo;

    public function __construct() {
        $this->authService = new AuthService();
        $this->userRepo = new UserRepository();
    }

    /**
     * Create engineer account (super_admin only)
     */
    public function createEngineer($fullName, $email, $phone = null, $createdByUserId = null) {
        require_role('super_admin');
        return $this->authService->createAccount($fullName, $email, 'engineer', $phone, $createdByUserId);
    }

    /**
     * Create manpower account (super_admin only)
     */
    public function createManpower($fullName, $email, $phone = null, $createdByUserId = null) {
        require_role('super_admin');
        return $this->authService->createAccount($fullName, $email, 'manpower', $phone, $createdByUserId);
    }

    /**
     * Create client account (super_admin only)
     */
    public function createClient($fullName, $email, $phone = null, $createdByUserId = null) {
        require_role('super_admin');
        return $this->authService->createAccount($fullName, $email, 'client', $phone, $createdByUserId);
    }

    /**
     * Process user login
     */
    public function login($email, $password) {
        return $this->authService->login($email, $password);
    }

    /**
     * Request password reset
     */
    public function requestPasswordReset($email) {
        return $this->authService->requestPasswordReset($email);
    }

    /**
     * Reset password with token
     */
    public function resetPassword($token, $newPassword) {
        return $this->authService->resetPassword($token, $newPassword);
    }

    /**
     * Change password for logged-in user
     */
    public function changePassword($userId, $currentPassword, $newPassword) {
        return $this->authService->changePassword($userId, $currentPassword, $newPassword);
    }

    /**
     * Get all users (super_admin viewing)
     */
    public function getAllUsers($limit = 50, $offset = 0, $role = null) {
        require_role('super_admin');
        return $this->userRepo->getAllUsers($limit, $offset, $role);
    }

    /**
     * Get engineers list
     */
    public function getEngineers() {
        return $this->userRepo->getUsersByRole('engineer');
    }

    /**
     * Get manpower list
     */
    public function getManpower() {
        return $this->userRepo->getUsersByRole('manpower');
    }

    /**
     * Get clients list
     */
    public function getClients() {
        return $this->userRepo->getUsersByRole('client');
    }

    /**
     * Deactivate user
     */
    public function deactivateUser($userId) {
        require_role('super_admin');
        return $this->userRepo->updateStatus($userId, 'inactive');
    }

    /**
     * Activate user
     */
    public function activateUser($userId) {
        require_role('super_admin');
        return $this->userRepo->updateStatus($userId, 'active');
    }

    /**
     * Get user by ID
     */
    public function getUser($userId) {
        return $this->userRepo->findById($userId);
    }
}
