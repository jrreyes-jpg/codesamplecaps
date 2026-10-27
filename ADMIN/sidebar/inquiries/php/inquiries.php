<?php
require_once __DIR__ . '/../../../includes/admin_auth.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/audit_log.php';
require_once __DIR__ . '/../../../../config/site_inspections.php';
require_once __DIR__ . '/../../../../config/inquiry_quotation_module.php';
require_once __DIR__ . '/../../../../config/user_notifications.php';
require_once __DIR__ . '/../../../../config/walk_in_inquiry_schedule.php';
require_once __DIR__ . '/../../../../config/inquiry_budget.php';
require_once __DIR__ . '/../../../../services/EmailService.php';

$message = '';
$error = '';
$ajaxErrorStatus = 422;
$allowedStatuses = ['Pending Review', 'For Inspection', 'Verified Lead', 'Not Qualified'];
$inquiryFilterStatuses = ['Pending Review', 'For Inspection', 'Rejected', 'Converted to Project'];

function inquiry_center_csrf_token(): string
{
    return auth_csrf_token('admin_inquiries');
}

function inquiry_center_is_valid_csrf(?string $token): bool
{
    return auth_is_valid_csrf($token, 'admin_inquiries');
}

function inquiry_center_has_table(mysqli $conn, string $tableName): bool
{
    $stmt = $conn->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $tableName);
    $stmt->execute();
    $result = $stmt->get_result();
    return (bool)($result && $result->fetch_assoc());
}

function inquiry_center_format_datetime(?string $dateTime): string
{
    $timestamp = $dateTime ? strtotime($dateTime) : false;
    if ($timestamp === false) {
        return 'Not set';
    }

    return date('M j, Y, g:ia', $timestamp);
}

function inquiry_center_format_date(?string $date): string
{
    $timestamp = $date ? strtotime($date) : false;
    if ($timestamp === false) {
        return 'Not set';
    }

    return date('M j, Y', $timestamp);
}

function inquiry_center_format_compact_datetime(?string $dateTime): string
{
    if (!$dateTime) {
        return 'Not set';
    }

    try {
        $manila = new DateTimeZone('Asia/Manila');
        $submittedAt = new DateTimeImmutable($dateTime, $manila);
        return $submittedAt->setTimezone($manila)->format('D, M j, Y • g:i A');
    } catch (Exception $exception) {
        return 'Not set';
    }
}

function inquiry_center_format_money(float $amount): string
{
    return 'PHP ' . number_format($amount, 2);
}

function inquiry_center_status_label(string $status): string
{
    // Hinihintay muna ang final na Engineer at schedule.
    if (in_array($status, ['Pending Review', 'Verified Lead', 'Not Qualified'], true)) {
        return 'Pending Confirmation';
    }

    return $status;
}

function inquiry_center_filter_url(array $params = []): string
{
    $query = http_build_query(array_filter($params, static fn($value): bool => $value !== '' && $value !== null));
    return '/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php' . ($query !== '' ? '?' . $query : '');
}

function inquiry_center_allowed_next_statuses(string $currentStatus): array
{
    // Simple flow: pending muna hanggang final ang engineer at date.
    $rules = [
        'Pending Review' => ['Pending Review'],
        'For Inspection' => ['For Inspection'],
        'Verified Lead' => ['Verified Lead'],
        'Not Qualified' => ['Not Qualified'],
    ];

    return $rules[$currentStatus] ?? [];
}

function inquiry_center_can_change_status(string $currentStatus, string $newStatus): bool
{
    return in_array($newStatus, inquiry_center_allowed_next_statuses($currentStatus), true);
}

function inquiry_center_has_client_quotation_approval(?string $quotationStatus): bool
{
    return inquiry_quote_normalize_status($quotationStatus) === 'accepted';
}

function inquiry_center_is_archived(mysqli $conn, int $inquiryId): bool
{
    if ($inquiryId <= 0) {
        return false;
    }

    $stmt = $conn->prepare('SELECT archived_at FROM service_inquiries WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $inquiryId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return $row && !empty($row['archived_at']);
}

function inquiry_center_inspection_schedule_is_locked(?array $inspection): bool
{
    if (!$inspection) {
        return false;
    }

    return !empty($inspection['started_at'])
        || in_array((string)($inspection['status'] ?? ''), ['Ongoing', 'Completed', 'Submitted'], true);
}

function inquiry_center_inspection_schedule_is_confirmation_locked(?array $inspection): bool
{
    return site_inspection_schedule_is_confirmation_locked($inspection);
}

function inquiry_center_schedule_notification_hash(int $engineerId, string $scheduledAt, string $siteNotes): string
{
    $normalizedNotes = preg_replace('/\r\n?|\n/', "\n", trim($siteNotes)) ?? '';

    return hash('sha256', implode("\n", [$engineerId, $scheduledAt, $normalizedNotes]));
}

function inquiry_center_send_pending_schedule_notifications(mysqli $conn, int $inspectionId, int $inquiryId, string $expectedHash, bool $createAssignmentNotification): array
{
    $result = ['client_sent' => false, 'engineer_sent' => false, 'confirmed' => false];
    $inspectionStmt = $conn->prepare(
        'SELECT id, engineer_id, scheduled_at, site_notes, status, client_schedule_notified_at, engineer_schedule_notified_at
         FROM site_inspections WHERE id = ? AND inquiry_id = ? LIMIT 1'
    );
    if (!$inspectionStmt) {
        return $result;
    }
    $inspectionStmt->bind_param('ii', $inspectionId, $inquiryId);
    $inspectionStmt->execute();
    $inspection = $inspectionStmt->get_result()->fetch_assoc() ?: null;
    if (!$inspection || !in_array((string)$inspection['status'], ['Assigned', 'Acknowledged'], true)) {
        return $result;
    }

    $engineerId = (int)$inspection['engineer_id'];
    $scheduleValue = (string)$inspection['scheduled_at'];
    $siteNotes = trim((string)($inspection['site_notes'] ?? ''));
    $rescheduleReason = site_inspection_current_admin_reschedule_reason($conn, $inspectionId, $engineerId, $scheduleValue);
    $isScheduleUpdate = $rescheduleReason !== '';
    if (!hash_equals($expectedHash, inquiry_center_schedule_notification_hash($engineerId, $scheduleValue, $siteNotes))) {
        return $result;
    }

    $inquiryStmt = $conn->prepare('SELECT client_name, email, contact_no, site_address, province, city_municipality, barangay, service_category FROM service_inquiries WHERE id = ? LIMIT 1');
    $engineerStmt = $conn->prepare("SELECT full_name, email FROM users WHERE id = ? AND role = 'engineer' LIMIT 1");
    if (!$inquiryStmt || !$engineerStmt) {
        return $result;
    }
    $inquiryStmt->bind_param('i', $inquiryId);
    $inquiryStmt->execute();
    $inquiry = $inquiryStmt->get_result()->fetch_assoc() ?: null;
    $engineerStmt->bind_param('i', $engineerId);
    $engineerStmt->execute();
    $engineer = $engineerStmt->get_result()->fetch_assoc() ?: null;
    if (!$inquiry || !$engineer) {
        return $result;
    }

    $clientName = trim((string)$inquiry['client_name']);
    $clientEmail = trim((string)$inquiry['email']);
    $engineerName = trim((string)$engineer['full_name']);
    $engineerEmail = trim((string)$engineer['email']);
    $siteAddress = implode(', ', array_filter([
        trim((string)($inquiry['site_address'] ?? '')),
        trim((string)($inquiry['barangay'] ?? '')),
        trim((string)($inquiry['city_municipality'] ?? '')),
        trim((string)($inquiry['province'] ?? '')),
    ], static fn(string $value): bool => $value !== ''));
    $scheduleForEmail = (new DateTimeImmutable($scheduleValue, site_inspection_timezone()))->format('l, F j, Y');
    $service = trim((string)($inquiry['service_category'] ?? ''));

    if ($createAssignmentNotification) {
        user_notifications_create_if_missing(
            $conn,
            $engineerId,
            'site_inspection_assignment',
            $inspectionId,
            'New Site Inspection Assignment',
            'Client: ' . ($clientName !== '' ? $clientName : 'Client') . ' • Service: ' . ($service ?: 'Service request') . ' • Schedule: ' . $scheduleForEmail,
            '/codesamplecaps/ENGINEER/dashboards/site_inspections.php?inspection_id=' . $inspectionId,
            'site_inspection_assignment:' . $engineerId . ':' . $inspectionId
        );
    }

    if (empty($inspection['client_schedule_notified_at']) && filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
        $clientMailer = new EmailService();
        if ($clientMailer->sendInspectionScheduleClientNotification($clientEmail, $clientName, $service, $scheduleForEmail, $engineerName, $siteAddress ?: 'Site address not set', $siteNotes, '', $isScheduleUpdate, $rescheduleReason)) {
            $markClient = $conn->prepare('UPDATE site_inspections SET client_schedule_notified_at = NOW() WHERE id = ? AND client_schedule_notified_at IS NULL');
            if ($markClient) { $markClient->bind_param('i', $inspectionId); $markClient->execute(); }
        }
    }

    if (empty($inspection['engineer_schedule_notified_at']) && filter_var($engineerEmail, FILTER_VALIDATE_EMAIL)) {
        $engineerMailer = new EmailService();
        if ($engineerMailer->sendInspectionScheduleEngineerAssignment($engineerEmail, $engineerName, $clientName, $service, $scheduleForEmail, $siteAddress ?: 'Site address not set', trim((string)($inquiry['contact_no'] ?? '')), $clientEmail, $siteNotes, $isScheduleUpdate, site_inspection_engineer_link($inspectionId), $rescheduleReason)) {
            $markEngineer = $conn->prepare('UPDATE site_inspections SET engineer_schedule_notified_at = NOW() WHERE id = ? AND engineer_schedule_notified_at IS NULL');
            if ($markEngineer) { $markEngineer->bind_param('i', $inspectionId); $markEngineer->execute(); }
        }
    }

    $refresh = $conn->prepare('SELECT client_schedule_notified_at, engineer_schedule_notified_at FROM site_inspections WHERE id = ? LIMIT 1');
    if (!$refresh) { return $result; }
    $refresh->bind_param('i', $inspectionId);
    $refresh->execute();
    $delivery = $refresh->get_result()->fetch_assoc() ?: [];
    $result['client_sent'] = !empty($delivery['client_schedule_notified_at']);
    $result['engineer_sent'] = !empty($delivery['engineer_schedule_notified_at']);
    if ($result['client_sent'] && $result['engineer_sent']) {
        $markComplete = $conn->prepare('UPDATE site_inspections SET schedule_notified_at = NOW(), schedule_notification_hash = ? WHERE id = ?');
        if ($markComplete) { $markComplete->bind_param('si', $expectedHash, $inspectionId); $result['confirmed'] = $markComplete->execute(); }
    }
    return $result;
}

function inquiry_center_quotation_prerequisite_message(?array $quotationDraft): string
{
    if (!$quotationDraft) {
        return 'Create the initial quotation first, then send it to the client for review.';
    }

    $isInitialQuotation = empty($quotationDraft['parent_draft_id'])
        && (int)($quotationDraft['revision_no'] ?? 0) === 0;
    $quotationName = $isInitialQuotation ? 'initial quotation' : 'revised quotation';

    $status = inquiry_quote_normalize_status((string)($quotationDraft['status'] ?? ''));
    if ($status === 'accepted') {
        return '';
    }

    if ($status === 'sent') {
        return 'Wait for client approval before assigning Engineer.';
    }

    if ($status === 'rejected') {
        return 'Client rejected the ' . $quotationName . '. Review the client note before taking the next action.';
    }

    return 'Send the ' . $quotationName . ' to the client. Wait for approval before assigning an engineer.';
}

function inquiry_center_validate_quotation_before_send(mysqli $conn, int $draftId, array $quotation): int
{
    $items = inquiry_quote_fetch_items($conn, $draftId);
    if (count($items) === 0) {
        throw new RuntimeException('Add at least one valid cost item before sending the quotation.');
    }

    $subtotal = 0.0;
    foreach ($items as $item) {
        $itemType = strtolower(trim((string)($item['item_type'] ?? '')));
        $name = trim((string)($item['item_name'] ?? ''));
        $unit = trim((string)($item['unit'] ?? ''));
        $quantity = (float)($item['quantity'] ?? 0);
        $unitCost = (float)($item['unit_cost'] ?? 0);
        $lineTotal = (float)($item['line_total'] ?? 0);

        if (inquiry_quote_normalize_item_type($itemType) === ''
            || $name === '' || $unit === '' || !is_finite($quantity) || !is_finite($unitCost) || !is_finite($lineTotal)
            || $quantity <= 0 || $unitCost <= 0 || $lineTotal <= 0
            || abs($lineTotal - round($quantity * $unitCost, 2)) > 0.01) {
            throw new RuntimeException('Quotation has an invalid cost item. Edit and save the quotation before sending.');
        }

        $subtotal += $lineTotal;
    }

    $subtotal = round($subtotal, 2);
    $markup = (float)($quotation['profit_margin_percent'] ?? -1);
    $profit = round($subtotal * ($markup / 100), 2);
    $grandTotal = round($subtotal + $profit, 2);

    if (!is_finite($markup) || $markup < 0 || $markup > 100
        || abs((float)($quotation['subtotal'] ?? 0) - $subtotal) > 0.01
        || abs((float)($quotation['profit_amount'] ?? 0) - $profit) > 0.01
        || abs((float)($quotation['grand_total'] ?? 0) - $grandTotal) > 0.01) {
        throw new RuntimeException('Quotation totals are invalid. Edit and save the quotation before sending.');
    }

    return count($items);
}

function inquiry_center_latest_rejected_quotation_exclusion(): string
{
    return "NOT EXISTS (
        SELECT 1 FROM inquiry_quotation_drafts rejected_quote
        WHERE rejected_quote.inquiry_id = service_inquiries.id
        AND rejected_quote.status = 'rejected'
        AND NOT EXISTS (
            SELECT 1 FROM inquiry_quotation_drafts newer_quote
            WHERE newer_quote.inquiry_id = rejected_quote.inquiry_id
            AND (
                newer_quote.updated_at > rejected_quote.updated_at
                OR (newer_quote.updated_at = rejected_quote.updated_at AND newer_quote.id > rejected_quote.id)
            )
        )
    )";
}

function inquiry_center_quotation_filter_condition(string $quotationFilter): string
{
    $initialQuoteWhere = 'initial_quote.inquiry_id = service_inquiries.id
        AND initial_quote.revision_no = 0
        AND initial_quote.parent_draft_id IS NULL';

    return match ($quotationFilter) {
        'needs_quotation' => "status = 'Verified Lead' AND NOT EXISTS (
            SELECT 1 FROM inquiry_quotation_drafts initial_quote
            WHERE $initialQuoteWhere
        )",
        'draft' => "EXISTS (
            SELECT 1 FROM inquiry_quotation_drafts initial_quote
            WHERE $initialQuoteWhere
            AND LOWER(initial_quote.status) IN ('draft', 'approved')
        )",
        'awaiting_client' => "EXISTS (
            SELECT 1 FROM inquiry_quotation_drafts initial_quote
            WHERE $initialQuoteWhere
            AND LOWER(initial_quote.status) = 'sent'
        )",
        'accepted' => "EXISTS (
            SELECT 1 FROM inquiry_quotation_drafts initial_quote
            WHERE $initialQuoteWhere
            AND LOWER(initial_quote.status) = 'accepted'
        )",
        'needs_revision' => "EXISTS (
            SELECT 1 FROM inquiry_quotation_drafts initial_quote
            WHERE $initialQuoteWhere
            AND LOWER(initial_quote.status) IN ('revision_requested', 'for_revision')
        )",
        'rejected' => "EXISTS (
            SELECT 1 FROM inquiry_quotation_drafts initial_quote
            WHERE $initialQuoteWhere
            AND LOWER(initial_quote.status) = 'rejected'
        )",
        default => '',
    };
}

function inquiry_center_current_initial_quotation_filter_condition(string $quotationFilter): string
{
    if ($quotationFilter === 'needs_quotation') {
        return inquiry_center_quotation_filter_condition($quotationFilter);
    }

    $statuses = match ($quotationFilter) {
        'draft' => "'draft', 'approved'",
        'awaiting_client' => "'sent'",
        'accepted' => "'accepted'",
        'needs_revision' => "'revision_requested', 'for_revision'",
        'rejected' => "'rejected'",
        default => '',
    };

    if ($statuses === '') {
        return '';
    }

    return "EXISTS (
        SELECT 1 FROM inquiry_quotation_drafts current_initial_quote
        WHERE current_initial_quote.inquiry_id = service_inquiries.id
        AND current_initial_quote.revision_no = 0
        AND current_initial_quote.parent_draft_id IS NULL
        AND LOWER(current_initial_quote.status) IN ($statuses)
        AND NOT EXISTS (
            SELECT 1 FROM inquiry_quotation_drafts newer_initial_quote
            WHERE newer_initial_quote.inquiry_id = current_initial_quote.inquiry_id
            AND newer_initial_quote.revision_no = 0
            AND newer_initial_quote.parent_draft_id IS NULL
            AND (
                newer_initial_quote.updated_at > current_initial_quote.updated_at
                OR (newer_initial_quote.updated_at = current_initial_quote.updated_at
                    AND newer_initial_quote.id > current_initial_quote.id)
            )
        )
    )";
}

function inquiry_center_quotation_filter_counts(mysqli $conn, array $scopeWhere, string $scopeTypes, array $scopeParams): array
{
    $counts = array_fill_keys(['needs_quotation', 'draft', 'awaiting_client', 'accepted', 'needs_revision', 'rejected'], 0);

    foreach (array_keys($counts) as $filter) {
        $where = $scopeWhere;
        $where[] = inquiry_center_current_initial_quotation_filter_condition($filter);
        $sql = 'SELECT COUNT(*) AS total FROM service_inquiries WHERE ' . implode(' AND ', $where);
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            continue;
        }
        if ($scopeTypes !== '') {
            $stmt->bind_param($scopeTypes, ...$scopeParams);
        }
        $stmt->execute();
        $counts[$filter] = (int)(($stmt->get_result()->fetch_assoc() ?: [])['total'] ?? 0);
    }

    return $counts;
}

