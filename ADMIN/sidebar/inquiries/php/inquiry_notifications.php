<?php
require_once __DIR__ . '/../../../includes/admin_auth.php';
require_once __DIR__ . '/../../../../config/database.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function admin_inquiry_notification_state(mysqli $conn): array
{
    $countResult = $conn->query(
        "SELECT COUNT(*) AS total, MAX(id) AS latest_id
         FROM service_inquiries
         WHERE status = 'Pending Review'
           AND viewed_at IS NULL
           AND archived_at IS NULL"
    );
    $countRow = $countResult ? $countResult->fetch_assoc() : [];

    $items = [];
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
        $items = $itemsResult->fetch_all(MYSQLI_ASSOC);
    }

    return [
        'unread_count' => (int)($countRow['total'] ?? 0),
        'latest_id' => (int)($countRow['latest_id'] ?? 0),
        'items' => $items,
    ];
}

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

    echo json_encode(array_merge(['success' => true], admin_inquiry_notification_state($conn)));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit();
}

$state = admin_inquiry_notification_state($conn);
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
    'show_unread_summary' => $isFirstPoll && $state['unread_count'] > 0,
    'new_items' => $newItems,
]));
