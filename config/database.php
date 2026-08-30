<?php
/**
 * Database Connection
 * Uses Config service for settings (NEVER hardcoded)
 */

require_once __DIR__ . '/Config.php';

$config = Config::getInstance();
$db_config = $config->getDbConnection();

try {
    // Create connection using config
    $conn = new mysqli(
        $db_config['host'],
        $db_config['user'],
        $db_config['password'],
        $db_config['database'],
        (int)$db_config['port']
    );

    // Set charset
    $conn->set_charset($db_config['charset']);
} catch (mysqli_sql_exception $exception) {
    http_response_code(500);
    error_log('Database connection failed: ' . $exception->getMessage());
    die('Database Connection Failed. Please make sure MySQL is running in XAMPP and the database settings are correct.');
}