function inquiry_center_lifecycle_filter_counts(mysqli $conn, array $searchWhere, string $searchTypes, array $searchParams): array
{
    $counts = array_fill_keys(['all', 'pending_review', 'qualified', 'for_inspection', 'not_qualified', 'archived'], 0);
    $sql = 'SELECT
                COUNT(CASE WHEN archived_at IS NULL THEN 1 END) AS all_count,
                COUNT(CASE WHEN archived_at IS NULL AND status IN (\'Pending Review\', \'Verified Lead\', \'Not Qualified\') THEN 1 END) AS pending_review_count,
                COUNT(CASE WHEN archived_at IS NULL AND status = \'Verified Lead\' THEN 1 END) AS qualified_count,
                COUNT(CASE WHEN archived_at IS NULL AND status = \'For Inspection\' THEN 1 END) AS for_inspection_count,
                COUNT(CASE WHEN archived_at IS NULL AND status = \'Not Qualified\' THEN 1 END) AS not_qualified_count,
                COUNT(CASE WHEN archived_at IS NOT NULL THEN 1 END) AS archived_count
            FROM service_inquiries';

    if ($searchWhere !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $searchWhere);
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $counts;
    }

    if ($searchTypes !== '') {
        $stmt->bind_param($searchTypes, ...$searchParams);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];

    return [
        'all' => (int)($row['all_count'] ?? 0),
        'pending_review' => (int)($row['pending_review_count'] ?? 0),
        'qualified' => (int)($row['qualified_count'] ?? 0),
        'for_inspection' => (int)($row['for_inspection_count'] ?? 0),
        'not_qualified' => (int)($row['not_qualified_count'] ?? 0),
        'archived' => (int)($row['archived_count'] ?? 0),
    ];
}

function inquiry_center_quotation_status_label(string $status): string
{
    return inquiry_quote_normalize_status($status) === 'accepted'
        ? 'Client Approved'
        : inquiry_quote_status_label($status);
}

function inquiry_center_redirect(string $view, string $message): void
{
    $_SESSION['inquiry_center_flash'] = $message;
    unset($_SESSION['super_admin_sidebar_notification_data']);
    $query = $view === 'archive' ? '?view=archive' : '';
    header('Location: /codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php' . $query);
    exit();
}

function inquiry_center_redirect_back(string $message, string $fallback = '/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php'): void
{
    $returnUrl = (string)($_POST['return_url'] ?? $fallback);
    if (!str_starts_with($returnUrl, '/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php')) {
        $returnUrl = $fallback;
    }

    $_SESSION['inquiry_center_flash'] = $message;
    unset($_SESSION['super_admin_sidebar_notification_data']);
    header('Location: ' . $returnUrl);
    exit();
}

function inquiry_center_redirect_with_project(int $projectId, string $message): void
{
    $_SESSION['inquiry_center_flash'] = $message;
    $_SESSION['inquiry_center_flash_project_id'] = $projectId;
    unset($_SESSION['super_admin_sidebar_notification_data']);
    header('Location: /codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?status=For+Inspection');
    exit();
}

function inquiry_center_redirect_to_open_modal(int $inquiryId, string $status, string $message, string $tab = 'client'): void
{
    $_SESSION['inquiry_center_flash'] = $message;
    unset($_SESSION['super_admin_sidebar_notification_data']);

    $query = [
        'status' => $status,
        'open' => 'inquiryModal' . $inquiryId,
        'tab' => $tab,
    ];

    $search = trim((string)($_GET['search'] ?? ''));
    if ($search !== '') {
        $query['search'] = $search;
    }

    header('Location: /codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?' . http_build_query($query));
    exit();
}

$csrfToken = inquiry_center_csrf_token();
$isAjaxRequest = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'monitor_inspection') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    $inspectionId = (int)($_GET['inspection_id'] ?? 0);
    $holdSelect = site_inspection_hold_columns_ready($conn)
        ? 'si.hold_reason, si.held_at,'
        : 'NULL AS hold_reason, NULL AS held_at,';
    $response = ['success' => false, 'message' => 'Inspection monitoring data was not found.'];

    if ($inspectionId > 0) {
        $stmt = $conn->prepare(
            "SELECT si.id, si.status, si.scheduled_at, si.created_at, si.acknowledged_at,
                    si.started_at, {$holdSelect} si.completed_at, si.submitted_at, si.updated_at,
                    si.engineer_findings, si.risk_notes, si.client_requests, u.full_name AS engineer_name
             FROM site_inspections si
             INNER JOIN users u ON u.id = si.engineer_id
             WHERE si.id = ?
             LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('i', $inspectionId);
            $stmt->execute();
            $inspection = $stmt->get_result()->fetch_assoc() ?: null;
            if ($inspection) {
                $inspectionStatus = (string)($inspection['status'] ?? 'Assigned');
                $monitorPhase = match ($inspectionStatus) {
                    'Submitted' => 'Submitted',
                    'Ongoing', 'On Hold', 'Completed' => 'Ongoing',
                    default => 'Assigned',
                };
                $report = null;
                if (!empty($inspection['submitted_at'])) {
                    $costStmt = $conn->prepare(
                        'SELECT item_type, item_name, quantity, unit, notes
                         FROM site_inspection_cost_items
                         WHERE inspection_id = ?
                         ORDER BY id ASC'
                    );
                    $items = [];
                    if ($costStmt) {
                        $costStmt->bind_param('i', $inspectionId);
                        $costStmt->execute();
                        $items = $costStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    }
                    $report = [
                        'findings' => (string)($inspection['engineer_findings'] ?? ''),
                        'risk_notes' => (string)($inspection['risk_notes'] ?? ''),
                        'client_requests' => (string)($inspection['client_requests'] ?? ''),
                        'materials' => array_map(static fn(array $item): array => [
                            'type' => inquiry_quote_item_type_label((string)($item['item_type'] ?? '')),
                            'name' => (string)($item['item_name'] ?? ''),
                            'quantity' => rtrim(rtrim(number_format((float)($item['quantity'] ?? 0), 2), '0'), '.'),
                            'unit' => (string)($item['unit'] ?? ''),
                            'remarks' => (string)($item['notes'] ?? ''),
                        ], $items),
                    ];
                }

                $response = [
                    'success' => true,
                    'inspection' => [
                        'engineer' => (string)($inspection['engineer_name'] ?? 'Not set'),
                        'status' => $monitorPhase,
                        'scheduled_at' => site_inspection_format_date($inspection['scheduled_at'] ?? null),
                        'started_at' => site_inspection_format_datetime($inspection['started_at'] ?? null),
                        'latest_activity' => site_inspection_format_datetime($inspection['updated_at'] ?? null),
                        'hold_reason' => (string)($inspection['status'] ?? '') === 'On Hold'
                            ? (string)($inspection['hold_reason'] ?? '')
                            : '',
                        'held_at' => site_inspection_format_datetime($inspection['held_at'] ?? null),
                        'timeline' => [
                            ['label' => 'Assigned', 'at' => site_inspection_format_datetime($inspection['created_at'] ?? null)],
                            ['label' => 'Ongoing', 'at' => site_inspection_format_datetime($inspection['started_at'] ?? null)],
                            ['label' => 'Submitted', 'at' => site_inspection_format_datetime($inspection['submitted_at'] ?? null)],
                        ],
                        'report' => $report,
                    ],
                ];
            }
        }
    }

    echo json_encode($response);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'poll_quotation_status') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    $inquiryIds = array_values(array_unique(array_filter(
        array_map('intval', explode(',', (string)($_GET['inquiry_ids'] ?? ''))),
        static fn(int $id): bool => $id > 0
    )));
    $inquiryIds = array_slice($inquiryIds, 0, 100);
    $statuses = [];
    $latestRevisionId = 0;
    $latestRevisionInquiryId = 0;
    $latestRevisionUpdatedAt = '';
    $latestRejectedId = 0;
    $latestRejectedInquiryId = 0;
    $latestRejectedNote = '';
    $latestRejectedAt = '';

    if ($inquiryIds && inquiry_quote_table_exists($conn, 'inquiry_quotation_drafts')) {
        $placeholders = implode(', ', array_fill(0, count($inquiryIds), '?'));
        $stmt = $conn->prepare(
            "SELECT q.inquiry_id, q.status, q.client_decision_note, q.updated_at
             FROM inquiry_quotation_drafts q
             INNER JOIN service_inquiries si ON si.id = q.inquiry_id AND si.archived_at IS NULL
             WHERE q.inquiry_id IN ($placeholders)
             ORDER BY q.updated_at DESC, q.id DESC"
        );

        if ($stmt) {
            $stmt->bind_param(str_repeat('i', count($inquiryIds)), ...$inquiryIds);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $inquiryId = (int)($row['inquiry_id'] ?? 0);
                if ($inquiryId > 0 && !isset($statuses[$inquiryId])) {
                    $status = inquiry_quote_normalize_status((string)($row['status'] ?? 'draft'));
                    $statuses[$inquiryId] = [
                        'inquiry_id' => $inquiryId,
                        'status' => $status,
                        'label' => inquiry_quote_status_label($status),
                        'client_decision_note' => (string)($row['client_decision_note'] ?? ''),
                        'updated_at' => (string)($row['updated_at'] ?? ''),
                    ];
                }
            }
        }
    }

    if (inquiry_quote_table_exists($conn, 'inquiry_quotation_drafts')) {
        $latestRevisionResult = $conn->query(
            "SELECT q.id, q.inquiry_id, q.updated_at
             FROM inquiry_quotation_drafts q
             INNER JOIN service_inquiries si ON si.id = q.inquiry_id AND si.archived_at IS NULL
             WHERE q.status IN ('revision_requested', 'for_revision')
             ORDER BY q.updated_at DESC, q.id DESC
             LIMIT 1"
        );
        $latestRevision = $latestRevisionResult ? $latestRevisionResult->fetch_assoc() : null;
        $latestRevisionId = (int)($latestRevision['id'] ?? 0);
        $latestRevisionInquiryId = (int)($latestRevision['inquiry_id'] ?? 0);
        $latestRevisionUpdatedAt = (string)($latestRevision['updated_at'] ?? '');

        $latestRejectedResult = $conn->query(
            "SELECT q.id, q.inquiry_id, q.client_decision_note, COALESCE(q.client_decision_at, q.updated_at) AS rejected_at
             FROM inquiry_quotation_drafts q
             INNER JOIN service_inquiries si ON si.id = q.inquiry_id AND si.archived_at IS NULL
             WHERE q.status = 'rejected'
             ORDER BY COALESCE(q.client_decision_at, q.updated_at) DESC, q.id DESC
             LIMIT 1"
        );
        $latestRejected = $latestRejectedResult ? $latestRejectedResult->fetch_assoc() : null;
        $latestRejectedId = (int)($latestRejected['id'] ?? 0);
        $latestRejectedInquiryId = (int)($latestRejected['inquiry_id'] ?? 0);
        $latestRejectedNote = (string)($latestRejected['client_decision_note'] ?? '');
        $latestRejectedAt = (string)($latestRejected['rejected_at'] ?? '');
    }

    echo json_encode([
        'success' => true,
        'quotations' => array_values($statuses),
        'latest_revision_id' => $latestRevisionId,
        'latest_revision_inquiry_id' => $latestRevisionInquiryId,
        'latest_revision_updated_at' => $latestRevisionUpdatedAt,
        'latest_rejected_id' => $latestRejectedId,
        'latest_rejected_inquiry_id' => $latestRejectedInquiryId,
        'latest_rejected_note' => $latestRejectedNote,
        'latest_rejected_at' => $latestRejectedAt,
    ]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'poll_inspection_schedule_response') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    $inquiryId = (int)($_GET['inquiry_id'] ?? 0);
    $response = [
        'scheduled' => false,
        'final' => false,
        'locked' => false,
        'client' => ['response' => 'not_scheduled', 'label' => 'Not Scheduled', 'note' => ''],
        'engineer' => ['response' => 'not_scheduled', 'label' => 'Not Scheduled', 'note' => ''],
    ];

    if ($inquiryId > 0) {
        $stmt = $conn->prepare(
            'SELECT scheduled_at, status, started_at, client_schedule_response, client_schedule_response_note,
                    client_schedule_responded_at, engineer_schedule_response,
                    engineer_schedule_response_note, engineer_schedule_responded_at
             FROM site_inspections
             WHERE inquiry_id = ?
             ORDER BY id DESC
             LIMIT 1'
        );
        if ($stmt) {
            $stmt->bind_param('i', $inquiryId);
            $stmt->execute();
            $inspectionResponse = $stmt->get_result()->fetch_assoc() ?: null;
            if ($inspectionResponse && !empty($inspectionResponse['scheduled_at'])) {
                $clientResponse = (string)($inspectionResponse['client_schedule_response'] ?? 'pending');
                $engineerResponse = (string)($inspectionResponse['engineer_schedule_response'] ?? 'pending');
                $response = [
                    'scheduled' => true,
                    'final' => $clientResponse === 'confirmed' && $engineerResponse === 'confirmed',
                    'phase' => (string)($inspectionResponse['status'] ?? 'Assigned'),
                    'locked' => inquiry_center_inspection_schedule_is_locked($inspectionResponse),
                    'client' => [
                        'response' => $clientResponse,
                        'label' => site_inspection_schedule_response_label($clientResponse),
                        'note' => (string)($inspectionResponse['client_schedule_response_note'] ?? ''),
                        'responded_at' => (string)($inspectionResponse['client_schedule_responded_at'] ?? ''),
                    ],
                    'engineer' => [
                        'response' => $engineerResponse,
                        'label' => site_inspection_schedule_response_label($engineerResponse),
                        'note' => (string)($inspectionResponse['engineer_schedule_response_note'] ?? ''),
                        'responded_at' => (string)($inspectionResponse['engineer_schedule_responded_at'] ?? ''),
                    ],
                ];
            }
        }
    }

    echo json_encode(['success' => true, 'inspection' => $response]);
    exit();
}

