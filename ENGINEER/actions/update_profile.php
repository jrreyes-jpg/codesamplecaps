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
$nickname = trim((string)($_POST['nickname'] ?? ''));
$nicknameLength = strlen($nickname);

if ($nickname !== '' && ($nicknameLength < 2 || $nicknameLength > 80)) {
    header('Location: ' . $redirect);
    exit();
}

$photoResult = profile_photo_store_upload($_FILES['profile_photo'] ?? [], $userId);
if ($photoResult['error'] !== null) {
    header('Location: ' . $redirect);
    exit();
}

$role = 'engineer';
$newPhotoPath = $photoResult['path'] ?? null;

if ($newPhotoPath !== null) {
    $stmt = $conn->prepare('UPDATE users SET nickname = ?, profile_photo_path = ? WHERE id = ? AND role = ?');
    $stmt->bind_param('ssis', $nickname, $newPhotoPath, $userId, $role);
} else {
    $stmt = $conn->prepare('UPDATE users SET nickname = ? WHERE id = ? AND role = ?');
    $stmt->bind_param('sis', $nickname, $userId, $role);
}

$stmt->execute();

header('Location: ' . $redirect);
exit();
