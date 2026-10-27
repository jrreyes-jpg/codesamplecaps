<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
auth_start_session();
$requestedInspectionId = filter_var($_GET['inspection_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!auth_session_is_valid_for_roles(['engineer'])) {
    $loginQuery = ['return_to' => 'engineer_inspection'];
    if ($requestedInspectionId !== false) {
        $loginQuery['inspection_id'] = (int)$requestedInspectionId;
    }
    auth_redirect_to_login($loginQuery);
}

define('AUTH_REQUIRED_ROLE', 'engineer');
require_once __DIR__ . '/../../config/auth_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/site_inspections.php';
require_once __DIR__ . '/../../config/inquiry_quotation_module.php';
require_once __DIR__ . '/../../config/audit_log.php';
require_once __DIR__ . '/../../config/user_notifications.php';
require_once __DIR__ . '/../../config/material_stock.php';
require_once __DIR__ . '/../../config/inquiry_budget.php';
require_once __DIR__ . '/../../services/EmailService.php';
require_once __DIR__ . '/../includes/engineer_helpers.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$message = '';
$error = '';

function engineer_inspection_csrf_token(): string
{
    return auth_csrf_token('engineer_site_inspections');
}

function engineer_inspection_valid_csrf(?string $token): bool
{
    return auth_is_valid_csrf($token, 'engineer_site_inspections');
}

function engineer_inspection_schedule_date_is_reached(?string $scheduledAt): bool
{
    if (trim((string)$scheduledAt) === '') {
        return false;
    }

    try {
        $timezone = site_inspection_timezone();
        $scheduledDate = new DateTimeImmutable((string)$scheduledAt, $timezone);
        $today = new DateTimeImmutable('today', $timezone);
        return $scheduledDate->format('Y-m-d') <= $today->format('Y-m-d');
    } catch (Throwable $exception) {
        return false;
    }
}

function engineer_format_money(float $amount): string
{
    return 'PHP ' . number_format($amount, 2);
}

function engineer_inspection_is_whole_count_unit(string $unit): bool
{
    return in_array($unit, ['unit', 'pc', 'pcs', 'roll', 'box', 'pack', 'set', 'lot', 'person', 'bundle', 'sheet', 'pair', 'tube', 'trip'], true);
}

function engineer_inspection_valid_quantity(string $value, string $unit): bool
{
    if (engineer_inspection_is_whole_count_unit($unit)) {
        return preg_match('/^[1-9]\d*$/', $value) === 1;
    }

    return preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/', $value) === 1 && (float)$value > 0;
}

function engineer_inspection_valid_unit_cost(string $value): bool
{
    return preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/', $value) === 1 && (float)$value > 0;
}

function engineer_inspection_has_meaningful_findings(string $value): bool
{
    $value = trim($value);
    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    return $length >= 10 && $length <= 1000 && preg_match('/\p{L}/u', $value) === 1;
}

function engineer_inspection_saved_report_error(mysqli $conn, int $inspectionId, string $findings): string
{
    if (!engineer_inspection_has_meaningful_findings($findings)) {
        return 'Save Engineer Findings before completing the inspection.';
    }

    $stmt = $conn->prepare(
        "SELECT
            COUNT(*) AS item_count,
            COALESCE(SUM(line_total), 0) AS total_cost
         FROM site_inspection_cost_items
         WHERE inspection_id = ?"
    );
    if (!$stmt) {
        return 'Unable to check the saved inspection costing.';
    }

    $stmt->bind_param('i', $inspectionId);
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    if ((int)($summary['item_count'] ?? 0) < 1) {
        return 'Save at least one cost item before completing the inspection.';
    }
    if ((float)($summary['total_cost'] ?? 0) <= 0) {
        return 'Save a valid total costing before completing the inspection.';
    }

    return '';
}

function engineer_notify_admin_report_submitted(mysqli $conn, int $inspectionId, string $engineerName): void
{
    $noticeStmt = $conn->prepare(
        "SELECT si.inquiry_id, si.created_by, si.scheduled_at, si.submitted_at,
                s.client_name, s.service_category, u.full_name AS admin_name, u.email AS admin_email
         FROM site_inspections si
         INNER JOIN service_inquiries s ON s.id = si.inquiry_id
         INNER JOIN users u ON u.id = si.created_by
         WHERE si.id = ? AND u.role IN ('admin', 'super_admin') AND u.status = 'active'
         LIMIT 1"
    );
    if (!$noticeStmt) {
        return;
    }

    $noticeStmt->bind_param('i', $inspectionId);
    $noticeStmt->execute();
    $notice = $noticeStmt->get_result()->fetch_assoc() ?: [];
    $noticeStmt->close();
    if (!$notice) {
        return;
    }

    $inquiryId = (int)($notice['inquiry_id'] ?? 0);
    $adminId = (int)($notice['created_by'] ?? 0);
    $clientName = trim((string)($notice['client_name'] ?? 'Client'));
    $service = trim((string)($notice['service_category'] ?? 'Service request'));
    $reportPath = '/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?open=inquiryModal'
        . $inquiryId . '&tab=inspection';
    $reportUrl = Config::getInstance()->get('APP_URL') . $reportPath;

    user_notifications_create_if_missing(
        $conn,
        $adminId,
        'engineer_inspection_report_submitted',
        $inspectionId,
        'Inspection Costing Submitted',
        ($engineerName !== '' ? $engineerName : 'Engineer') . ' submitted the inspection costing for ' . ($clientName !== '' ? $clientName : 'Client') . '.',
        $reportPath,
        'engineer_inspection_report_submitted:' . $inspectionId . ':' . (string)($notice['submitted_at'] ?? '')
    );

    $adminEmail = trim((string)($notice['admin_email'] ?? ''));
    if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        (new EmailService())->sendInspectionReportAdminNotification(
            $adminEmail,
            trim((string)($notice['admin_name'] ?? 'Admin')),
            $engineerName,
            $clientName,
            $service,
            site_inspection_format_date($notice['scheduled_at'] ?? null),
            $reportUrl
        );
    }
}