if (isset($_GET['viewed_inquiry']) && inquiry_center_has_table($conn, 'service_inquiries')) {
    $viewedInquiryId = (int)$_GET['viewed_inquiry'];
    if ($viewedInquiryId > 0) {
        $viewStmt = $conn->prepare('UPDATE service_inquiries SET viewed_at = COALESCE(viewed_at, NOW()) WHERE id = ? AND archived_at IS NULL');
        if ($viewStmt) {
            $viewStmt->bind_param('i', $viewedInquiryId);
            $viewStmt->execute();
        }
    }

    unset($_SESSION['super_admin_sidebar_notification_data']);
    header('Location: /codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?open=inquiryModal' . $viewedInquiryId . '&tab=client');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!inquiry_center_is_valid_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid request. Please try again.';
    } elseif (
        !in_array((string)($_POST['action'] ?? ''), ['delete_inquiry', 'restore_inquiry', 'archive_inquiry'], true)
        && inquiry_center_is_archived($conn, (int)($_POST['inquiry_id'] ?? 0))
    ) {
        $error = 'This inquiry is archived. Restore it before making changes.';
    } elseif (($_POST['action'] ?? '') === 'delete_inquiry') {
        $inquiryId = (int)($_POST['inquiry_id'] ?? 0);

        if ($inquiryId <= 0) {
            $error = 'Invalid delete request.';
        } else {
            $inspectionDeleteStmt = $conn->prepare('DELETE FROM site_inspections WHERE inquiry_id = ?');
            if ($inspectionDeleteStmt) {
                $inspectionDeleteStmt->bind_param('i', $inquiryId);
                $inspectionDeleteStmt->execute();
            }

            $deleteStmt = $conn->prepare('DELETE FROM service_inquiries WHERE id = ? AND archived_at IS NOT NULL');

            if (!$deleteStmt) {
                $error = 'Failed to prepare delete request.';
            } else {
                $deleteStmt->bind_param('i', $inquiryId);
                if ($deleteStmt->execute() && $deleteStmt->affected_rows > 0) {
                    audit_log_event(
                        $conn,
                        (int)($_SESSION['user_id'] ?? 0),
                        'delete_archived_inquiry',
                        'service_inquiry',
                        $inquiryId,
                        null,
                        ['deleted_from' => 'archive']
                    );
                    inquiry_center_redirect('archive', 'Archived inquiry permanently deleted.');
                } else {
                    $error = 'Only archived inquiries can be permanently deleted.';
                }
            }
        }
    } elseif (($_POST['action'] ?? '') === 'restore_inquiry') {
        $inquiryId = (int)($_POST['inquiry_id'] ?? 0);

        if ($inquiryId <= 0) {
            $error = 'Invalid restore request.';
        } else {
            $restoreStmt = $conn->prepare(
                'UPDATE service_inquiries
                 SET archived_at = NULL, archived_by = NULL, archive_reason = NULL
                 WHERE id = ? AND archived_at IS NOT NULL'
            );

            if (!$restoreStmt) {
                $error = 'Failed to prepare restore request.';
            } else {
                $restoreStmt->bind_param('i', $inquiryId);
                if ($restoreStmt->execute()) {
                    audit_log_event(
                        $conn,
                        (int)($_SESSION['user_id'] ?? 0),
                        'restore_inquiry',
                        'service_inquiry',
                        $inquiryId,
                        null,
                        ['restored_to' => 'active_inquiries']
                    );
                    inquiry_center_redirect('active', 'Inquiry restored.');
                } else {
                    $error = 'Failed to restore inquiry.';
                }
            }
        }
    } elseif (($_POST['action'] ?? '') === 'archive_inquiry') {
        $inquiryId = (int)($_POST['inquiry_id'] ?? 0);
        $archiveReason = trim((string)($_POST['archive_reason'] ?? ''));
        $archiveReasonOther = trim((string)($_POST['archive_reason_other'] ?? ''));
        $archiveReasons = [
            'Duplicate inquiry',
            'Client backed out',
            'Not qualified',
            'No response from client',
            'Other',
        ];
        if (!in_array($archiveReason, $archiveReasons, true)) {
            $archiveReason = '';
        }
        $hasManualArchiveNote = $archiveReasonOther !== '';
        if ($archiveReason === 'Other' || $hasManualArchiveNote) {
            $compactOtherReason = preg_replace('/\s+/u', '', $archiveReasonOther) ?? '';
            if (mb_strlen($compactOtherReason, 'UTF-8') < 5 || preg_match('/\p{L}/u', $archiveReasonOther) !== 1) {
                $archiveReason = '';
                $error = 'Enter a clear manual reason with at least 5 characters.';
            } elseif ($archiveReason === 'Other') {
                $archiveReason = $archiveReasonOther;
            } else {
                $archiveReason .= ' - ' . $archiveReasonOther;
            }
        }

        if ($error === '' && ($inquiryId <= 0 || $archiveReason === '')) {
            $error = 'Please add archive reason.';
        } elseif ($error === '') {
            $archiveStmt = $conn->prepare(
                'UPDATE service_inquiries
                 SET archived_at = NOW(), archived_by = ?, archive_reason = ?
                 WHERE id = ? AND archived_at IS NULL'
            );

            if (!$archiveStmt) {
                $error = 'Failed to prepare archive request.';
            } else {
                $archivedBy = (int)($_SESSION['user_id'] ?? 0);
                $archiveStmt->bind_param('isi', $archivedBy, $archiveReason, $inquiryId);
                if ($archiveStmt->execute()) {
                    audit_log_event(
                        $conn,
                        $archivedBy,
                        'archive_inquiry',
                        'service_inquiry',
                        $inquiryId,
                        null,
                        ['archive_reason' => $archiveReason]
                    );
                    inquiry_center_redirect_back('Inquiry archived.');
                } else {
                    $error = 'Failed to archive inquiry.';
                }
            }
        }
    } elseif (($_POST['action'] ?? '') === 'proceed_without_quotation_revision') {
        $inquiryId = (int)($_POST['inquiry_id'] ?? 0);
        $inspectionId = (int)($_POST['inspection_id'] ?? 0);
        $draftId = (int)($_POST['draft_id'] ?? 0);
        $remarks = trim((string)($_POST['admin_remarks'] ?? ''));
        $adminId = (int)($_SESSION['user_id'] ?? 0);

        try {
            $quotation = inquiry_quote_fetch_full($conn, $draftId);
            $inspection = inquiry_quote_fetch_post_inspection_decision($conn, $inspectionId);
            if ($inspection) {
                throw new RuntimeException('A post-inspection quotation decision already exists.');
            }
            if (
                !$quotation
                || (int)($quotation['inquiry_id'] ?? 0) !== $inquiryId
                || (int)($quotation['inspection_id'] ?? 0) !== $inspectionId
                || !empty($quotation['parent_draft_id'])
                || (int)($quotation['revision_no'] ?? 0) !== 0
                || inquiry_quote_normalize_status((string)($quotation['status'] ?? '')) !== 'accepted'
            ) {
                throw new RuntimeException('The accepted quotation does not match this approved inspection.');
            }

            $inspectionStmt = $conn->prepare(
                "SELECT id FROM site_inspections
                 WHERE id = ? AND inquiry_id = ? AND admin_review_status = 'Approved' LIMIT 1"
            );
            if (!$inspectionStmt) {
                throw new RuntimeException('Unable to check the inspection costing.');
            }
            $inspectionStmt->bind_param('ii', $inspectionId, $inquiryId);
            $inspectionStmt->execute();
            if (!$inspectionStmt->get_result()->fetch_assoc()) {
                throw new RuntimeException('Approve the inspection costing before choosing the quotation decision.');
            }

            $costStmt = $conn->prepare(
                'SELECT COALESCE(SUM(line_total), 0) AS total
                 FROM site_inspection_cost_items WHERE inspection_id = ?'
            );
            if (!$costStmt) {
                throw new RuntimeException('Unable to calculate inspection costing.');
            }
            $costStmt->bind_param('i', $inspectionId);
            $costStmt->execute();
            $costingTotal = (float)($costStmt->get_result()->fetch_assoc()['total'] ?? 0);
            $variance = round($costingTotal - (float)$quotation['grand_total'], 2);
            if (abs($variance) > 0.004 && $remarks === '') {
                throw new RuntimeException('Add Admin remarks because the inspection costing differs from the accepted quotation.');
            }

            $decision = 'proceed_without_revision';
            $decisionStmt = $conn->prepare(
                'INSERT INTO inspection_quotation_decisions
                 (inquiry_id, inspection_id, initial_quotation_draft_id, final_quotation_draft_id, inspection_costing_total, variance_amount, decision, admin_remarks, decided_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$decisionStmt) {
                throw new RuntimeException('Unable to save the quotation decision.');
            }
            $decisionStmt->bind_param('iiiiddssi', $inquiryId, $inspectionId, $draftId, $draftId, $costingTotal, $variance, $decision, $remarks, $adminId);
            $decisionStmt->execute();
            audit_log_event($conn, $adminId, 'proceed_without_quotation_revision', 'inspection_quotation_decision', (int)$conn->insert_id, null, [
                'inquiry_id' => $inquiryId,
                'inspection_id' => $inspectionId,
                'quotation_draft_id' => $draftId,
                'inspection_costing_total' => $costingTotal,
                'variance_amount' => $variance,
                'admin_remarks' => $remarks,
            ]);
            inquiry_center_redirect_to_open_modal($inquiryId, 'For Inspection', 'Quotation kept as the final commercial basis. You can now create the project.', 'quotation');
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    } elseif (($_POST['action'] ?? '') === 'create_post_inspection_quotation_revision') {
        $inquiryId = (int)($_POST['inquiry_id'] ?? 0);
        $inspectionId = (int)($_POST['inspection_id'] ?? 0);
        $draftId = (int)($_POST['draft_id'] ?? 0);

        try {
            $revisedDraftId = inquiry_quote_create_post_inspection_revision(
                $conn,
                $inquiryId,
                $inspectionId,
                $draftId,
                (int)($_SESSION['user_id'] ?? 0)
            );
            audit_log_event($conn, (int)($_SESSION['user_id'] ?? 0), 'create_post_inspection_quotation_revision', 'quotation', $revisedDraftId, null, [
                'inquiry_id' => $inquiryId,
                'inspection_id' => $inspectionId,
                'parent_draft_id' => $draftId,
            ]);
            $_SESSION['inquiry_center_flash'] = 'Revised quotation draft created from approved inspection costing. Review it, then send it to the client.';
            header('Location: /codesamplecaps/ADMIN/sidebar/inquiries/php/create_quotation.php?edit_id=' . $revisedDraftId);
            exit();
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    } elseif (($_POST['action'] ?? '') === 'prepare_project_from_quote') {
        $inquiryId = (int)($_POST['inquiry_id'] ?? 0);
        $draftId = (int)($_POST['draft_id'] ?? 0);

        if ($inquiryId <= 0 || $draftId <= 0) {
            $error = 'Invalid project setup request.';
        } else {
            try {
                $quotation = inquiry_quote_fetch_full($conn, $draftId);
                if (!$quotation || (int)($quotation['inquiry_id'] ?? 0) !== $inquiryId) {
                    throw new RuntimeException('Accepted quotation not found.');
                }

                if (inquiry_quote_normalize_status($quotation['status'] ?? '') !== 'accepted') {
                    throw new RuntimeException('Client must accept the quotation before project setup.');
                }

                $decisionStmt = $conn->prepare(
                    "SELECT d.decision
                     FROM inspection_quotation_decisions d
                     INNER JOIN site_inspections si ON si.id = d.inspection_id
                     WHERE d.inquiry_id = ?
                     AND d.final_quotation_draft_id = ?
                     AND si.admin_review_status = 'Approved'
                     LIMIT 1"
                );
                if (!$decisionStmt) {
                    throw new RuntimeException('Unable to check the post-inspection quotation decision.');
                }
                $decisionStmt->bind_param('ii', $inquiryId, $draftId);
                $decisionStmt->execute();
                if (!$decisionStmt->get_result()->fetch_assoc()) {
                    throw new RuntimeException('Choose the post-inspection quotation decision before project setup.');
                }

                if (!empty($quotation['project_id'])) {
                    inquiry_center_redirect_with_project((int)$quotation['project_id'], 'Project was already created from this quotation.');
                }

                $clientAccount = inquiry_quote_prepare_client_account(
                    $conn,
                    $draftId,
                    (int)($_SESSION['user_id'] ?? 0)
                );
                $recipient = inquiry_quote_resolve_recipient($conn, $draftId);
                $clientWasInvited = in_array((string)($clientAccount['state'] ?? ''), ['pending_created', 'pending_resend'], true);
                $activationEmailSent = $clientAccount['activation_email_sent'] ?? null;
                $engineerId = (int)($quotation['engineer_id'] ?? 0);
                $locationArea = implode(' / ', array_filter([
                    trim((string)($quotation['province'] ?? '')),
                    trim((string)($quotation['city_municipality'] ?? '')),
                    trim((string)($quotation['barangay'] ?? '')),
                ], static fn(string $value): bool => $value !== ''));
                $_SESSION['projects_old_input'] = [
                    'project_name' => inquiry_quote_unique_project_title($conn, $quotation),
                    'description' => trim((string)($quotation['engineer_findings'] ?: $quotation['description'] ?? '')),
                    'contact_person' => trim((string)($quotation['client_name'] ?? '')),
                    'contact_number' => trim((string)($quotation['contact_no'] ?? '')),
                    'project_site' => $locationArea,
                    'project_address' => trim((string)($quotation['site_address'] ?? '')),
                    'project_email' => trim((string)($quotation['email'] ?? '')),
                    'project_source' => 'inquiry_quotation',
                    'quotation_draft_id' => (string)$draftId,
                    'source_inquiry_id' => (string)$inquiryId,
                    'client_id' => !empty($recipient['client_id']) ? (string)$recipient['client_id'] : '',
                    'engineer_ids' => $engineerId > 0 ? [(string)$engineerId] : [],
                    'status' => 'pending',
                    'po_date' => '',
                    'project_start_date' => date('Y-m-d'),
                    'estimated_completion_date' => date('Y-m-d', strtotime('+7 days')),
                    'estimated_duration_days' => '7',
                    'budget_amount' => number_format((float)($quotation['grand_total'] ?? 0), 2, '.', ''),
                    'budget_notes' => '',
                    'focus_field' => 'engineer_ids',
                ];
                $_SESSION['projects_flash'] = [
                    'type' => $clientWasInvited && $activationEmailSent === false ? 'warning' : 'success',
                    'message' => $clientWasInvited
                        ? ($activationEmailSent === false
                            ? 'Client account was linked, but the activation email was not sent. Open Project Setup again to resend it.'
                            : 'Client account was linked and the activation email was sent. Review the project team.')
                        : 'Client account was linked. Review the accepted quotation details and project team.',
                ];
                header('Location: /codesamplecaps/ADMIN/sidebar/projects/php/projects.php#create-project');
                exit;
            } catch (Throwable $throwable) {
                $error = $throwable->getMessage();
            }
        }
    } elseif (($_POST['action'] ?? '') === 'send_quotation_to_client') {
        $inquiryId = (int)($_POST['inquiry_id'] ?? 0);
        $draftId = (int)($_POST['draft_id'] ?? 0);

        if ($inquiryId <= 0 || $draftId <= 0) {
            $error = 'Invalid quotation send request.';
        } else {
            try {
                $quotationBeforeSend = inquiry_quote_fetch_full($conn, $draftId);
                if (!$quotationBeforeSend || (int)($quotationBeforeSend['inquiry_id'] ?? 0) !== $inquiryId) {
                    throw new RuntimeException('Quotation does not match this inquiry.');
                }
                $statusBeforeSend = inquiry_quote_normalize_status((string)($quotationBeforeSend['status'] ?? ''));
                inquiry_center_validate_quotation_before_send($conn, $draftId, $quotationBeforeSend);
                inquiry_quote_send_to_client($conn, $draftId, (int)($_SESSION['user_id'] ?? 0));
                audit_log_event(
                    $conn,
                    (int)($_SESSION['user_id'] ?? 0),
                    'send_inquiry_quotation_to_client',
                    'quotation',
                    $draftId,
                    ['status' => $statusBeforeSend],
                    ['status' => 'sent']
                );
                $isInitialQuotation = empty($quotationBeforeSend['parent_draft_id'])
                    && (int)($quotationBeforeSend['revision_no'] ?? 0) === 0;
                $quotationSentMessage = $isInitialQuotation
                    ? 'Initial quotation sent to client.'
                    : 'Revised quotation sent to client.';
                if ($isAjaxRequest) {
                    $_SESSION['inquiry_center_flash'] = $quotationSentMessage;
                    header('Content-Type: application/json; charset=UTF-8');
                    echo json_encode([
                        'success' => true,
                        'message' => $quotationSentMessage,
                        'redirect' => '/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?status=Verified+Lead&open=inquiryModal' . $inquiryId . '&tab=quotation',
                    ]);
                    exit();
                }
                inquiry_center_redirect_to_open_modal($inquiryId, 'Verified Lead', $quotationSentMessage);
            } catch (Throwable $throwable) {
                if ((int)$throwable->getCode() === 503) {
                    $error = 'Quotation was not sent. Please check your connection or email service and try again.';
                    $ajaxErrorStatus = 503;
                } else {
                    $error = $throwable->getMessage();
                }
            }
        }
    } elseif (($_POST['action'] ?? '') === 'reopen_quotation_revision') {
        $inquiryId = (int)($_POST['inquiry_id'] ?? 0);
        $draftId = (int)($_POST['draft_id'] ?? 0);

        if ($inquiryId <= 0 || $draftId <= 0) {
            $error = 'Invalid quotation revision request.';
        } else {
            try {
                inquiry_quote_reopen_for_revision($conn, $draftId, (int)($_SESSION['user_id'] ?? 0));
                audit_log_event(
                    $conn,
                    (int)($_SESSION['user_id'] ?? 0),
                    'reopen_inquiry_quotation_revision',
                    'quotation',
                    $draftId,
                    ['status' => 'revision_requested'],
                    ['status' => 'draft']
                );
                $_SESSION['inquiry_center_flash'] = 'Quotation reopened for revision.';
                header('Location: /codesamplecaps/ADMIN/sidebar/inquiries/php/create_quotation.php?edit_id=' . $draftId);
                exit();
            } catch (Throwable $throwable) {
                $error = $throwable->getMessage();
            }
        }
    } elseif (($_POST['action'] ?? '') === 'create_quotation_draft') {
        $inquiryId = (int)($_POST['inquiry_id'] ?? 0);
        $inspectionId = (int)($_POST['inspection_id'] ?? 0);
        $marginPercent = (float)($_POST['profit_margin_percent'] ?? 15);

        if ($inquiryId <= 0 || $inspectionId <= 0) {
            $error = 'Invalid quotation draft request.';
        } elseif ($marginPercent < 0 || $marginPercent > 100) {
            $error = 'Profit margin must be from 0 to 100 percent.';
        } else {
            try {
                $draftId = inquiry_quote_create_from_inspection(
                    $conn,
                    $inquiryId,
                    $inspectionId,
                    (int)($_SESSION['user_id'] ?? 0),
                    $marginPercent
                );

                audit_log_event(
                    $conn,
                    (int)($_SESSION['user_id'] ?? 0),
                    'create_inquiry_quotation_draft',
                    'quotation',
                    $draftId,
                    null,
                    [
                        'inquiry_id' => $inquiryId,
                        'inspection_id' => $inspectionId,
                        'profit_margin_percent' => $marginPercent,
                    ]
                );

                inquiry_center_redirect_to_open_modal($inquiryId, 'For Inspection', 'Quotation draft generated from engineer costing.');
            } catch (Throwable $throwable) {
                $error = $throwable->getMessage();
            }
        }
    } elseif (($_POST['action'] ?? '') === 'review_inspection_report') {
        $inquiryId = (int)($_POST['inquiry_id'] ?? 0);
        $inspectionId = (int)($_POST['inspection_id'] ?? 0);
        $reviewDecision = trim((string)($_POST['review_decision'] ?? ''));
        $adminRemarks = trim((string)($_POST['admin_remarks'] ?? ''));
        $targetReviewStatus = $reviewDecision === 'approve'
            ? 'Approved'
            : ($reviewDecision === 'return' ? 'Returned' : '');

        if ($inquiryId <= 0 || $inspectionId <= 0 || $targetReviewStatus === '') {
            $error = 'Invalid inspection review request.';
        } elseif ($targetReviewStatus === 'Returned'
            && (mb_strlen(preg_replace('/\s+/u', '', $adminRemarks) ?? '', 'UTF-8') < 5
                || preg_match('/\p{L}/u', $adminRemarks) !== 1)) {
            $error = 'Enter a clear return reason with at least 5 letters.';
        } elseif (mb_strlen($adminRemarks, 'UTF-8') > 2000) {
            $error = 'Admin remarks must not exceed 2,000 characters.';
        } else {
            $conn->begin_transaction();

            try {
                $reviewStmt = $conn->prepare(
                    'SELECT status, admin_review_status, admin_remarks, submitted_at, engineer_findings
                     FROM site_inspections
                     WHERE id = ? AND inquiry_id = ?
                     LIMIT 1
                     FOR UPDATE'
                );
                if (!$reviewStmt) {
                    throw new RuntimeException('Unable to prepare inspection review.');
                }

                $reviewStmt->bind_param('ii', $inspectionId, $inquiryId);
                $reviewStmt->execute();
                $inspectionBeforeReview = $reviewStmt->get_result()->fetch_assoc();
                if (!$inspectionBeforeReview) {
                    throw new RuntimeException('Inspection costing was not found.');
                }

                $currentInspectionStatus = (string)($inspectionBeforeReview['status'] ?? '');
                $currentReviewStatus = (string)($inspectionBeforeReview['admin_review_status'] ?? 'Pending');
                if (!site_inspection_can_admin_review(
                    $currentInspectionStatus,
                    $currentReviewStatus,
                    $targetReviewStatus
                )) {
                    throw new RuntimeException('This inspection costing was already reviewed or is not ready for review.');
                }

                if ($targetReviewStatus === 'Approved') {
                    $findings = trim((string)($inspectionBeforeReview['engineer_findings'] ?? ''));
                    if ($findings === '' || preg_match('/\p{L}/u', $findings) !== 1) {
                        throw new RuntimeException('Engineer findings are required before approval.');
                    }

                    $costingCheck = $conn->prepare(
                        'SELECT COUNT(*) AS item_count, COALESCE(SUM(line_total), 0) AS total_cost
                         FROM site_inspection_cost_items
                         WHERE inspection_id = ?'
                    );
                    if (!$costingCheck) {
                        throw new RuntimeException('Unable to validate inspection costing.');
                    }
                    $costingCheck->bind_param('i', $inspectionId);
                    $costingCheck->execute();
                    $costingSummary = $costingCheck->get_result()->fetch_assoc() ?: [];
                    if ((int)($costingSummary['item_count'] ?? 0) < 1 || (float)($costingSummary['total_cost'] ?? 0) <= 0) {
                        throw new RuntimeException('Add at least one costing item with a total above PHP 0 before approval.');
                    }
                }

                $adminId = (int)($_SESSION['user_id'] ?? 0);
                $nextInspectionStatus = $targetReviewStatus === 'Returned' ? 'Completed' : 'Submitted';
                $remarksToStore = $targetReviewStatus === 'Returned'
                    ? $adminRemarks
                    : (string)($inspectionBeforeReview['admin_remarks'] ?? '');
                $updateReview = $conn->prepare(
                    'UPDATE site_inspections
                     SET status = ?, admin_review_status = ?, admin_remarks = ?,
                         admin_reviewed_by = ?, admin_reviewed_at = NOW()
                     WHERE id = ? AND inquiry_id = ?
                       AND status = \'Submitted\' AND admin_review_status = \'Pending\''
                );
                if (!$updateReview) {
                    throw new RuntimeException('Unable to save inspection review.');
                }

                $updateReview->bind_param(
                    'sssiii',
                    $nextInspectionStatus,
                    $targetReviewStatus,
                    $remarksToStore,
                    $adminId,
                    $inspectionId,
                    $inquiryId
                );
                $updateReview->execute();
                if ($updateReview->affected_rows !== 1) {
                    throw new RuntimeException('Inspection review was not saved. Please refresh the page.');
                }

                audit_log_event(
                    $conn,
                    $adminId,
                    $targetReviewStatus === 'Returned' ? 'return_site_inspection_report' : 'approve_site_inspection_report',
                    'site_inspection',
                    $inspectionId,
                    [
                        'status' => $currentInspectionStatus,
                        'admin_review_status' => $currentReviewStatus,
                        'admin_remarks' => $inspectionBeforeReview['admin_remarks'] ?? null,
                        'submitted_at' => $inspectionBeforeReview['submitted_at'] ?? null,
                    ],
                    [
                        'status' => $nextInspectionStatus,
                        'admin_review_status' => $targetReviewStatus,
                        'admin_remarks' => $remarksToStore,
                    ]
                );

                $conn->commit();
                $message = $targetReviewStatus === 'Returned'
                    ? 'Inspection costing returned to the Engineer for revision.'
                    : 'Inspection costing approved.';
            } catch (Throwable $exception) {
                $conn->rollback();
                $safeReviewErrors = [
                    'Inspection costing was not found.',
                    'This inspection costing was already reviewed or is not ready for review.',
                    'Inspection review was not saved. Please refresh the page.',
                    'Engineer findings are required before approval.',
                    'Add at least one costing item with a total above PHP 0 before approval.',
                ];
                $error = in_array($exception->getMessage(), $safeReviewErrors, true)
                    ? $exception->getMessage()
                    : 'Failed to save the inspection review.';
            }
        }
    } elseif (($_POST['action'] ?? '') === 'retry_inspection_notifications') {
        $inquiryId = (int)($_POST['inquiry_id'] ?? 0);
        $inspectionId = (int)($_POST['inspection_id'] ?? 0);
        $notificationHash = trim((string)($_POST['notification_hash'] ?? ''));
        if ($inquiryId <= 0 || $inspectionId <= 0 || $notificationHash === '') {
            $error = 'Invalid inspection notification retry request.';
        } else {
            $delivery = inquiry_center_send_pending_schedule_notifications($conn, $inspectionId, $inquiryId, $notificationHash, false);
            if ($delivery['confirmed']) {
                $message = 'Inspection schedule confirmed. Client and Engineer notified.';
            } elseif (!$delivery['client_sent'] && !$delivery['engineer_sent']) {
                $message = 'Schedule saved, but the Client and Engineer emails could not be sent.';
                $_SESSION['inquiry_center_flash_type'] = 'warning';
            } elseif (!$delivery['client_sent']) {
                $message = 'Schedule saved, but the Client email could not be sent.';
                $_SESSION['inquiry_center_flash_type'] = 'warning';
            } else {
                $message = 'Schedule saved, but the Engineer email could not be sent.';
                $_SESSION['inquiry_center_flash_type'] = 'warning';
            }
            inquiry_center_redirect_to_open_modal($inquiryId, 'For Inspection', $message, 'inspection');
        }
    } elseif (($_POST['action'] ?? '') === 'schedule_inspection') {
        $inquiryId = (int)($_POST['inquiry_id'] ?? 0);
        $engineerId = (int)($_POST['engineer_id'] ?? 0);
        $acceptedQuotationId = 0;
        $scheduleNotificationWarning = false;
        $scheduleDate = trim((string)($_POST['inspection_date'] ?? ''));
        $siteNotes = trim((string)($_POST['site_notes'] ?? ''));
        $rescheduleReason = trim((string)($_POST['reschedule_reason'] ?? ''));
        $scheduleValidation = site_inspection_validate_date($scheduleDate);
        $scheduleDateTime = $scheduleValidation['datetime'];
        $existingScheduleValue = '';
        $existingScheduleEngineerId = 0;
        if ($inquiryId > 0) {
            $existingScheduleStmt = $conn->prepare(
                'SELECT engineer_id, scheduled_at
                 FROM site_inspections
                 WHERE inquiry_id = ?
                 ORDER BY id DESC
                 LIMIT 1'
            );
            if ($existingScheduleStmt) {
                $existingScheduleStmt->bind_param('i', $inquiryId);
                $existingScheduleStmt->execute();
                $existingScheduleRow = $existingScheduleStmt->get_result()->fetch_assoc() ?: [];
                $existingScheduleValue = (string)($existingScheduleRow['scheduled_at'] ?? '');
                $existingScheduleEngineerId = (int)($existingScheduleRow['engineer_id'] ?? 0);
            }
        }
        $submittedScheduleValue = $scheduleDate !== ''
            ? $scheduleDate . ' 00:00:00'
            : '';
        $isUnchangedExistingSchedule = $existingScheduleValue !== ''
            && $existingScheduleEngineerId === $engineerId
            && substr($existingScheduleValue, 0, 10) === $scheduleDate;
        if (!$scheduleValidation['valid'] && $isUnchangedExistingSchedule) {
            $scheduleDateTime = new DateTimeImmutable($existingScheduleValue, site_inspection_timezone());
            $scheduleValidation = ['valid' => true, 'message' => '', 'datetime' => $scheduleDateTime];
        }

        if ($inquiryId <= 0 || $engineerId <= 0) {
            $error = 'Please select engineer and valid inspection schedule.';
        } elseif (!$scheduleValidation['valid']) {
            $error = (string)$scheduleValidation['message'];
        } else {
            $currentInquiryStatus = '';
            $statusStmt = $conn->prepare('SELECT status FROM service_inquiries WHERE id = ? LIMIT 1');
            if ($statusStmt) {
                $statusStmt->bind_param('i', $inquiryId);
                $statusStmt->execute();
                $statusRow = $statusStmt->get_result()->fetch_assoc();
                $currentInquiryStatus = (string)($statusRow['status'] ?? '');
            }
            if (!in_array($currentInquiryStatus, ['Pending Review', 'For Inspection', 'Verified Lead', 'Not Qualified'], true)) {
                $error = 'This inquiry cannot be scheduled at its current stage.';
            }
        }

        if ($error === '') {
            $conn->begin_transaction();
            $scheduleTransactionStarted = true;
            $scheduleValue = $scheduleDateTime->format('Y-m-d H:i:s');
            $existingInspectionId = 0;
            $existingInspectionStatus = '';

            $lockedInquiryStmt = $conn->prepare(
                'SELECT status, archived_at FROM service_inquiries WHERE id = ? LIMIT 1 FOR UPDATE'
            );
            if (!$lockedInquiryStmt) {
                $error = 'Unable to verify the inquiry before saving the schedule.';
            } else {
                $lockedInquiryStmt->bind_param('i', $inquiryId);
                $lockedInquiryStmt->execute();
                $lockedInquiry = $lockedInquiryStmt->get_result()->fetch_assoc();
                if (!$lockedInquiry) {
                    $error = 'Inquiry not found.';
                } elseif (!empty($lockedInquiry['archived_at'])) {
                    $error = 'This inquiry is archived. Restore it before changing the inspection schedule.';
                } elseif (!in_array((string)$lockedInquiry['status'], ['Pending Review', 'For Inspection', 'Verified Lead', 'Not Qualified'], true)) {
                    $error = 'This inquiry cannot be scheduled at its current stage.';
                }
            }

            $existingStmt = $conn->prepare(
                'SELECT id, status, engineer_id, scheduled_at, site_notes, acknowledged_at, started_at, completed_at, submitted_at,
                        client_schedule_response, engineer_schedule_response,
                        client_schedule_notified_at, engineer_schedule_notified_at, schedule_notified_at, schedule_notification_hash
                 FROM site_inspections
                 WHERE inquiry_id = ?
                 ORDER BY id DESC
                 LIMIT 1
                 FOR UPDATE'
            );
            $existingInspection = null;
            if ($error === '' && !$existingStmt) {
                $error = 'Unable to verify the current inspection state.';
            } elseif ($error === '' && $existingStmt) {
                $existingStmt->bind_param('i', $inquiryId);
                $existingStmt->execute();
                $existingRow = $existingStmt->get_result()->fetch_assoc();
                $existingInspection = $existingRow ?: null;
                $existingInspectionId = (int)($existingRow['id'] ?? 0);
                $existingInspectionStatus = (string)($existingRow['status'] ?? '');
            }

            if ($error === '' && $existingInspectionId > 0 && inquiry_center_inspection_schedule_is_locked($existingInspection)) {
                $error = 'The inspection already started. Engineer and date can no longer be changed.';
            }
            $notificationHash = inquiry_center_schedule_notification_hash($engineerId, $scheduleValue, $siteNotes);
            $hasAssignmentChanges = $existingInspectionId <= 0
                || (int)($existingInspection['engineer_id'] ?? 0) !== $engineerId
                || substr((string)($existingInspection['scheduled_at'] ?? ''), 0, 10) !== $scheduleDate;
            $hasNotesChanges = $existingInspectionId > 0
                && trim((string)($existingInspection['site_notes'] ?? '')) !== $siteNotes;
            $hasScheduleChanges = $hasAssignmentChanges || $hasNotesChanges;
            if ($error === '' && $hasAssignmentChanges
                && site_inspection_engineer_has_schedule_on_date(
                    $conn,
                    $engineerId,
                    $scheduleDateTime,
                    $existingInspectionId,
                    true
                )) {
                $error = 'This Engineer already has an inspection on this date.';
            } elseif ($error === '' && $existingInspectionId > 0 && !$hasAssignmentChanges) {
                // Notes lang ay hindi reschedule. Gamitin ang hiwalay na retry kung email lang ang kulang.
                $error = 'Change the Engineer or inspection date before updating the schedule.';
            } elseif ($error === '' && mb_strlen($rescheduleReason, 'UTF-8') > 1000) {
                $error = 'Reschedule note must not exceed 1000 characters.';
            }
            $storedNotificationHash = trim((string)($existingInspection['schedule_notification_hash'] ?? ''));
            $needsScheduleNotification = $hasAssignmentChanges
                || ($existingInspectionId > 0 && (
                    $storedNotificationHash === ''
                    || empty($existingInspection['client_schedule_notified_at'])
                    || empty($existingInspection['engineer_schedule_notified_at'])
                ));
            // Admin already confirmed the new date with Client and Engineer by call or text.
            $clientScheduleToken = '';
            $clientScheduleTokenHash = '';

            $stmt = null;
            if ($error === '' && $hasScheduleChanges) {
                $stmt = $existingInspectionId > 0 && $hasAssignmentChanges
                    ? $conn->prepare(
                        "UPDATE site_inspections
                         SET engineer_id = ?, scheduled_at = ?, site_notes = ?,
                             client_schedule_notified_at = NULL, engineer_schedule_notified_at = NULL,
                             schedule_notified_at = NULL, schedule_notification_hash = NULL,
                             client_schedule_response = 'pending', client_schedule_response_note = NULL,
                             client_schedule_preferred_at = NULL, client_schedule_responded_at = NULL,
                             client_schedule_token_hash = ?, client_schedule_token_expires_at = DATE_ADD(NOW(), INTERVAL 14 DAY),
                             engineer_schedule_response = 'pending', engineer_schedule_response_note = NULL,
                             engineer_schedule_preferred_at = NULL, engineer_schedule_responded_at = NULL,
                             status = CASE WHEN status = 'Acknowledged' THEN 'Assigned' ELSE status END,
                             acknowledged_at = CASE WHEN status = 'Acknowledged' THEN NULL ELSE acknowledged_at END
                         WHERE id = ? AND status IN ('Assigned', 'Acknowledged')"
                    )
                    : ($existingInspectionId <= 0
                    ? $conn->prepare(
                         "INSERT INTO site_inspections (inquiry_id, engineer_id, scheduled_at, site_notes, client_schedule_token_hash, client_schedule_token_expires_at, status, created_by)
                          VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 14 DAY), 'Assigned', ?)"
                    )
                    : $conn->prepare(
                        'UPDATE site_inspections
                         SET site_notes = ?, schedule_notification_hash = ?
                         WHERE id = ? AND status IN (\'Assigned\', \'Acknowledged\')'
                    ));
            }

            if ($error === '' && $hasScheduleChanges && !$stmt) {
                $error = 'Failed to prepare inspection schedule.';
            } elseif ($error === '' && $hasScheduleChanges && $stmt) {
                $createdBy = (int)($_SESSION['user_id'] ?? 0);
                if ($existingInspectionId > 0 && $hasAssignmentChanges) {
                    $stmt->bind_param('isssi', $engineerId, $scheduleValue, $siteNotes, $clientScheduleTokenHash, $existingInspectionId);
                } elseif ($existingInspectionId <= 0) {
                    $stmt->bind_param('iisssi', $inquiryId, $engineerId, $scheduleValue, $siteNotes, $clientScheduleTokenHash, $createdBy);
                } else {
                    $stmt->bind_param('ssi', $siteNotes, $notificationHash, $existingInspectionId);
                }
                if ($stmt->execute() && ($existingInspectionId <= 0 || $stmt->affected_rows === 1)) {
                    $savedInspectionId = $existingInspectionId > 0 ? $existingInspectionId : (int)$conn->insert_id;
                } else {
                    $error = inquiry_center_inspection_schedule_is_locked($existingInspection)
                        ? 'The inspection already started. The schedule was not changed.'
                        : 'Failed to save inspection schedule.';
                }
            }

            if ($error === '' && !$hasScheduleChanges && !$needsScheduleNotification) {
                $message = 'Schedule Confirmed.';
            }

            if ($error === '') {
                $savedInspectionId = $existingInspectionId > 0 ? $existingInspectionId : (int)$conn->insert_id;
                $createdBy = (int)($_SESSION['user_id'] ?? 0);

                if ($hasAssignmentChanges) {
                    $linkQuotation = $conn->prepare(
                        'UPDATE inquiry_quotation_drafts SET inspection_id = ? WHERE id = ? AND status = ?'
                    );
                    if ($linkQuotation && $savedInspectionId > 0 && $acceptedQuotationId > 0) {
                        $acceptedStatus = 'accepted';
                        $linkQuotation->bind_param('iis', $savedInspectionId, $acceptedQuotationId, $acceptedStatus);
                        $linkQuotation->execute();
                    }

                    $updateInquiry = $conn->prepare("UPDATE service_inquiries SET status = 'For Inspection', reviewed_at = NOW() WHERE id = ?");
                    if ($updateInquiry) {
                        $updateInquiry->bind_param('i', $inquiryId);
                        $updateInquiry->execute();
                    }

                    audit_log_event(
                        $conn,
                        $createdBy,
                        $existingInspectionId > 0 ? 'reschedule_site_inspection' : 'schedule_site_inspection',
                        'site_inspection',
                        $savedInspectionId,
                        $existingInspectionId > 0 ? [
                            'engineer_id' => (int)($existingInspection['engineer_id'] ?? 0),
                            'scheduled_at' => (string)($existingInspection['scheduled_at'] ?? ''),
                            'site_notes' => (string)($existingInspection['site_notes'] ?? ''),
                        ] : null,
                        [
                            'engineer_id' => $engineerId,
                            'scheduled_at' => $scheduleValue,
                            'site_notes' => $siteNotes,
                            'reschedule_reason' => $existingInspectionId > 0 ? $rescheduleReason : null,
                        ]
                    );
                } elseif ($hasNotesChanges) {
                    audit_log_event(
                        $conn,
                        $createdBy,
                        'update_site_inspection_notes',
                        'site_inspection',
                        $savedInspectionId,
                        ['site_notes' => (string)($existingInspection['site_notes'] ?? '')],
                        ['site_notes' => $siteNotes]
                    );
                }

                if ($scheduleTransactionStarted) {
                    $conn->commit();
                    $scheduleTransactionStarted = false;
                }

                if ($needsScheduleNotification) {
                    $notificationInquiry = null;
                    $notificationEngineer = null;
                    $inquiryNotificationStmt = $conn->prepare(
                        'SELECT client_name, email, contact_no, site_address, province, city_municipality, barangay, service_category
                         FROM service_inquiries
                         WHERE id = ?
                         LIMIT 1'
                    );
                    $engineerNotificationStmt = $conn->prepare(
                        "SELECT full_name, email FROM users WHERE id = ? AND role = 'engineer' LIMIT 1"
                    );

                    if ($inquiryNotificationStmt) {
                        $inquiryNotificationStmt->bind_param('i', $inquiryId);
                        $inquiryNotificationStmt->execute();
                        $notificationInquiry = $inquiryNotificationStmt->get_result()->fetch_assoc() ?: null;
                    }
                    if ($engineerNotificationStmt) {
                        $engineerNotificationStmt->bind_param('i', $engineerId);
                        $engineerNotificationStmt->execute();
                        $notificationEngineer = $engineerNotificationStmt->get_result()->fetch_assoc() ?: null;
                    }

                    $clientEmail = trim((string)($notificationInquiry['email'] ?? ''));
                    $clientName = trim((string)($notificationInquiry['client_name'] ?? ''));
                    $engineerEmail = trim((string)($notificationEngineer['email'] ?? ''));
                    $engineerName = trim((string)($notificationEngineer['full_name'] ?? ''));
                    $siteAddressParts = array_filter([
                        trim((string)($notificationInquiry['site_address'] ?? '')),
                        trim((string)($notificationInquiry['barangay'] ?? '')),
                        trim((string)($notificationInquiry['city_municipality'] ?? '')),
                        trim((string)($notificationInquiry['province'] ?? '')),
                    ], static fn(string $value): bool => $value !== '');
                    $siteAddress = implode(', ', $siteAddressParts);
                    $scheduleForEmail = $scheduleDateTime->format('l, F j, Y');
                    $clientScheduleLink = '';
                    $isScheduleUpdate = $existingInspectionId > 0 && $hasAssignmentChanges;
                    $isEngineerReassignment = $isScheduleUpdate
                        && (int)($existingInspection['engineer_id'] ?? 0) !== $engineerId;

                    // Para lang ito sa unang assignment. Walang reschedule notification dito.
                    if ($existingInspectionId <= 0 && $savedInspectionId > 0 && $notificationInquiry && $notificationEngineer) {
                        $assignmentMessage = 'Client: ' . ($clientName !== '' ? $clientName : 'Client')
                            . ' • Service: ' . (trim((string)($notificationInquiry['service_category'] ?? '')) ?: 'Service request')
                            . ' • Schedule: ' . $scheduleForEmail;
                        $assignmentNotification = user_notifications_create_if_missing(
                            $conn,
                            $engineerId,
                            'site_inspection_assignment',
                            $savedInspectionId,
                            'New Site Inspection Assignment',
                            $assignmentMessage,
                            '/codesamplecaps/ENGINEER/dashboards/site_inspections.php?inspection_id=' . $savedInspectionId,
                            'site_inspection_assignment:' . $engineerId . ':' . $savedInspectionId
                        );
                        if ($assignmentNotification === null) {
                            error_log('Engineer assignment notification was not saved for inspection ' . $savedInspectionId);
                        }
                    }

                    $clientEmailSent = !$hasScheduleChanges && !empty($existingInspection['client_schedule_notified_at']);
                    $engineerEmailSent = !$hasScheduleChanges && !empty($existingInspection['engineer_schedule_notified_at']);

                    if (!$clientEmailSent && filter_var($clientEmail, FILTER_VALIDATE_EMAIL) && $notificationInquiry) {
                        $clientMailer = new EmailService();
                        $clientEmailSent = $clientMailer->sendInspectionScheduleClientNotification(
                            $clientEmail,
                            $clientName,
                            trim((string)($notificationInquiry['service_category'] ?? '')),
                            $scheduleForEmail,
                            $engineerName,
                            $siteAddress !== '' ? $siteAddress : 'Site address not set',
                            $siteNotes,
                            $clientScheduleLink,
                            $isScheduleUpdate,
                            $isScheduleUpdate ? $rescheduleReason : ''
                        );
                        if ($clientEmailSent) {
                            $markClient = $conn->prepare('UPDATE site_inspections SET client_schedule_notified_at = NOW() WHERE id = ? AND client_schedule_notified_at IS NULL');
                            if ($markClient) {
                                $markClient->bind_param('i', $savedInspectionId);
                                $markClient->execute();
                            }
                        }
                    }

                    if (!$engineerEmailSent && filter_var($engineerEmail, FILTER_VALIDATE_EMAIL) && $notificationEngineer && $notificationInquiry) {
                        $engineerMailer = new EmailService();
                        $engineerEmailSent = $engineerMailer->sendInspectionScheduleEngineerAssignment(
                            $engineerEmail,
                            $engineerName,
                            $clientName,
                            trim((string)($notificationInquiry['service_category'] ?? '')),
                            $scheduleForEmail,
                            $siteAddress !== '' ? $siteAddress : 'Site address not set',
                            trim((string)($notificationInquiry['contact_no'] ?? '')),
                            $clientEmail,
                            $siteNotes,
                            $isScheduleUpdate,
                            site_inspection_engineer_link($savedInspectionId),
                            $isScheduleUpdate ? $rescheduleReason : '',
                            $isEngineerReassignment
                        );
                        if ($engineerEmailSent) {
                            $markEngineer = $conn->prepare('UPDATE site_inspections SET engineer_schedule_notified_at = NOW() WHERE id = ? AND engineer_schedule_notified_at IS NULL');
                            if ($markEngineer) {
                                $markEngineer->bind_param('i', $savedInspectionId);
                                $markEngineer->execute();
                            }
                        }
                    }

                    if ($isScheduleUpdate && $notificationEngineer && $notificationInquiry) {
                        user_notifications_create_if_missing(
                            $conn,
                            $engineerId,
                            'site_inspection_schedule_updated',
                            $savedInspectionId,
                            'Inspection Schedule Updated',
                            'Client: ' . ($clientName !== '' ? $clientName : 'Client') . ' • New schedule: ' . $scheduleForEmail . ($siteNotes !== '' ? ' • Notes: ' . mb_strimwidth($siteNotes, 0, 80, '…') : ''),
                            '/codesamplecaps/ENGINEER/dashboards/site_inspections.php?inspection_id=' . $savedInspectionId,
                            'site_inspection_schedule_updated:' . $engineerId . ':' . $savedInspectionId . ':' . $notificationHash
                        );
                    }

                    $notificationMarked = false;
                    if ($clientEmailSent && $engineerEmailSent) {
                        $markNotified = $conn->prepare(
                            'UPDATE site_inspections
                             SET schedule_notified_at = NOW(), schedule_notification_hash = ?
                             WHERE id = ?'
                        );
                        if ($markNotified) {
                            $markNotified->bind_param('si', $notificationHash, $savedInspectionId);
                            $notificationMarked = $markNotified->execute();
                        } else {
                            error_log('Inspection notification marker could not be prepared for inspection ' . $savedInspectionId);
                        }
                    }

                    $message = $clientEmailSent && $engineerEmailSent && $notificationMarked
                        ? ($isScheduleUpdate
                            ? 'Inspection updated. Client and Engineer notified.'
                            : 'Inspection scheduled. Client and Engineer notified.')
                        : (!$clientEmailSent && !$engineerEmailSent
                            ? 'Schedule saved, but the Client and Engineer emails could not be sent.'
                            : (!$clientEmailSent
                                ? 'Schedule saved, but the Client email could not be sent.'
                                : 'Schedule saved, but the Engineer email could not be sent.'));
                    $scheduleNotificationWarning = !($clientEmailSent && $engineerEmailSent && $notificationMarked);
                    if ($scheduleNotificationWarning) {
                        $_SESSION['inquiry_center_flash_type'] = 'warning';
                    }
                }
            }
        }

        if (!empty($scheduleTransactionStarted)) {
            $conn->rollback();
            $scheduleTransactionStarted = false;
        }

        if ($isAjaxRequest && $error === '') {
            $_SESSION['inquiry_center_flash'] = $message !== '' ? $message : 'Inspection schedule saved.';
            if ($scheduleNotificationWarning) {
                $_SESSION['inquiry_center_flash_type'] = 'warning';
            }
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'success' => true,
                'message' => $_SESSION['inquiry_center_flash'],
                'redirect' => '/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?status=For+Inspection&open=inquiryModal' . $inquiryId . '&tab=inspection',
            ]);
            exit();
        }
    } else {
        $inquiryId = (int)($_POST['inquiry_id'] ?? 0);
        $adminNotes = trim((string)($_POST['admin_notes'] ?? ''));

        if ($inquiryId <= 0) {
            $error = 'Invalid inquiry update request.';
        } else {
            $stmt = $conn->prepare('SELECT status, admin_notes, archived_at FROM service_inquiries WHERE id = ? LIMIT 1');
            if (!$stmt) {
                $error = 'Unable to load inquiry.';
            } else {
                $stmt->bind_param('i', $inquiryId);
                $stmt->execute();
                $current = $stmt->get_result()->fetch_assoc();

                if (!$current) {
                    $error = 'Inquiry not found.';
                } elseif (!empty($current['archived_at'])) {
                    $error = 'This inquiry is archived. Restore it before making changes.';
                } elseif (mb_strlen($adminNotes, 'UTF-8') > 2000) {
                    $error = 'Admin notes must not exceed 2000 characters.';
                }

                if ($error === '') {
                    $updateStmt = $conn->prepare(
                        'UPDATE service_inquiries
                         SET admin_notes = ?, reviewed_at = NOW()
                         WHERE id = ?'
                    );

                    if (!$updateStmt) {
                        $error = 'Failed to prepare notes update.';
                    } else {
                        $updateStmt->bind_param('si', $adminNotes, $inquiryId);
                        if ($updateStmt->execute()) {
                            audit_log_event(
                                $conn,
                                (int)($_SESSION['user_id'] ?? 0),
                                'update_inquiry_notes',
                                'service_inquiry',
                                $inquiryId,
                                ['admin_notes' => (string)($current['admin_notes'] ?? '')],
                                ['admin_notes' => $adminNotes]
                            );
                            $successMessage = 'Admin notes saved.';
                            $currentStatus = (string)($current['status'] ?? 'Pending Review');

                            if ($isAjaxRequest) {
                                $_SESSION['inquiry_center_flash'] = $successMessage;
                                header('Content-Type: application/json; charset=UTF-8');
                                echo json_encode([
                                    'success' => true,
                                    'status' => $currentStatus,
                                    'target_tab' => 'client',
                                    'redirect' => '/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?' . http_build_query([
                                        'open' => 'inquiryModal' . $inquiryId,
                                        'tab' => 'client',
                                    ]),
                                ]);
                                exit();
                            }

                            inquiry_center_redirect_to_open_modal($inquiryId, '', $successMessage, 'client');
                        } else {
                            $error = 'Failed to save Admin notes.';
                        }
                    }
                }
            }
        }
    }
}

