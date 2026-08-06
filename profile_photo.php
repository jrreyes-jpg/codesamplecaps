<?php
require_once __DIR__ . '/config/auth_middleware.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/profile_photo_storage.php';

require_any_role(['super_admin', 'admin', 'inventory_clerk', 'engineer', 'foreman', 'client']);

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    profile_photo_output_default_image();
}

$columnResult = $conn->query("SHOW COLUMNS FROM users LIKE 'profile_photo_path'");
if ($columnResult && $columnResult->num_rows === 0) {
    $conn->query('ALTER TABLE users ADD COLUMN profile_photo_path VARCHAR(255) NULL AFTER token_expiry');
}

$stmt = $conn->prepare('SELECT profile_photo_path FROM users WHERE id = ? LIMIT 1');
if (!$stmt) {
    profile_photo_output_default_image();
}

$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();
$row = $result ? $result->fetch_assoc() : null;
$reference = $row['profile_photo_path'] ?? null;

profile_photo_output_reference($reference);
