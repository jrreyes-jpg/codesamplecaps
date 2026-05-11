<?php
/**
 * Database Connection
 * Uses Config service for settings (NEVER hardcoded)
 */

require_once __DIR__ . '/Config.php';

$config = Config::getInstance();
$db_config = $config->getDbConnection();

// Create connection using config
$conn = new mysqli(
    $db_config['host'],
    $db_config['user'],
    $db_config['password'],
    $db_config['database'],
    $db_config['port']
);

// Check connection
if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

// Set charset
$conn->set_charset($db_config['charset']);