if ($isAjaxRequest && $error !== '') {
    http_response_code($ajaxErrorStatus);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'message' => $error,
        'error_code' => $ajaxErrorStatus === 503 ? 'quotation_email_send_failed' : 'request_failed',
    ]);
    exit();
}

$message = (string)($_SESSION['inquiry_center_flash'] ?? $message);
$flashType = (string)($_SESSION['inquiry_center_flash_type'] ?? '');
$flashProjectId = (int)($_SESSION['inquiry_center_flash_project_id'] ?? 0);
unset($_SESSION['inquiry_center_flash']);
unset($_SESSION['inquiry_center_flash_type']);
unset($_SESSION['inquiry_center_flash_project_id']);

$engineers = [];
$engineerResult = $conn->query("SELECT id, full_name, role FROM users WHERE role = 'engineer' AND status = 'active' ORDER BY full_name ASC");
if ($engineerResult) {
    $engineers = $engineerResult->fetch_all(MYSQLI_ASSOC);
}

$walkInOldInput = $_SESSION['walk_in_inquiry_old_input'] ?? [];
unset($_SESSION['walk_in_inquiry_old_input']);
$openWalkInInquiry = (string)($_GET['walk_in'] ?? '') === '1' || $walkInOldInput !== [];
$walkInScheduleRange = walk_in_inquiry_preferred_date_range();
$walkInCsrfToken = auth_csrf_token('super_admin');

