<?php

function admin_dashboard_handle_update_inquiry_status(mysqli $conn, int $currentUserId, int $inquiryId, string $newStatus): array
{
    $result = [
        'error' => '',
        'message' => '',
        'activeTab' => 'dashboard',
    ];

    if ($inquiryId <= 0 || !isValidInquiryStatus($newStatus)) {
        $result['error'] = 'Invalid inquiry status update request.';
        return $result;
    }

    $statusStmt = $conn->prepare('SELECT status FROM service_inquiries WHERE id = ? LIMIT 1');
    if (!$statusStmt) {
        $result['error'] = 'Unable to load inquiry for update.';
        return $result;
    }

    $statusStmt->bind_param('i', $inquiryId);
    $statusStmt->execute();
    $statusResult = $statusStmt->get_result();
    $existingInquiry = $statusResult ? $statusResult->fetch_assoc() : null;

    if (!$existingInquiry) {
        $result['error'] = 'Inquiry not found.';
        return $result;
    }

    if ((string)($existingInquiry['status'] ?? '') === $newStatus) {
        $result['error'] = 'Inquiry is already marked as ' . $newStatus . '.';
        return $result;
    }

    $updateStmt = $conn->prepare('UPDATE service_inquiries SET status = ? WHERE id = ?');
    if (!$updateStmt) {
        $result['error'] = 'Failed to prepare inquiry update.';
        return $result;
    }

    $updateStmt->bind_param('si', $newStatus, $inquiryId);

    if ($updateStmt->execute()) {
        audit_log_event(
            $conn,
            $currentUserId,
            'update_inquiry_status',
            'service_inquiry',
            $inquiryId,
            ['status' => (string)($existingInquiry['status'] ?? '')],
            ['status' => $newStatus]
        );

        $result['message'] = 'Inquiry status updated to ' . $newStatus . '.';
        return $result;
    }

    $result['error'] = 'Failed to update inquiry status.';
    return $result;
}
