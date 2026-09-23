<?php
require_once __DIR__ . '/../../../includes/admin_auth.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/user_notifications.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function admin_inquiry_notification_state(mysqli $conn, int $userId): array
{
    $countResult = $conn->query(
        "SELECT COUNT(*) AS total, MAX(id) AS latest_id
         FROM service_inquiries
         WHERE status = 'Pending Review'
           AND viewed_at IS NULL
           AND archived_at IS NULL"
    );
    $countRow = $countResult ? $countResult->fetch_assoc() : [];

    $inquiryItems = [];
    $itemsResult = $conn->query(
        "SELECT id, client_name, service_category, created_at
         FROM service_inquiries
         WHERE status = 'Pending Review'
           AND viewed_at IS NULL
           AND archived_at IS NULL
         ORDER BY created_at DESC, id DESC
         LIMIT 8"
    );
    if ($itemsResult) {
        $inquiryItems = $itemsResult->fetch_all(MYSQLI_ASSOC);
    }

    $inquiryUnreadCount = (int)($countRow['total'] ?? 0);
    $genericState = user_notifications_fetch_unread_state($conn, $userId);
    $items = [];

    foreach ($inquiryItems as $inquiry) {
        $items[] = [
            'source' => 'inquiry',
            'id' => (int)($inquiry['id'] ?? 0),
            'client_name' => (string)($inquiry['client_name'] ?? ''),
            'service_category' => (string)($inquiry['service_category'] ?? ''),
            'created_at' => (string)($inquiry['created_at'] ?? ''),
        ];
    }

    foreach ($genericState['items'] as $notification) {
        $items[] = [
            'source' => 'user_notification',
            'id' => (int)($notification['id'] ?? 0),
            'title' => (string)($notification['title'] ?? ''),
            'message' => (string)($notification['message'] ?? ''),
            'target_url' => (string)($notification['target_url'] ?? ''),
            'created_at' => (string)($notification['created_at'] ?? ''),
        ];
    }

    usort($items, static function (array $left, array $right): int {
        return strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
    });

    return [
        'unread_count' => $inquiryUnreadCount + (int)($genericState['unread_count'] ?? 0),
        'inquiry_unread_count' => $inquiryUnreadCount,
        'user_notification_unread_count' => (int)($genericState['unread_count'] ?? 0),
        'latest_id' => (int)($countRow['latest_id'] ?? 0),
        'items' => array_slice($items, 0, 8),
    ];
}

$userId = (int)($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    if (!auth_is_valid_csrf($payload['csrf_token'] ?? null, 'admin_inquiry_notifications')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit();
    }

    $source = (string)($payload['source'] ?? 'inquiry');
    if ($source === 'user_notification') {
        $notificationId = (int)($payload['notification_id'] ?? 0);
        if ($notificationId <= 0 || !user_notifications_mark_read($conn, $notificationId, $userId)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Unable to update notification.']);
            exit();
        }

        echo json_encode(array_merge(['success' => true], admin_inquiry_notification_state($conn, $userId)));
        exit();
    }

    $inquiryId = (int)($payload['inquiry_id'] ?? 0);
    if ($inquiryId <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Invalid inquiry.']);
        exit();
    }

    $stmt = $conn->prepare(
        'UPDATE service_inquiries
         SET viewed_at = COALESCE(viewed_at, NOW())
         WHERE id = ? AND archived_at IS NULL'
    );
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to update notification.']);
        exit();
    }

    $stmt->bind_param('i', $inquiryId);
    $stmt->execute();
    unset($_SESSION['super_admin_sidebar_notification_data']);

    echo json_encode(array_merge(['success' => true], admin_inquiry_notification_state($conn, $userId)));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit();
}

$state = admin_inquiry_notification_state($conn, $userId);
$isFirstPoll = !array_key_exists('admin_inquiry_notification_last_id', $_SESSION);
$previousLatestId = (int)($_SESSION['admin_inquiry_notification_last_id'] ?? 0);
$newItems = [];

if (!$isFirstPoll && $state['latest_id'] > $previousLatestId) {
    $stmt = $conn->prepare(
        "SELECT id, client_name, service_category, created_at
         FROM service_inquiries
         WHERE status = 'Pending Review'
           AND viewed_at IS NULL
           AND archived_at IS NULL
           AND id > ?
         ORDER BY id ASC"
    );
    if ($stmt) {
        $stmt->bind_param('i', $previousLatestId);
        $stmt->execute();
        $newItems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

$_SESSION['admin_inquiry_notification_last_id'] = max($previousLatestId, $state['latest_id']);

echo json_encode(array_merge($state, [
    'success' => true,
    'show_unread_summary' => $isFirstPoll && $state['inquiry_unread_count'] > 0,
    'new_items' => $newItems,
]));
