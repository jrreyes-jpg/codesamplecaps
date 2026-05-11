<?php
/**
 * Configuration Service
 * 
 * Centralized configuration management
 * All settings in one place - NEVER hardcoded in views/controllers
 * 
 * Usage:
 *   $config = Config::getInstance();
 *   $email = $config->get('MAIL_USERNAME');
 */

class Config {
    private static $instance = null;
    private $settings = [];

    private function __construct() {
        $this->loadSettings();
    }

    /**
     * Get singleton instance
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Load all settings
     * Place environment-specific values here
     */
    private function loadSettings() {
        // ============ DATABASE ============
        $this->settings['DB_HOST'] = getenv('DB_HOST') ?: '127.0.0.1';
        $this->settings['DB_PORT'] = getenv('DB_PORT') ?: '3306';
        $this->settings['DB_USER'] = getenv('DB_USER') ?: 'root';
        $this->settings['DB_PASS'] = getenv('DB_PASS') ?: '';
        $this->settings['DB_NAME'] = getenv('DB_NAME') ?: 'edge_project_asset_inventory_db';
        $this->settings['DB_CHARSET'] = 'utf8mb4';

        // ============ APP ============
        $this->settings['APP_NAME'] = 'Edge Automation';
        $this->settings['APP_URL'] = getenv('APP_URL') ?: 'http://localhost/CAPSTONE';
        $this->settings['APP_TIMEZONE'] = 'Asia/Manila';
        // ============ EMAIL/SMTP ============
        $this->settings['MAIL_DRIVER'] = getenv('MAIL_DRIVER') ?: 'smtp';
        $this->settings['MAIL_HOST'] = getenv('MAIL_HOST') ?: 'smtp.gmail.com';
        $this->settings['MAIL_PORT'] = getenv('MAIL_PORT') ?: 587;
        $this->settings['MAIL_USERNAME'] = getenv('MAIL_USERNAME') ?: 'jeshowap@gmail.com';
        $this->settings['MAIL_PASSWORD'] = getenv('MAIL_PASSWORD') ?: 'ypjvvfqfaeddpnil';
        $this->settings['MAIL_ENCRYPTION'] = getenv('MAIL_ENCRYPTION') ?: 'tls';
        $this->settings['MAIL_FROM_ADDRESS'] = getenv('MAIL_FROM_ADDRESS') ?: 'jeshowap@gmail.com';
        $this->settings['MAIL_FROM_NAME'] = getenv('MAIL_FROM_NAME') ?: 'Edge Automation';

        // ============ SECURITY ============
        $this->settings['PASSWORD_RESET_EXPIRY_MINUTES'] = 60;
        $this->settings['LOGIN_MAX_ATTEMPTS'] = 5;
        $this->settings['LOGIN_LOCKOUT_MINUTES'] = 15;

        // ============ SESSION ============
        $this->settings['SESSION_TIMEOUT_MINUTES'] = 60;
        date_default_timezone_set($this->settings['APP_TIMEZONE']);
    }

    /**
     * Get a configuration value
     * 
     * @param string $key Configuration key
     * @param mixed $default Default value if not found
     * @return mixed Configuration value
     */
    public function get($key, $default = null) {
        return $this->settings[$key] ?? $default;
    }

    /**
     * Set a configuration value (runtime)
     * 
     * @param string $key Configuration key
     * @param mixed $value Configuration value
     */
    public function set($key, $value) {
        $this->settings[$key] = $value;
    }

    /**
     * Check if configuration key exists
     */
    public function has($key) {
        return isset($this->settings[$key]);
    }

    /**
     * Get all settings (use with caution)
     */
    public function all() {
        return $this->settings;
    }

    /**
     * Get database connection array
     */
    public function getDbConnection() {
        return [
            'host' => $this->get('DB_HOST'),
            'port' => $this->get('DB_PORT'),
            'user' => $this->get('DB_USER'),
            'password' => $this->get('DB_PASS'),
            'database' => $this->get('DB_NAME'),
            'charset' => $this->get('DB_CHARSET'),
        ];
    }

    /**
     * Get mail configuration
     */
    public function getMailConfig() {
        return [
            'driver' => $this->get('MAIL_DRIVER'),
            'host' => $this->get('MAIL_HOST'),
            'port' => $this->get('MAIL_PORT'),
            'username' => $this->get('MAIL_USERNAME'),
            'password' => $this->get('MAIL_PASSWORD'),
            'encryption' => $this->get('MAIL_ENCRYPTION'),
            'from_address' => $this->get('MAIL_FROM_ADDRESS'),
            'from_name' => $this->get('MAIL_FROM_NAME'),
        ];
    }
}
