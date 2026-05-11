<?php
require_once __DIR__ . '/config/auth_middleware.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/profile_photo_storage.php';

require_role('super_admin');

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    profile_photo_output_default_image();
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
