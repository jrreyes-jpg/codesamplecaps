<?php

require_once __DIR__ . '/inspection_date_window.php';

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

function site_inspection_schedule_window_days(): int
{
    return 30;
}

function site_inspection_earliest_schedule_date(?DateTimeImmutable $now = null): string
{
    return inspection_date_window($now)['min'];
}

function site_inspection_latest_schedule_date(?DateTimeImmutable $now = null): string
{
    return inspection_date_window($now)['max'];
}

function site_inspection_validate_date(string $date, ?DateTimeImmutable $now = null): array
{
    $timezone = site_inspection_timezone();
    $now = ($now ?: new DateTimeImmutable('now', $timezone))->setTimezone($timezone);
    $candidate = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
    $parseErrors = DateTimeImmutable::getLastErrors();
    $hasParseErrors = is_array($parseErrors)
        && ((int)$parseErrors['warning_count'] > 0 || (int)$parseErrors['error_count'] > 0);

    if (!$candidate || $hasParseErrors || $candidate->format('Y-m-d') !== $date) {
        return ['valid' => false, 'message' => 'Please choose a valid inspection date.', 'datetime' => null];
    }
    if ($date < site_inspection_earliest_schedule_date($now) || $date > site_inspection_latest_schedule_date($now)) {
        return ['valid' => false, 'message' => 'Before 5:00 PM, today is allowed. From 5:00 PM, choose tomorrow onward.', 'datetime' => null];
    }

    return ['valid' => true, 'message' => '', 'datetime' => $candidate];
}

function site_inspection_current_admin_reschedule_reason(
    mysqli $conn,
    int $inspectionId,
    int $engineerId,
    string $scheduledAt
): string {
    static $cache = [];
    $cacheKey = $inspectionId . ':' . $engineerId . ':' . $scheduledAt;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $cache[$cacheKey] = '';
    if (!function_exists('audit_log_table_exists') || !audit_log_table_exists($conn)) {
        return $cache[$cacheKey];
    }
    $stmt = $conn->prepare(
        "SELECT new_values
         FROM audit_logs
         WHERE entity_type = 'site_inspection'
           AND entity_id = ?
           AND action = 'reschedule_site_inspection'
         ORDER BY id DESC"
    );
    if (!$stmt) {
        return $cache[$cacheKey];
    }

    $stmt->bind_param('i', $inspectionId);
    $stmt->execute();
    $rows = $stmt->get_result();
    while ($row = $rows->fetch_assoc()) {
        $values = json_decode((string)($row['new_values'] ?? ''), true);
        if (!is_array($values)
            || (int)($values['engineer_id'] ?? 0) !== $engineerId
            || (string)($values['scheduled_at'] ?? '') !== $scheduledAt) {
            continue;
        }

        $reason = trim((string)($values['reschedule_reason'] ?? ''));
        if ($reason !== '') {
            $cache[$cacheKey] = $reason;
        }
        break;
    }
    $stmt->close();

    return $cache[$cacheKey];
}

function site_inspection_engineer_has_schedule_on_date(
    mysqli $conn,
    int $engineerId,
    DateTimeImmutable $candidate,
    int $excludeInspectionId = 0,
    bool $lockRows = false
): bool {
    $date = $candidate->format('Y-m-d');
    $sql = "SELECT id FROM site_inspections
            WHERE engineer_id = ? AND id <> ?
              AND DATE(scheduled_at) = ?
              AND status IN ('Assigned', 'Acknowledged', 'Ongoing')
            LIMIT 1" . ($lockRows ? ' FOR UPDATE' : '');
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return true;
    }
    $stmt->bind_param('iis', $engineerId, $excludeInspectionId, $date);
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

