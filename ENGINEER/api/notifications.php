<?php
define('AUTH_REQUIRED_ROLE', 'engineer');
require_once __DIR__ . '/../../config/auth_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/user_notifications.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function engineer_notification_assignment_items(mysqli $conn, int $userId, int $limit = 20): array
{
    if ($userId <= 0 || !user_notifications_table_exists($conn)) {
        return [];
    }

    $limit = max(1, min(50, $limit));
    $stmt = $conn->prepare(
        "SELECT un.id AS notification_id, un.reference_id AS inspection_id, s.client_name
         FROM user_notifications un
         INNER JOIN site_inspections si
            ON si.id = un.reference_id
           AND si.engineer_id = un.user_id
         INNER JOIN service_inquiries s
            ON s.id = si.inquiry_id
           AND s.archived_at IS NULL
         WHERE un.user_id = ?
           AND un.type = 'site_inspection_assignment'
         ORDER BY un.id DESC
         LIMIT ?"
    );
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('ii', $userId, $limit);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function engineer_notification_latest_id(mysqli $conn, int $userId): int
{
    if ($userId <= 0 || !user_notifications_table_exists($conn)) {
        return 0;
    }

    $stmt = $conn->prepare('SELECT COALESCE(MAX(id), 0) AS latest_id FROM user_notifications WHERE user_id = ?');
    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    return (int)($stmt->get_result()->fetch_assoc()['latest_id'] ?? 0);
}

function engineer_notification_state(mysqli $conn, int $userId, bool $trackNewAssignments): array
{
    $state = user_notifications_fetch_unread_state($conn, $userId);
    $latestId = engineer_notification_latest_id($conn, $userId);
    $assignmentItems = engineer_notification_assignment_items($conn, $userId);
    $newItems = [];

    if ($trackNewAssignments) {
        $sessionKey = 'engineer_notification_last_id';
        $isFirstPoll = !array_key_exists($sessionKey, $_SESSION);
        $previousId = (int)($_SESSION[$sessionKey] ?? 0);
        if (!$isFirstPoll && $latestId > $previousId) {
            $newItems = array_values(array_filter(
                $assignmentItems,
                static fn(array $item): bool => (int)($item['notification_id'] ?? 0) > $previousId
            ));
        }
        $_SESSION[$sessionKey] = max($previousId, $latestId);
    }

    return array_merge($state, [
        'latest_id' => $latestId,
        'assignment_items' => $assignmentItems,
        'new_items' => $newItems,
    ]);
}

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

    echo json_encode(array_merge(['success' => true], engineer_notification_state($conn, $userId, false)));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit();
}

echo json_encode(array_merge(['success' => true], engineer_notification_state($conn, $userId, true)));
