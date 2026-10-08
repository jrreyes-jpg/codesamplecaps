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

if (!function_exists('user_notifications_active_target_condition')) {
    function user_notifications_active_target_condition(string $alias = 'un'): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
            $alias = 'un';
        }

        $inspectionTypes = "'site_inspection_assignment', 'site_inspection_schedule_updated', "
            . "'client_inspection_reschedule_requested', 'engineer_inspection_reschedule_requested'";

        return "(
            {$alias}.type NOT IN ({$inspectionTypes}, 'client_quotation_response')
            OR (
                {$alias}.type IN ({$inspectionTypes})
                AND EXISTS (
                    SELECT 1
                    FROM site_inspections notification_inspection
                    INNER JOIN service_inquiries notification_inquiry
                        ON notification_inquiry.id = notification_inspection.inquiry_id
                       AND notification_inquiry.archived_at IS NULL
                    WHERE notification_inspection.id = {$alias}.reference_id
                )
            )
            OR (
                {$alias}.type = 'client_quotation_response'
                AND EXISTS (
                    SELECT 1
                    FROM inquiry_quotation_drafts notification_quote
                    INNER JOIN service_inquiries notification_inquiry
                        ON notification_inquiry.id = notification_quote.inquiry_id
                       AND notification_inquiry.archived_at IS NULL
                    WHERE notification_quote.id = {$alias}.reference_id
                )
            )
        )";
    }
}

if (!function_exists('user_notifications_retire_for_inquiry')) {
    function user_notifications_retire_for_inquiry(mysqli $conn, int $inquiryId): bool
    {
        if ($inquiryId <= 0 || !user_notifications_table_exists($conn)) {
            return $inquiryId > 0;
        }

        $stmt = $conn->prepare(
            "UPDATE user_notifications un
             SET un.read_at = COALESCE(un.read_at, NOW())
             WHERE (
                un.type IN (
                    'site_inspection_assignment',
                    'site_inspection_schedule_updated',
                    'client_inspection_reschedule_requested',
                    'engineer_inspection_reschedule_requested'
                )
                AND EXISTS (
                    SELECT 1
                    FROM site_inspections notification_inspection
                    WHERE notification_inspection.id = un.reference_id
                      AND notification_inspection.inquiry_id = ?
                )
             ) OR (
                un.type = 'client_quotation_response'
                AND EXISTS (
                    SELECT 1
                    FROM inquiry_quotation_drafts notification_quote
                    WHERE notification_quote.id = un.reference_id
                      AND notification_quote.inquiry_id = ?
                )
             )"
        );
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('ii', $inquiryId, $inquiryId);
        return $stmt->execute();
    }
}

if (!function_exists('user_notifications_delete_for_inquiry')) {
    function user_notifications_delete_for_inquiry(mysqli $conn, int $inquiryId): bool
    {
        if ($inquiryId <= 0 || !user_notifications_table_exists($conn)) {
            return $inquiryId > 0;
        }

        $stmt = $conn->prepare(
            "DELETE un
             FROM user_notifications un
             WHERE (
                un.type IN (
                    'site_inspection_assignment',
                    'site_inspection_schedule_updated',
                    'client_inspection_reschedule_requested',
                    'engineer_inspection_reschedule_requested'
                )
                AND EXISTS (
                    SELECT 1
                    FROM site_inspections notification_inspection
                    WHERE notification_inspection.id = un.reference_id
                      AND notification_inspection.inquiry_id = ?
                )
             ) OR (
                un.type = 'client_quotation_response'
                AND EXISTS (
                    SELECT 1
                    FROM inquiry_quotation_drafts notification_quote
                    WHERE notification_quote.id = un.reference_id
                      AND notification_quote.inquiry_id = ?
                )
             )"
        );
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('ii', $inquiryId, $inquiryId);
        return $stmt->execute();
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
        $activeTargetCondition = user_notifications_active_target_condition('un');
        $countStmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM user_notifications un
             WHERE un.user_id = ?
               AND un.read_at IS NULL
               AND {$activeTargetCondition}"
        );
        if ($countStmt) {
            $countStmt->bind_param('i', $userId);
            $countStmt->execute();
            $state['unread_count'] = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        }

        $itemsStmt = $conn->prepare(
            "SELECT un.id, un.type, un.reference_id, un.title, un.message, un.target_url, un.created_at
             FROM user_notifications un
             WHERE un.user_id = ?
               AND un.read_at IS NULL
               AND {$activeTargetCondition}
             ORDER BY un.created_at DESC, un.id DESC
             LIMIT ?"
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
