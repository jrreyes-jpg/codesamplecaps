<?php
define('AUTH_REQUIRED_ROLE', 'inventory_clerk');
require_once __DIR__ . '/../../config/auth_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/user_notifications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$state = user_notifications_fetch_unread_state($conn, (int)($_SESSION['user_id'] ?? 0), 20);
$items = [];
foreach ($state['items'] as $notification) {
    if (($notification['type'] ?? '') !== 'material_delivery_count_ready') {
        continue;
    }
    $items[] = [
        'id' => (int)$notification['id'],
        'title' => (string)$notification['title'],
        'message' => (string)$notification['message'],
        'target_url' => (string)$notification['target_url'],
        'relative_time' => user_notifications_relative_time((string)$notification['created_at']),
    ];
}

echo json_encode([
    'unread_count' => count($items),
    'items' => $items,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