$statusFilter = trim((string)($_GET['status'] ?? ''));
$quotationFilter = trim((string)($_GET['quotation_filter'] ?? ''));
$sourceFilter = trim((string)($_GET['source'] ?? ''));
$search = trim((string)($_GET['search'] ?? ''));
$view = trim((string)($_GET['view'] ?? 'active'));
$sortOrder = trim((string)($_GET['sort'] ?? 'oldest'));
if (!in_array($view, ['active', 'archive'], true)) {
    $view = 'active';
}
if (!in_array($statusFilter, $inquiryFilterStatuses, true)) {
    $statusFilter = '';
}
$quotationFilterOptions = ['needs_quotation', 'draft', 'awaiting_client', 'accepted', 'needs_revision', 'rejected'];
if (!in_array($quotationFilter, $quotationFilterOptions, true)) {
    $quotationFilter = '';
}
// Quotation is handled by the Engineer after inspection.
$quotationFilter = '';
if (!in_array($sourceFilter, ['online', 'walk_in'], true)) {
    $sourceFilter = '';
}
if (!in_array($sortOrder, ['oldest', 'newest'], true)) {
    $sortOrder = 'oldest';
}

$hasInquiryQuotationTable = inquiry_quote_table_exists($conn, 'inquiry_quotation_drafts');
$hasQuotationProjectLink = $hasInquiryQuotationTable
    && inquiry_quote_column_exists($conn, 'inquiry_quotation_drafts', 'project_id');
$hasPreferredInspectionTime = inquiry_quote_column_exists($conn, 'service_inquiries', 'preferred_inspection_time');
$hasInquirySource = inquiry_quote_column_exists($conn, 'service_inquiries', 'inquiry_source');
$hasEstimatedBudget = inquiry_budget_column_exists($conn);
$inquiryRows = [];
$quotationFilterCounts = array_fill_keys($quotationFilterOptions, 0);
$lifecycleFilterCounts = array_fill_keys(['all', 'pending_review', 'qualified', 'for_inspection', 'not_qualified', 'archived'], 0);
if (inquiry_center_has_table($conn, 'service_inquiries')) {
    $where = [];
    $types = '';
    $params = [];
    $lifecycleSearchWhere = [];
    $lifecycleSearchTypes = '';
    $lifecycleSearchParams = [];

    $where[] = $view === 'archive' ? 'archived_at IS NOT NULL' : 'archived_at IS NULL';

    if ($sourceFilter === 'walk_in') {
        $sourceCondition = $hasInquirySource
            ? "inquiry_source = 'walk_in'"
            : "admin_notes LIKE '[Walk-in inquiry]%'";
        $where[] = $sourceCondition;
        $lifecycleSearchWhere[] = $sourceCondition;
    } elseif ($sourceFilter === 'online') {
        $sourceCondition = $hasInquirySource
            ? "COALESCE(inquiry_source, 'online') <> 'walk_in'"
            : "COALESCE(admin_notes, '') NOT LIKE '[Walk-in inquiry]%'";
        $where[] = $sourceCondition;
        $lifecycleSearchWhere[] = $sourceCondition;
    }

    if ($statusFilter === 'Rejected' && $hasInquiryQuotationTable) {
        $where[] = "EXISTS (
            SELECT 1 FROM inquiry_quotation_drafts rejected_quote
            WHERE rejected_quote.inquiry_id = service_inquiries.id
            AND rejected_quote.status = 'rejected'
            AND NOT EXISTS (
                SELECT 1 FROM inquiry_quotation_drafts newer_quote
                WHERE newer_quote.inquiry_id = rejected_quote.inquiry_id
                AND (
                    newer_quote.updated_at > rejected_quote.updated_at
                    OR (newer_quote.updated_at = rejected_quote.updated_at AND newer_quote.id > rejected_quote.id)
                )
            )
        )";
    } elseif ($statusFilter === 'Converted to Project' && $hasQuotationProjectLink) {
        $where[] = 'EXISTS (
            SELECT 1 FROM inquiry_quotation_drafts quote_filter
            WHERE quote_filter.inquiry_id = service_inquiries.id
            AND quote_filter.project_id IS NOT NULL
        )';
    } elseif ($statusFilter !== '') {
        if ($statusFilter === 'Pending Review') {
            $where[] = "status IN ('Pending Review', 'Verified Lead', 'Not Qualified')";
        } else {
            $where[] = 'status = ?';
            $types .= 's';
            $params[] = $statusFilter;
        }

        if ($hasQuotationProjectLink) {
            $where[] = 'NOT EXISTS (
                SELECT 1 FROM inquiry_quotation_drafts quote_filter
                WHERE quote_filter.inquiry_id = service_inquiries.id
                AND quote_filter.project_id IS NOT NULL
            )';
        }
    }

    if ($search !== '') {
        // Smart search: hanapin sa important fields para mas mabilis ang lead filtering.
        $searchCondition = '(client_name LIKE ? OR company_name LIKE ? OR email LIKE ? OR contact_no LIKE ? OR province LIKE ? OR city_municipality LIKE ? OR barangay LIKE ? OR site_address LIKE ? OR service_category LIKE ? OR status LIKE ? OR description LIKE ? OR admin_notes LIKE ? OR archive_reason LIKE ?)';
        $where[] = $searchCondition;
        $keyword = '%' . $search . '%';
        $types .= 'sssssssssssss';
        array_push($params, $keyword, $keyword, $keyword, $keyword, $keyword, $keyword, $keyword, $keyword, $keyword, $keyword, $keyword, $keyword, $keyword);
        $lifecycleSearchWhere[] = $searchCondition;
        $lifecycleSearchTypes = 'sssssssssssss';
        $lifecycleSearchParams = array_fill(0, 13, $keyword);
    }

    $quotationFilterScopeWhere = $where;
    $quotationFilterScopeTypes = $types;
    $quotationFilterScopeParams = $params;

    if ($view === 'active' && $statusFilter !== 'Rejected' && $quotationFilter !== 'rejected' && $hasInquiryQuotationTable) {
        $where[] = inquiry_center_latest_rejected_quotation_exclusion();
    }

    if ($view === 'active' && $quotationFilter !== '' && $hasInquiryQuotationTable) {
        $where[] = inquiry_center_quotation_filter_condition($quotationFilter);
    }

    $preferredTimeSelect = $hasPreferredInspectionTime
        ? 'preferred_inspection_time'
        : 'NULL AS preferred_inspection_time';
    $sourceSelect = $hasInquirySource
        ? 'inquiry_source'
        : "CASE WHEN admin_notes LIKE '[Walk-in inquiry]%' THEN 'walk_in' ELSE 'online' END AS inquiry_source";
    $budgetSelect = $hasEstimatedBudget
        ? 'estimated_budget'
        : 'NULL AS estimated_budget';
    $sql = 'SELECT id, client_id, client_name, company_name, email, contact_no, site_address,
                   province, city_municipality, barangay, service_category, ' . $budgetSelect . ', description, preferred_inspection_date,
                   ' . $preferredTimeSelect . ',
                   ' . $sourceSelect . ',
                   status, admin_notes, client_review_reason, reviewed_at, viewed_at, archived_at, archive_reason, created_at
            FROM service_inquiries';
    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sortDirection = $sortOrder === 'newest' ? 'DESC' : 'ASC';
    $sql .= " ORDER BY created_at {$sortDirection}, id {$sortDirection}";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $inquiryRows[] = $row;
        }
    }

    if ($view === 'active' && $hasInquiryQuotationTable) {
        $quotationFilterCounts = inquiry_center_quotation_filter_counts(
            $conn,
            $quotationFilterScopeWhere,
            $quotationFilterScopeTypes,
            $quotationFilterScopeParams
        );
    }

    $lifecycleFilterCounts = inquiry_center_lifecycle_filter_counts(
        $conn,
        $lifecycleSearchWhere,
        $lifecycleSearchTypes,
        $lifecycleSearchParams
    );
}

$inspectionByInquiry = [];
$inspectionHoldSelect = site_inspection_hold_columns_ready($conn)
    ? 'si.hold_reason, si.held_at, si.resumed_at,'
    : 'NULL AS hold_reason, NULL AS held_at, NULL AS resumed_at,';
$inspectionResult = $conn->query(
    "SELECT
        si.id,
        si.inquiry_id,
        si.engineer_id,
        si.scheduled_at,
        si.client_schedule_notified_at,
        si.engineer_schedule_notified_at,
        si.schedule_notified_at,
        si.schedule_notification_hash,
        si.client_schedule_response,
        si.client_schedule_response_note,
        si.client_schedule_preferred_at,
        si.client_schedule_responded_at,
        si.engineer_schedule_response,
        si.engineer_schedule_response_note,
        si.engineer_schedule_preferred_at,
        si.engineer_schedule_responded_at,
        si.status,
        si.created_at,
        si.acknowledged_at,
        si.started_at,
        {$inspectionHoldSelect}
        si.completed_at,
        si.submitted_at,
        si.admin_review_status,
        si.admin_remarks,
        si.admin_reviewed_by,
        si.admin_reviewed_at,
        si.site_notes,
        si.engineer_findings,
        si.risk_notes,
        si.client_requests,
        si.updated_at,
        u.full_name AS engineer_name
     FROM site_inspections si
     INNER JOIN users u ON u.id = si.engineer_id
     ORDER BY si.scheduled_at DESC, si.id DESC"
);
if ($inspectionResult) {
    while ($inspection = $inspectionResult->fetch_assoc()) {
        $inquiryId = (int)($inspection['inquiry_id'] ?? 0);
        if ($inquiryId > 0 && !isset($inspectionByInquiry[$inquiryId])) {
            $inspectionByInquiry[$inquiryId] = $inspection;
        }
    }
}

$costItemsByInspection = [];
$inspectionCostingHasMaterials = inquiry_center_has_table($conn, 'materials');
$inspectionCostingMaterialSelect = $inspectionCostingHasMaterials
    ? ', GREATEST(COALESCE(m.physical_quantity, 0) - COALESCE(m.reserved_quantity, 0), 0) AS material_available'
    : ', NULL AS material_available';
$inspectionCostingMaterialJoin = $inspectionCostingHasMaterials
    ? ' LEFT JOIN materials m ON m.id = ci.material_id'
    : '';
$costingResult = $conn->query(
    "SELECT ci.inspection_id, ci.item_type, ci.material_id, ci.item_name, ci.quantity, ci.unit, ci.unit_cost, ci.line_total, ci.notes
     {$inspectionCostingMaterialSelect}
     FROM site_inspection_cost_items ci
     {$inspectionCostingMaterialJoin}
     ORDER BY ci.id ASC"
);
if ($costingResult) {
    while ($costItem = $costingResult->fetch_assoc()) {
        $inspectionId = (int)($costItem['inspection_id'] ?? 0);
        if ($inspectionId > 0) {
            $costItemsByInspection[$inspectionId][] = $costItem;
        }
    }
}

$costingReviewByInquiry = [];
$costingReviewResult = $conn->query(
    "SELECT
        si.id,
        si.inquiry_id,
        si.scheduled_at,
        si.status,
        si.engineer_findings,
        si.risk_notes,
        si.client_requests,
        si.updated_at,
        u.full_name AS engineer_name,
        COUNT(ci.id) AS costing_rows,
        COALESCE(SUM(ci.line_total), 0) AS costing_total
     FROM site_inspections si
     INNER JOIN users u ON u.id = si.engineer_id
     INNER JOIN site_inspection_cost_items ci ON ci.inspection_id = si.id
     GROUP BY si.id, si.inquiry_id, si.scheduled_at, si.status, si.engineer_findings, si.risk_notes, si.client_requests, si.updated_at, u.full_name
     ORDER BY (si.status = 'Submitted') DESC, si.updated_at DESC, si.id DESC"
);
if ($costingReviewResult) {
    while ($review = $costingReviewResult->fetch_assoc()) {
        $inquiryId = (int)($review['inquiry_id'] ?? 0);
        if ($inquiryId > 0 && !isset($costingReviewByInquiry[$inquiryId])) {
            $costingReviewByInquiry[$inquiryId] = $review;
        }
    }
}

$quotationDraftByInquiry = inquiry_quote_fetch_by_inquiry($conn);
$originalQuotationByInquiry = [];
$originalQuotationResult = $conn->query(
    'SELECT * FROM inquiry_quotation_drafts
     WHERE revision_no = 0
     ORDER BY updated_at DESC, id DESC'
);
if ($originalQuotationResult) {
    while ($originalQuotation = $originalQuotationResult->fetch_assoc()) {
        $inquiryId = (int)($originalQuotation['inquiry_id'] ?? 0);
        if ($inquiryId > 0 && !isset($originalQuotationByInquiry[$inquiryId])) {
            $originalQuotationByInquiry[$inquiryId] = $originalQuotation;
        }
    }
}

$postInspectionDecisionByInspection = [];
if (inquiry_center_has_table($conn, 'inspection_quotation_decisions')) {
    $decisionResult = $conn->query('SELECT * FROM inspection_quotation_decisions ORDER BY id DESC');
    if ($decisionResult) {
        while ($decision = $decisionResult->fetch_assoc()) {
            $inspectionId = (int)($decision['inspection_id'] ?? 0);
            if ($inspectionId > 0 && !isset($postInspectionDecisionByInspection[$inspectionId])) {
                $postInspectionDecisionByInspection[$inspectionId] = $decision;
            }
        }
    }
}
$latestRevisionId = 0;
$latestRevisionUpdatedAt = '';
$latestRejectedId = 0;
$latestRejectedAt = '';
if (inquiry_quote_table_exists($conn, 'inquiry_quotation_drafts')) {
    $latestRevisionResult = $conn->query(
        "SELECT q.id, q.updated_at
         FROM inquiry_quotation_drafts q
         INNER JOIN service_inquiries si ON si.id = q.inquiry_id AND si.archived_at IS NULL
         WHERE q.status IN ('revision_requested', 'for_revision')
         ORDER BY q.updated_at DESC, q.id DESC
         LIMIT 1"
    );
    $latestRevision = $latestRevisionResult ? $latestRevisionResult->fetch_assoc() : null;
    $latestRevisionId = (int)($latestRevision['id'] ?? 0);
    $latestRevisionUpdatedAt = (string)($latestRevision['updated_at'] ?? '');

    $latestRejectedResult = $conn->query(
        "SELECT q.id, COALESCE(q.client_decision_at, q.updated_at) AS rejected_at
         FROM inquiry_quotation_drafts q
         INNER JOIN service_inquiries si ON si.id = q.inquiry_id AND si.archived_at IS NULL
         WHERE q.status = 'rejected'
         ORDER BY COALESCE(q.client_decision_at, q.updated_at) DESC, q.id DESC
         LIMIT 1"
    );
    $latestRejected = $latestRejectedResult ? $latestRejectedResult->fetch_assoc() : null;
    $latestRejectedId = (int)($latestRejected['id'] ?? 0);
    $latestRejectedAt = (string)($latestRejected['rejected_at'] ?? '');
}
$pendingCount = 0;
$verifiedCount = 0;
$inspectionCount = 0;
$notQualifiedCount = 0;
$rejectedCount = 0;
if (inquiry_center_has_table($conn, 'service_inquiries')) {
    // Global counts ito, hindi lang current search result.
    $countWhere = $hasQuotationProjectLink
        ? ' AND NOT EXISTS (
            SELECT 1 FROM inquiry_quotation_drafts quote_count
            WHERE quote_count.inquiry_id = service_inquiries.id
            AND quote_count.project_id IS NOT NULL
        )'
        : '';
    if ($hasInquiryQuotationTable) {
        $countWhere .= " AND NOT EXISTS (
            SELECT 1 FROM inquiry_quotation_drafts rejected_quote
            WHERE rejected_quote.inquiry_id = service_inquiries.id
            AND rejected_quote.status = 'rejected'
            AND NOT EXISTS (
                SELECT 1 FROM inquiry_quotation_drafts newer_quote
                WHERE newer_quote.inquiry_id = rejected_quote.inquiry_id
                AND (
                    newer_quote.updated_at > rejected_quote.updated_at
                    OR (newer_quote.updated_at = rejected_quote.updated_at AND newer_quote.id > rejected_quote.id)
                )
            )
        )";
    }
    $countResult = $conn->query('SELECT status, COUNT(*) AS total FROM service_inquiries WHERE archived_at IS NULL' . $countWhere . ' GROUP BY status');
    if ($countResult) {
        while ($countRow = $countResult->fetch_assoc()) {
            $status = (string)($countRow['status'] ?? 'Pending Review');
            $total = (int)($countRow['total'] ?? 0);
            if ($status === 'Pending Review') {
                $pendingCount = $total;
            } elseif ($status === 'Verified Lead') {
                $verifiedCount = $total;
            } elseif ($status === 'For Inspection') {
                $inspectionCount = $total;
            } elseif ($status === 'Not Qualified') {
                $notQualifiedCount = $total;
            }
        }
    }

    if ($hasInquiryQuotationTable) {
        $rejectedResult = $conn->query(
            "SELECT COUNT(*) AS total
             FROM service_inquiries
             WHERE archived_at IS NULL
             AND EXISTS (
                SELECT 1 FROM inquiry_quotation_drafts rejected_quote
                WHERE rejected_quote.inquiry_id = service_inquiries.id
                AND rejected_quote.status = 'rejected'
                AND NOT EXISTS (
                    SELECT 1 FROM inquiry_quotation_drafts newer_quote
                    WHERE newer_quote.inquiry_id = rejected_quote.inquiry_id
                    AND (
                        newer_quote.updated_at > rejected_quote.updated_at
                        OR (newer_quote.updated_at = rejected_quote.updated_at AND newer_quote.id > rejected_quote.id)
                    )
                )
             )"
        );
        $rejectedCount = (int)(($rejectedResult ? $rejectedResult->fetch_assoc() : [])['total'] ?? 0);
    }
}