if (!function_exists('site_inspection_format_date')) {
    function site_inspection_format_date(?string $dateTime): string
    {
        if (!$dateTime) {
            return 'Not set';
        }

        try {
            return (new DateTimeImmutable($dateTime, site_inspection_timezone()))->format('M j, Y');
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

if (!function_exists('site_inspection_schedule_is_confirmation_locked')) {
    function site_inspection_schedule_is_confirmation_locked(?array $inspection): bool
    {
        if (!$inspection) {
            return false;
        }

        // Responses are reset whenever Admin saves a real schedule change.
        return (string)($inspection['client_schedule_response'] ?? '') === 'confirmed'
            && (string)($inspection['engineer_schedule_response'] ?? '') === 'confirmed';
    }
}

if (!function_exists('site_inspection_hold_columns_ready')) {
    function site_inspection_hold_columns_ready(mysqli $conn): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }

        $ready = false;
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS column_count
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'site_inspections'
               AND COLUMN_NAME IN ('hold_reason', 'held_at', 'resumed_at')"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $ready = (int)($row['column_count'] ?? 0) === 3;
        $stmt->close();

        return $ready;
    }
}

if (!function_exists('site_inspection_next_status')) {
    function site_inspection_next_status(string $status): ?string
    {
        $transitions = [
            // Kapag handa na ang Engineer, puwede na siyang magsimula agad.
            'Assigned' => 'Ongoing',
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

if (!function_exists('site_inspection_can_hold')) {
    function site_inspection_can_hold(string $status, string $action): bool
    {
        return ($action === 'hold' && $status === 'Ongoing')
            || ($action === 'resume' && $status === 'On Hold');
    }
}

if (!function_exists('site_inspection_set_hold_state')) {
    function site_inspection_set_hold_state(
        mysqli $conn,
        int $inspectionId,
        int $engineerId,
        string $action,
        string $reason = ''
    ): bool {
        if (!in_array($action, ['hold', 'resume'], true) || !site_inspection_hold_columns_ready($conn)) {
            return false;
        }

        if ($action === 'hold') {
            $stmt = $conn->prepare(
                "UPDATE site_inspections
                 SET status = 'On Hold', hold_reason = ?, held_at = NOW()
                 WHERE id = ? AND engineer_id = ? AND status = 'Ongoing'"
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('sii', $reason, $inspectionId, $engineerId);
        } else {
            $stmt = $conn->prepare(
                "UPDATE site_inspections
                 SET status = 'Ongoing', resumed_at = NOW()
                 WHERE id = ? AND engineer_id = ? AND status = 'On Hold'"
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('ii', $inspectionId, $engineerId);
        }

        $stmt->execute();
        $changed = $stmt->affected_rows === 1;
        $stmt->close();

        return $changed;
    }
}

if (!function_exists('site_inspection_admin_review_statuses')) {
    function site_inspection_admin_review_statuses(): array
    {
        return ['Pending', 'Returned', 'Approved'];
    }
}

if (!function_exists('site_inspection_costing_versions_ready')) {
    function site_inspection_costing_versions_ready(mysqli $conn): bool
    {
        foreach (['site_inspection_costing_versions', 'site_inspection_costing_version_items'] as $tableName) {
            $stmt = $conn->prepare(
                'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('s', $tableName);
            $stmt->execute();
            $exists = (bool)$stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$exists) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('site_inspection_create_costing_version')) {
    function site_inspection_create_costing_version(
        mysqli $conn,
        int $inspectionId,
        int $engineerId
    ): int {
        if (!site_inspection_costing_versions_ready($conn)) {
            throw new RuntimeException('Costing version setup is not ready. Apply the required database migration.');
        }

        $versionStmt = $conn->prepare(
            'SELECT COALESCE(MAX(version_no), 0) + 1 AS next_version
             FROM site_inspection_costing_versions
             WHERE inspection_id = ?'
        );
        if (!$versionStmt) {
            throw new RuntimeException('Unable to prepare the costing version.');
        }
        $versionStmt->bind_param('i', $inspectionId);
        $versionStmt->execute();
        $versionNo = (int)(($versionStmt->get_result()->fetch_assoc()['next_version'] ?? 1));
        $versionStmt->close();

        $snapshotStmt = $conn->prepare(
            "INSERT INTO site_inspection_costing_versions
             (inspection_id, version_no, engineer_findings, risk_notes, client_requests,
              total_cost, review_status, submitted_by, submitted_at)
             SELECT si.id, ?, si.engineer_findings, si.risk_notes, si.client_requests,
                    COALESCE(SUM(ci.line_total), 0), 'Pending', ?, NOW()
             FROM site_inspections si
             LEFT JOIN site_inspection_cost_items ci ON ci.inspection_id = si.id
             WHERE si.id = ? AND si.engineer_id = ?
             GROUP BY si.id, si.engineer_findings, si.risk_notes, si.client_requests"
        );
        if (!$snapshotStmt) {
            throw new RuntimeException('Unable to save the costing version.');
        }
        $snapshotStmt->bind_param('iiii', $versionNo, $engineerId, $inspectionId, $engineerId);
        $snapshotStmt->execute();
        if ($snapshotStmt->affected_rows !== 1) {
            throw new RuntimeException('Unable to save the costing version.');
        }
        $costingVersionId = (int)$conn->insert_id;
        $snapshotStmt->close();

        $itemsStmt = $conn->prepare(
            'INSERT INTO site_inspection_costing_version_items
             (costing_version_id, item_type, inventory_id, material_id, item_name,
              quantity, unit, unit_cost, line_total, notes)
             SELECT ?, item_type, inventory_id, material_id, item_name,
                    quantity, unit, unit_cost, line_total, notes
             FROM site_inspection_cost_items
             WHERE inspection_id = ?
             ORDER BY id ASC'
        );
        if (!$itemsStmt) {
            throw new RuntimeException('Unable to save the costing version items.');
        }
        $itemsStmt->bind_param('ii', $costingVersionId, $inspectionId);
        $itemsStmt->execute();
        $itemsStmt->close();

        return $versionNo;
    }
}

if (!function_exists('site_inspection_review_latest_costing_version')) {
    function site_inspection_review_latest_costing_version(
        mysqli $conn,
        int $inspectionId,
        string $reviewStatus,
        string $remarks,
        int $adminId
    ): void {
        if (!site_inspection_costing_versions_ready($conn)) {
            return;
        }

        $stmt = $conn->prepare(
            'UPDATE site_inspection_costing_versions
             SET review_status = ?, admin_remarks = ?, reviewed_by = ?, reviewed_at = NOW()
             WHERE inspection_id = ? AND review_status = \'Pending\'
             ORDER BY version_no DESC LIMIT 1'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to update the costing version review.');
        }
        $stmt->bind_param('ssii', $reviewStatus, $remarks, $adminId, $inspectionId);
        $stmt->execute();
        $stmt->close();
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
