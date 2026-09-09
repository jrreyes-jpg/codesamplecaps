<?php
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