$adminPageTitle = 'Inquiry Center - Edge Automation';
$adminCssFiles = [
    '/codesamplecaps/ADMIN/common/css/admin-common.css',
    '/codesamplecaps/SHARED/toast/css/toast.css',
    '/codesamplecaps/ADMIN/sidebar/projects/css/projects.css?v=20261025-4',
    '/codesamplecaps/ADMIN/sidebar/inquiries/css/inquiries.css?v=20261027-2',
];
$adminJsFiles = [
    '/codesamplecaps/ADMIN/common/js/admin-common.js',
    '/codesamplecaps/SHARED/toast/js/toast.js',
    '/codesamplecaps/ADMIN/sidebar/inquiries/js/inquiries.js?v=20261027-2',
    '/codesamplecaps/ADMIN/sidebar/projects/js/projects.js?v=20261025-3',
];
include __DIR__ . '/../../../layout/header.php';
include __DIR__ . '/../../../admin_sidebar.php';
?>

<main class="main-content admin-dashboard-content">
    <div
        class="inquiries-shell"
        data-latest-revision-id="<?php echo $latestRevisionId; ?>"
        data-latest-revision-updated-at="<?php echo htmlspecialchars($latestRevisionUpdatedAt, ENT_QUOTES, 'UTF-8'); ?>"
        data-latest-rejected-id="<?php echo $latestRejectedId; ?>"
        data-latest-rejected-at="<?php echo htmlspecialchars($latestRejectedAt, ENT_QUOTES, 'UTF-8'); ?>"
    >
        <?php if ($message || $error): ?>
            <div
                class="shared-toast <?php echo $message ? ($flashType === 'error' ? 'shared-toast--error' : ($flashType === 'warning' ? 'shared-toast--warning' : 'shared-toast--success')) : 'shared-toast--error'; ?>"
                role="<?php echo $message ? 'status' : 'alert'; ?>"
                data-shared-toast
            >
                <span><?php echo htmlspecialchars($message ?: $error, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php if ($message && $flashProjectId > 0): ?>
                    <a href="/codesamplecaps/ADMIN/sidebar/projects/php/project_details.php?id=<?php echo (int)$flashProjectId; ?>">
                        Open Project
                    </a>
                <?php endif; ?>
                <button type="button" class="shared-toast__close" data-shared-toast-close aria-label="Close notification">&times;</button>
                <span class="shared-toast__progress" aria-hidden="true"></span>
            </div>
        <?php endif; ?>


        <div class="inquiry-page-header">
            <h1>Inquiry Center</h1>
            <form class="inquiry-header-search" method="GET" role="search">
                <input type="search" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search inquiries..." aria-label="Search inquiries">
                <?php if ($view === 'archive'): ?><input type="hidden" name="view" value="archive"><?php endif; ?>
                <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                <?php if ($quotationFilter !== ''): ?><input type="hidden" name="quotation_filter" value="<?php echo htmlspecialchars($quotationFilter, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                <?php if ($sourceFilter !== ''): ?><input type="hidden" name="source" value="<?php echo htmlspecialchars($sourceFilter, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sortOrder, ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="btn-primary">Search</button>
            </form>
            <?php if ($view !== 'archive'): ?>
                <button type="button" class="btn-primary" data-walk-in-inquiry-open>+ Create Walk-in Inquiry</button>
            <?php endif; ?>
        </div>

        <?php include __DIR__ . '/walk_in_inquiry_modal.php'; ?>

        <nav class="inquiry-status-strip inquiry-source-strip" aria-label="Inquiry source filters">
            <a class="inquiry-view-link <?php echo $sourceFilter === '' ? 'is-active' : ''; ?>" href="<?php echo htmlspecialchars(inquiry_center_filter_url(['view' => $view === 'archive' ? 'archive' : '', 'status' => $statusFilter, 'quotation_filter' => $quotationFilter, 'search' => $search, 'sort' => $sortOrder]), ENT_QUOTES, 'UTF-8'); ?>">All Sources</a>
            <a class="inquiry-view-link <?php echo $sourceFilter === 'online' ? 'is-active' : ''; ?>" href="<?php echo htmlspecialchars(inquiry_center_filter_url(['source' => 'online', 'view' => $view === 'archive' ? 'archive' : '', 'status' => $statusFilter, 'quotation_filter' => $quotationFilter, 'search' => $search, 'sort' => $sortOrder]), ENT_QUOTES, 'UTF-8'); ?>">Online</a>
            <a class="inquiry-view-link <?php echo $sourceFilter === 'walk_in' ? 'is-active' : ''; ?>" href="<?php echo htmlspecialchars(inquiry_center_filter_url(['source' => 'walk_in', 'view' => $view === 'archive' ? 'archive' : '', 'status' => $statusFilter, 'quotation_filter' => $quotationFilter, 'search' => $search, 'sort' => $sortOrder]), ENT_QUOTES, 'UTF-8'); ?>">Walk-in</a>
        </nav>

        <div class="inquiry-lifecycle-toolbar">
        <nav class="inquiry-status-strip inquiry-status-strip--primary" aria-label="Inquiry lifecycle filters">
            <a class="inquiry-view-link inquiry-lifecycle-filter <?php echo $view === 'active' && $statusFilter === '' ? 'is-active' : ''; ?>" href="<?php echo htmlspecialchars(inquiry_center_filter_url(['search' => $search, 'source' => $sourceFilter, 'sort' => $sortOrder]), ENT_QUOTES, 'UTF-8'); ?>">
                <span>All</span>
                <span class="inquiry-quotation-filter__count" data-inquiry-count-key="lifecycle:all"><?php echo (int)($lifecycleFilterCounts['all'] ?? 0); ?></span>
            </a>
            <?php foreach (['Pending Review', 'For Inspection'] as $status): ?>
                <?php $lifecycleCountKey = match ($status) { 'Pending Review' => 'pending_review', 'For Inspection' => 'for_inspection' }; ?>
                <a class="inquiry-status inquiry-status-link inquiry-lifecycle-filter <?php echo $view === 'active' && $statusFilter === $status ? 'is-active' : ''; ?>" data-status="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>" href="<?php echo htmlspecialchars(inquiry_center_filter_url(['status' => $status, 'search' => $search, 'source' => $sourceFilter, 'sort' => $sortOrder]), ENT_QUOTES, 'UTF-8'); ?>">
                    <span><?php echo htmlspecialchars(inquiry_center_status_label($status), ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="inquiry-quotation-filter__count" data-inquiry-count-key="lifecycle:<?php echo htmlspecialchars($lifecycleCountKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo (int)($lifecycleFilterCounts[$lifecycleCountKey] ?? 0); ?></span>
                </a>
            <?php endforeach; ?>
            <a class="inquiry-view-link inquiry-lifecycle-filter <?php echo $view === 'archive' ? 'is-active' : ''; ?>" href="<?php echo htmlspecialchars(inquiry_center_filter_url(['view' => 'archive', 'search' => $search, 'source' => $sourceFilter, 'sort' => $sortOrder]), ENT_QUOTES, 'UTF-8'); ?>">
                <span>Archived</span>
                <span class="inquiry-quotation-filter__count" data-inquiry-count-key="lifecycle:archived"><?php echo (int)($lifecycleFilterCounts['archived'] ?? 0); ?></span>
            </a>
        </nav>

        <form class="inquiry-sort-form" method="GET">
            <select id="inquirySortOrder" name="sort" aria-label="Sort inquiries">
                <option value="oldest"<?php echo $sortOrder === 'oldest' ? ' selected' : ''; ?>>Oldest First</option>
                <option value="newest"<?php echo $sortOrder === 'newest' ? ' selected' : ''; ?>>Newest First</option>
            </select>
            <?php if ($view === 'archive'): ?><input type="hidden" name="view" value="archive"><?php endif; ?>
            <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
            <?php if ($sourceFilter !== ''): ?><input type="hidden" name="source" value="<?php echo htmlspecialchars($sourceFilter, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
            <?php if ($search !== ''): ?><input type="hidden" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
        </form>
        </div>

        <?php $showQuotationFilters = false; ?>
        <?php if ($showQuotationFilters): ?>
            <nav class="inquiry-status-strip inquiry-status-strip--quotation" aria-label="Initial quotation filters">
                <?php $quotationFilterChips = ['needs_quotation' => 'Needs Quotation', 'draft' => 'Draft', 'awaiting_client' => 'Awaiting Client', 'accepted' => 'Client Approved', 'needs_revision' => 'Needs Revision', 'rejected' => 'Rejected']; ?>
                <?php if ($statusFilter === 'For Inspection'): unset($quotationFilterChips['needs_quotation']); endif; ?>
                <?php foreach ($quotationFilterChips as $filterKey => $filterLabel): ?>
                    <a class="inquiry-quotation-filter <?php echo $quotationFilter === $filterKey ? 'is-active' : ''; ?>" href="<?php echo htmlspecialchars(inquiry_center_filter_url(['status' => $statusFilter, 'search' => $search, 'quotation_filter' => $filterKey, 'source' => $sourceFilter, 'sort' => $sortOrder]), ENT_QUOTES, 'UTF-8'); ?>">
                        <span><?php echo htmlspecialchars($filterLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="inquiry-quotation-filter__count" data-inquiry-count-key="quotation:<?php echo htmlspecialchars($filterKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo (int)($quotationFilterCounts[$filterKey] ?? 0); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
        <?php if (empty($inquiryRows)): ?>
            <div class="inquiry-empty">No inquiries found.</div>
        <?php else: ?>
            <div class="inquiry-list">
                <?php foreach ($inquiryRows as $inquiry): ?>
                    <?php $currentStatus = (string)($inquiry['status'] ?? 'Pending Review'); ?>
                    <?php $isViewed = !empty($inquiry['viewed_at']); ?>
                    <?php $latestInspection = $inspectionByInquiry[(int)$inquiry['id']] ?? null; ?>
                    <?php $costingReview = $costingReviewByInquiry[(int)$inquiry['id']] ?? null; ?>
                    <?php $latestCostItems = $costingReview ? ($costItemsByInspection[(int)$costingReview['id']] ?? []) : []; ?>
                    <?php
                        $outOfStockInspectionItems = [];
                        foreach ($latestCostItems as $latestCostItem) {
                            $isLinkedMaterial = strtolower((string)($latestCostItem['item_type'] ?? '')) === 'material'
                                && (int)($latestCostItem['material_id'] ?? 0) > 0;
                            if ($isLinkedMaterial
                                && ($latestCostItem['material_available'] ?? null) !== null
                                && (float)$latestCostItem['material_available'] <= 0) {
                                $outOfStockInspectionItems[] = [
                                    'name' => (string)($latestCostItem['item_name'] ?? 'Material'),
                                    'needed' => rtrim(rtrim(number_format((float)($latestCostItem['quantity'] ?? 0), 2), '0'), '.'),
                                    'unit' => (string)($latestCostItem['unit'] ?? ''),
                                    'available' => rtrim(rtrim(number_format((float)$latestCostItem['material_available'], 2), '0'), '.'),
                                ];
                            }
                        }
                        $hasOutOfStockInspectionMaterial = !empty($outOfStockInspectionItems);
                    ?>
                    <?php $latestCostTotal = (float)($costingReview['costing_total'] ?? 0); ?>
                    <?php $quotationDraft = $quotationDraftByInquiry[(int)$inquiry['id']] ?? null; ?>
                    <?php $originalQuotation = $originalQuotationByInquiry[(int)$inquiry['id']] ?? $quotationDraft; ?>
                    <?php $isInitialQuotationDraft = $quotationDraft
                        && empty($quotationDraft['parent_draft_id'])
                        && (int)($quotationDraft['revision_no'] ?? 0) === 0; ?>
                    <?php $postInspectionDecision = $latestInspection ? ($postInspectionDecisionByInspection[(int)$latestInspection['id']] ?? null) : null; ?>
                    <?php $quotationListStatus = $quotationDraft ? inquiry_quote_normalize_status((string)$quotationDraft['status']) : ''; ?>
                    <?php $isConvertedToProject = !empty($quotationDraft['project_id']); ?>
                    <?php
                        $latestInspectionStatus = $latestInspection
                            ? (string)($latestInspection['status'] ?? 'Assigned')
                            : 'Not Scheduled';
                        $displayStatus = $isConvertedToProject
                            ? 'Converted to Project'
                            : ($quotationListStatus === 'rejected' ? 'Rejected' : $currentStatus);
                        if (in_array($displayStatus, ['Verified Lead', 'Not Qualified'], true)) {
                            $displayStatus = 'Pending Review';
                        }
                        if (!$isConvertedToProject && $displayStatus === 'For Inspection') {
                            if (in_array($latestInspectionStatus, ['Ongoing', 'On Hold', 'Completed'], true)) {
                                $displayStatus = 'Inspection Ongoing';
                            } elseif ($latestInspectionStatus === 'Submitted') {
                                $displayStatus = 'Inspection Submitted';
                            }
                        }
                    ?>
                    <?php $displayStatusLabel = in_array($displayStatus, ['Inspection Ongoing', 'Inspection Submitted'], true)
                        ? $displayStatus
                        : inquiry_center_status_label($displayStatus); ?>
                    <?php
                        $addressParts = array_filter([
                            trim((string)($inquiry['site_address'] ?? '')),
                            trim((string)($inquiry['barangay'] ?? '')),
                            trim((string)($inquiry['city_municipality'] ?? '')),
                            trim((string)($inquiry['province'] ?? '')),
                        ], static fn(string $part): bool => $part !== '');
                        $fullAddress = $addressParts ? implode(', ', $addressParts) : 'Not set';
                        $cardLocation = implode(', ', array_filter([
                            trim((string)($inquiry['barangay'] ?? '')),
                            trim((string)($inquiry['city_municipality'] ?? '')),
                        ], static fn(string $part): bool => $part !== ''));
                        if ($cardLocation === '') {
                            $cardLocation = trim((string)($inquiry['province'] ?? '')) ?: 'Not set';
                        }
                        $cardService = trim((string)($inquiry['service_category'] ?? ''));
                        if ($cardService === 'Other Service') {
                            $descriptionValue = (string)($inquiry['description'] ?? '');
                            $otherDetails = '';
                            if (preg_match('/Other service details:\s*(.+)/is', $descriptionValue, $matches)) {
                                $otherDetails = preg_replace('/\s+/u', ' ', trim((string)$matches[1])) ?? '';
                            }
                            $cardService = $otherDetails !== ''
                                ? 'Other: ' . mb_strimwidth($otherDetails, 0, 42, '...')
                                : 'Other Service';
                        }
                    ?>
                    <?php $showCosting = $costingReview && !empty($latestCostItems); ?>
                    <?php
                        $quotationStage = $quotationListStatus;
                        $canScheduleInspection = in_array($currentStatus, ['Pending Review', 'For Inspection', 'Verified Lead', 'Not Qualified'], true);
                        $quotationPrerequisiteMessage = inquiry_center_quotation_prerequisite_message($quotationDraft);
                        $showInspection = $latestInspection || $canScheduleInspection;
                        $showQuotation = false;
                        // Simple lang para kay Admin: details at inspection lang ang tingnan.
                        $nextActionLabel = 'View Details';
                        $nextActionTab = $latestInspection ? 'inspection' : 'client';
                        $nextActionClass = '';
                        $currentScheduleHash = $latestInspection
                            ? inquiry_center_schedule_notification_hash(
                                (int)($latestInspection['engineer_id'] ?? 0),
                                (string)($latestInspection['scheduled_at'] ?? ''),
                                (string)($latestInspection['site_notes'] ?? '')
                            )
                            : '';
                        $storedScheduleHash = (string)($latestInspection['schedule_notification_hash'] ?? '');
                        $isScheduleConfirmed = $latestInspection
                            && !empty($latestInspection['client_schedule_notified_at'])
                            && !empty($latestInspection['engineer_schedule_notified_at'])
                            && !empty($latestInspection['schedule_notified_at'])
                            && $storedScheduleHash !== ''
                            && hash_equals($storedScheduleHash, $currentScheduleHash);
                        $canRetryScheduleNotifications = $latestInspection
                            && !$isScheduleConfirmed
                            && empty($inquiry['archived_at'])
                            && in_array($latestInspectionStatus, ['Assigned', 'Acknowledged'], true);
                    ?>
                    <article class="inquiry-card <?php echo $isViewed ? 'is-viewed' : 'is-unviewed'; ?>" data-inquiry-card-id="<?php echo (int)$inquiry['id']; ?>">
                        <div class="inquiry-card__head">
                            <div class="inquiry-card__identity">
                                <?php if (!$isViewed): ?>
                                    <span class="inquiry-card__new-indicator" data-inquiry-unread-indicator>
                                        <span aria-hidden="true"></span>New
                                    </span>
                                <?php endif; ?>
                                <span class="inquiry-card__eyebrow">Contact Person · <?php echo (string)($inquiry['inquiry_source'] ?? 'online') === 'walk_in' ? 'Walk-in' : 'Online'; ?></span>
                                <h2><?php echo htmlspecialchars((string)$inquiry['client_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                            </div>
                            <span class="inquiry-status" data-status="<?php echo htmlspecialchars($displayStatus, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($displayStatusLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>

                        <div class="inquiry-card__summary">
                            <div class="inquiry-card__info">
                                <span>Service</span>
                                <strong><?php echo htmlspecialchars($cardService, ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                            <div class="inquiry-card__info">
                                <span>Location</span>
                                <strong><?php echo htmlspecialchars($cardLocation, ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                            <div class="inquiry-card__info">
                                <?php if (in_array($displayStatus, ['For Inspection', 'Inspection Ongoing', 'Inspection Submitted'], true) && !empty($latestInspection['scheduled_at'])): ?>
                                    <span>Inspection Schedule</span>
                                    <strong><?php echo htmlspecialchars(site_inspection_format_date($latestInspection['scheduled_at']), ENT_QUOTES, 'UTF-8'); ?></strong>
                                <?php else: ?>
                                    <span>Received</span>
                                    <strong><?php echo htmlspecialchars(inquiry_center_format_compact_datetime($inquiry['created_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($isConvertedToProject): ?>
                            <a class="inquiry-next-action" href="/codesamplecaps/ADMIN/sidebar/projects/php/project_details.php?id=<?php echo (int)$quotationDraft['project_id']; ?>">
                                Open Project
                            </a>
                        <?php else: ?>
                            <button
                                type="button"
                                class="inquiry-open-modal inquiry-next-action<?php echo $nextActionClass; ?>"
                                data-inquiry-modal-open="inquiryModal<?php echo (int)$inquiry['id']; ?>"
                                data-inquiry-open-tab="<?php echo htmlspecialchars($nextActionTab, ENT_QUOTES, 'UTF-8'); ?>"
                            >
                                <?php echo htmlspecialchars($nextActionLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </button>
                        <?php endif; ?>

                        <div class="inquiry-modal" id="inquiryModal<?php echo (int)$inquiry['id']; ?>" data-inquiry-id="<?php echo (int)$inquiry['id']; ?>" data-inspection-id="<?php echo (int)($latestInspection['id'] ?? 0); ?>" data-quotation-status="<?php echo htmlspecialchars($quotationStage, ENT_QUOTES, 'UTF-8'); ?>" hidden>
                            <div class="inquiry-modal__panel" role="dialog" aria-modal="true" aria-labelledby="inquiryModalTitle<?php echo (int)$inquiry['id']; ?>">
                                <div class="inquiry-modal__head">
                                    <div>
                                        <span class="reports-kicker"><?php echo (string)($inquiry['inquiry_source'] ?? 'online') === 'walk_in' ? 'Walk-in Inquiry Details' : 'Online Inquiry Details'; ?></span>
                                        <h2 id="inquiryModalTitle<?php echo (int)$inquiry['id']; ?>"><?php echo htmlspecialchars((string)$inquiry['client_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                                        <div class="inquiry-modal__meta">
                                            <span><?php echo htmlspecialchars((string)$inquiry['service_category'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span class="inquiry-status inquiry-status--modal" data-modal-status-chip data-status="<?php echo htmlspecialchars($displayStatus, ENT_QUOTES, 'UTF-8'); ?>">
                                                <?php echo htmlspecialchars($displayStatusLabel, ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <?php if (!empty($inquiry['archived_at'])): ?>
                                        <div class="inquiry-modal__summary-card inquiry-modal__summary-card--archive">
                                            <strong>Archived</strong>
                                            <span><?php echo htmlspecialchars(inquiry_center_format_datetime($inquiry['archived_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span>Reason: <?php echo htmlspecialchars((string)($inquiry['archive_reason'] ?: 'No reason'), ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="inquiry-modal__tools">
                                        <?php if (empty($inquiry['archived_at'])): ?>
                                            <button type="button" class="inquiry-icon-button inquiry-icon-button--archive" data-tooltip="Archive inquiry" data-archive-modal-open="archiveModal<?php echo (int)$inquiry['id']; ?>" aria-label="Archive inquiry">
                                                <span aria-hidden="true">&#8631;</span>
                                            </button>
                                        <?php else: ?>
                                            <form method="POST" class="inquiry-restore-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="restore_inquiry">
                                                <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry['id']; ?>">
                                                <button type="submit" class="inquiry-icon-button inquiry-icon-button--restore" data-tooltip="Restore inquiry" aria-label="Restore inquiry">
                                                    <span aria-hidden="true">&#8634;</span>
                                                </button>
                                            </form>
                                            <form method="POST" class="inquiry-delete-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="delete_inquiry">
                                                <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry['id']; ?>">
                                                <button type="submit" class="inquiry-icon-button inquiry-icon-button--delete" data-tooltip="Delete permanently" aria-label="Delete permanently">
                                                    <span aria-hidden="true">&#128465;</span>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <button type="button" class="inquiry-modal__close" data-inquiry-modal-close aria-label="Close inquiry details">&times;</button>
                                    </div>
                                </div>
                                <?php $showInspectionTab = (bool)$showInspection; ?>
                                <div class="inquiry-modal-tabs inquiry-workflow inquiry-workflow--admin" role="tablist" aria-label="Inquiry details tabs">
                                    <button type="button" class="inquiry-modal-tab inquiry-workflow__step is-active is-current" data-inquiry-tab="client" aria-selected="true" aria-current="step">
                                        <span class="inquiry-workflow__marker" aria-hidden="true"><span>1</span><b>&#10003;</b></span>
                                        <span class="inquiry-workflow__label">Contact &amp; Request</span>
                                    </button>
                                    <?php if ($showInspectionTab): ?>
                                        <button type="button" class="inquiry-modal-tab inquiry-workflow__step" data-inquiry-tab="inspection" data-inquiry-stage="inspection" aria-selected="false" aria-current="false">
                                            <span class="inquiry-workflow__marker" aria-hidden="true"><span>2</span><b>&#10003;</b></span>
                                            <span class="inquiry-workflow__label">Inspection</span>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <div class="inquiry-modal-panels" <?php echo !empty($inquiry['archived_at']) ? 'data-archived-readonly="true"' : ''; ?>>
                                <section class="inquiry-tab-panel is-active" data-inquiry-panel="client">
                                    <div class="inquiry-section-title">Contact and Request Details</div>
                                    <div class="inquiry-details-grid">
                                        <?php if (trim((string)$inquiry['email']) !== ''): ?>
                                            <div class="inquiry-detail"><span>Email</span><strong><?php echo htmlspecialchars((string)$inquiry['email'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                        <?php endif; ?>
                                        <div class="inquiry-detail"><span>Contact Number</span><strong><?php echo htmlspecialchars((string)$inquiry['contact_no'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                        <div class="inquiry-detail inquiry-detail--wide"><span>Complete Address</span><strong><?php echo htmlspecialchars($fullAddress, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                        <?php if (!empty($inquiry['preferred_inspection_date'])): ?>
                                            <div class="inquiry-detail"><span>Client Tentative Inspection Date</span><strong><?php echo htmlspecialchars(inquiry_center_format_date($inquiry['preferred_inspection_date']), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                        <?php endif; ?>
                                        <div class="inquiry-detail"><span>Estimated Budget</span><strong><?php echo htmlspecialchars(inquiry_budget_label($inquiry['estimated_budget'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                    </div>

                                    <div class="inquiry-description">
                                        <strong>Project Description</strong><br>
                                        <?php echo nl2br(htmlspecialchars((string)$inquiry['description'], ENT_QUOTES, 'UTF-8')); ?>
                                    </div>

                                    <?php if (!empty($inquiry['archived_at'])): ?>
                                        <div class="inquiry-archive-output">
                                            <strong>Archived</strong>
                                            <span><?php echo htmlspecialchars(inquiry_center_format_datetime($inquiry['archived_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span>Reason: <?php echo htmlspecialchars((string)($inquiry['archive_reason'] ?: 'No reason'), ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </section>

                                <section class="inquiry-tab-panel" data-inquiry-panel="inspection" hidden>
                                    <?php
                                    $canRescheduleInspection = $latestInspection
                                        && empty($inquiry['archived_at'])
                                        && !inquiry_center_inspection_schedule_is_locked($latestInspection);
                                    $canMonitorInspection = $latestInspection
                                        && in_array($latestInspectionStatus, ['Ongoing', 'Completed', 'Submitted'], true);
                                    ?>
                                    <?php if ($latestInspection): ?>
                                        <?php
                                        $currentScheduleReason = site_inspection_current_admin_reschedule_reason(
                                            $conn,
                                            (int)$latestInspection['id'],
                                            (int)$latestInspection['engineer_id'],
                                            (string)$latestInspection['scheduled_at']
                                        );
                                        ?>
                                        <div class="inquiry-details-grid inquiry-inspection-summary-grid">
                                            <div class="inquiry-detail inquiry-inspection-summary-card" data-inspection-assigned-detail>
                                                <div class="inquiry-inspection-summary-card__head">
                                                    <span>Assigned Engineer</span>
                                                </div>
                                                <div class="inquiry-inspection-summary-card__value-line"><strong><?php echo htmlspecialchars((string)$latestInspection['engineer_name'], ENT_QUOTES, 'UTF-8'); ?></strong><button type="button" class="btn-secondary inquiry-inspection-summary-card__monitor" data-inspection-monitor-open data-inspection-id="<?php echo (int)$latestInspection['id']; ?>" <?php echo !$canMonitorInspection ? 'hidden' : ''; ?>>Monitor Inspection</button></div>
                                            </div>
                                            <div class="inquiry-detail inquiry-inspection-summary-card" data-inspection-date-detail>
                                                <div class="inquiry-inspection-summary-card__head"><span>Scheduled Inspection Date</span></div>
                                                <div class="inquiry-inspection-summary-card__value-line">
                                                    <div class="inquiry-inspection-date-stack">
                                                        <strong><?php echo htmlspecialchars(site_inspection_format_date($latestInspection['scheduled_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong>
                                                        <div class="inquiry-schedule-email-status <?php echo $isScheduleConfirmed ? 'is-sent' : 'is-failed'; ?>">
                                                            <span><?php echo $isScheduleConfirmed ? '&#10003; Email sent to Client and Engineer' : 'Email failed'; ?></span>
                                                            <?php if (!$isScheduleConfirmed && $canRetryScheduleNotifications): ?>
                                                                <form method="POST" class="inquiry-notification-retry-form">
                                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                                    <input type="hidden" name="action" value="retry_inspection_notifications">
                                                                    <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry['id']; ?>">
                                                                    <input type="hidden" name="inspection_id" value="<?php echo (int)$latestInspection['id']; ?>">
                                                                    <input type="hidden" name="notification_hash" value="<?php echo htmlspecialchars($currentScheduleHash, ENT_QUOTES, 'UTF-8'); ?>">
                                                                    <button type="submit" class="btn-secondary">Retry</button>
                                                                </form>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <?php if ($canRescheduleInspection): ?>
                                                        <button type="button" class="btn-secondary inquiry-inspection-summary-card__reschedule" data-reschedule-inspection-open>Reschedule Inspection</button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php if ($currentScheduleReason !== ''): ?>
                                                <div class="inquiry-detail inquiry-detail--wide"><span>Schedule Change Reason</span><strong class="inquiry-detail__multiline"><?php echo nl2br(htmlspecialchars($currentScheduleReason, ENT_QUOTES, 'UTF-8')); ?></strong></div>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($latestInspection['submitted_at'])): ?>
                                            <?php
                                            $inspectionReviewStatus = (string)($latestInspection['admin_review_status'] ?? 'Pending');
                                            $inspectionDisplayStatus = $latestInspectionStatus === 'Completed' && $inspectionReviewStatus === 'Returned'
                                                ? 'Returned for Revision'
                                                : $latestInspectionStatus;
                                            $initialQuotationTotalForReview = (float)($originalQuotation['grand_total'] ?? 0);
                                            $inspectionCostDifference = round($latestCostTotal - $initialQuotationTotalForReview, 2);
                                            ?>
                                            <section class="submitted-inspection-report">
                                                <div class="submitted-inspection-report__head">
                                                    <div>
                                                    <span>Submitted Inspection Costing</span>
                                                    </div>
                                                    <div class="submitted-inspection-report__states">
                                                        <?php if ($hasOutOfStockInspectionMaterial): ?>
                                                            <button type="button" class="inspection-stock-alert inspection-stock-alert--button" data-inspection-stock-open data-stock-items="<?php echo htmlspecialchars((string)json_encode($outOfStockInspectionItems, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">Out of Stock</button>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>

                                                <div class="inquiry-details-grid submitted-inspection-report__details">
                                                    <div class="inquiry-detail inquiry-detail--wide"><span>Engineer Findings</span><strong><?php echo nl2br(htmlspecialchars((string)($latestInspection['engineer_findings'] ?: 'No findings provided.'), ENT_QUOTES, 'UTF-8')); ?></strong></div>
                                                    <?php if (trim((string)($latestInspection['risk_notes'] ?? '')) !== ''): ?>
                                                        <div class="inquiry-detail"><span>Risk / Safety Notes</span><strong><?php echo nl2br(htmlspecialchars((string)$latestInspection['risk_notes'], ENT_QUOTES, 'UTF-8')); ?></strong></div>
                                                    <?php endif; ?>
                                                    <?php if (trim((string)($latestInspection['client_requests'] ?? '')) !== ''): ?>
                                                        <div class="inquiry-detail"><span>Client Requests</span><strong><?php echo nl2br(htmlspecialchars((string)$latestInspection['client_requests'], ENT_QUOTES, 'UTF-8')); ?></strong></div>
                                                    <?php endif; ?>
                                                </div>

                                                <div class="inspection-cost-comparison" aria-label="Quotation and inspection cost comparison">
                                                    <div><span>Initial Quotation Total</span><strong><?php echo htmlspecialchars(inquiry_center_format_money($initialQuotationTotalForReview), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                                    <div><span>Engineer Costing Total</span><strong><?php echo htmlspecialchars(inquiry_center_format_money($latestCostTotal), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                                    <div class="inspection-cost-comparison__difference"><span>Difference</span><strong><?php echo htmlspecialchars(inquiry_center_format_money($inspectionCostDifference), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                                </div>

                                                <div class="inquiry-costing-table submitted-inspection-costing">
                                                    <div class="inquiry-costing-table__row submitted-inspection-costing__row submitted-inspection-costing__row--head">
                                                        <span>Type</span>
                                                        <span>Item / Labor</span>
                                                        <span>Qty</span>
                                                        <span>Unit</span>
                                                        <span>Unit Cost</span>
                                                        <span>Notes</span>
                                                        <span>Line Total</span>
                                                    </div>
                                                    <?php foreach ($latestCostItems as $costItem): ?>
                                                        <div class="inquiry-costing-table__row submitted-inspection-costing__row">
                                                            <span><?php echo htmlspecialchars(inquiry_quote_item_type_label((string)$costItem['item_type']), ENT_QUOTES, 'UTF-8'); ?></span>
                                                            <span><?php echo htmlspecialchars((string)$costItem['item_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                            <span><?php echo htmlspecialchars(rtrim(rtrim(number_format((float)$costItem['quantity'], 2), '0'), '.'), ENT_QUOTES, 'UTF-8'); ?></span>
                                                            <span><?php echo htmlspecialchars((string)$costItem['unit'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                            <span><?php echo htmlspecialchars(inquiry_center_format_money((float)$costItem['unit_cost']), ENT_QUOTES, 'UTF-8'); ?></span>
                                                            <span><?php echo htmlspecialchars((string)($costItem['notes'] ?: '—'), ENT_QUOTES, 'UTF-8'); ?></span>
                                                            <span><?php echo htmlspecialchars(inquiry_center_format_money((float)$costItem['line_total']), ENT_QUOTES, 'UTF-8'); ?></span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                <div class="submitted-inspection-report__total">
                                                    <span>Engineer Grand Total</span>
                                                    <strong><?php echo htmlspecialchars(inquiry_center_format_money($latestCostTotal), ENT_QUOTES, 'UTF-8'); ?></strong>
                                                </div>

                                                <?php if ($inspectionReviewStatus === 'Returned' && !empty($latestInspection['admin_remarks'])): ?>
                                                    <div class="submitted-inspection-review-state is-returned">
                                                        <strong>Returned to Engineer</strong>
                                                        <p><?php echo nl2br(htmlspecialchars((string)$latestInspection['admin_remarks'], ENT_QUOTES, 'UTF-8')); ?></p>
                                                    </div>
                                                <?php elseif ($inspectionReviewStatus === 'Approved'): ?>
                                                    <div class="submitted-inspection-review-state is-approved">
                                                        <strong>Inspection Report Approved</strong>
                                                        <span><?php echo htmlspecialchars(site_inspection_format_datetime($latestInspection['admin_reviewed_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></span>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if (empty($inquiry['archived_at']) && $latestInspectionStatus === 'Submitted' && $inspectionReviewStatus === 'Pending'): ?>
                                                    <form method="POST" class="submitted-inspection-review-form">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="action" value="review_inspection_report">
                                                        <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry['id']; ?>">
                                                        <input type="hidden" name="inspection_id" value="<?php echo (int)$latestInspection['id']; ?>">
                                                        <input type="hidden" name="review_decision" value="" data-review-decision>
                                                        <label>
                                                            <span>Admin Remarks / Reason for Return</span>
                                                            <textarea name="admin_remarks" rows="3" maxlength="2000" placeholder="Required only when returning the report to the Engineer"></textarea>
                                                        </label>
                                                        <div class="inquiry-review-actions">
                                                            <button type="submit" name="review_decision" value="return" class="btn-secondary">Return to Engineer</button>
                                                            <button type="submit" name="review_decision" value="approve" class="btn-primary">Approve Costing</button>
                                                        </div>
                                                    </form>
                                                <?php endif; ?>
                                            </section>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php if (($latestInspection || in_array($currentStatus, ['Pending Review', 'Verified Lead', 'Not Qualified'], true)) && in_array($currentStatus, ['Pending Review', 'For Inspection', 'Verified Lead', 'Not Qualified'], true)): ?>
                                        <?php $inspectionTimestamp = !empty($latestInspection['scheduled_at']) ? strtotime((string)$latestInspection['scheduled_at']) : false; ?>
                                        <?php $inspectionScheduleLocked = inquiry_center_inspection_schedule_is_locked($latestInspection); ?>
                                        <?php $scheduleReadOnly = $inspectionScheduleLocked; ?>
                                        <?php
                                            $scheduleSubmitLabel = $latestInspection
                                                ? 'Update Schedule'
                                                : 'Confirm Schedule & Notify';
                                            $showScheduleEditor = $latestInspection
                                                && !$scheduleReadOnly
                                                && empty($inquiry['archived_at']);
                                        ?>
                                        <?php if ($showScheduleEditor): ?>
                                            <div class="inquiry-reschedule-modal" data-inspection-schedule-editor hidden>
                                                <div class="inquiry-reschedule-modal__panel" role="dialog" aria-modal="true" aria-labelledby="rescheduleTitle<?php echo (int)$inquiry['id']; ?>">
                                                    <div class="inquiry-reschedule-modal__head">
                                                        <div>
                                                            <h3 id="rescheduleTitle<?php echo (int)$inquiry['id']; ?>">Reschedule Inspection</h3>
                                                            <p>Confirm the new schedule with the Client and Engineer first.</p>
                                                        </div>
                                                        <button type="button" class="inquiry-modal__close" data-reschedule-inspection-close aria-label="Close reschedule form">&times;</button>
                                                    </div>
                                        <?php endif; ?>
                                        <?php $initialInspectionDate = $latestInspection ? substr((string)($latestInspection['scheduled_at'] ?? ''), 0, 10) : (string)($inquiry['preferred_inspection_date'] ?? ''); ?>
                                        <form method="POST" class="inquiry-schedule-form" data-inquiry-inspection-form data-schedule-confirmed="<?php echo $isScheduleConfirmed ? '1' : '0'; ?>" data-has-existing-schedule="<?php echo $latestInspection ? '1' : '0'; ?>" data-original-engineer="<?php echo (int)($latestInspection['engineer_id'] ?? 0); ?>" data-original-date="<?php echo htmlspecialchars($latestInspection ? substr((string)($latestInspection['scheduled_at'] ?? ''), 0, 10) : '', ENT_QUOTES, 'UTF-8'); ?>" <?php echo ((!$latestInspection && !$canScheduleInspection) || $scheduleReadOnly || !empty($inquiry['archived_at'])) ? 'hidden' : ''; ?>>
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="schedule_inspection">
                                            <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry['id']; ?>">
                                            <?php if (!$showScheduleEditor): ?>
                                                <strong>Inspection Schedule</strong>
                                            <?php endif; ?>
                                            <div class="inquiry-schedule-context">
                                                <?php if (!empty($inquiry['preferred_inspection_date'])): ?>
                                                    <span><b>Client Tentative Date:</b> <?php echo htmlspecialchars(inquiry_center_format_date($inquiry['preferred_inspection_date']), ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php endif; ?>
                                                <span>Set only after the Client and Engineer agree.</span>
                                            </div>
                                            <div class="inquiry-schedule-grid">
                                                <label>
                                                    <span>Confirmed Engineer</span>
                                                    <select name="engineer_id" required>
                                                        <option value="" disabled <?php echo !$latestInspection ? 'selected' : ''; ?>>Select engineer</option>
                                                        <?php foreach ($engineers as $engineer): ?>
                                                            <option value="<?php echo (int)$engineer['id']; ?>" <?php echo (int)($latestInspection['engineer_id'] ?? 0) === (int)$engineer['id'] ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars((string)$engineer['full_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </label>
                                                <label class="admin-date-field">
                                                    <span>Confirmed Inspection Date</span>
                                                    <span class="admin-date-input-wrap">
                                                        <input type="date" class="js-admin-inspection-date" name="inspection_date" value="<?php echo htmlspecialchars($initialInspectionDate, ENT_QUOTES, 'UTF-8'); ?>" min="<?php echo site_inspection_earliest_schedule_date(); ?>" max="<?php echo site_inspection_latest_schedule_date(); ?>" required aria-describedby="adminInspectionDateHelp<?php echo (int)$inquiry['id']; ?>">
                                                        <button class="admin-date-picker-button js-admin-date-picker-button" type="button" aria-label="Open inspection calendar">&#128197;</button>
                                                    </span>
                                                    <small class="inquiry-schedule-field-feedback" data-admin-schedule-conflict-feedback hidden aria-live="polite"></small>
                                                    <small class="inquiry-schedule-date-notice" data-admin-date-cutoff-feedback hidden aria-live="polite"></small>
                                                    <small id="adminInspectionDateHelp<?php echo (int)$inquiry['id']; ?>" class="inquiry-schedule-window-note">
                                                        Today before 5:00 PM. Tomorrow from 5:00 PM. Up to 30 days.
                                                    </small>
                                                </label>
                                            </div>
                                            <textarea name="site_notes" hidden><?php echo htmlspecialchars((string)($latestInspection['site_notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                                            <?php if ($latestInspection): ?>
                                                <small class="inquiry-schedule-guidance" data-admin-reschedule-guidance aria-live="polite" hidden></small>
                                            <?php endif; ?>
                                            <div class="inquiry-review-actions">
                                                <?php if ($showScheduleEditor): ?>
                                                    <button type="button" class="btn-secondary" data-reschedule-inspection-close>Cancel</button>
                                                <?php endif; ?>
                                                <button type="submit" class="btn-primary" data-schedule-submit disabled><?php echo $scheduleSubmitLabel; ?></button>
                                            </div>
                                            <small class="inquiry-schedule-field-feedback inquiry-schedule-submit-feedback" data-admin-schedule-feedback hidden aria-live="polite"></small>
                                        </form>
                                        <?php if ($showScheduleEditor): ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </section>

                                <?php
                                    // Engineer ang may hawak ng quotation at costing workspace.
                                    $showAdminQuotationWorkspace = false;
                                ?>
                                <?php if ($showAdminQuotationWorkspace): ?>
                                <section class="inquiry-tab-panel" data-inquiry-panel="quotation" hidden>
                                    <div class="inquiry-quotation-layout">
                                    <?php if ($quotationPrerequisiteMessage !== ''): ?>
                                        <div
                                            class="inquiry-prerequisite-banner"
                                            data-prerequisite-check="client-quotation-approval"
                                            data-prerequisite-message="<?php echo htmlspecialchars($quotationPrerequisiteMessage, ENT_QUOTES, 'UTF-8'); ?>"
                                        >
                                            <strong><?php echo htmlspecialchars($quotationPrerequisiteMessage, ENT_QUOTES, 'UTF-8'); ?></strong>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($quotationDraft): ?>
                                        <?php $quotationStatus = inquiry_quote_normalize_status((string)$quotationDraft['status']); ?>
                                        <?php $isRevisionRequested = in_array($quotationStatus, ['revision_requested', 'for_revision'], true); ?>
                                        <?php $isRejectedQuotation = $quotationStatus === 'rejected'; ?>
                                        <?php
                                            $isFinalClientAccepted = $postInspectionDecision
                                                && (string)($postInspectionDecision['decision'] ?? '') === 'create_revised_quotation'
                                                && (int)($postInspectionDecision['revised_quotation_draft_id'] ?? 0) === (int)$quotationDraft['id']
                                                && (int)($postInspectionDecision['final_quotation_draft_id'] ?? 0) === (int)$quotationDraft['id']
                                                && $quotationStatus === 'accepted'
                                                && !empty($quotationDraft['client_decision_at']);
                                            $quotationStageLabel = $isFinalClientAccepted
                                                ? 'Final Quotation'
                                                : ($isInitialQuotationDraft ? 'Initial Quotation' : 'Revised Quotation');
                                            $quotationDisplayStatus = match ($quotationStatus) {
                                                'draft' => 'Draft',
                                                'sent' => 'Awaiting Client Approval',
                                                'accepted' => 'Approved',
                                                default => inquiry_center_quotation_status_label((string)$quotationDraft['status']),
                                            };
                                            $quotationStatusClass = 'status-draft';
                                            if ($quotationStatus === 'sent') {
                                                $quotationStatusClass = 'status-sent';
                                            } elseif ($isRevisionRequested) {
                                                $quotationStatusClass = 'status-revision';
                                            } elseif (in_array($quotationStatus, ['accepted', 'approved'], true)) {
                                                $quotationStatusClass = 'status-accepted';
                                            } elseif ($quotationStatus === 'rejected') {
                                                $quotationStatusClass = 'status-rejected';
                                            }
                                        ?>
                                        <div class="inquiry-quote-draft">
                                            <div>
                                                <span><?php echo htmlspecialchars($quotationStageLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                                <strong><?php echo htmlspecialchars((string)$quotationDraft['quotation_no'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            </div>
                                            <div>
                                                <span>Status</span>
                                                <strong class="status-badge <?php echo $quotationStatusClass; ?>" data-quotation-status-label><?php echo htmlspecialchars($quotationDisplayStatus, ENT_QUOTES, 'UTF-8'); ?></strong>
                                            </div>
                                            <div class="inquiry-quote-draft__action">
                                                <?php if (empty($inquiry['archived_at']) && inquiry_quote_normalize_status((string)$quotationDraft['status']) === 'draft'): ?>
                                                    <a class="inquiry-quote-edit-link" href="/codesamplecaps/ADMIN/sidebar/inquiries/php/create_quotation.php?edit_id=<?php echo (int)$quotationDraft['id']; ?>">
                                                        Edit Details
                                                    </a>
                                                <?php endif; ?>
                                                <?php
                                                    $quotationPdfParams = ['id' => (int)$quotationDraft['id']];
                                                    if ($search !== '') {
                                                        $quotationPdfParams['return_search'] = $search;
                                                    }
                                                    if ($statusFilter !== '') {
                                                        $quotationPdfParams['return_status'] = $statusFilter;
                                                    }
                                                    if ($view !== 'active') {
                                                        $quotationPdfParams['return_view'] = $view;
                                                    }
                                                    if ($quotationFilter !== '') {
                                                        $quotationPdfParams['return_quotation_filter'] = $quotationFilter;
                                                    }
                                                    $quotationPdfUrl = '/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiry_quotation_pdf.php?' . http_build_query($quotationPdfParams);
                                                ?>
                                                <a class="inquiry-quote-pdf-link" href="<?php echo htmlspecialchars($quotationPdfUrl, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <?php echo $isFinalClientAccepted ? 'View / Print Final Quotation' : 'Preview Quotation'; ?>
                                                </a>
                                            </div>
                                        </div>
                                        <?php
                                            $isInspectionReportApproved = $latestInspection
                                                && (string)($latestInspection['admin_review_status'] ?? '') === 'Approved';
                                            $initialQuoteStatus = inquiry_quote_normalize_status((string)($originalQuotation['status'] ?? ''));
                                            $canChoosePostInspectionDecision = $isInspectionReportApproved
                                                && $originalQuotation
                                                && $initialQuoteStatus === 'accepted';
                                            $initialQuotationTotal = (float)($originalQuotation['grand_total'] ?? 0);
                                            $inspectionVariance = round($latestCostTotal - $initialQuotationTotal, 2);
                                            $finalQuotationId = (int)($postInspectionDecision['final_quotation_draft_id'] ?? 0);
                                        ?>
                                        <?php if ($canChoosePostInspectionDecision && !$postInspectionDecision): ?>
                                            <section class="post-inspection-quotation-decision">
                                                <div>
                                                    <span>Post-Inspection Quotation Decision</span>
                                                    <strong>Choose the final commercial basis</strong>
                                                </div>
                                                <dl class="post-inspection-quotation-decision__totals">
                                                    <div><dt>Initial Quotation Total</dt><dd><?php echo htmlspecialchars(inquiry_quote_format_money($initialQuotationTotal), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                                                    <div><dt>Approved Inspection Costing Total</dt><dd><?php echo htmlspecialchars(inquiry_quote_format_money($latestCostTotal), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                                                    <div class="post-inspection-quotation-decision__variance"><dt>Variance / Difference</dt><dd><?php echo htmlspecialchars(inquiry_quote_format_money($inspectionVariance), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                                                </dl>
                                                <form method="POST" class="post-inspection-quotation-decision__proceed-form">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="action" value="proceed_without_quotation_revision">
                                                    <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry['id']; ?>">
                                                    <input type="hidden" name="inspection_id" value="<?php echo (int)$latestInspection['id']; ?>">
                                                    <input type="hidden" name="draft_id" value="<?php echo (int)$originalQuotation['id']; ?>">
                                                    <label>
                                                        <span>Admin Remarks <?php echo abs($inspectionVariance) > 0.004 ? '(required because totals differ)' : '(optional)'; ?></span>
                                                        <textarea name="admin_remarks" rows="2" <?php echo abs($inspectionVariance) > 0.004 ? 'required' : ''; ?> placeholder="Reason for keeping the original quotation"></textarea>
                                                    </label>
                                                    <button type="submit" class="btn-secondary">Proceed Without Revision</button>
                                                </form>
                                                <form method="POST" class="post-inspection-quotation-decision__revision-form">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="action" value="create_post_inspection_quotation_revision">
                                                    <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry['id']; ?>">
                                                    <input type="hidden" name="inspection_id" value="<?php echo (int)$latestInspection['id']; ?>">
                                                    <input type="hidden" name="draft_id" value="<?php echo (int)$originalQuotation['id']; ?>">
                                                    <button type="submit" class="btn-primary">Create Revised Quotation</button>
                                                </form>
                                            </section>
                                        <?php elseif ($postInspectionDecision): ?>
                                            <div class="post-inspection-quotation-decision post-inspection-quotation-decision--saved">
                                                <strong><?php echo $postInspectionDecision['decision'] === 'proceed_without_revision' ? 'Original accepted quotation kept as final.' : 'Revised quotation is required before project creation.'; ?></strong>
                                                <span>Inspection costing: <?php echo htmlspecialchars(inquiry_quote_format_money((float)$postInspectionDecision['inspection_costing_total']), ENT_QUOTES, 'UTF-8'); ?> | Variance: <?php echo htmlspecialchars(inquiry_quote_format_money((float)$postInspectionDecision['variance_amount']), ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php if ($finalQuotationId === (int)$quotationDraft['id'] && $quotationStatus === 'accepted' && empty($quotationDraft['project_id'])): ?>
                                                    <form method="POST" class="post-inspection-quotation-decision__project-form">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="action" value="prepare_project_from_quote">
                                                        <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry['id']; ?>">
                                                        <input type="hidden" name="draft_id" value="<?php echo (int)$quotationDraft['id']; ?>">
                                                        <button type="submit" class="btn-primary">Continue to Project Setup</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php $quotationRecipient = null; ?>
                                        <?php if (in_array($quotationStatus, ['draft', 'approved', 'accepted'], true)): ?>
                                            <?php try { $quotationRecipient = inquiry_quote_resolve_recipient($conn, (int)$quotationDraft['id']); } catch (Throwable $throwable) { $quotationRecipient = null; } ?>
                                        <?php endif; ?>
                                        <div class="inquiry-quote-revision-alert status-revision" data-quotation-revision-alert <?php echo !$isRevisionRequested ? 'hidden' : ''; ?>>
                                            <strong>Client Revision Request</strong>
                                            <p data-quotation-revision-note><?php echo nl2br(htmlspecialchars((string)($quotationDraft['client_decision_note'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></p>
                                        </div>
                                        <form method="POST" class="inquiry-quote-revision-form" data-quotation-revision-action <?php echo !$isRevisionRequested || !empty($quotationDraft['project_id']) ? 'hidden' : ''; ?>>
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="reopen_quotation_revision">
                                            <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry['id']; ?>">
                                            <input type="hidden" name="draft_id" value="<?php echo (int)$quotationDraft['id']; ?>">
                                            <button type="submit" class="btn-secondary">Edit Quotation</button>
                                        </form>
                                        <div class="inquiry-quote-rejection-alert" data-quotation-rejection-alert <?php echo !$isRejectedQuotation ? 'hidden' : ''; ?>>
                                            <strong>Quotation Rejected by Client</strong>
                                            <p>Client Note: <span data-quotation-rejection-note><?php echo htmlspecialchars((string)($quotationDraft['client_decision_note'] ?: 'No note provided.'), ENT_QUOTES, 'UTF-8'); ?></span></p>
                                        </div>
                                        <?php if (!$isRevisionRequested && !$isRejectedQuotation && !empty($quotationDraft['client_decision_note'])): ?>
                                            <div class="inquiry-detail inquiry-detail--wide">
                                                <span>Client Note</span>
                                                <strong><?php echo nl2br(htmlspecialchars((string)$quotationDraft['client_decision_note'], ENT_QUOTES, 'UTF-8')); ?></strong>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (in_array($quotationStatus, ['draft', 'approved'], true) && empty($quotationDraft['project_id'])): ?>
                                            <?php if (!$quotationRecipient || empty($quotationRecipient['email'])): ?>
                                                <div class="inquiry-detail inquiry-detail--wide">
                                                    <span><?php echo $isInitialQuotationDraft ? 'Send Initial Quotation' : 'Send Revised Quotation'; ?></span>
                                                    <strong>Recipient email is missing. Update the inquiry or client account first.</strong>
                                                </div>
                                            <?php else: ?>
                                            <form
                                                method="POST"
                                                class="inquiry-quote-send-form"
                                                data-quote-recipient-name="<?php echo htmlspecialchars((string)$quotationRecipient['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-quote-recipient-email="<?php echo htmlspecialchars((string)$quotationRecipient['email'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-quote-recipient-contact="<?php echo htmlspecialchars((string)$quotationRecipient['contact'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-quote-recipient-source="<?php echo htmlspecialchars((string)$quotationRecipient['source_label'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-quote-kind="<?php echo $isInitialQuotationDraft ? 'initial' : 'revised'; ?>"
                                                data-quotation-item-count="<?php echo $isInitialQuotationDraft ? count(inquiry_quote_fetch_items($conn, (int)$quotationDraft['id'])) : 0; ?>"
                                            >
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="send_quotation_to_client">
                                                <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry['id']; ?>">
                                                <input type="hidden" name="draft_id" value="<?php echo (int)$quotationDraft['id']; ?>">
                                                <button type="submit" class="btn-primary inquiry-quote-send-button"><?php echo $isInitialQuotationDraft ? 'Send Initial Quotation to Client' : 'Send Revised Quotation to Client'; ?></button>
                                            </form>
                                            <?php endif; ?>
                                        <?php elseif (!empty($quotationDraft['project_id'])): ?>
                                            <div class="inquiry-created-project">
                                                <span>Project Created</span>
                                                <a href="/codesamplecaps/ADMIN/sidebar/projects/php/project_details.php?id=<?php echo (int)$quotationDraft['project_id']; ?>">
                                                    Open Project
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    <?php elseif ($costingReview && !empty($latestCostItems)): ?>
                                        <form method="POST" class="inquiry-quote-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="create_quotation_draft">
                                            <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry['id']; ?>">
                                            <input type="hidden" name="inspection_id" value="<?php echo (int)$costingReview['id']; ?>">
                                            <label>
                                                <span>Profit Margin (%)</span>
                                                <input type="number" name="profit_margin_percent" min="0" max="100" step="0.01" value="15" required>
                                            </label>
                                            <button type="submit" class="btn-primary">Generate Quotation Draft</button>
                                        </form>
                                    <?php elseif ($currentStatus === 'Verified Lead' && empty($inquiry['archived_at'])): ?>
                                        <a class="btn-primary inquiry-modal__primary-action inquiry-quotation-primary-action" href="/codesamplecaps/ADMIN/sidebar/inquiries/php/create_quotation.php?inquiry_id=<?php echo (int)$inquiry['id']; ?>">Create Initial Quotation</a>
                                    <?php else: ?>
                                        <div class="inquiry-empty">Quotation is not available for this inquiry.</div>
                                    <?php endif; ?>
                                    </div>
                                </section>
                                <?php endif; ?>

                            </div>
                            </div>
                        </div>
                        <?php if (empty($inquiry['archived_at'])): ?>
                            <div class="inquiry-archive-modal" id="archiveModal<?php echo (int)$inquiry['id']; ?>" hidden>
                                <div class="inquiry-archive-modal__panel" role="dialog" aria-modal="true" aria-labelledby="archiveModalTitle<?php echo (int)$inquiry['id']; ?>">
                                    <div class="inquiry-archive-modal__head">
                                        <div>
                                            <span class="reports-kicker">Archive Inquiry</span>
                                            <h2 id="archiveModalTitle<?php echo (int)$inquiry['id']; ?>"><?php echo htmlspecialchars((string)$inquiry['client_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                                        </div>
                                        <button type="button" class="inquiry-modal__close" data-archive-modal-close aria-label="Close archive form">&times;</button>
                                    </div>
                                    <form method="POST" class="inquiry-archive-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="action" value="archive_inquiry">
                                        <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry['id']; ?>">
                                        <input type="hidden" name="return_url" value="<?php echo htmlspecialchars((string)($_SERVER['REQUEST_URI'] ?? '/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php'), ENT_QUOTES, 'UTF-8'); ?>">
                                        <label>
                                            <span>Archive Reason</span>
                                            <select name="archive_reason" required>
                                                <option value="" selected disabled>Select reason</option>
                                                <option value="Duplicate inquiry">Duplicate inquiry</option>
                                                <option value="Client backed out">Client backed out</option>
                                                <option value="Not qualified">Not qualified</option>
                                                <option value="No response from client">No response from client</option>
                                                <option value="Service not offered">Service not offered</option>
                                                <option value="Invalid contact details">Invalid contact details</option>
                                                <option value="Other">Other reason</option>
                                            </select>
                                        </label>
                                        <label data-archive-manual-reason>
                                            <span data-archive-manual-label>Additional Notes (Optional) <b class="archive-other-required" data-archive-other-required hidden>*</b></span>
                                            <textarea name="archive_reason_other" rows="2" placeholder="Add custom reason or extra note"></textarea>
                                        </label>
                                        <div class="inquiry-review-actions">
                                            <button type="button" class="btn-secondary" data-archive-modal-close>Cancel</button>
                                            <button type="submit" class="btn-secondary inquiry-archive-button">Archive Inquiry</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php include __DIR__ . '/../../../layout/footer.php'; ?>
