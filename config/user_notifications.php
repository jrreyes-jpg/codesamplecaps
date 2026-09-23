<?php

// Shared helpers ito para sa user-specific in-app notifications.

if (!function_exists('user_notifications_table_exists')) {
    function user_notifications_table_exists(mysqli $conn): bool
    {
        static $exists = null;

        if ($exists !== null) {
            return $exists;
        }

        $result = $conn->query("SHOW TABLES LIKE 'user_notifications'");
        $exists = $result instanceof mysqli_result && $result->num_rows > 0;

        return $exists;
    }
}

if (!function_exists('user_notifications_create_if_missing')) {
    function user_notifications_create_if_missing(
        mysqli $conn,
        int $userId,
        string $type,
        int $referenceId,
        string $title,
        string $message,
        string $targetUrl,
        string $dedupeKey
    ): ?bool {
        if ($userId <= 0 || $referenceId <= 0 || trim($dedupeKey) === '' || !user_notifications_table_exists($conn)) {
            return null;
        }

        $stmt = $conn->prepare(
            'INSERT INTO user_notifications
                (user_id, type, reference_id, title, message, target_url, dedupe_key)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = id'
        );
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('isissss', $userId, $type, $referenceId, $title, $message, $targetUrl, $dedupeKey);
        if (!$stmt->execute()) {
            return null;
        }

        return $stmt->affected_rows === 1;
    }
}

if (!function_exists('user_notifications_fetch_unread_state')) {
    function user_notifications_fetch_unread_state(mysqli $conn, int $userId, int $limit = 8): array
    {
        $state = ['unread_count' => 0, 'items' => []];
        if ($userId <= 0 || !user_notifications_table_exists($conn)) {
            return $state;
        }

        $limit = max(1, min(20, $limit));
        $countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM user_notifications WHERE user_id = ? AND read_at IS NULL');
        if ($countStmt) {
            $countStmt->bind_param('i', $userId);
            $countStmt->execute();
            $state['unread_count'] = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        }

        $itemsStmt = $conn->prepare(
            'SELECT id, type, reference_id, title, message, target_url, created_at
             FROM user_notifications
             WHERE user_id = ? AND read_at IS NULL
             ORDER BY created_at DESC, id DESC
             LIMIT ?'
        );
        if ($itemsStmt) {
            $itemsStmt->bind_param('ii', $userId, $limit);
            $itemsStmt->execute();
            $state['items'] = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }

        return $state;
    }
}

if (!function_exists('user_notifications_mark_read')) {
    function user_notifications_mark_read(mysqli $conn, int $notificationId, int $userId): bool
    {
        if ($notificationId <= 0 || $userId <= 0 || !user_notifications_table_exists($conn)) {
            return false;
        }

        $stmt = $conn->prepare(
            'UPDATE user_notifications
             SET read_at = COALESCE(read_at, NOW())
             WHERE id = ? AND user_id = ?'
        );
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('ii', $notificationId, $userId);
        return $stmt->execute();
    }
}

if (!function_exists('user_notifications_relative_time')) {
    function user_notifications_relative_time(?string $dateTime): string
    {
        if (!$dateTime) {
            return '';
        }

        try {
            $timestamp = (new DateTimeImmutable($dateTime))->getTimestamp();
            $seconds = max(0, time() - $timestamp);
            if ($seconds < 60) return 'Just now';
            if ($seconds < 3600) return (string)floor($seconds / 60) . ' min ago';
            if ($seconds < 86400) return (string)floor($seconds / 3600) . ' hr ago';
            if ($seconds < 604800) return (string)floor($seconds / 86400) . ' day(s) ago';

            return (new DateTimeImmutable($dateTime))->format('M j, Y');
        } catch (Throwable $exception) {
            return '';
        }
    }
}
