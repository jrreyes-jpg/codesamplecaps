<?php
define('AUTH_REQUIRED_ROLE', 'inventory_clerk');
require_once __DIR__ . '/../../config/auth_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/user_notifications.php';
require_once __DIR__ . '/../../config/material_physical_count.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$clerkUserId = (int)($_SESSION['user_id'] ?? 0);
material_physical_count_ensure_ready_notifications($conn, $clerkUserId);
$state = user_notifications_fetch_unread_state($conn, $clerkUserId, 20);
$items = [];
foreach ($state['items'] as $notification) {
    if (!in_array((string)($notification['type'] ?? ''), [
        'material_delivery_count_ready',
        'material_physical_count_ready',
        'material_physical_count_recount',
    ], true)) {
        continue;
    }
    $items[] = [
        'id' => (int)$notification['id'],
        'type' => (string)$notification['type'],
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
