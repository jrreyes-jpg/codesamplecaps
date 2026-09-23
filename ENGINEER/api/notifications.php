<?php
define('AUTH_REQUIRED_ROLE', 'engineer');
require_once __DIR__ . '/../../config/auth_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/user_notifications.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized request.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    if (!auth_is_valid_csrf($payload['csrf_token'] ?? null, 'engineer_user_notifications')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit();
    }

    $notificationId = (int)($payload['notification_id'] ?? 0);
    if ($notificationId <= 0 || !user_notifications_mark_read($conn, $notificationId, $userId)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Unable to update notification.']);
        exit();
    }

    echo json_encode(array_merge(['success' => true], user_notifications_fetch_unread_state($conn, $userId)));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit();
}

echo json_encode(array_merge(['success' => true], user_notifications_fetch_unread_state($conn, $userId)));
