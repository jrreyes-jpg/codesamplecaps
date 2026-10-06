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

function site_inspection_timezone(): DateTimeZone
{
    return new DateTimeZone('Asia/Manila');
}

function site_inspection_duration_minutes(): int
{
    return 60;
}

function site_inspection_minimum_lead_minutes(): int
{
    return 60;
}

function site_inspection_available_time_slots(): array
{
    $slots = [];
    foreach ([[8 * 60, 11 * 60 + 30], [13 * 60, 16 * 60]] as [$start, $end]) {
        for ($minutes = $start; $minutes <= $end; $minutes += 30) {
            $hour = intdiv($minutes, 60);
            $minute = $minutes % 60;
            $value = sprintf('%02d:%02d', $hour, $minute);
            $slots[$value] = (new DateTimeImmutable($value, site_inspection_timezone()))->format('g:i A');
        }
    }

    return $slots;
}

function site_inspection_validate_start(string $date, string $time, ?DateTimeImmutable $now = null): array
{
    $timezone = site_inspection_timezone();
    $candidate = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $timezone);
    $parseErrors = DateTimeImmutable::getLastErrors();
    $hasParseErrors = is_array($parseErrors)
        && ((int)$parseErrors['warning_count'] > 0 || (int)$parseErrors['error_count'] > 0);

    if (!$candidate || $hasParseErrors || $candidate->format('Y-m-d') !== $date || $candidate->format('H:i') !== $time) {
        return ['valid' => false, 'message' => 'Please choose a valid inspection date and time.', 'datetime' => null];
    }
    if (!array_key_exists($time, site_inspection_available_time_slots())) {
        return ['valid' => false, 'message' => 'Please choose an available inspection time.', 'datetime' => null];
    }

    $now = $now ?: new DateTimeImmutable('now', $timezone);
    $minimum = $now->setTime((int)$now->format('H'), (int)$now->format('i'))->modify('+' . site_inspection_minimum_lead_minutes() . ' minutes');
    if ($candidate < $minimum) {
        return ['valid' => false, 'message' => 'Please choose a schedule at least 1 hour from now.', 'datetime' => null];
    }

    return ['valid' => true, 'message' => '', 'datetime' => $candidate];
}

function site_inspection_engineer_has_conflict(
    mysqli $conn,
    int $engineerId,
    DateTimeImmutable $candidate,
    int $excludeInspectionId = 0,
    bool $lockRows = false
): bool {
    $duration = site_inspection_duration_minutes();
    $start = $candidate->format('Y-m-d H:i:s');
    $end = $candidate->modify('+' . $duration . ' minutes')->format('Y-m-d H:i:s');
    $sql = 'SELECT id FROM site_inspections
            WHERE engineer_id = ? AND id <> ?
              AND scheduled_at < ?
              AND DATE_ADD(scheduled_at, INTERVAL ' . $duration . ' MINUTE) > ?
            LIMIT 1' . ($lockRows ? ' FOR UPDATE' : '');
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return true;
    }
    $stmt->bind_param('iiss', $engineerId, $excludeInspectionId, $end, $start);
    $stmt->execute();

    return (bool)$stmt->get_result()->fetch_assoc();
}
// Shared Site Inspection helpers para hindi duplicate sa Admin at Engineer.

if (!function_exists('site_inspection_format_datetime')) {
    function site_inspection_format_datetime(?string $dateTime): string
    {
        if (!$dateTime) {
            return 'Not set';
        }

        try {
            return (new DateTimeImmutable($dateTime, site_inspection_timezone()))->format('M j, Y, g:i A');
        } catch (Throwable $exception) {
            return 'Not set';
        }
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