function engineer_inspection_fetch_active_material(mysqli $conn, int $materialId): ?array
{
    $stmt = $conn->prepare('SELECT material_name, unit FROM materials WHERE id = ? AND status = \'active\' LIMIT 1');
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $materialId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function engineer_inspection_asset_requirements_table_exists(mysqli $conn): bool
{
    $result = $conn->query("SHOW TABLES LIKE 'site_inspection_asset_requirements'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function engineer_inspection_fetch_active_asset(mysqli $conn, int $assetId): ?array
{
    $stmt = $conn->prepare(
        "SELECT a.id, a.asset_name,
                CASE
                    WHEN COALESCE(unit_totals.total_units, 0) > 0 THEN COALESCE(unit_totals.available_units, 0)
                    ELSE COALESCE(i.quantity, 0)
                END AS available_quantity
         FROM assets a
         INNER JOIN inventory i ON i.asset_id = a.id
         LEFT JOIN (
            SELECT inventory_id,
                   COUNT(*) AS total_units,
                   SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) AS available_units
            FROM asset_units
            WHERE status <> 'archived'
            GROUP BY inventory_id
         ) unit_totals ON unit_totals.inventory_id = i.id
         WHERE a.id = ? AND a.deleted_at IS NULL
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $assetId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function engineer_inspection_draft_is_unchanged(mysqli $conn, int $inspectionId, array $rows, array $assetRequirements, string $findings, string $riskNotes, string $clientRequests): bool
{
    $notesStmt = $conn->prepare('SELECT engineer_findings, risk_notes, client_requests FROM site_inspections WHERE id = ? LIMIT 1');
    $itemsStmt = $conn->prepare('SELECT item_type, inventory_id, material_id, item_name, quantity, unit, unit_cost, notes FROM site_inspection_cost_items WHERE inspection_id = ? ORDER BY id ASC');
    if (!$notesStmt || !$itemsStmt) {
        return false;
    }

    $notesStmt->bind_param('i', $inspectionId);
    $notesStmt->execute();
    $savedNotes = $notesStmt->get_result()->fetch_assoc() ?: [];
    if (trim((string)($savedNotes['engineer_findings'] ?? '')) !== $findings
        || trim((string)($savedNotes['risk_notes'] ?? '')) !== $riskNotes
        || trim((string)($savedNotes['client_requests'] ?? '')) !== $clientRequests) {
        return false;
    }

    $itemsStmt->bind_param('i', $inspectionId);
    $itemsStmt->execute();
    $savedRows = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    if (count($savedRows) !== count($rows)) {
        return false;
    }

    foreach ($rows as $index => $row) {
        $saved = $savedRows[$index];
        if ((string)$saved['item_type'] !== $row['item_type']
            || (int)($saved['inventory_id'] ?? 0) !== (int)($row['inventory_id'] ?? 0)
            || (int)($saved['material_id'] ?? 0) !== (int)($row['material_id'] ?? 0)
            || trim((string)$saved['item_name']) !== $row['item_name']
            || trim((string)$saved['unit']) !== $row['unit']
            || trim((string)($saved['notes'] ?? '')) !== $row['notes']
            || abs((float)$saved['quantity'] - (float)$row['quantity']) > 0.000001
            || abs((float)$saved['unit_cost'] - (float)$row['unit_cost']) > 0.000001) {
            return false;
        }
    }

    $requirementsStmt = $conn->prepare(
        'SELECT asset_id, quantity_required, notes
         FROM site_inspection_asset_requirements
         WHERE inspection_id = ?
         ORDER BY id ASC'
    );
    if (!$requirementsStmt) {
        return false;
    }

    $requirementsStmt->bind_param('i', $inspectionId);
    $requirementsStmt->execute();
    $savedRequirements = $requirementsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    if (count($savedRequirements) !== count($assetRequirements)) {
        return false;
    }

    foreach ($assetRequirements as $index => $requirement) {
        $savedRequirement = $savedRequirements[$index];
        if ((int)$savedRequirement['asset_id'] !== (int)$requirement['asset_id']
            || (int)$savedRequirement['quantity_required'] !== (int)$requirement['quantity_required']
            || trim((string)($savedRequirement['notes'] ?? '')) !== $requirement['notes']) {
            return false;
        }
    }

    return true;
}

function engineer_inspection_complete_site_address(array $inspection): string
{
    $parts = [];
    $seen = [];

    foreach (['site_address', 'barangay', 'city_municipality', 'province'] as $field) {
        $value = trim((string)($inspection[$field] ?? ''));
        $key = mb_strtolower($value, 'UTF-8');
        if ($value !== '' && !isset($seen[$key])) {
            $parts[] = $value;
            $seen[$key] = true;
        }
    }

    return implode(', ', $parts);
}

function engineer_owns_inspection(mysqli $conn, int $inspectionId, int $engineerId): bool
{
    $stmt = $conn->prepare('SELECT id FROM site_inspections WHERE id = ? AND engineer_id = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ii', $inspectionId, $engineerId);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_assoc();
}

function engineer_get_inspection_state(mysqli $conn, int $inspectionId, int $engineerId): array
{
    $holdSelect = site_inspection_hold_columns_ready($conn)
        ? 'hold_reason, held_at, resumed_at,'
        : 'NULL AS hold_reason, NULL AS held_at, NULL AS resumed_at,';
    $stmt = $conn->prepare(
        "SELECT status, admin_review_status, admin_remarks, engineer_findings, scheduled_at, created_at, acknowledged_at, started_at, {$holdSelect} completed_at, submitted_at, client_schedule_response, engineer_schedule_response
         FROM site_inspections
         WHERE id = ? AND engineer_id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('ii', $inspectionId, $engineerId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: [];
}

$isAjaxRequest = $_SERVER['REQUEST_METHOD'] === 'POST'
    && strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

if ($isAjaxRequest) {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED & ~E_NOTICE & ~E_USER_NOTICE & ~E_WARNING & ~E_USER_WARNING);
    ini_set('display_errors', '0');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inspectionId = (int)($_POST['inspection_id'] ?? 0);
    $costingAction = (string)($_POST['costing_action'] ?? 'save_draft');
    $workflowAction = (string)($_POST['workflow_action'] ?? '');
    $scheduleResponseAction = (string)($_POST['schedule_response_action'] ?? '');
    $workflowTargets = [
        'start' => 'Ongoing',
        'complete' => 'Completed',
    ];
    $inspectionState = $inspectionId > 0
        ? engineer_get_inspection_state($conn, $inspectionId, $userId)
        : [];
    $currentStatus = (string)($inspectionState['status'] ?? '');
    $currentReviewStatus = (string)($inspectionState['admin_review_status'] ?? 'Pending');

    if (!engineer_inspection_valid_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid request. Please try again.';
    } elseif ($inspectionId <= 0 || !engineer_owns_inspection($conn, $inspectionId, $userId)) {
        $error = 'Inspection not found.';
    } elseif ($scheduleResponseAction === 'request_reschedule') {
        $reason = trim((string)($_POST['schedule_response_note'] ?? ''));
        $preferredDate = trim((string)($_POST['schedule_preferred_date'] ?? ''));
        $preferredAt = $preferredDate !== '' ? $preferredDate . ' 00:00:00' : null;
        $reasonWithoutWhitespace = preg_replace('/\s+/u', '', $reason) ?? '';
        if (in_array($currentStatus, ['Ongoing', 'On Hold', 'Completed', 'Submitted'], true) || mb_strlen($reasonWithoutWhitespace, 'UTF-8') < 5) {
            $error = 'Enter a reschedule reason with at least 5 characters before inspection starts.';
        } elseif ($preferredDate === '') {
            $error = 'Choose a preferred new date.';
        } else {
            $scheduleValidation = site_inspection_validate_date($preferredDate);
            if (!$scheduleValidation['valid']) {
                $error = (string)$scheduleValidation['message'];
            }
        }
        if (empty($error)) {
            $preferredDt = $scheduleValidation['datetime'];
            $conn->begin_transaction();
            $transactionCommitted = false;
            try {
                $lock = $conn->prepare(
                    'SELECT status, client_schedule_response, engineer_schedule_response
                     FROM site_inspections WHERE id = ? AND engineer_id = ? LIMIT 1 FOR UPDATE'
                );
                if (!$lock) throw new RuntimeException('Unable to check the inspection schedule.');
                $lock->bind_param('ii', $inspectionId, $userId);
                $lock->execute();
                $currentSchedule = $lock->get_result()->fetch_assoc();
                if (!$currentSchedule
                    || !in_array((string)$currentSchedule['status'], ['Assigned', 'Acknowledged'], true)
                    || (string)$currentSchedule['engineer_schedule_response'] !== 'pending'
                    || (string)$currentSchedule['client_schedule_response'] === 'reschedule_requested') {
                    throw new RuntimeException('This schedule response is already recorded or is waiting for Admin review.');
                }
                if (site_inspection_engineer_has_schedule_on_date($conn, $userId, $preferredDt, $inspectionId, true)) {
                    throw new RuntimeException('You already have an inspection on this date. Choose another date.');
                }

                $update = $conn->prepare("UPDATE site_inspections SET engineer_schedule_response = 'reschedule_requested', engineer_schedule_response_note = ?, engineer_schedule_preferred_at = ?, engineer_schedule_responded_at = NOW(), status = 'Assigned', acknowledged_at = NULL WHERE id = ? AND engineer_id = ? AND engineer_schedule_response = 'pending' AND client_schedule_response <> 'reschedule_requested'");
                if (!$update) throw new RuntimeException('Unable to send the reschedule request.');
                $update->bind_param('ssii', $reason, $preferredAt, $inspectionId, $userId);
                $update->execute();
                if ($update->affected_rows !== 1) {
                    throw new RuntimeException('This schedule response was already updated. Please refresh the page.');
                }
                $conn->commit();
                $transactionCommitted = true;
            } catch (Throwable $exception) {
                if (!$transactionCommitted) {
                    $conn->rollback();
                }
                $safeMessages = [
                    'This schedule response is already recorded or is waiting for Admin review.',
                    'You already have an inspection on this date. Choose another date.',
                    'This schedule response was already updated. Please refresh the page.',
                ];
                $error = in_array($exception->getMessage(), $safeMessages, true)
                    ? $exception->getMessage()
                    : 'Unable to send the reschedule request. Please try again.';
            }
            if ($transactionCommitted) {
                $noticeStmt = $conn->prepare('SELECT si.created_by, si.inquiry_id, si.scheduled_at, s.client_name FROM site_inspections si JOIN service_inquiries s ON s.id = si.inquiry_id WHERE si.id = ? LIMIT 1');
                if ($noticeStmt) {
                    $noticeStmt->bind_param('i', $inspectionId);
                    $noticeStmt->execute();
                    $notice = $noticeStmt->get_result()->fetch_assoc() ?: [];
                    user_notifications_create_if_missing($conn, (int)($notice['created_by'] ?? 0), 'engineer_inspection_reschedule_requested', $inspectionId, 'Engineer Requested Inspection Reschedule', 'Engineer requested a schedule change. Reason: ' . mb_strimwidth($reason, 0, 90, '…'), '/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?open=inquiryModal' . (int)($notice['inquiry_id'] ?? 0) . '&tab=inspection', 'engineer_inspection_reschedule:' . $inspectionId . ':' . hash('sha256', (string)($notice['scheduled_at'] ?? '') . $reason));
                }
                $message = 'Reschedule request sent to Admin.';
            }
        }
    } elseif (in_array($workflowAction, ['hold', 'resume'], true)) {
        $holdReason = trim((string)($_POST['hold_reason'] ?? ''));
        $meaningfulHoldReason = preg_replace('/\s+/u', '', $holdReason) ?? '';

        if (!engineer_inspection_schedule_date_is_reached($inspectionState['scheduled_at'] ?? null)) {
            $error = 'This action is available on the scheduled inspection date.';
        } elseif (!site_inspection_hold_columns_ready($conn)) {
            $error = 'On Hold setup is not ready. Please apply the site inspection hold migration first.';
        } elseif (!site_inspection_can_hold($currentStatus, $workflowAction)) {
            $error = 'This inspection cannot be updated right now. Please refresh the page.';
        } elseif ($workflowAction === 'hold' && ($meaningfulHoldReason === '' || mb_strlen($holdReason, 'UTF-8') > 2000)) {
            $error = 'Enter a valid hold reason before putting the inspection on hold.';
        } elseif (site_inspection_set_hold_state($conn, $inspectionId, $userId, $workflowAction, $holdReason)) {
            audit_log_event(
                $conn,
                $userId,
                $workflowAction === 'hold' ? 'hold_site_inspection' : 'resume_site_inspection',
                'site_inspection',
                $inspectionId,
                ['status' => $currentStatus],
                $workflowAction === 'hold'
                    ? ['status' => 'On Hold', 'hold_reason' => $holdReason]
                    : ['status' => 'Ongoing']
            );
            $message = $workflowAction === 'hold'
                ? 'Inspection put on hold.'
                : 'Inspection resumed.';
        } else {
            $error = 'Inspection status was not changed. Please refresh the page.';
        }

        if ($isAjaxRequest) {
            $freshInspectionState = engineer_get_inspection_state($conn, $inspectionId, $userId);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'success' => $error === '',
                'message' => $error !== '' ? $error : $message,
                'status' => $error === '' ? (string)($freshInspectionState['status'] ?? '') : 'error',
                'inspection_id' => $inspectionId,
            ]);
            exit;
        }
    } elseif ($workflowAction !== '') {
        $targetStatus = $workflowTargets[$workflowAction] ?? '';
        $savedStatus = $currentStatus;

        if ($workflowAction === 'start' && !engineer_inspection_schedule_date_is_reached($inspectionState['scheduled_at'] ?? null)) {
            $error = 'You can start this inspection on ' . site_inspection_format_date($inspectionState['scheduled_at'] ?? null) . '.';
        } elseif ($targetStatus === '' || !site_inspection_can_transition($currentStatus, $targetStatus)) {
            $error = 'Invalid inspection status change. Please refresh the page.';
        } elseif ($workflowAction === 'start' && (string)($inspectionState['client_schedule_response'] ?? '') === 'reschedule_requested') {
            $error = 'A schedule change has been requested. Wait for Admin to resolve the schedule before starting the inspection.';
        } elseif ($workflowAction === 'start' && (string)($inspectionState['engineer_schedule_response'] ?? '') === 'reschedule_requested') {
            $error = 'A schedule change has been requested. Wait for Admin to resolve the schedule before starting the inspection.';
        } elseif ($workflowAction === 'complete') {
            $savedReportError = engineer_inspection_saved_report_error(
                $conn,
                $inspectionId,
                (string)($inspectionState['engineer_findings'] ?? '')
            );

            if ($savedReportError !== '') {
                $error = $savedReportError;
            } else {
                $conn->begin_transaction();
                try {
                    if (!site_inspection_transition($conn, $inspectionId, $userId, 'Ongoing', 'Completed')
                        || !site_inspection_transition($conn, $inspectionId, $userId, 'Completed', 'Submitted')) {
                        throw new RuntimeException('Unable to finalize the inspection costing.');
                    }

                    $resetReview = $conn->prepare(
                        "UPDATE site_inspections
                         SET admin_review_status = 'Pending'
                         WHERE id = ? AND engineer_id = ? AND status = 'Submitted'"
                    );
                    if (!$resetReview) {
                        throw new RuntimeException('Unable to prepare the Admin review.');
                    }
                    $resetReview->bind_param('ii', $inspectionId, $userId);
                    $resetReview->execute();
                    $resetReview->close();

                    audit_log_event(
                        $conn,
                        $userId,
                        'submit_site_inspection_report',
                        'site_inspection',
                        $inspectionId,
                        ['status' => 'Ongoing', 'admin_review_status' => $currentReviewStatus],
                        ['status' => 'Submitted', 'admin_review_status' => 'Pending']
                    );
                    $conn->commit();
                    try {
                        engineer_notify_admin_report_submitted($conn, $inspectionId, (string)($_SESSION['name'] ?? 'Engineer'));
                    } catch (Throwable $notificationError) {
                        error_log('Inspection report Admin notification failed: ' . $notificationError->getMessage());
                    }
                    $message = 'Inspection costing submitted to Admin for review.';
                    $savedStatus = 'Submitted';
                } catch (Throwable $exception) {
                    $conn->rollback();
                    $error = 'Unable to complete and submit the inspection. Please try again.';
                }
            }
        } elseif (site_inspection_transition($conn, $inspectionId, $userId, $currentStatus, $targetStatus)) {
            $message = match ($targetStatus) {
                'Ongoing' => 'Site inspection started.',
                'Completed' => 'Inspection marked as completed.',
                default => 'Inspection status updated.',
            };
            $savedStatus = $targetStatus;
        } else {
            $error = 'Inspection status was not changed. Please refresh the page.';
        }

        if ($isAjaxRequest) {
            $freshInspectionState = $inspectionId > 0
                ? engineer_get_inspection_state($conn, $inspectionId, $userId)
                : [];
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'success' => $error === '',
                'message' => $error !== '' ? $error : $message,
                'status' => $error === '' ? (string)($freshInspectionState['status'] ?? $targetStatus) : 'error',
                'timestamps' => [
                    'Assigned' => site_inspection_format_datetime($freshInspectionState['created_at'] ?? null),
                    'Ongoing' => site_inspection_format_datetime($freshInspectionState['started_at'] ?? null),
                    'Completed' => site_inspection_format_datetime($freshInspectionState['completed_at'] ?? null),
                    'Submitted' => site_inspection_format_datetime($freshInspectionState['submitted_at'] ?? null),
                ],
                'inspection_id' => $inspectionId,
            ]);
            exit;
        }
    } elseif (!in_array($costingAction, ['save_draft', 'submit_to_admin'], true)) {
        $error = 'Invalid costing action.';
    } elseif ($currentStatus === 'Submitted') {
        $error = 'This inspection was already submitted to Admin.';
    } elseif (!in_array($currentStatus, ['Ongoing', 'Completed'], true)) {
        $error = 'Start the inspection before saving findings or costing.';
    } else {
        $itemTypes = $_POST['item_type'] ?? [];
        $inventoryIds = $_POST['inventory_id'] ?? [];
        $materialIds = $_POST['material_id'] ?? [];
        $itemNames = $_POST['item_name'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $units = $_POST['unit'] ?? [];
        $unitCosts = $_POST['unit_cost'] ?? [];
        $notes = $_POST['notes'] ?? [];
        $directCosts = is_array($_POST['direct_cost'] ?? null) ? $_POST['direct_cost'] : [];
        $assetRequirementIds = $_POST['asset_requirement_asset_id'] ?? [];
        $assetRequirementQuantities = $_POST['asset_requirement_quantity'] ?? [];
        $assetRequirementNotes = $_POST['asset_requirement_notes'] ?? [];
        $engineerFindings = trim((string)($_POST['engineer_findings'] ?? ''));
        $engineerFindingsLength = function_exists('mb_strlen')
            ? mb_strlen($engineerFindings, 'UTF-8')
            : strlen($engineerFindings);
        $riskNotes = trim((string)($_POST['risk_notes'] ?? ''));
        $clientRequests = trim((string)($_POST['client_requests'] ?? ''));
        $rows = [];
        $grandTotal = 0.0;
        $assetRequirements = [];
        $selectedAssetIds = [];

        foreach ($itemNames as $index => $rawName) {
            $itemName = trim((string)$rawName);
            $rawQuantityText = trim((string)($quantities[$index] ?? ''));
            $rawUnitCostText = trim((string)($unitCosts[$index] ?? ''));
            if (preg_match('/^\.\d+$/', $rawQuantityText)) {
                $rawQuantityText = '0' . $rawQuantityText;
            }
            if (preg_match('/^\.\d+$/', $rawUnitCostText)) {
                $rawUnitCostText = '0' . $rawUnitCostText;
            }
            $unit = trim((string)($units[$index] ?? 'unit'));
            $quantity = (float)($quantities[$index] ?? 0);
            $unitCost = (float)($unitCosts[$index] ?? 0);
            $itemType = inquiry_quote_normalize_item_type((string)($itemTypes[$index] ?? ''));
            $inventoryId = (int)($inventoryIds[$index] ?? 0);
            $materialId = (int)($materialIds[$index] ?? 0);
            $itemNotes = trim((string)($notes[$index] ?? ''));

            if ($itemType === '') {
                $error = 'Please select a valid cost type.';
                break;
            }
            if ($itemType !== 'material') {
                $error = 'Detailed costing rows are only for materials.';
                break;
            }

            // Huwag bilangin ang default na blank row bilang draft item.
            if ($itemName === ''
                && $materialId <= 0
                && $rawUnitCostText === ''
                && $itemNotes === ''
                && in_array($rawQuantityText, ['', '1'], true)) {
                continue;
            }

            if (!in_array($unit, ['unit', 'pc', 'pcs', 'roll', 'box', 'pack', 'set', 'lot', 'person', 'bundle', 'sheet', 'pair', 'tube', 'meter', 'kg', 'liter', 'hour', 'day', 'trip'], true)) {
                $error = 'Please select a valid quantity unit.';
                break;
            }

            if (!engineer_inspection_valid_quantity($rawQuantityText, $unit)) {
                $error = engineer_inspection_is_whole_count_unit($unit)
                    ? 'Quantity must be a whole number greater than 0 for ' . $unit . '.'
                    : 'Quantity must be greater than 0 with up to 2 decimal places.';
                break;
            }

            if (!engineer_inspection_valid_unit_cost($rawUnitCostText)) {
                $error = 'Unit cost must be greater than 0.';
                break;
            }

            if ($itemName === '') {
                $error = 'Please complete item name and quantity.';
                break;
            }

            if ($unit === '') {
                $error = 'Please select a quantity unit.';
                break;
            }

            if ($materialId > 0) {
                $linkedMaterial = engineer_inspection_fetch_active_material($conn, $materialId);
                if (!$linkedMaterial) {
                    $error = 'Selected material is no longer available. Please choose again.';
                    break;
                }

                $itemName = trim((string)$linkedMaterial['material_name']);
                $unit = trim((string)$linkedMaterial['unit']);
            }

            $lineTotal = $quantity * $unitCost;
            $grandTotal += $lineTotal;

            $rows[] = [
                'item_type' => $itemType,
                'inventory_id' => $inventoryId > 0 ? $inventoryId : null,
                'material_id' => $materialId > 0 ? $materialId : null,
                'item_name' => $itemName,
                'quantity' => $quantity,
                'unit' => $unit,
                'unit_cost' => $unitCost,
                'line_total' => $lineTotal,
                'notes' => $itemNotes,
            ];
        }

        $directCostTypes = inquiry_quote_item_type_options();
        unset($directCostTypes['material']);
        if ($error === '') {
            foreach ($directCostTypes as $directType => $directLabel) {
                $rawAmount = trim((string)($directCosts[$directType] ?? ''));
                if (preg_match('/^\.\d+$/', $rawAmount)) {
                    $rawAmount = '0' . $rawAmount;
                }

                // Ang blank o zero ay valid dahil puwedeng walang gastos sa category.
                if ($rawAmount === '' || (preg_match('/^(?:0|0\.0{1,2})$/', $rawAmount) === 1)) {
                    continue;
                }
                if (!engineer_inspection_valid_unit_cost($rawAmount)) {
                    $error = $directLabel . ' must be 0 or a valid amount with up to 2 decimal places.';
                    break;
                }

                $directAmount = (float)$rawAmount;
                $grandTotal += $directAmount;
                $rows[] = [
                    'item_type' => $directType,
                    'inventory_id' => null,
                    'material_id' => null,
                    'item_name' => $directLabel,
                    'quantity' => 1.0,
                    'unit' => 'lot',
                    'unit_cost' => $directAmount,
                    'line_total' => $directAmount,
                    'notes' => '',
                ];
            }
        }

        foreach ($assetRequirementIds as $index => $rawAssetId) {
            $assetId = (int)$rawAssetId;
            $quantityText = trim((string)($assetRequirementQuantities[$index] ?? ''));
            $requirementNotes = trim((string)($assetRequirementNotes[$index] ?? ''));

            if ($assetId <= 0 && $quantityText === '' && $requirementNotes === '') {
                continue;
            }

            if ($assetId <= 0) {
                $error = 'Please select an asset requirement.';
                break;
            }

            if (isset($selectedAssetIds[$assetId])) {
                $error = 'Each asset can be added only once. Update its quantity instead.';
                break;
            }

            if (preg_match('/^[1-9]\d*$/', $quantityText) !== 1) {
                $error = 'Asset requirement quantity must be a whole number greater than 0.';
                break;
            }

            $asset = engineer_inspection_fetch_active_asset($conn, $assetId);
            if (!$asset) {
                $error = 'Selected asset is no longer available. Please choose again.';
                break;
            }

            $selectedAssetIds[$assetId] = true;
            $assetRequirements[] = [
                'asset_id' => $assetId,
                'quantity_required' => (int)$quantityText,
                'notes' => $requirementNotes,
            ];
        }

        if ($error === '' && $engineerFindingsLength > 1000) {
            $error = 'Engineer Findings must not exceed 1,000 characters.';
        }

        if ($error === '' && $costingAction === 'submit_to_admin' && empty($rows)) {
            $error = 'Please add at least one costing item.';
        }

        if ($error === '' && !engineer_inspection_asset_requirements_table_exists($conn)) {
            $error = 'Asset Requirements setup is not ready yet. Please apply the required database migration.';
        }

        if ($error === '' && $costingAction === 'submit_to_admin') {
            if (!engineer_inspection_has_meaningful_findings($engineerFindings)) {
                $error = 'Engineer Findings must be 10 to 1,000 characters.';
            } elseif ($grandTotal <= 0) {
                $error = 'Total costing must be greater than 0 before submitting.';
            } elseif (!site_inspection_costing_versions_ready($conn)) {
                $error = 'Costing version setup is not ready. Apply the required database migration.';
            }
        }

        if ($error === '' && $costingAction === 'save_draft'
            && engineer_inspection_draft_is_unchanged($conn, $inspectionId, $rows, $assetRequirements, $engineerFindings, $riskNotes, $clientRequests)) {
            $message = 'No draft changes to save.';
        }

        if ($error === '' && $message === '') {
            $conn->begin_transaction();

            try {
                $deleteStmt = $conn->prepare('DELETE FROM site_inspection_cost_items WHERE inspection_id = ?');
                if (!$deleteStmt) {
                    throw new RuntimeException('Failed to prepare old costing cleanup.');
                }
                $deleteStmt->bind_param('i', $inspectionId);
                $deleteStmt->execute();

                $insertStmt = $conn->prepare(
                    'INSERT INTO site_inspection_cost_items
                     (inspection_id, item_type, inventory_id, material_id, item_name, quantity, unit, unit_cost, line_total, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                if (!$insertStmt) {
                    throw new RuntimeException('Failed to prepare costing save.');
                }

                foreach ($rows as $row) {
                    $itemType = $row['item_type'];
                    $inventoryId = $row['inventory_id'];
                    $materialId = $row['material_id'];
                    $itemName = $row['item_name'];
                    $quantity = $row['quantity'];
                    $unit = $row['unit'];
                    $unitCost = $row['unit_cost'];
                    $lineTotal = $row['line_total'];
                    $itemNotes = $row['notes'];
                    $insertStmt->bind_param(
                        'isiisdsdds',
                        $inspectionId,
                        $itemType,
                        $inventoryId,
                        $materialId,
                        $itemName,
                        $quantity,
                        $unit,
                        $unitCost,
                        $lineTotal,
                        $itemNotes
                    );
                    $insertStmt->execute();
                }

                $deleteAssetRequirementsStmt = $conn->prepare(
                    'DELETE FROM site_inspection_asset_requirements WHERE inspection_id = ?'
                );
                if (!$deleteAssetRequirementsStmt) {
                    throw new RuntimeException('Failed to prepare old asset requirements cleanup.');
                }
                $deleteAssetRequirementsStmt->bind_param('i', $inspectionId);
                $deleteAssetRequirementsStmt->execute();

                $insertAssetRequirementStmt = $conn->prepare(
                    'INSERT INTO site_inspection_asset_requirements
                     (inspection_id, asset_id, quantity_required, notes)
                     VALUES (?, ?, ?, ?)'
                );
                if (!$insertAssetRequirementStmt) {
                    throw new RuntimeException('Failed to prepare asset requirements save.');
                }

                foreach ($assetRequirements as $assetRequirement) {
                    $assetId = $assetRequirement['asset_id'];
                    $quantityRequired = $assetRequirement['quantity_required'];
                    $requirementNotes = $assetRequirement['notes'];
                    $insertAssetRequirementStmt->bind_param(
                        'iiis',
                        $inspectionId,
                        $assetId,
                        $quantityRequired,
                        $requirementNotes
                    );
                    $insertAssetRequirementStmt->execute();
                }

                $notesStmt = $conn->prepare(
                    'UPDATE site_inspections
                     SET engineer_findings = ?, risk_notes = ?, client_requests = ?
                     WHERE id = ? AND engineer_id = ?'
                );
                if (!$notesStmt) {
                    throw new RuntimeException('Failed to prepare engineer notes save.');
                }
                $notesStmt->bind_param('sssii', $engineerFindings, $riskNotes, $clientRequests, $inspectionId, $userId);
                $notesStmt->execute();

                if ($costingAction === 'submit_to_admin') {
                    if ($currentStatus === 'Ongoing'
                        && !site_inspection_transition($conn, $inspectionId, $userId, 'Ongoing', 'Completed')) {
                        throw new RuntimeException('Failed to complete the inspection status.');
                    }
                    if (!site_inspection_transition($conn, $inspectionId, $userId, 'Completed', 'Submitted')) {
                        throw new RuntimeException('Failed to submit the inspection status.');
                    }
                }

                if ($costingAction === 'submit_to_admin') {
                    $resetReview = $conn->prepare(
                        "UPDATE site_inspections
                         SET admin_review_status = 'Pending'
                         WHERE id = ? AND engineer_id = ? AND status = 'Submitted'"
                    );
                    if (!$resetReview) {
                        throw new RuntimeException('Failed to reset the Admin review status.');
                    }
                    $resetReview->bind_param('ii', $inspectionId, $userId);
                    $resetReview->execute();

                    $submittedVersionNo = site_inspection_create_costing_version($conn, $inspectionId, $userId);

                    audit_log_event(
                        $conn,
                        $userId,
                        $currentReviewStatus === 'Returned'
                            ? 'resubmit_site_inspection_report'
                            : 'submit_site_inspection_report',
                        'site_inspection',
                        $inspectionId,
                        [
                            'status' => $currentStatus,
                            'admin_review_status' => $currentReviewStatus,
                            'submitted_at' => $inspectionState['submitted_at'] ?? null,
                        ],
                        [
                            'status' => 'Submitted',
                            'admin_review_status' => 'Pending',
                            'costing_version' => $submittedVersionNo,
                        ]
                    );
                }

                $conn->commit();
                $message = $costingAction === 'submit_to_admin'
                    ? 'Costing V' . $submittedVersionNo . ' submitted to Admin.'
                    : 'Inspection draft saved.';
                if ($costingAction === 'submit_to_admin') {
                    try {
                        engineer_notify_admin_report_submitted($conn, $inspectionId, (string)($_SESSION['name'] ?? 'Engineer'));
                    } catch (Throwable $notificationError) {
                        error_log('Inspection costing Admin notification failed: ' . $notificationError->getMessage());
                    }
                }
            } catch (Throwable $exception) {
                $conn->rollback();
                $error = 'Failed to save costing.';
            }
        }
    }

    if ($isAjaxRequest && $workflowAction === '' && $scheduleResponseAction === '') {
        $freshInspectionState = $inspectionId > 0
            ? engineer_get_inspection_state($conn, $inspectionId, $userId)
            : [];
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => $error === '',
            'message' => $error !== '' ? $error : $message,
            'status' => $error === '' ? (string)($freshInspectionState['status'] ?? $currentStatus) : 'error',
            'inspection_id' => $inspectionId,
        ]);
        exit;
    }
}

$materialOptions = material_stock_fetch_active_materials($conn);
$assetRequirementOptions = [];
$assetRequirementsByInspection = [];
$assetRequirementsReady = engineer_inspection_asset_requirements_table_exists($conn);
// Nakatago muna habang hinihintay kung sino ang hahawak ng company assets.
$showCompanyAssetRequirements = false;

if ($assetRequirementsReady) {
    $assetOptionsResult = $conn->query(
        "SELECT a.id, a.asset_name,
                CASE
                    WHEN COALESCE(unit_totals.total_units, 0) > 0 THEN COALESCE(unit_totals.available_units, 0)
                    ELSE COALESCE(i.quantity, 0)
                END AS available_quantity
         FROM assets a
         INNER JOIN inventory i ON i.asset_id = a.id
         LEFT JOIN (
            SELECT inventory_id,
                   COUNT(*) AS total_units,
                   SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) AS available_units
            FROM asset_units
            WHERE status <> 'archived'
            GROUP BY inventory_id
         ) unit_totals ON unit_totals.inventory_id = i.id
         WHERE a.deleted_at IS NULL
         ORDER BY a.asset_name ASC, a.id ASC"
    );
    $assetRequirementOptions = $assetOptionsResult instanceof mysqli_result
        ? $assetOptionsResult->fetch_all(MYSQLI_ASSOC)
        : [];

    $assetRequirementsResult = $conn->query(
        'SELECT inspection_id, asset_id, quantity_required, notes
         FROM site_inspection_asset_requirements
         ORDER BY id ASC'
    );
    foreach ($assetRequirementsResult?->fetch_all(MYSQLI_ASSOC) ?: [] as $assetRequirement) {
        $assetRequirementsByInspection[(int)$assetRequirement['inspection_id']][] = $assetRequirement;
    }
}

$inspections = [];
$unreadInspectionNotificationIds = [];
if ($userId > 0 && user_notifications_table_exists($conn)) {
    $unreadInspectionStmt = $conn->prepare(
        "SELECT reference_id, MAX(id) AS notification_id
         FROM user_notifications
         WHERE user_id = ?
           AND read_at IS NULL
           AND type IN ('site_inspection_assignment', 'site_inspection_schedule_updated')
         GROUP BY reference_id"
    );
    if ($unreadInspectionStmt) {
        $unreadInspectionStmt->bind_param('i', $userId);
        $unreadInspectionStmt->execute();
        foreach ($unreadInspectionStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $unreadInspectionNotification) {
            $unreadInspectionNotificationIds[(int)$unreadInspectionNotification['reference_id']]
                = (int)$unreadInspectionNotification['notification_id'];
        }
        $unreadInspectionStmt->close();
    }
}
$inspectionHoldSelect = site_inspection_hold_columns_ready($conn)
    ? 'si.hold_reason, si.held_at, si.resumed_at,'
    : 'NULL AS hold_reason, NULL AS held_at, NULL AS resumed_at,';
$estimatedBudgetSelect = inquiry_budget_column_exists($conn)
    ? 's.estimated_budget,'
    : 'NULL AS estimated_budget,';
$stmt = $conn->prepare(
    "SELECT
        si.id,
        si.inquiry_id,
        si.scheduled_at,
        si.site_notes,
        si.status,
        si.client_schedule_response,
        si.engineer_schedule_response,
        si.engineer_schedule_response_note,
        si.engineer_schedule_preferred_at,
        si.acknowledged_at,
        si.started_at,
        {$inspectionHoldSelect}
        si.completed_at,
        si.submitted_at,
        si.admin_review_status,
        si.admin_remarks,
        si.admin_reviewed_at,
        si.engineer_findings,
        si.risk_notes,
        si.client_requests,
        si.created_at,
        s.client_name,
        s.company_name,
        s.email,
        s.contact_no,
        s.site_address,
        s.barangay,
        s.city_municipality,
        s.province,
        s.service_category,
        {$estimatedBudgetSelect}
        s.description
     FROM site_inspections si
     INNER JOIN service_inquiries s ON s.id = si.inquiry_id
     WHERE si.engineer_id = ?
       AND s.archived_at IS NULL
     ORDER BY si.scheduled_at ASC, si.id DESC"
);
if ($stmt) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['complete_site_address'] = engineer_inspection_complete_site_address($row);
        $inspections[] = $row;
    }
}

