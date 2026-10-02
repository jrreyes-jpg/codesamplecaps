<?php

function site_inspection_schedule_token_hash(string $token): string
{
    return hash('sha256', $token);
}

function site_inspection_schedule_public_link(string $token): string
{
    $appUrl = rtrim((string)Config::getInstance()->get('APP_URL', 'http://localhost/codesamplecaps'), '/');
    return $appUrl . '/LOGIN/php/inspection_schedule.php?token=' . urlencode($token);
}

function site_inspection_engineer_link(int $inspectionId): string
{
    $appUrl = rtrim((string)Config::getInstance()->get('APP_URL', 'http://localhost/codesamplecaps'), '/');
    return $appUrl . '/ENGINEER/dashboards/site_inspections.php?inspection_id=' . $inspectionId;
}

function site_inspection_schedule_response_label(string $response): string
{
    return match ($response) {
        'confirmed' => 'Confirmed',
        'reschedule_requested' => 'Reschedule Requested',
        default => 'Pending',
    };
}

function site_inspection_available_time_slots(): array
{
    return [
        '08:00' => '8:00 AM',
        '09:00' => '9:00 AM',
        '10:00' => '10:00 AM',
        '11:00' => '11:00 AM',
        '13:00' => '1:00 PM',
        '14:00' => '2:00 PM',
        '15:00' => '3:00 PM',
        '16:00' => '4:00 PM',
        '17:00' => '5:00 PM',
    ];
}
// Shared Site Inspection helpers para hindi duplicate sa Admin at Engineer.

if (!function_exists('site_inspection_format_datetime')) {
    function site_inspection_format_datetime(?string $dateTime): string
    {
        $timestamp = $dateTime ? strtotime($dateTime) : false;
        if ($timestamp === false) {
            return 'Not set';
        }

        return date('M j, Y, g:ia', $timestamp);
    }
}

if (!function_exists('site_inspection_statuses')) {
    function site_inspection_statuses(): array
    {
        return ['Assigned', 'Acknowledged', 'Ongoing', 'Completed', 'Submitted'];
    }
}

if (!function_exists('site_inspection_next_status')) {
    function site_inspection_next_status(string $status): ?string
    {
        $transitions = [
            'Assigned' => 'Acknowledged',
            'Acknowledged' => 'Ongoing',
            'Ongoing' => 'Completed',
            'Completed' => 'Submitted',
        ];

        return $transitions[$status] ?? null;
    }
}

if (!function_exists('site_inspection_can_transition')) {
    function site_inspection_can_transition(string $currentStatus, string $targetStatus): bool
    {
        return site_inspection_next_status($currentStatus) === $targetStatus;
    }
}

if (!function_exists('site_inspection_admin_review_statuses')) {
    function site_inspection_admin_review_statuses(): array
    {
        return ['Pending', 'Returned', 'Approved'];
    }
}

if (!function_exists('site_inspection_can_admin_review')) {
    function site_inspection_can_admin_review(
        string $inspectionStatus,
        string $currentReviewStatus,
        string $targetReviewStatus
    ): bool {
        return $inspectionStatus === 'Submitted'
            && $currentReviewStatus === 'Pending'
            && in_array($targetReviewStatus, ['Returned', 'Approved'], true);
    }
}

if (!function_exists('site_inspection_transition')) {
    function site_inspection_transition(
        mysqli $conn,
        int $inspectionId,
        int $engineerId,
        string $currentStatus,
        string $targetStatus
    ): bool {
        $timestampColumns = [
            'Acknowledged' => 'acknowledged_at',
            'Ongoing' => 'started_at',
            'Completed' => 'completed_at',
            'Submitted' => 'submitted_at',
        ];

        if (!site_inspection_can_transition($currentStatus, $targetStatus)
            || !isset($timestampColumns[$targetStatus])) {
            return false;
        }

        $timestampColumn = $timestampColumns[$targetStatus];
        $stmt = $conn->prepare(
            "UPDATE site_inspections
             SET status = ?, {$timestampColumn} = NOW()
             WHERE id = ? AND engineer_id = ? AND status = ?"
        );
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('siis', $targetStatus, $inspectionId, $engineerId, $currentStatus);
        $stmt->execute();
        $changed = $stmt->affected_rows === 1;
        $stmt->close();

        return $changed;
    }
}
