<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../vendor/autoload.php';

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
    /**
     * @var Config|null
     */
    private static $instance = null;

    /**
     * @var array<string, mixed>
     */
    private $settings = [];
private function __construct() {
    $this->loadEnvironment();
    $this->loadSettings();
}
  

    /**
     * Basahin ang .env file kung meron, para hindi ilalagay sa code ang email password.
     */
 private function loadEnvironment() {
    if (!class_exists(\Dotenv\Dotenv::class) || !is_file(__DIR__ . '/../.env')) {
        return;
    }

    $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->safeLoad();
}
private function env($key, $default = null)
{
    $value = $_ENV[$key]
        ?? $_SERVER[$key]
        ?? getenv($key);

    if ($value === false || $value === null || trim((string)$value) === '') {
        return $default;
    }

    return $value;
}
    /**
     * Get singleton instance
     * 
     * @return Config
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
        $this->settings['DB_HOST'] = $this->env('DB_HOST') ?: '127.0.0.1';
        $this->settings['DB_PORT'] = $this->env('DB_PORT') ?: '3306';
        $this->settings['DB_USER'] = $this->env('DB_USER') ?: 'root';
        $this->settings['DB_PASS'] = $this->env('DB_PASS') ?: '';
        $this->settings['DB_NAME'] = $this->env('DB_NAME') ?: 'edge_project_asset_inventory_db';
        $this->settings['DB_CHARSET'] = 'utf8mb4';

        // ============ APP ============
        $this->settings['APP_NAME'] = 'Edge Automation';
        $this->settings['APP_URL'] = $this->env('APP_URL') ?: 'http://localhost/codesamplecaps';
        $this->settings['APP_TIMEZONE'] = 'Asia/Manila';
        // ============ EMAIL/SMTP ============
        $this->settings['MAIL_DRIVER'] = $this->env('MAIL_DRIVER') ?: 'smtp';
        $this->settings['MAIL_HOST'] = $this->env('MAIL_HOST') ?: 'smtp.gmail.com';
        $this->settings['MAIL_PORT'] = $this->env('MAIL_PORT') ?: 587;
        $this->settings['MAIL_USERNAME'] = $this->env('MAIL_USERNAME') ?: ($this->env('GMAIL_SMTP_USER') ?: 'ejimenez.edge@gmail.com');
        $this->settings['MAIL_PASSWORD'] = $this->env('MAIL_PASSWORD') ?: ($this->env('GMAIL_SMTP_APP_PASSWORD') ?: '');
        $this->settings['MAIL_ENCRYPTION'] = $this->env('MAIL_ENCRYPTION') ?: 'tls';
        $this->settings['MAIL_FROM_ADDRESS'] = $this->env('MAIL_FROM_ADDRESS') ?: $this->settings['MAIL_USERNAME'];
        $this->settings['MAIL_FROM_NAME'] = $this->env('MAIL_FROM_NAME') ?: 'Edge Automation';

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
     * 
     * @param string $key Configuration key
     * @return bool
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
