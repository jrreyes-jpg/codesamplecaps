<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../includes/engineer_helpers.php';

require_role('engineer');

$redirect = (string)($_POST['return_url'] ?? '/codesamplecaps/ENGINEER/dashboards/overview.php');
if (!str_starts_with($redirect, '/codesamplecaps/ENGINEER/')) {
    $redirect = '/codesamplecaps/ENGINEER/dashboards/overview.php';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !engineer_is_valid_csrf_token($_POST['csrf_token'] ?? null)) {
    header('Location: ' . $redirect);
    exit();
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$photoResult = profile_photo_store_upload($_FILES['profile_photo'] ?? [], $userId);
if ($photoResult['error'] !== null) {
    header('Location: ' . $redirect);
    exit();
}

$role = 'engineer';
$newPhotoPath = $photoResult['path'] ?? null;

if ($newPhotoPath !== null) {
    $stmt = $conn->prepare('UPDATE users SET profile_photo_path = ? WHERE id = ? AND role = ?');
    $stmt->bind_param('sis', $newPhotoPath, $userId, $role);
    $stmt->execute();
}

header('Location: ' . $redirect);
exit();
