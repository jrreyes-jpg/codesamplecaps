<?php
// Run this script once to ensure asset tracking tables exist.
// Usage: php scripts/setup_asset_tables.php

require_once __DIR__ . '/../config/database.php';

$stmts = [
    // Assets
    "CREATE TABLE IF NOT EXISTS `assets` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `asset_name` varchar(255) NOT NULL,
        `asset_category` varchar(80) DEFAULT NULL,
        `asset_type` varchar(150) DEFAULT NULL,
        `serial_number` varchar(150) DEFAULT NULL,
        `asset_status` enum('available','in_use','maintenance') NOT NULL DEFAULT 'available',
        `criticality` varchar(30) DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `idx_status` (`asset_status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",

    // Asset QR codes
    "CREATE TABLE IF NOT EXISTS `asset_qr_codes` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `asset_id` int(11) NOT NULL,
        `qr_code_value` varchar(512) NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `idx_asset` (`asset_id`),
        CONSTRAINT `fk_asset_qr_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",

    // Asset usage log
    "CREATE TABLE IF NOT EXISTS `asset_usage_logs` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `asset_id` int(11) NOT NULL,
        `foreman_id` int(11) NOT NULL,
        `worker_name` varchar(255) NOT NULL,
        `notes` text DEFAULT NULL,
        `used_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `idx_asset` (`asset_id`),
        KEY `idx_foreman` (`foreman_id`),
        KEY `idx_used_at` (`used_at`),
        CONSTRAINT `fk_usage_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_usage_foreman` FOREIGN KEY (`foreman_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",

    // Asset scan history
    "CREATE TABLE IF NOT EXISTS `asset_scan_history` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `asset_id` int(11) NOT NULL,
        `foreman_id` int(11) NOT NULL,
        `scan_time` timestamp NOT NULL DEFAULT current_timestamp(),
        `scan_device` varchar(255) DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_asset` (`asset_id`),
        KEY `idx_foreman` (`foreman_id`),
        KEY `idx_scan_time` (`scan_time`),
        CONSTRAINT `fk_scan_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_scan_foreman` FOREIGN KEY (`foreman_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;"
];

foreach ($stmts as $sql) {
    if (!$conn->query($sql)) {
        echo "[ERROR] " . $conn->error . "\n";
    }
}

echo "Asset tables are set up (if not already present).\n";