if ($requestedInspectionId !== false && $error === '') {
    $requestedInspectionFound = false;
    foreach ($inspections as $inspection) {
        if ((int)$inspection['id'] === (int)$requestedInspectionId) {
            $requestedInspectionFound = true;
            break;
        }
    }
    if (!$requestedInspectionFound) {
        $error = 'Inspection not found or not assigned to you.';
    }
}

$costItemsByInspection = [];
$costResult = $conn->query('SELECT * FROM site_inspection_cost_items ORDER BY id ASC');
if ($costResult) {
    while ($item = $costResult->fetch_assoc()) {
        $costItemsByInspection[(int)$item['inspection_id']][] = $item;
    }
}

$costingVersionsByInspection = [];
if (site_inspection_costing_versions_ready($conn)) {
    $versionResult = $conn->query(
        'SELECT inspection_id, version_no, total_cost, review_status, admin_remarks, submitted_at, reviewed_at
         FROM site_inspection_costing_versions
         ORDER BY inspection_id ASC, version_no DESC'
    );
    if ($versionResult) {
        while ($versionRow = $versionResult->fetch_assoc()) {
            $costingVersionsByInspection[(int)$versionRow['inspection_id']][] = $versionRow;
        }
    }
}

$initialQuotationsByInquiry = [];
$initialQuotationItemsByInquiry = [];
if (!empty($inspections)
    && inquiry_quote_table_exists($conn, 'inquiry_quotation_drafts')
    && inquiry_quote_table_exists($conn, 'inquiry_quotation_items')) {
    $initialQuoteResult = $conn->query(
        "SELECT q.id, q.inquiry_id, q.quotation_no, q.subtotal, q.grand_total
         FROM inquiry_quotation_drafts q
         INNER JOIN (
            SELECT inquiry_id, MAX(id) AS id
            FROM inquiry_quotation_drafts
            WHERE parent_draft_id IS NULL
              AND revision_no = 0
              AND LOWER(status) = 'accepted'
            GROUP BY inquiry_id
         ) accepted_initial ON accepted_initial.id = q.id"
    );

    foreach ($initialQuoteResult?->fetch_all(MYSQLI_ASSOC) ?: [] as $initialQuote) {
        $inquiryId = (int)$initialQuote['inquiry_id'];
        $initialQuotationsByInquiry[$inquiryId] = $initialQuote;
        $itemStmt = $conn->prepare(
            'SELECT item_type, item_name, quantity, unit, unit_cost, line_total
             FROM inquiry_quotation_items
             WHERE draft_id = ?
             ORDER BY id ASC'
        );
        if (!$itemStmt) {
            continue;
        }
        $draftId = (int)$initialQuote['id'];
        $itemStmt->bind_param('i', $draftId);
        $itemStmt->execute();
        $initialQuotationItemsByInquiry[$inquiryId] = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

$csrfToken = engineer_inspection_csrf_token();
$inspectionFilterLabels = ['all' => 'All', 'new' => 'New', 'ongoing' => 'Ongoing', 'submitted' => 'Submitted'];
$inspectionFilterCounts = array_fill_keys(array_keys($inspectionFilterLabels), 0);
foreach ($inspections as $filterInspection) {
    $inspectionFilterCounts['all']++;
    if ((int)($unreadInspectionNotificationIds[(int)$filterInspection['id']] ?? 0) > 0) {
        $inspectionFilterCounts['new']++;
    }
    if (($filterInspection['status'] ?? '') === 'Ongoing') {
        $inspectionFilterCounts['ongoing']++;
    } elseif (($filterInspection['status'] ?? '') === 'Submitted') {
        $inspectionFilterCounts['submitted']++;
    }
}
$engineerPageTitle = 'Site Inspections - Engineer';
$engineerCssFiles = [
    '/codesamplecaps/ENGINEER/css/site-inspections.css?v=20261027-7',
    '/codesamplecaps/SHARED/toast/css/toast.css',
];
require __DIR__ . '/../layout/header.php';
?>
<?php include __DIR__ . '/../../SHARED/sidebar/php/sidebar.php'; ?>
<main class="main-content">
    <?php
    include __DIR__ . '/../includes/header.php';
    ?>

    <div class="inspection-shell">
        <div class="inspection-toolbar">
            <h1 id="inspectionPageTitle">Site Inspections</h1>
            <div class="inspection-filters" role="group" aria-label="Filter inspections">
                <?php foreach ($inspectionFilterLabels as $filterKey => $filterLabel): ?>
                    <button type="button" class="inspection-filter" data-inspection-filter="<?php echo $filterKey; ?>" aria-pressed="<?php echo $filterKey === 'all' ? 'true' : 'false'; ?>" aria-controls="engineerInspectionList">
                        <?php echo $filterLabel; ?>
                        <span data-inspection-filter-count><?php echo $inspectionFilterCounts[$filterKey]; ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if (in_array($message, ['Inspection draft saved.', 'Inspection costing submitted to Admin for review.', 'Reschedule request sent to Admin.'], true)): ?>
            <div class="shared-toast shared-toast--success" data-shared-toast role="status">
                <span><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></span>
                <button type="button" class="shared-toast__close" data-shared-toast-close aria-label="Close notification">&times;</button>
                <span class="shared-toast__progress" aria-hidden="true"></span>
            </div>
        <?php elseif ($message): ?>
            <div class="inspection-flash success"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($error): ?><div class="inspection-flash error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        <section class="inspection-panel" id="engineerInspectionList" aria-labelledby="inspectionPageTitle">
            <?php if (empty($inspections)): ?>
                <p class="inspection-meta" data-inspection-empty>No site inspections yet.</p>
            <?php else: ?>
                <?php foreach ($inspections as $inspection): ?>
                    <?php
                    $inspectionId = (int)$inspection['id'];
                    $unreadInspectionNotificationId = (int)($unreadInspectionNotificationIds[$inspectionId] ?? 0);
                    $initialQuotation = $initialQuotationsByInquiry[(int)($inspection['inquiry_id'] ?? 0)] ?? null;
                    $initialQuotationItems = $initialQuotationItemsByInquiry[(int)($inspection['inquiry_id'] ?? 0)] ?? [];
                    $costItems = $costItemsByInspection[$inspectionId] ?? [];
                    $costingVersions = $costingVersionsByInspection[$inspectionId] ?? [];
                    $currentCostingVersion = (int)($costingVersions[0]['version_no'] ?? 0);
                    $showCostingVersionLabel = count($costingVersions) > 1
                        || (string)($costingVersions[0]['review_status'] ?? '') === 'Returned';
                    $assetRequirements = $assetRequirementsByInspection[$inspectionId] ?? [];
                    $totalCost = array_sum(array_map(static fn($item) => (float)($item['line_total'] ?? 0), $costItems));
                    $costTotalsByType = array_fill_keys(array_keys(inquiry_quote_item_type_options()), 0.0);
                    foreach ($costItems as $savedCostItem) {
                        $savedCostType = inquiry_quote_normalize_item_type((string)($savedCostItem['item_type'] ?? ''));
                        if ($savedCostType !== '') {
                            $costTotalsByType[$savedCostType] += (float)($savedCostItem['line_total'] ?? 0);
                        }
                    }
                    $materialCostItems = array_values(array_filter(
                        $costItems,
                        static fn(array $costItem): bool => inquiry_quote_normalize_item_type((string)($costItem['item_type'] ?? '')) === 'material'
                    ));
                    $directCostTypes = inquiry_quote_item_type_options();
                    unset($directCostTypes['material']);
                    $inspectionStatus = (string)($inspection['status'] ?? 'Assigned');
                    $currentScheduleReason = site_inspection_current_admin_reschedule_reason(
                        $conn,
                        $inspectionId,
                        (int)($inspection['engineer_id'] ?? $userId),
                        (string)($inspection['scheduled_at'] ?? '')
                    );
                    $visibleSiteNotes = trim((string)($inspection['site_notes'] ?? ''));
                    if ($visibleSiteNotes === 'Walk-in inspection schedule confirmed by Admin.') {
                        $visibleSiteNotes = '';
                    }
                    $adminReviewStatus = (string)($inspection['admin_review_status'] ?? 'Pending');
                    $displayInspectionStatus = $inspectionStatus === 'Completed' && $adminReviewStatus === 'Returned'
                        ? 'Returned for Revision'
                        : ($inspectionStatus === 'Submitted' ? 'Costing Submitted' : $inspectionStatus);
                    $isSubmittedToAdmin = $inspectionStatus === 'Submitted';
                    $isReturnedForRevision = $inspectionStatus === 'Completed' && $adminReviewStatus === 'Returned';
                    // Para sa dating completed report bago ang one-click submit flow.
                    $isLegacyCompletedPending = $inspectionStatus === 'Completed' && $adminReviewStatus === 'Pending';
                    $isScheduleDateReached = engineer_inspection_schedule_date_is_reached($inspection['scheduled_at'] ?? null);
                    $isInspectionStarted = in_array($inspectionStatus, ['Ongoing', 'On Hold', 'Completed', 'Submitted'], true);
                    $canOpenInspectionReport = $isScheduleDateReached && $isInspectionStarted;
                    $showReportRequiredBadge = $isInspectionStarted && !$isSubmittedToAdmin;
                    $canEditCosting = $inspectionStatus === 'Ongoing' || $isReturnedForRevision || $isLegacyCompletedPending;
                    $canSubmitToAdmin = $isReturnedForRevision || $isLegacyCompletedPending;
                    $savedReportError = engineer_inspection_saved_report_error(
                        $conn,
                        $inspectionId,
                        (string)($inspection['engineer_findings'] ?? '')
                    );
                    $hasSavedFinalReport = $savedReportError === '';
                    $workflowAction = match ($inspectionStatus) {
                        'Assigned', 'Acknowledged' => 'start',
                        'Ongoing' => 'complete',
                        'On Hold' => 'resume',
                        default => '',
                    };
                    if (!$isScheduleDateReached && in_array($inspectionStatus, ['Assigned', 'Acknowledged'], true)) {
                        $workflowAction = '';
                    }
                    $workflowActionLabel = match ($inspectionStatus) {
                        'Assigned', 'Acknowledged' => 'Start Inspection',
                        'Ongoing' => 'Submit to Admin',
                        'On Hold' => 'Resume Inspection',
                        default => '',
                    };
                    $statusTimes = [
                        'Assigned' => $inspection['created_at'] ?? null,
                        'Ongoing' => $inspection['started_at'] ?? null,
                        'Submitted' => $inspection['submitted_at'] ?? null,
                    ];
                    // Simple lang ang progress na kailangan makita ng Engineer.
                    $engineerProgressSteps = [
                        ['label' => 'Assigned', 'time' => $statusTimes['Assigned']],
                        ['label' => 'Ongoing', 'time' => $statusTimes['Ongoing']],
                        ['label' => 'Costing Submitted', 'time' => $statusTimes['Submitted']],
                    ];
                    $engineerProgressIndex = match ($inspectionStatus) {
                        'Ongoing', 'On Hold' => 1,
                        'Completed', 'Submitted' => 2,
                        default => 0,
                    };
                    if (empty($materialCostItems)) {
                        $materialCostItems = [[
                            'item_type' => 'material',
                            'inventory_id' => '',
                            'material_id' => '',
                            'item_name' => '',
                            'quantity' => 1,
                            'unit' => 'unit',
                            'unit_cost' => '',
                            'notes' => '',
                        ]];
                    }

                    $cardService = trim((string)($inspection['service_category'] ?? ''));
                    if ($cardService === 'Other Service') {
                        $otherServiceDetails = '';
                        if (preg_match('/Other service details:\s*(.+)/is', (string)($inspection['description'] ?? ''), $matches)) {
                            $otherServiceDetails = preg_replace('/\s+/u', ' ', trim((string)$matches[1])) ?? '';
                        }
                        $cardService = $otherServiceDetails !== ''
                            ? mb_strimwidth($otherServiceDetails, 0, 48, '...')
                            : 'Other Service';
                    }
                    if ($cardService === '') {
                        $cardService = 'Service request';
                    }

                    $cardLocation = implode(', ', array_filter([
                        trim((string)($inspection['barangay'] ?? '')),
                        trim((string)($inspection['city_municipality'] ?? '')),
                    ], static fn(string $part): bool => $part !== ''));
                    if ($cardLocation === '') {
                        $cardLocation = trim((string)($inspection['province'] ?? '')) ?: 'Not set';
                    }
                    ?>
                    <article class="inspection-card <?php echo $unreadInspectionNotificationId > 0 ? 'is-unviewed' : 'is-viewed'; ?>" data-inspection-card-id="<?php echo $inspectionId; ?>" data-inspection-sync-key="<?php echo htmlspecialchars(hash('sha256', implode('|', [(string)($inspection['scheduled_at'] ?? ''), (string)($inspection['engineer_id'] ?? ''), (string)($inspection['status'] ?? ''), (string)($inspection['client_schedule_response'] ?? ''), (string)($inspection['engineer_schedule_response'] ?? ''), (string)$unreadInspectionNotificationId, $currentScheduleReason, $visibleSiteNotes])), ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="inspection-card__head">
                            <div class="inspection-card__identity">
                                <?php if ($unreadInspectionNotificationId > 0): ?>
                                    <span class="inspection-card__new-indicator" data-inspection-new-badge>
                                        <span aria-hidden="true"></span>New
                                    </span>
                                <?php endif; ?>
                                <span class="inspection-card__eyebrow">Client - Site Inspection</span>
                                <h2><?php echo htmlspecialchars((string)$inspection['client_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                            </div>
                            <span class="inspection-status" data-status="<?php echo htmlspecialchars($displayInspectionStatus, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($displayInspectionStatus, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>

                        <div class="inspection-card__summary">
                            <div class="inspection-card__info">
                                <span>Service</span>
                                <strong><?php echo htmlspecialchars($cardService, ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                            <div class="inspection-card__info">
                                <span>Location</span>
                                <strong><?php echo htmlspecialchars($cardLocation, ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                            <div class="inspection-card__info">
                                <span>Inspection Schedule</span>
                                <strong><?php echo htmlspecialchars(site_inspection_format_date($inspection['scheduled_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                        </div>

                        <button
                            type="button"
                            class="btn-primary inspection-view-button"
                            data-inspection-modal-open="inspectionModal<?php echo $inspectionId; ?>"
                            <?php echo $unreadInspectionNotificationId > 0 ? 'data-engineer-notification-id="' . $unreadInspectionNotificationId . '"' : ''; ?>
                        >
                            View Details
                        </button>

                        <div class="inspection-modal" id="inspectionModal<?php echo $inspectionId; ?>" data-report-submitted="<?php echo $isSubmittedToAdmin ? 'true' : 'false'; ?>" hidden>
                            <div class="inspection-modal__panel" role="dialog" aria-modal="true" aria-labelledby="inspectionModalTitle<?php echo $inspectionId; ?>">
                                <div class="inspection-modal__head">
                                    <div>
                                        <span class="inspection-modal__eyebrow">Site Inspection</span>
                                        <h2 id="inspectionModalTitle<?php echo $inspectionId; ?>"><?php echo htmlspecialchars((string)$inspection['client_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                                        <p><?php echo htmlspecialchars($cardService, ENT_QUOTES, 'UTF-8'); ?></p>
                                    </div>
                                    <button type="button" class="inspection-modal__close" data-inspection-modal-close aria-label="Close inspection details">&times;</button>
                                </div>

                                <div class="inspection-workflow" aria-label="Inspection progress">
                                    <?php foreach ($engineerProgressSteps as $statusIndex => $progressStep): ?>
                                        <?php
                                        $stepClass = $statusIndex < $engineerProgressIndex
                                            ? 'is-done'
                                            : ($statusIndex === $engineerProgressIndex ? 'is-current' : '');
                                        ?>
                                        <div class="inspection-workflow__step <?php echo $stepClass; ?>" data-phase="<?php echo htmlspecialchars($progressStep['label'], ENT_QUOTES, 'UTF-8'); ?>"<?php echo $statusIndex === $engineerProgressIndex ? ' aria-current="step"' : ''; ?>>
                                            <span><?php echo htmlspecialchars($progressStep['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php if (!empty($progressStep['time'])): ?>
                                                <small><?php echo htmlspecialchars(site_inspection_format_datetime($progressStep['time']), ENT_QUOTES, 'UTF-8'); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="inspection-tab-toolbar">
                                    <div class="inspection-tabs" role="tablist" aria-label="Site inspection sections">
                                        <button type="button" class="inspection-tabs__button is-active" role="tab" aria-selected="true" aria-controls="inspectionDetails<?php echo $inspectionId; ?>" id="inspectionDetailsTab<?php echo $inspectionId; ?>" data-inspection-tab="details">Details</button>
                                        <button type="button" class="inspection-tabs__button" role="tab" aria-selected="false" aria-controls="inspectionReport<?php echo $inspectionId; ?>" id="inspectionReportTab<?php echo $inspectionId; ?>" data-inspection-tab="report" <?php echo !$canOpenInspectionReport ? 'disabled aria-disabled="true" title="Start the inspection on its scheduled date to open this report."' : ''; ?>>
                                            <span>Inspection Report</span>
                                            <?php if ($showReportRequiredBadge): ?><small class="inspection-tabs__required">Required</small><?php endif; ?>
                                        </button>
                                    </div>
                                    <div class="inspection-report-actions" data-inspection-report-actions hidden>
                                        <?php if ($inspectionStatus === 'Ongoing'): ?>
                                            <form method="POST">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="inspection_id" value="<?php echo $inspectionId; ?>">
                                                <input type="hidden" name="workflow_action" value="hold">
                                                <button type="button" class="btn-secondary" data-workflow-transition>Put On Hold</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($workflowAction !== '' && !in_array($workflowAction, ['start', 'complete'], true)): ?>
                                            <form method="POST">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="inspection_id" value="<?php echo $inspectionId; ?>">
                                                <input type="hidden" name="workflow_action" value="<?php echo htmlspecialchars($workflowAction, ENT_QUOTES, 'UTF-8'); ?>">
                                                <button type="button" class="btn-primary<?php echo $workflowAction === 'complete' && !$hasSavedFinalReport ? ' is-locked' : ''; ?>" data-workflow-transition data-confirm-inspection-transition="<?php echo htmlspecialchars($workflowActionLabel, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $workflowAction === 'complete' ? 'data-complete-inspection data-complete-ready="' . ($hasSavedFinalReport ? 'true' : 'false') . '" aria-disabled="' . ($hasSavedFinalReport ? 'false' : 'true') . '" title="' . htmlspecialchars($hasSavedFinalReport ? '' : $savedReportError, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
                                                    <?php echo htmlspecialchars($workflowActionLabel, ENT_QUOTES, 'UTF-8'); ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <section class="inspection-tab-panel" id="inspectionDetails<?php echo $inspectionId; ?>" role="tabpanel" aria-labelledby="inspectionDetailsTab<?php echo $inspectionId; ?>" data-inspection-tab-panel="details">
                                <?php if (!$isInspectionStarted && !$isScheduleDateReached): ?>
                                    <p class="inspection-schedule-waiting" role="status">This inspection starts on <?php echo htmlspecialchars(site_inspection_format_date($inspection['scheduled_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?>. Details are available now.</p>
                                <?php endif; ?>
                                <div class="inspection-grid">
                                    <div class="inspection-detail"><span>Scheduled Inspection Date</span><strong><?php echo htmlspecialchars(site_inspection_format_date($inspection['scheduled_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                    <div class="inspection-detail inspection-detail--span-2"><span>Site Address</span><strong><?php echo htmlspecialchars((string)($inspection['complete_site_address'] ?: 'Not set'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                    <div class="inspection-detail"><span>Contact Number</span><strong><?php echo htmlspecialchars((string)$inspection['contact_no'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                    <div class="inspection-detail"><span>Email Address</span><strong><?php echo htmlspecialchars((string)$inspection['email'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                    <div class="inspection-detail"><span>Company</span><strong><?php echo htmlspecialchars((string)($inspection['company_name'] ?: 'Individual client'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                    <div class="inspection-detail"><span>Estimated Budget</span><strong><?php echo htmlspecialchars(inquiry_budget_label($inspection['estimated_budget'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                    <?php if (trim((string)$inspection['description']) !== ''): ?>
                                        <div class="inspection-detail inspection-detail--span-2"><span>Project Description</span><strong class="inspection-detail__multiline"><?php echo nl2br(htmlspecialchars((string)$inspection['description'], ENT_QUOTES, 'UTF-8')); ?></strong></div>
                                    <?php endif; ?>
                                    <?php if ($visibleSiteNotes !== ''): ?>
                                        <div class="inspection-detail inspection-detail--wide"><span>Site Notes</span><strong class="inspection-detail__multiline"><?php echo nl2br(htmlspecialchars($visibleSiteNotes, ENT_QUOTES, 'UTF-8')); ?></strong></div>
                                    <?php endif; ?>
                                    <?php if ($currentScheduleReason !== ''): ?>
                                        <div class="inspection-detail inspection-detail--wide"><span>Schedule Change Reason</span><strong class="inspection-detail__multiline"><?php echo nl2br(htmlspecialchars($currentScheduleReason, ENT_QUOTES, 'UTF-8')); ?></strong></div>
                                    <?php endif; ?>
                                    <?php if ($inspectionStatus === 'On Hold'): ?>
                                        <div class="inspection-detail inspection-detail--wide inspection-detail--hold"><span>Hold Reason</span><strong class="inspection-detail__multiline"><?php echo nl2br(htmlspecialchars((string)($inspection['hold_reason'] ?: 'Not set'), ENT_QUOTES, 'UTF-8')); ?></strong><small>Put on hold: <?php echo htmlspecialchars(site_inspection_format_datetime($inspection['held_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></small></div>
                                    <?php elseif (!empty($inspection['resumed_at'])): ?>
                                        <div class="inspection-detail inspection-detail--wide"><span>Last Resumed</span><strong><?php echo htmlspecialchars(site_inspection_format_datetime($inspection['resumed_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                    <?php endif; ?>
                                    <?php if (!$isInspectionStarted && $isScheduleDateReached): ?>
                                        <div class="inspection-details-action inspection-detail--wide">
                                            <form method="POST">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="inspection_id" value="<?php echo $inspectionId; ?>">
                                                <input type="hidden" name="workflow_action" value="start">
                                                <button type="button" class="btn-primary" data-workflow-transition data-confirm-inspection-transition="Start Inspection">Start Inspection</button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($inspection['admin_remarks'])): ?>
                                    <div class="inspection-admin-remarks" data-review-status="<?php echo htmlspecialchars($adminReviewStatus, ENT_QUOTES, 'UTF-8'); ?>">
                                        <strong>Admin Remarks / Reason for Return</strong>
                                        <p><?php echo nl2br(htmlspecialchars((string)$inspection['admin_remarks'], ENT_QUOTES, 'UTF-8')); ?></p>
                                        <small>Reviewed: <?php echo htmlspecialchars(site_inspection_format_datetime($inspection['admin_reviewed_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></small>
                                    </div>
                                <?php endif; ?>
                                </section>

                                <section class="inspection-tab-panel" id="inspectionReport<?php echo $inspectionId; ?>" role="tabpanel" aria-labelledby="inspectionReportTab<?php echo $inspectionId; ?>" data-inspection-tab-panel="report" hidden>
                                <div class="inspection-status-action">
                                    <?php if ((string)($inspection['engineer_schedule_response'] ?? 'pending') === 'reschedule_requested'): ?>
                                        <section class="inspection-schedule-pending" aria-live="polite">
                                            <strong>Pending Admin Review</strong>
                                            <p>Your requested schedule is waiting for Admin review. The official schedule stays unchanged.</p>
                                            <?php if (!empty($inspection['engineer_schedule_preferred_at'])): ?><p><span>Requested date</span><?php echo htmlspecialchars(site_inspection_format_date($inspection['engineer_schedule_preferred_at']), ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
                                            <?php if (!empty($inspection['engineer_schedule_response_note'])): ?><p><span>Your reason</span><?php echo htmlspecialchars((string)$inspection['engineer_schedule_response_note'], ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
                                            <button type="button" class="btn-secondary" disabled>Request Pending</button>
                                        </section>
                                    <?php elseif (in_array($inspectionStatus, ['Assigned', 'Acknowledged'], true)
                                       && (string)($inspection['engineer_schedule_response'] ?? 'pending') === 'pending'
                                        && (string)($inspection['client_schedule_response'] ?? 'pending') !== 'reschedule_requested'): ?>
                                        <button type="button" class="btn-secondary inspection-schedule-change-button"
                                            data-engineer-reschedule-open
                                            data-inspection-id="<?php echo $inspectionId; ?>"
                                            data-official-schedule="<?php echo htmlspecialchars(site_inspection_format_date($inspection['scheduled_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?>">
                                            Request a Schedule Change
                                        </button>
                                    <?php endif; ?>
                                </div>

                                <?php if ($initialQuotation): ?>
                                    <details class="inspection-disclosure inspection-initial-quotation" open>
                                        <summary>
                                            Initial Quotation
                                            <span><?php echo htmlspecialchars((string)$initialQuotation['quotation_no'], ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars(engineer_format_money((float)$initialQuotation['grand_total']), ENT_QUOTES, 'UTF-8'); ?></span>
                                        </summary>
                                        <div class="inspection-initial-quotation__body">
                                            <p>Admin reference only. Add the actual site costs below.</p>
                                            <?php if ($initialQuotationItems): ?>
                                                <div class="inspection-initial-quotation__table-wrap">
                                                    <table>
                                                        <thead><tr><th>Type</th><th>Item</th><th>Qty</th><th>Unit</th><th>Unit Cost</th><th>Total</th></tr></thead>
                                                        <tbody>
                                                            <?php foreach ($initialQuotationItems as $initialItem): ?>
                                                                <tr>
                                                                    <td><?php echo htmlspecialchars(inquiry_quote_item_type_label((string)$initialItem['item_type']), ENT_QUOTES, 'UTF-8'); ?></td>
                                                                    <td><?php echo htmlspecialchars((string)$initialItem['item_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                                    <td><?php echo htmlspecialchars((string)$initialItem['quantity'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                                    <td><?php echo htmlspecialchars((string)$initialItem['unit'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                                    <td><?php echo htmlspecialchars(engineer_format_money((float)$initialItem['unit_cost']), ENT_QUOTES, 'UTF-8'); ?></td>
                                                                    <td><?php echo htmlspecialchars(engineer_format_money((float)$initialItem['line_total']), ENT_QUOTES, 'UTF-8'); ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php else: ?>
                                                <p class="inspection-initial-quotation__empty">No initial quotation items were saved.</p>
                                            <?php endif; ?>
                                        </div>
                                    </details>
                                <?php endif; ?>

                                <form method="POST" class="inspection-costing-form<?php echo !$canEditCosting ? ' inspection-costing-form--readonly' : ''; ?>" data-costing-form>
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="inspection_id" value="<?php echo $inspectionId; ?>">
                            <div class="costing-head">
                                <strong><?php echo $canEditCosting ? 'Inspection Findings &amp; Costing' : 'Submitted Inspection Costing'; ?><?php echo $showCostingVersionLabel && $currentCostingVersion > 0 ? ' V' . $currentCostingVersion : ''; ?></strong>
                            </div>
                            <?php if (count($costingVersions) > 1): ?>
                                <div class="costing-version-history" aria-label="Costing version history">
                                    <strong>Version History</strong>
                                    <div class="costing-version-history__list">
                                        <?php foreach ($costingVersions as $costingVersion): ?>
                                            <?php $versionStatusClass = strtolower((string)$costingVersion['review_status']); ?>
                                            <div class="costing-version-history__item is-<?php echo htmlspecialchars($versionStatusClass, ENT_QUOTES, 'UTF-8'); ?>">
                                                <span>V<?php echo (int)$costingVersion['version_no']; ?></span>
                                                <small><?php echo htmlspecialchars((string)$costingVersion['review_status'], ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars(site_inspection_format_datetime($costingVersion['submitted_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></small>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <p class="costing-error" data-costing-error hidden></p>
                            <?php if ($isSubmittedToAdmin): ?>
                                <div class="inspection-submit-note">Submitted to Admin. Wait for Admin review before changing this costing.</div>
                            <?php elseif ($isLegacyCompletedPending): ?>
                                <div class="inspection-submit-note inspection-submit-note--waiting">This costing is not yet sent. Review it, then submit it to Admin.</div>
                            <?php elseif (!$canEditCosting): ?>
                                <div class="inspection-submit-note inspection-submit-note--waiting">Start the inspection before adding findings and costing.</div>
                            <?php endif; ?>

                            <div class="inspection-costing-notes">
                                <label class="inspection-costing-notes__findings">
                                    <span>Engineer Findings <b>*</b></span>
                                    <div class="inspection-findings-control">
                                        <textarea name="engineer_findings" rows="3" maxlength="1000" aria-describedby="engineerFindingsReminder<?php echo $inspectionId; ?> engineerFindingsCount<?php echo $inspectionId; ?>" placeholder="Actual problem found, site condition, or recommended scope" <?php echo !$canEditCosting ? 'disabled' : ''; ?>><?php echo htmlspecialchars((string)($inspection['engineer_findings'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                                        <span class="inspection-findings-check" data-findings-check aria-hidden="true" hidden>&#10003;</span>
                                    </div>
                                    <div class="inspection-findings-feedback">
                                        <small id="engineerFindingsReminder<?php echo $inspectionId; ?>" class="inspection-findings-reminder" data-findings-reminder>Enter at least 10 characters.</small>
                                        <small id="engineerFindingsCount<?php echo $inspectionId; ?>" class="inspection-findings-count" data-findings-count>0 / 1000</small>
                                    </div>
                                </label>
                            </div>

                            <details class="inspection-disclosure inspection-disclosure--notes">
                                <summary>Additional Notes (Optional)</summary>
                                <div class="inspection-costing-notes inspection-costing-notes--optional">
                                    <label>
                                        <span>Risk / Safety Notes</span>
                                        <textarea name="risk_notes" rows="2" placeholder="Access issue, electrical risk, working height, downtime risk..." <?php echo !$canEditCosting ? 'disabled' : ''; ?>><?php echo htmlspecialchars((string)($inspection['risk_notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                                    </label>
                                    <label>
                                        <span>Client Requests</span>
                                        <textarea name="client_requests" rows="2" placeholder="Preferred schedule, brand request, special instruction..." <?php echo !$canEditCosting ? 'disabled' : ''; ?>><?php echo htmlspecialchars((string)($inspection['client_requests'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                                    </label>
                                </div>
                            </details>

                            <details class="inspection-disclosure" open>
                                <summary>Cost Items <span>Detailed materials and direct category amounts</span></summary>
                            <div class="costing-rows" data-costing-rows>
                                <?php foreach ($materialCostItems as $itemIndex => $item): ?>
                                    <div class="costing-row">
                                        <strong class="costing-row__number" data-costing-row-number>Material <?php echo $itemIndex + 1; ?></strong>
                                        <input type="hidden" name="item_type[]" value="material">
                                        <label data-material-field>
                                            <span>Material Reference</span>
                                            <input type="hidden" name="inventory_id[]" value="<?php echo (int)($item['inventory_id'] ?? 0); ?>">
                                            <select name="material_id[]" data-material-picker <?php echo !$canEditCosting ? 'disabled' : ''; ?>>
                                                <option value="">Manual / non-stock item</option>
                                                <?php foreach ($materialOptions as $material): ?>
                                                    <option
                                                        value="<?php echo (int)$material['id']; ?>"
                                                        data-name="<?php echo htmlspecialchars((string)$material['material_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-unit="<?php echo htmlspecialchars((string)$material['unit'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-available="<?php echo htmlspecialchars((string)$material['available_quantity'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        <?php echo (int)($item['material_id'] ?? 0) === (int)$material['id'] ? 'selected' : ''; ?>
                                                    >
                                                        <?php echo htmlspecialchars((string)$material['material_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small class="material-stock-feedback" data-material-stock-feedback hidden></small>
                                        </label>
                                        <label data-item-name-field<?php echo (int)($item['material_id'] ?? 0) > 0 ? ' hidden' : ''; ?>>
                                            <span data-item-name-label>Material Name <b aria-hidden="true">*</b></span>
                                            <input type="text" name="item_name[]" placeholder="Enter material name" value="<?php echo htmlspecialchars((string)($item['item_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required <?php echo !$canEditCosting ? 'disabled' : ''; ?>>
                                        </label>
                                        <label>
                                            <span>Qty <b aria-hidden="true">*</b></span>
                                            <input type="text" name="quantity[]" inputmode="decimal" autocomplete="off" maxlength="12" placeholder="1" value="<?php echo htmlspecialchars((string)($item['quantity'] ?? 1), ENT_QUOTES, 'UTF-8'); ?>" data-costing-decimal required <?php echo !$canEditCosting ? 'disabled' : ''; ?>>
                                        </label>
                                        <label>
                                            <span>Unit <b aria-hidden="true">*</b></span>
                                            <select name="unit[]" required <?php echo !$canEditCosting ? 'disabled' : ''; ?>>
                                                <?php foreach (['unit', 'pc', 'pcs', 'roll', 'box', 'pack', 'set', 'lot', 'person', 'bundle', 'sheet', 'pair', 'tube', 'meter', 'kg', 'liter', 'hour', 'day', 'trip'] as $unitOption): ?>
                                                    <option value="<?php echo htmlspecialchars($unitOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($item['unit'] ?? 'unit') === $unitOption ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars(ucfirst($unitOption), ENT_QUOTES, 'UTF-8'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>
                                            <span>Estimated Unit Cost (PHP) <b aria-hidden="true">*</b></span>
                                            <input type="text" name="unit_cost[]" inputmode="decimal" autocomplete="off" maxlength="15" placeholder="0.00" value="<?php echo htmlspecialchars((string)($item['unit_cost'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-costing-decimal required <?php echo !$canEditCosting ? 'disabled' : ''; ?>>
                                        </label>
                                        <label>
                                            <span>Notes (Optional)</span>
                                            <input type="text" name="notes[]" placeholder="Add a note if needed" value="<?php echo htmlspecialchars((string)($item['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" <?php echo !$canEditCosting ? 'disabled' : ''; ?>>
                                        </label>
                                        <?php if ($canEditCosting): ?>
                                            <button type="button" class="btn-remove-row" data-remove-costing-row>Remove</button>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($canEditCosting): ?>
                                <div class="inspection-section-action">
                                    <button type="button" class="btn-secondary" data-add-costing-row>Add Material</button>
                                </div>
                            <?php endif; ?>

                            <div class="inspection-direct-costs" data-direct-costs>
                                <strong class="inspection-direct-costs__title">
                                    Other Cost Categories
                                    <small>Enter 0 if there is no cost.</small>
                                </strong>
                                <?php foreach ($directCostTypes as $directType => $directLabel): ?>
                                    <label class="inspection-direct-cost">
                                        <span><?php echo htmlspecialchars($directLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span class="inspection-direct-cost__input">
                                            <b aria-hidden="true">PHP</b>
                                            <input
                                                type="text"
                                                name="direct_cost[<?php echo htmlspecialchars($directType, ENT_QUOTES, 'UTF-8'); ?>]"
                                                inputmode="decimal"
                                                autocomplete="off"
                                                maxlength="15"
                                                placeholder="0.00"
                                                value="<?php echo (float)($costTotalsByType[$directType] ?? 0) > 0 ? htmlspecialchars(number_format((float)$costTotalsByType[$directType], 2, '.', ''), ENT_QUOTES, 'UTF-8') : ''; ?>"
                                                data-direct-cost
                                                data-costing-decimal
                                                <?php echo !$canEditCosting ? 'disabled' : ''; ?>
                                            >
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            </details>

                            <div class="inspection-costing-summary" aria-label="Estimated costing summary">
                                <?php foreach (inquiry_quote_item_type_options() as $typeValue => $typeLabel): ?>
                                    <div>
                                        <span><?php echo htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <strong data-costing-category-total="<?php echo htmlspecialchars($typeValue, ENT_QUOTES, 'UTF-8'); ?>"><?php echo engineer_format_money((float)($costTotalsByType[$typeValue] ?? 0)); ?></strong>
                                    </div>
                                <?php endforeach; ?>
                                <div class="inspection-costing-summary__total">
                                    <span>Estimated Costing Total</span>
                                    <strong data-costing-total><?php echo engineer_format_money($totalCost); ?></strong>
                                </div>
                            </div>

                            <details class="inspection-disclosure"<?php echo !empty($assetRequirements) ? ' open' : ''; ?><?php echo !$showCompanyAssetRequirements ? ' hidden' : ''; ?>>
                                <summary>Company Assets Needed <span>Optional reusable company assets</span></summary>
                            <section class="asset-requirements" data-asset-requirements>
                                <?php if (!$assetRequirementsReady): ?>
                                    <p class="inspection-submit-note inspection-submit-note--waiting">Asset Requirements setup is not ready yet. Please apply the required database migration.</p>
                                <?php else: ?>
                                    <div class="asset-requirement-rows" data-asset-requirement-rows>
                                        <?php foreach ($assetRequirements as $assetRequirement): ?>
                                            <div class="asset-requirement-row" data-asset-requirement-row>
                                                <label>
                                                    <span>Asset</span>
                                                    <select name="asset_requirement_asset_id[]" data-asset-requirement-picker <?php echo !$canEditCosting ? 'disabled' : ''; ?>>
                                                        <option value="">Select asset</option>
                                                        <?php foreach ($assetRequirementOptions as $asset): ?>
                                                            <option value="<?php echo (int)$asset['id']; ?>" data-available="<?php echo (int)$asset['available_quantity']; ?>" <?php echo (int)$assetRequirement['asset_id'] === (int)$asset['id'] ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars((string)$asset['asset_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </label>
                                                <label>
                                                    <span>Available</span>
                                                    <output data-asset-requirement-available>0 available</output>
                                                </label>
                                                <label>
                                                    <span>Qty Needed</span>
                                                    <input type="text" name="asset_requirement_quantity[]" inputmode="numeric" autocomplete="off" value="<?php echo htmlspecialchars((string)$assetRequirement['quantity_required'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo !$canEditCosting ? 'disabled' : ''; ?>>
                                                    <small class="asset-requirement-shortage" data-asset-requirement-shortage hidden></small>
                                                </label>
                                                <label>
                                                    <span>Notes</span>
                                                    <input type="text" name="asset_requirement_notes[]" placeholder="Optional notes" value="<?php echo htmlspecialchars((string)($assetRequirement['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" <?php echo !$canEditCosting ? 'disabled' : ''; ?>>
                                                </label>
                                                <?php if ($canEditCosting): ?>
                                                    <button type="button" class="btn-remove-row" data-remove-asset-requirement>Remove</button>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <?php if ($canEditCosting): ?>
                                        <template data-asset-requirement-template>
                                            <div class="asset-requirement-row" data-asset-requirement-row>
                                                <label>
                                                    <span>Asset</span>
                                                    <select name="asset_requirement_asset_id[]" data-asset-requirement-picker>
                                                        <option value="">Select asset</option>
                                                        <?php foreach ($assetRequirementOptions as $asset): ?>
                                                            <option value="<?php echo (int)$asset['id']; ?>" data-available="<?php echo (int)$asset['available_quantity']; ?>">
                                                                <?php echo htmlspecialchars((string)$asset['asset_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </label>
                                                <label><span>Available</span><output data-asset-requirement-available>Select an asset</output></label>
                                                <label><span>Qty Needed</span><input type="text" name="asset_requirement_quantity[]" inputmode="numeric" autocomplete="off" value="1"><small class="asset-requirement-shortage" data-asset-requirement-shortage hidden></small></label>
                                                <label><span>Notes</span><input type="text" name="asset_requirement_notes[]" placeholder="Optional notes"></label>
                                                <button type="button" class="btn-remove-row" data-remove-asset-requirement>Remove</button>
                                            </div>
                                        </template>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($canEditCosting && $assetRequirementsReady): ?>
                                    <div class="inspection-section-action">
                                        <button type="button" class="btn-secondary" data-add-asset-requirement>Add Asset Requirement</button>
                                    </div>
                                <?php endif; ?>
                            </section>
                            </details>

                            <?php if ($canEditCosting): ?>
                                <div class="inspection-actions">
                                    <?php if ($workflowAction === 'complete' || $canSubmitToAdmin): ?>
                                        <p class="inspection-submit-guidance" data-submit-guidance role="status" aria-live="polite"></p>
                                    <?php endif; ?>
                                    <div class="inspection-actions__group inspection-actions__group--save">
                                        <button type="submit" name="costing_action" value="save_draft" class="btn-secondary" data-save-draft formnovalidate disabled>Save Draft</button>
                                        <?php if ($workflowAction === 'complete' || $canSubmitToAdmin): ?>
                                            <button type="submit" name="costing_action" value="submit_to_admin" class="btn-primary is-locked" data-submit-costing data-confirm-submit-costing aria-disabled="true"><?php echo $isReturnedForRevision ? 'Resubmit to Admin' : 'Submit to Admin'; ?></button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                                </form><?php if (!$canEditCosting && $inspectionStatus === 'On Hold'): ?>
                                    <p class="inspection-submit-note inspection-submit-note--waiting">Inspection is on hold. Resume to edit this costing.</p>
                                <?php endif; ?>
                                </section>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
            <p class="inspection-meta" data-inspection-filter-empty role="status" hidden>No inspections match this filter.</p>
        </section>

        <div class="inspection-confirm-modal" data-complete-inspection-modal hidden>
            <div class="inspection-confirm-modal__panel" role="dialog" aria-modal="true" aria-labelledby="completeInspectionTitle">
                <h2 id="completeInspectionTitle">Submit costing to Admin?</h2>
                <p>This will send the findings and costing to Admin. Admin can approve or return it.</p>
                <div class="inspection-confirm-modal__actions">
                    <button type="button" class="btn-secondary" data-complete-inspection-cancel>Cancel</button>
                    <button type="button" class="btn-primary" data-complete-inspection-confirm>Submit Costing</button>
                </div>
            </div>
        </div>

        <div class="inspection-confirm-modal" data-inspection-action-confirm-modal hidden>
            <div class="inspection-confirm-modal__panel" role="dialog" aria-modal="true" aria-labelledby="inspectionActionConfirmTitle">
                <div class="inspection-action-confirm__head">
                    <h2 id="inspectionActionConfirmTitle" data-inspection-action-confirm-title></h2>
                    <button type="button" class="inspection-modal__close" data-inspection-action-confirm-close aria-label="Close confirmation">&times;</button>
                </div>
                <p data-inspection-action-confirm-message></p>
                <label class="inspection-action-confirm__reason" data-inspection-action-confirm-reason-wrap hidden>
                    <span>Hold Reason</span>
                    <textarea rows="3" maxlength="2000" data-inspection-action-confirm-reason aria-describedby="inspectionActionConfirmReasonError"></textarea>
                    <small id="inspectionActionConfirmReasonError" data-inspection-action-confirm-reason-error hidden aria-live="polite"></small>
                </label>
                <div class="inspection-confirm-modal__actions">
                    <button type="button" class="btn-secondary" data-inspection-action-confirm-cancel>Cancel</button>
                    <button type="button" class="btn-primary" data-inspection-action-confirm-submit></button>
                </div>
            </div>
        </div>

        <div class="inspection-confirm-modal inspection-reschedule-modal" data-engineer-reschedule-modal data-reschedule-sent="<?php echo $message === 'Reschedule request sent to Admin.' ? '1' : '0'; ?>" hidden>
            <div class="inspection-confirm-modal__panel inspection-reschedule-modal__panel" role="dialog" aria-modal="true" aria-labelledby="engineerRescheduleTitle">
                <button type="button" class="inspection-modal__close" data-engineer-reschedule-close aria-label="Close schedule change form">&times;</button>
                <h2 id="engineerRescheduleTitle">Request a Schedule Change</h2>
                <p class="inspection-reschedule-modal__notice">Your requested date will be reviewed by Admin. The official date stays unchanged until approved.</p>
                <p class="inspection-reschedule-modal__official"><span>Current official schedule</span><strong data-engineer-reschedule-official></strong></p>
                <form method="POST" data-engineer-reschedule-form novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="inspection_id" value="" data-engineer-reschedule-inspection-id>
                    <input type="hidden" name="schedule_response_action" value="request_reschedule">
                    <label class="inspection-reschedule-field">Reason for request
                        <textarea name="schedule_response_note" required minlength="5" placeholder="Explain why the schedule needs to change." data-engineer-reschedule-reason></textarea>
                        <small data-engineer-reschedule-error="reason" hidden></small>
                    </label>
                    <div class="inspection-reschedule-fields">
                        <label class="inspection-reschedule-field">Preferred new date
                            <input type="date" name="schedule_preferred_date" min="<?php echo (new DateTimeImmutable('today', site_inspection_timezone()))->format('Y-m-d'); ?>" max="<?php echo site_inspection_latest_schedule_date(); ?>" required data-engineer-reschedule-date>
                            <small data-engineer-reschedule-error="date" hidden></small>
                        </label>
                    </div>
                    <div class="inspection-confirm-modal__actions inspection-reschedule-modal__actions">
                        <button type="button" class="btn-secondary" data-engineer-reschedule-cancel>Cancel</button>
                        <button type="submit" class="btn-primary">Continue</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="inspection-confirm-modal inspection-reschedule-modal" data-engineer-reschedule-review-modal hidden>
            <div class="inspection-confirm-modal__panel inspection-reschedule-modal__panel" role="dialog" aria-modal="true" aria-labelledby="engineerRescheduleReviewTitle">
                <h2 id="engineerRescheduleReviewTitle">Review Schedule Change Request</h2>
                <p>Please check your request before sending it to Admin.</p>
                <dl class="inspection-reschedule-review" data-engineer-reschedule-review-details></dl>
                <div class="inspection-confirm-modal__actions">
                    <button type="button" class="btn-secondary" data-engineer-reschedule-review-cancel>Cancel</button>
                    <button type="button" class="btn-primary" data-engineer-reschedule-review-submit>Submit Request</button>
                </div>
            </div>
        </div>
    </div>
</main>
<?php
$engineerJsFiles = [
    '/codesamplecaps/SHARED/toast/js/toast.js',
    '/codesamplecaps/ENGINEER/js/site-inspections.js?v=20261027-8',
];
require __DIR__ . '/../layout/footer.php';
?>
