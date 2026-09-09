<?php
require_once __DIR__ . '/../../../includes/admin_auth.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/audit_log.php';
require_once __DIR__ . '/../../../../config/site_inspections.php';
require_once __DIR__ . '/../../../../config/inquiry_quotation_module.php';

$message = '';
$error = '';
$allowedStatuses = ['Pending Review', 'Verified Lead', 'Not Qualified', 'For Inspection'];
$inquiryFilterStatuses = array_merge($allowedStatuses, ['Rejected', 'Converted to Project']);

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

function inquiry_center_format_money(float $amount): string
{
    return 'PHP ' . number_format($amount, 2);
}

function inquiry_center_allowed_next_statuses(string $currentStatus): array
{
    // Status rules para hindi basta-basta tumalon ang lead sa maling stage.
    $rules = [
        'Pending Review' => ['Pending Review', 'Verified Lead', 'Not Qualified'],
        'Verified Lead' => ['Verified Lead', 'Not Qualified'],
        'For Inspection' => ['For Inspection', 'Verified Lead'],
        'Not Qualified' => ['Not Qualified', 'Pending Review'],
    ];

    return $rules[$currentStatus] ?? ['Pending Review'];
}

function inquiry_center_can_change_status(string $currentStatus, string $newStatus): bool
{
    return in_array($newStatus, inquiry_center_allowed_next_statuses($currentStatus), true);
}

function inquiry_center_has_client_quotation_approval(?string $quotationStatus): bool
{
    return inquiry_quote_normalize_status($quotationStatus) === 'accepted';
}

function inquiry_center_quotation_prerequisite_message(?array $quotationDraft): string
{
    if (!$quotationDraft) {
        return 'Create quotation before assigning Engineer or setting inspection date.';
    }

    $status = inquiry_quote_normalize_status((string)($quotationDraft['status'] ?? ''));
    if ($status === 'accepted') {
        return '';
    }

    if ($status === 'sent') {
        return 'Wait for client approval before assigning Engineer.';
    }

    if ($status === 'rejected') {
        return 'Client rejected the quotation. Review the client note before taking the next action.';
    }

    return 'Send quotation to client and wait for approval before assigning Engineer.';
}

function inquiry_center_redirect(string $view, string $message): void
{
    $_SESSION['inquiry_center_flash'] = $message;
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
    header('Location: ' . $returnUrl);
    exit();
}

function inquiry_center_redirect_with_project(int $projectId, string $message): void
{
    $_SESSION['inquiry_center_flash'] = $message;
    $_SESSION['inquiry_center_flash_project_id'] = $projectId;
    header('Location: /codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?status=For+Inspection');
    exit();
}

function inquiry_center_redirect_to_open_modal(int $inquiryId, string $status, string $message, string $tab = 'client'): void
{
    $_SESSION['inquiry_center_flash'] = $message;

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

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'poll_quotation_status') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    $inquiryIds = array_values(array_unique(array_filter(
        array_map('intval', explode(',', (string)($_GET['inquiry_ids'] ?? ''))),
        static fn(int $id): bool => $id > 0
    )));
    $inquiryIds = array_slice($inquiryIds, 0, 100);
    $statuses = [];
    $pendingUnreadInquiryCount = 0;
    $latestPendingInquiryId = 0;
    $latestRevisionId = 0;
    $latestRevisionInquiryId = 0;
    $latestRevisionUpdatedAt = '';
    $latestRejectedId = 0;
    $latestRejectedInquiryId = 0;
    $latestRejectedNote = '';
    $latestRejectedAt = '';

    if (inquiry_center_has_table($conn, 'service_inquiries')) {
        $pendingUnreadResult = $conn->query(
            "SELECT COUNT(*) AS total, MAX(id) AS latest_id
             FROM service_inquiries
             WHERE status = 'Pending Review' AND viewed_at IS NULL"
        );
        $pendingUnreadRow = $pendingUnreadResult ? $pendingUnreadResult->fetch_assoc() : [];
        $pendingUnreadInquiryCount = (int)($pendingUnreadRow['total'] ?? 0);
        $latestPendingInquiryId = (int)($pendingUnreadRow['latest_id'] ?? 0);
    }

    if ($inquiryIds && inquiry_quote_table_exists($conn, 'inquiry_quotation_drafts')) {
        $placeholders = implode(', ', array_fill(0, count($inquiryIds), '?'));
        $stmt = $conn->prepare(
            "SELECT inquiry_id, status, client_decision_note, updated_at
             FROM inquiry_quotation_drafts
             WHERE inquiry_id IN ($placeholders)
             ORDER BY updated_at DESC, id DESC"
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
            "SELECT id, inquiry_id, updated_at
             FROM inquiry_quotation_drafts
             WHERE status IN ('revision_requested', 'for_revision')
             ORDER BY updated_at DESC, id DESC
             LIMIT 1"
        );
        $latestRevision = $latestRevisionResult ? $latestRevisionResult->fetch_assoc() : null;
        $latestRevisionId = (int)($latestRevision['id'] ?? 0);
        $latestRevisionInquiryId = (int)($latestRevision['inquiry_id'] ?? 0);
        $latestRevisionUpdatedAt = (string)($latestRevision['updated_at'] ?? '');

        $latestRejectedResult = $conn->query(
            "SELECT id, inquiry_id, client_decision_note, COALESCE(client_decision_at, updated_at) AS rejected_at
             FROM inquiry_quotation_drafts
             WHERE status = 'rejected'
             ORDER BY COALESCE(client_decision_at, updated_at) DESC, id DESC
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
        'pending_unread_inquiry_count' => $pendingUnreadInquiryCount,
        'latest_pending_inquiry_id' => $latestPendingInquiryId,
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

if (isset($_GET['viewed_inquiry']) && inquiry_center_has_table($conn, 'service_inquiries')) {
    $viewedInquiryId = (int)$_GET['viewed_inquiry'];
    if ($viewedInquiryId > 0) {
        $viewStmt = $conn->prepare('UPDATE service_inquiries SET viewed_at = COALESCE(viewed_at, NOW()) WHERE id = ?');
        if ($viewStmt) {
            $viewStmt->bind_param('i', $viewedInquiryId);
            $viewStmt->execute();
        }
    }

    unset($_SESSION['super_admin_sidebar_notification_data']);
    header('Location: /codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!inquiry_center_is_valid_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid request. Please try again.';
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
        if ($archiveReason === 'Other') {
            $archiveReason = $archiveReasonOther;
        } elseif ($archiveReasonOther !== '') {
            $archiveReason .= ' - ' . $archiveReasonOther;
        }

        if ($inquiryId <= 0 || $archiveReason === '') {
            $error = 'Please add archive reason.';
        } else {
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
                throw new RuntimeException('Unable to check the inspection report.');
            }
            $inspectionStmt->bind_param('ii', $inspectionId, $inquiryId);
            $inspectionStmt->execute();
            if (!$inspectionStmt->get_result()->fetch_assoc()) {
                throw new RuntimeException('Approve the inspection report before choosing the quotation decision.');
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
                $_SESSION['projects_old_input'] = [
                    'project_name' => inquiry_quote_unique_project_title($conn, $quotation),
                    'description' => trim((string)($quotation['engineer_findings'] ?: $quotation['description'] ?? '')),
                    'contact_person' => trim((string)($quotation['client_name'] ?? '')),
                    'contact_number' => trim((string)($quotation['contact_no'] ?? '')),
                    'project_site' => trim((string)($quotation['city_municipality'] ?? '')),
                    'project_address' => trim((string)($quotation['site_address'] ?? '')),
                    'project_email' => trim((string)($quotation['email'] ?? '')),
                    'project_source' => 'inquiry_quotation',
                    'quotation_draft_id' => (string)$draftId,
                    'source_inquiry_id' => (string)$inquiryId,
                    'client_id' => !empty($recipient['client_id']) ? (string)$recipient['client_id'] : '',
                    'engineer_ids' => $engineerId > 0 ? [(string)$engineerId] : [],
                    'status' => 'pending',
                    'start_date' => date('Y-m-d'),
                    'project_start_date' => date('Y-m-d'),
                    'estimated_completion_date' => date('Y-m-d', strtotime('+7 days')),
                    'estimated_duration_days' => '7',
                    'budget_amount' => number_format((float)($quotation['grand_total'] ?? 0), 2, '.', ''),
                    'budget_notes' => 'Accepted quotation ' . (string)($quotation['quotation_no'] ?? ''),
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
                if ($isAjaxRequest) {
                    $_SESSION['inquiry_center_flash'] = 'Quotation sent to client.';
                    header('Content-Type: application/json; charset=UTF-8');
                    echo json_encode([
                        'success' => true,
                        'message' => 'Quotation sent to client.',
                        'redirect' => '/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?status=Verified+Lead&open=inquiryModal' . $inquiryId . '&tab=quotation',
                    ]);
                    exit();
                }
                inquiry_center_redirect_to_open_modal($inquiryId, 'Verified Lead', 'Quotation sent to client.');
            } catch (Throwable $throwable) {
                $error = $throwable->getMessage();
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
        } elseif ($targetReviewStatus === 'Returned' && $adminRemarks === '') {
            $error = 'Admin Remarks / Reason for Return is required.';
        } elseif (mb_strlen($adminRemarks, 'UTF-8') > 2000) {
            $error = 'Admin remarks must not exceed 2,000 characters.';
        } else {
            $conn->begin_transaction();

            try {
                $reviewStmt = $conn->prepare(
                    'SELECT status, admin_review_status, admin_remarks, submitted_at
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
                    throw new RuntimeException('Inspection report was not found.');
                }

                $currentInspectionStatus = (string)($inspectionBeforeReview['status'] ?? '');
                $currentReviewStatus = (string)($inspectionBeforeReview['admin_review_status'] ?? 'Pending');
                if (!site_inspection_can_admin_review(
                    $currentInspectionStatus,
                    $currentReviewStatus,
                    $targetReviewStatus
                )) {
                    throw new RuntimeException('This inspection report was already reviewed or is not ready for review.');
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
                    ? 'Inspection report returned to the Engineer for revision.'
                    : 'Inspection report approved.';
            } catch (Throwable $exception) {
                $conn->rollback();
                $safeReviewErrors = [
                    'Inspection report was not found.',
                    'This inspection report was already reviewed or is not ready for review.',
                    'Inspection review was not saved. Please refresh the page.',
                ];
                $error = in_array($exception->getMessage(), $safeReviewErrors, true)
                    ? $exception->getMessage()
                    : 'Failed to save the inspection review.';
            }
        }
    } elseif (($_POST['action'] ?? '') === 'schedule_inspection') {
        $inquiryId = (int)($_POST['inquiry_id'] ?? 0);
        $engineerId = (int)($_POST['engineer_id'] ?? 0);
        $acceptedQuotationId = 0;
        $scheduledAt = trim((string)($_POST['scheduled_at'] ?? ''));
        if ($scheduledAt === '') {
            $scheduleDate = trim((string)($_POST['inspection_date'] ?? ''));
            $scheduleTime = trim((string)($_POST['inspection_time'] ?? ''));
            $scheduledAt = ($scheduleDate !== '' && $scheduleTime !== '') ? $scheduleDate . ' ' . $scheduleTime : '';
        }
        $siteNotes = trim((string)($_POST['site_notes'] ?? ''));
        $scheduleTimestamp = $scheduledAt !== '' ? strtotime($scheduledAt) : false;
        $scheduleTime = $scheduleTimestamp !== false ? date('H:i', $scheduleTimestamp) : '';
        $allowedInspectionTimes = ['08:00', '09:00', '10:00', '11:00', '13:00', '14:00', '15:00', '16:00'];

        if ($inquiryId <= 0 || $engineerId <= 0 || $scheduleTimestamp === false) {
            $error = 'Please select engineer and valid inspection schedule.';
        } elseif (!in_array($scheduleTime, $allowedInspectionTimes, true)) {
            $error = 'Please select a valid working-hour inspection time.';
        } elseif ($scheduleTimestamp < (time() + (30 * 60))) {
            $error = 'Please select an inspection time at least 30 minutes from now.';
        } else {
            $currentInquiryStatus = '';
            $statusStmt = $conn->prepare('SELECT status FROM service_inquiries WHERE id = ? LIMIT 1');
            if ($statusStmt) {
                $statusStmt->bind_param('i', $inquiryId);
                $statusStmt->execute();
                $statusRow = $statusStmt->get_result()->fetch_assoc();
                $currentInquiryStatus = (string)($statusRow['status'] ?? '');
            }

            if (!in_array($currentInquiryStatus, ['Verified Lead', 'For Inspection'], true)) {
                $error = 'Only verified leads can be scheduled for site inspection.';
            }

            if ($error === '') {
                $quotationStmt = $conn->prepare(
                    'SELECT id, status FROM inquiry_quotation_drafts
                     WHERE inquiry_id = ?
                     ORDER BY updated_at DESC, id DESC
                     LIMIT 1'
                );
                if (!$quotationStmt) {
                    $error = 'Unable to check the quotation status.';
                } else {
                    $quotationStmt->bind_param('i', $inquiryId);
                    $quotationStmt->execute();
                    $quotationRow = $quotationStmt->get_result()->fetch_assoc();
                    if (!inquiry_center_has_client_quotation_approval((string)($quotationRow['status'] ?? ''))) {
                        $error = 'Client must accept the quotation before assigning an engineer or scheduling inspection.';
                    } else {
                        $acceptedQuotationId = (int)$quotationRow['id'];
                    }
                }
            }
        }

        if ($error === '') {
            $scheduleValue = date('Y-m-d H:i:s', $scheduleTimestamp);
            $existingInspectionId = 0;
            $existingInspectionStatus = '';
            $existingStmt = $conn->prepare('SELECT id, status FROM site_inspections WHERE inquiry_id = ? ORDER BY id DESC LIMIT 1');
            if ($existingStmt) {
                $existingStmt->bind_param('i', $inquiryId);
                $existingStmt->execute();
                $existingRow = $existingStmt->get_result()->fetch_assoc();
                $existingInspectionId = (int)($existingRow['id'] ?? 0);
                $existingInspectionStatus = (string)($existingRow['status'] ?? '');
            }

            if ($existingInspectionId > 0 && $existingInspectionStatus !== 'Assigned') {
                $error = 'The Engineer already started this inspection workflow. Its assignment and schedule are now locked.';
            }

            $stmt = null;
            if ($error === '') {
                $stmt = $existingInspectionId > 0
                    ? $conn->prepare(
                        'UPDATE site_inspections
                         SET engineer_id = ?, scheduled_at = ?, site_notes = ?
                         WHERE id = ? AND status = \'Assigned\''
                    )
                    : $conn->prepare(
                        "INSERT INTO site_inspections (inquiry_id, engineer_id, scheduled_at, site_notes, status, created_by)
                         VALUES (?, ?, ?, ?, 'Assigned', ?)"
                    );
            }

            if ($error === '' && !$stmt) {
                $error = 'Failed to prepare inspection schedule.';
            } elseif ($error === '' && $stmt) {
                $createdBy = (int)($_SESSION['user_id'] ?? 0);
                if ($existingInspectionId > 0) {
                    $stmt->bind_param('issi', $engineerId, $scheduleValue, $siteNotes, $existingInspectionId);
                } else {
                    $stmt->bind_param('iissi', $inquiryId, $engineerId, $scheduleValue, $siteNotes, $createdBy);
                }
                if ($stmt->execute()) {
                    $savedInspectionId = $existingInspectionId > 0 ? $existingInspectionId : (int)$conn->insert_id;
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
                        'service_inquiry',
                        $inquiryId,
                        null,
                        [
                            'engineer_id' => $engineerId,
                            'scheduled_at' => $scheduleValue,
                        ]
                    );
                    try {
                        inquiry_quote_send_final_confirmation($conn, $acceptedQuotationId);
                        $message = $existingInspectionId > 0
                            ? 'Inspection schedule updated and final quotation emailed.'
                            : 'Inspection finalized and final quotation emailed.';
                    } catch (Throwable $mailThrowable) {
                        $error = $mailThrowable->getMessage();
                    }
                } else {
                    $error = 'Failed to save inspection schedule.';
                }
            }
        }
    } else {
        $inquiryId = (int)($_POST['inquiry_id'] ?? 0);
        $newStatus = trim((string)($_POST['status'] ?? ''));
        $adminNotes = trim((string)($_POST['admin_notes'] ?? ''));

        if ($inquiryId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
            $error = 'Invalid inquiry update request.';
        } elseif ($newStatus === 'Pending Review') {
            $error = 'Please update the status before saving.';
        } else {
            $stmt = $conn->prepare('SELECT status, admin_notes FROM service_inquiries WHERE id = ? LIMIT 1');
            if (!$stmt) {
                $error = 'Unable to load inquiry.';
            } else {
                $stmt->bind_param('i', $inquiryId);
                $stmt->execute();
                $current = $stmt->get_result()->fetch_assoc();

                if (!$current) {
                    $error = 'Inquiry not found.';
                } elseif (!inquiry_center_can_change_status((string)($current['status'] ?? 'Pending Review'), $newStatus)) {
                    $error = 'This status change is not allowed for the current inquiry stage.';
                } else {
                    $updateStmt = $conn->prepare(
                        'UPDATE service_inquiries
                         SET status = ?, admin_notes = ?, reviewed_at = NOW()
                         WHERE id = ?'
                    );

                    if (!$updateStmt) {
                        $error = 'Failed to prepare inquiry update.';
                    } else {
                        $updateStmt->bind_param('ssi', $newStatus, $adminNotes, $inquiryId);
                        if ($updateStmt->execute()) {
                            audit_log_event(
                                $conn,
                                (int)($_SESSION['user_id'] ?? 0),
                                'update_inquiry_status',
                                'service_inquiry',
                                $inquiryId,
                                [
                                    'status' => (string)($current['status'] ?? ''),
                                    'admin_notes' => (string)($current['admin_notes'] ?? ''),
                                ],
                                [
                                    'status' => $newStatus,
                                    'admin_notes' => $adminNotes,
                                ]
                            );
                            $successMessage = $newStatus === 'Verified Lead'
                                ? 'Inquiry marked as verified.'
                                : 'Inquiry updated successfully.';
                            $targetTab = $newStatus === 'Verified Lead' ? 'quotation' : 'client';

                            if ($isAjaxRequest) {
                                $_SESSION['inquiry_center_flash'] = $successMessage;
                                header('Content-Type: application/json; charset=UTF-8');
                                echo json_encode([
                                    'success' => true,
                                    'status' => $newStatus,
                                    'target_tab' => $targetTab,
                                    'redirect' => '/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?' . http_build_query([
                                        'status' => $newStatus,
                                        'open' => 'inquiryModal' . $inquiryId,
                                        'tab' => $targetTab,
                                    ]),
                                ]);
                                exit();
                            }

                            inquiry_center_redirect_to_open_modal($inquiryId, $newStatus, $successMessage, $targetTab);
                        } else {
                            $error = 'Failed to update inquiry.';
                        }
                    }
                }
            }
        }
    }
}

if ($isAjaxRequest && $error !== '') {
    http_response_code(422);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'message' => $error,
    ]);
    exit();
}

$message = (string)($_SESSION['inquiry_center_flash'] ?? $message);
$flashProjectId = (int)($_SESSION['inquiry_center_flash_project_id'] ?? 0);
unset($_SESSION['inquiry_center_flash']);
unset($_SESSION['inquiry_center_flash_project_id']);

$engineers = [];
$engineerResult = $conn->query("SELECT id, full_name FROM users WHERE role = 'engineer' AND status = 'active' ORDER BY full_name ASC");
if ($engineerResult) {
    $engineers = $engineerResult->fetch_all(MYSQLI_ASSOC);
}

$statusFilter = trim((string)($_GET['status'] ?? ''));
$search = trim((string)($_GET['search'] ?? ''));
$view = trim((string)($_GET['view'] ?? 'active'));
if (!in_array($view, ['active', 'archive'], true)) {
    $view = 'active';
}
if (!in_array($statusFilter, $inquiryFilterStatuses, true)) {
    $statusFilter = '';
}

$hasInquiryQuotationTable = inquiry_quote_table_exists($conn, 'inquiry_quotation_drafts');
$hasQuotationProjectLink = $hasInquiryQuotationTable
    && inquiry_quote_column_exists($conn, 'inquiry_quotation_drafts', 'project_id');
$inquiryRows = [];
if (inquiry_center_has_table($conn, 'service_inquiries')) {
    $where = [];
    $types = '';
    $params = [];

    $where[] = $view === 'archive' ? 'archived_at IS NOT NULL' : 'archived_at IS NULL';

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
        $where[] = 'status = ?';
        $types .= 's';
        $params[] = $statusFilter;

        if ($hasQuotationProjectLink) {
            $where[] = 'NOT EXISTS (
                SELECT 1 FROM inquiry_quotation_drafts quote_filter
                WHERE quote_filter.inquiry_id = service_inquiries.id
                AND quote_filter.project_id IS NOT NULL
            )';
        }
    }

    if ($view === 'active' && $statusFilter !== 'Rejected' && $hasInquiryQuotationTable) {
        $where[] = "NOT EXISTS (
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

    if ($search !== '') {
        // Smart search: hanapin sa important fields para mas mabilis ang lead filtering.
        $where[] = '(client_name LIKE ? OR company_name LIKE ? OR email LIKE ? OR contact_no LIKE ? OR province LIKE ? OR city_municipality LIKE ? OR barangay LIKE ? OR site_address LIKE ? OR service_category LIKE ? OR status LIKE ? OR description LIKE ? OR admin_notes LIKE ? OR archive_reason LIKE ?)';
        $keyword = '%' . $search . '%';
        $types .= 'sssssssssssss';
        array_push($params, $keyword, $keyword, $keyword, $keyword, $keyword, $keyword, $keyword, $keyword, $keyword, $keyword, $keyword, $keyword, $keyword);
    }

    $sql = 'SELECT id, client_name, company_name, email, contact_no, site_address,
                   province, city_municipality, barangay, service_category, description, preferred_inspection_date,
                   status, admin_notes, reviewed_at, viewed_at, archived_at, archive_reason, created_at
            FROM service_inquiries';
    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY created_at DESC';

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
}

$inspectionByInquiry = [];
$inspectionResult = $conn->query(
    "SELECT
        si.id,
        si.inquiry_id,
        si.engineer_id,
        si.scheduled_at,
        si.status,
        si.created_at,
        si.acknowledged_at,
        si.started_at,
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
$costingResult = $conn->query(
    "SELECT inspection_id, item_type, item_name, quantity, unit, unit_cost, line_total, notes
     FROM site_inspection_cost_items
     ORDER BY id ASC"
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
        "SELECT id, updated_at
         FROM inquiry_quotation_drafts
         WHERE status IN ('revision_requested', 'for_revision')
         ORDER BY updated_at DESC, id DESC
         LIMIT 1"
    );
    $latestRevision = $latestRevisionResult ? $latestRevisionResult->fetch_assoc() : null;
    $latestRevisionId = (int)($latestRevision['id'] ?? 0);
    $latestRevisionUpdatedAt = (string)($latestRevision['updated_at'] ?? '');

    $latestRejectedResult = $conn->query(
        "SELECT id, COALESCE(client_decision_at, updated_at) AS rejected_at
         FROM inquiry_quotation_drafts
         WHERE status = 'rejected'
         ORDER BY COALESCE(client_decision_at, updated_at) DESC, id DESC
         LIMIT 1"
    );
    $latestRejected = $latestRejectedResult ? $latestRejectedResult->fetch_assoc() : null;
    $latestRejectedId = (int)($latestRejected['id'] ?? 0);
    $latestRejectedAt = (string)($latestRejected['rejected_at'] ?? '');
}
$pendingUnreadInquiryCount = 0;
if (inquiry_center_has_table($conn, 'service_inquiries')) {
    $pendingUnreadResult = $conn->query(
        "SELECT COUNT(*) AS total
         FROM service_inquiries
         WHERE status = 'Pending Review' AND viewed_at IS NULL"
    );
    $pendingUnreadInquiryCount = (int)(($pendingUnreadResult ? $pendingUnreadResult->fetch_assoc() : [])['total'] ?? 0);
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
    '/codesamplecaps/ADMIN/sidebar/inquiries/css/inquiries.css',
];
$adminJsFiles = [
    '/codesamplecaps/ADMIN/common/js/admin-common.js',
    '/codesamplecaps/SHARED/toast/js/toast.js',
    '/codesamplecaps/ADMIN/sidebar/inquiries/js/inquiries.js',
];
include __DIR__ . '/../../../layout/header.php';
include __DIR__ . '/../../../admin_sidebar.php';
?>

<main class="main-content admin-dashboard-content">
    <div
        class="inquiries-shell"
        data-pending-unread-inquiry-count="<?php echo $pendingUnreadInquiryCount; ?>"
        data-latest-revision-id="<?php echo $latestRevisionId; ?>"
        data-latest-revision-updated-at="<?php echo htmlspecialchars($latestRevisionUpdatedAt, ENT_QUOTES, 'UTF-8'); ?>"
        data-latest-rejected-id="<?php echo $latestRejectedId; ?>"
        data-latest-rejected-at="<?php echo htmlspecialchars($latestRejectedAt, ENT_QUOTES, 'UTF-8'); ?>"
    >
        <?php if ($message || $error): ?>
            <div
                class="shared-toast <?php echo $message ? 'shared-toast--success' : 'shared-toast--error'; ?>"
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



        <form class="inquiry-filter-bar" method="GET">
            <input type="search" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search name, email, contact, status, notes, address, service, or archive reason">
            <select name="status">
                <option value="">All statuses</option>
                <?php foreach ($inquiryFilterStatuses as $status): ?>
                    <option value="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="inquiry-filter-actions">
                <button type="submit" class="btn-primary">Filter</button>
                <a href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php" class="btn-secondary">Reset</a>
            </div>
        </form>

        <div class="inquiry-status-strip" aria-label="Inquiry status summary">
            <a class="inquiry-view-link <?php echo $view === 'active' ? 'is-active' : ''; ?>" href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php">Active</a>
            <a class="inquiry-status inquiry-status-link <?php echo $statusFilter === 'Pending Review' ? 'is-active' : ''; ?>" data-status="Pending Review" href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?status=Pending+Review<?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>">Pending: <?php echo $pendingCount; ?></a>
            <a class="inquiry-status inquiry-status-link <?php echo $statusFilter === 'Verified Lead' ? 'is-active' : ''; ?>" data-status="Verified Lead" href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?status=Verified+Lead<?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>">Verified: <?php echo $verifiedCount; ?></a>
            <a class="inquiry-status inquiry-status-link <?php echo $statusFilter === 'For Inspection' ? 'is-active' : ''; ?>" data-status="For Inspection" href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?status=For+Inspection<?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>">For Inspection: <?php echo $inspectionCount; ?></a>
            <a class="inquiry-status inquiry-status-link <?php echo $statusFilter === 'Rejected' ? 'is-active' : ''; ?>" data-status="Rejected" href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?status=Rejected<?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>">Rejected: <?php echo $rejectedCount; ?></a>
            <a class="inquiry-status inquiry-status-link <?php echo $statusFilter === 'Not Qualified' ? 'is-active' : ''; ?>" data-status="Not Qualified" href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?status=Not+Qualified<?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>">Not Qualified: <?php echo $notQualifiedCount; ?></a>
            <a class="inquiry-view-link <?php echo $view === 'archive' ? 'is-active' : ''; ?>" href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?view=archive">Archive</a>
        </div>

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
                    <?php $latestCostTotal = (float)($costingReview['costing_total'] ?? 0); ?>
                    <?php $quotationDraft = $quotationDraftByInquiry[(int)$inquiry['id']] ?? null; ?>
                    <?php $originalQuotation = $originalQuotationByInquiry[(int)$inquiry['id']] ?? $quotationDraft; ?>
                    <?php $postInspectionDecision = $latestInspection ? ($postInspectionDecisionByInspection[(int)$latestInspection['id']] ?? null) : null; ?>
                    <?php $quotationListStatus = $quotationDraft ? inquiry_quote_normalize_status((string)$quotationDraft['status']) : ''; ?>
                    <?php $isConvertedToProject = !empty($quotationDraft['project_id']); ?>
                    <?php $displayStatus = $isConvertedToProject ? 'Converted to Project' : ($quotationListStatus === 'rejected' ? 'Rejected' : $currentStatus); ?>
                    <?php
                        $addressParts = array_filter([
                            trim((string)($inquiry['site_address'] ?? '')),
                            trim((string)($inquiry['barangay'] ?? '')),
                            trim((string)($inquiry['city_municipality'] ?? '')),
                            trim((string)($inquiry['province'] ?? '')),
                        ], static fn(string $part): bool => $part !== '');
                        $fullAddress = $addressParts ? implode(', ', $addressParts) : 'Not set';
                    ?>
                    <?php $showCosting = $costingReview && !empty($latestCostItems); ?>
                    <?php
                        $nextActionLabel = 'Review Inquiry';
                        $nextActionTab = 'client';
                        $quotationStage = $quotationListStatus;
                        $canScheduleInspection = inquiry_center_has_client_quotation_approval($quotationStage);
                        $quotationPrerequisiteMessage = inquiry_center_quotation_prerequisite_message($quotationDraft);
                        $showInspection = $latestInspection || $canScheduleInspection;
                        $showQuotation = $quotationDraft || $showCosting || $currentStatus === 'Verified Lead';

                        if ($quotationStage === 'accepted') {
                            $nextActionLabel = $latestInspection ? 'View Inspection' : 'Schedule Inspection';
                            $nextActionTab = 'inspection';
                        } elseif ($quotationStage === 'sent') {
                            $nextActionLabel = 'Waiting for Client';
                            $nextActionTab = 'quotation';
                        } elseif ($quotationStage === 'revision_requested') {
                            $nextActionLabel = 'Review Revision';
                            $nextActionTab = 'quotation';
                        } elseif (in_array($quotationStage, ['draft', 'approved', 'rejected'], true)) {
                            $nextActionLabel = $quotationStage === 'approved' ? 'Send Quotation' : 'Review Quotation';
                            $nextActionTab = 'quotation';
                        } elseif ($showCosting) {
                            $nextActionLabel = 'Prepare Quotation';
                            $nextActionTab = 'quotation';
                        } elseif ($latestInspection) {
                            $nextActionLabel = (string)($latestInspection['status'] ?? '') === 'Submitted'
                                ? 'Review Costing'
                                : 'View Inspection';
                            $nextActionTab = (string)($latestInspection['status'] ?? '') === 'Submitted' && $showCosting
                                ? 'quotation'
                                : 'inspection';
                        } elseif ($currentStatus === 'Verified Lead') {
                            $nextActionLabel = 'Create Quotation';
                            $nextActionTab = 'quotation';
                        } elseif ($currentStatus === 'Not Qualified') {
                            $nextActionLabel = 'View Review';
                        }
                    ?>
                    <article class="inquiry-card <?php echo $isViewed ? 'is-viewed' : 'is-unviewed'; ?>" data-inquiry-card-id="<?php echo (int)$inquiry['id']; ?>">
                        <div class="inquiry-card__head">
                            <div class="inquiry-card__identity">
                                <span class="inquiry-card__eyebrow">Contact Person</span>
                                <h2><?php echo htmlspecialchars((string)$inquiry['client_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                            </div>
                            <span class="inquiry-status" data-status="<?php echo htmlspecialchars($displayStatus, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($displayStatus, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>

                        <div class="inquiry-card__summary">
                            <div class="inquiry-card__info">
                                <span>Company</span>
                                <strong><?php echo htmlspecialchars((string)($inquiry['company_name'] ?: 'Individual client'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                            <div class="inquiry-card__info">
                                <span>Service</span>
                                <strong><?php echo htmlspecialchars((string)$inquiry['service_category'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                            <div class="inquiry-card__info">
                                <span>Location</span>
                                <strong><?php echo htmlspecialchars(trim((string)($inquiry['city_municipality'] ?: $inquiry['province'] ?: 'Not set')), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                            <div class="inquiry-card__info">
                                <span>Submitted</span>
                                <strong><?php echo htmlspecialchars(inquiry_center_format_date($inquiry['created_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                        </div>

                        <?php if ($isConvertedToProject): ?>
                            <a class="inquiry-next-action" href="/codesamplecaps/ADMIN/sidebar/projects/php/project_details.php?id=<?php echo (int)$quotationDraft['project_id']; ?>">
                                Open Project
                            </a>
                        <?php else: ?>
                            <button
                                type="button"
                                class="inquiry-open-modal inquiry-next-action"
                                data-inquiry-modal-open="inquiryModal<?php echo (int)$inquiry['id']; ?>"
                                data-inquiry-open-tab="<?php echo htmlspecialchars($nextActionTab, ENT_QUOTES, 'UTF-8'); ?>"
                            >
                                <?php echo htmlspecialchars($nextActionLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </button>
                        <?php endif; ?>

                        <div class="inquiry-modal" id="inquiryModal<?php echo (int)$inquiry['id']; ?>" data-inquiry-id="<?php echo (int)$inquiry['id']; ?>" data-quotation-status="<?php echo htmlspecialchars($quotationStage, ENT_QUOTES, 'UTF-8'); ?>" hidden>
                            <div class="inquiry-modal__panel" role="dialog" aria-modal="true" aria-labelledby="inquiryModalTitle<?php echo (int)$inquiry['id']; ?>">
                                <div class="inquiry-modal__head">
                                    <div>
                                        <span class="reports-kicker">Inquiry Review</span>
                                        <h2 id="inquiryModalTitle<?php echo (int)$inquiry['id']; ?>"><?php echo htmlspecialchars((string)$inquiry['client_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                                        <div class="inquiry-modal__meta">
                                            <span><?php echo htmlspecialchars((string)$inquiry['service_category'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span class="inquiry-status inquiry-status--modal" data-modal-status-chip data-status="<?php echo htmlspecialchars($displayStatus, ENT_QUOTES, 'UTF-8'); ?>">
                                                <?php echo htmlspecialchars($displayStatus, ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <?php if (!empty($inquiry['archived_at'])): ?>
                                        <div class="inquiry-modal__summary-card inquiry-modal__summary-card--archive">
                                            <strong>Archived</strong>
                                            <span><?php echo htmlspecialchars(inquiry_center_format_datetime($inquiry['archived_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span>Reason: <?php echo htmlspecialchars((string)($inquiry['archive_reason'] ?: 'No reason'), ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                    <?php elseif ($latestInspection): ?>
                                        <div class="inquiry-modal__summary-card inquiry-modal__summary-card--schedule">
                                            <strong>Latest Schedule</strong>
                                            <span>Engineer: <?php echo htmlspecialchars((string)$latestInspection['engineer_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span>Date/Time: <?php echo htmlspecialchars(site_inspection_format_datetime($latestInspection['scheduled_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></span>
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
                                        <button type="button" class="inquiry-modal__close" data-inquiry-modal-close aria-label="Close inquiry review">&times;</button>
                                    </div>
                                </div>
                                <div class="inquiry-modal-tabs" role="tablist" aria-label="Inquiry review sections">
                                    <button type="button" class="inquiry-modal-tab is-active" data-inquiry-tab="client">Contact &amp; Review</button>
                                    <button type="button" class="inquiry-modal-tab<?php echo !$showQuotation ? ' chip-disabled' : ''; ?>" data-inquiry-tab="quotation" aria-disabled="<?php echo !$showQuotation ? 'true' : 'false'; ?>">Quotation</button>
                                    <button type="button" class="inquiry-modal-tab<?php echo $latestInspection ? ' has-data' : ''; ?><?php echo !$showInspection ? ' chip-disabled' : ''; ?>" data-inquiry-tab="inspection" data-inquiry-stage="inspection" aria-disabled="<?php echo !$showInspection ? 'true' : 'false'; ?>">Inspection</button>
                                </div>
                            <div class="inquiry-modal-panels">
                                <section class="inquiry-tab-panel is-active" data-inquiry-panel="client">
                                    <div class="inquiry-section-title">Contact and Request Details</div>
                                    <div class="inquiry-details-grid">
                                        <div class="inquiry-detail"><span>Contact Person</span><strong><?php echo htmlspecialchars((string)$inquiry['client_name'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                        <div class="inquiry-detail"><span>Email</span><strong><?php echo htmlspecialchars((string)$inquiry['email'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                        <div class="inquiry-detail"><span>Contact Number</span><strong><?php echo htmlspecialchars((string)$inquiry['contact_no'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                        <div class="inquiry-detail"><span>Company</span><strong><?php echo htmlspecialchars((string)($inquiry['company_name'] ?: 'N/A'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                        <div class="inquiry-detail inquiry-detail--wide"><span>Complete Address</span><strong><?php echo htmlspecialchars($fullAddress, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                        <div class="inquiry-detail"><span>Preferred Date</span><strong><?php echo htmlspecialchars(inquiry_center_format_date($inquiry['preferred_inspection_date'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                        <div class="inquiry-detail"><span>Submitted</span><strong><?php echo htmlspecialchars(inquiry_center_format_datetime($inquiry['created_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                        <div class="inquiry-detail"><span>Reviewed</span><strong><?php echo htmlspecialchars(!empty($inquiry['reviewed_at']) ? inquiry_center_format_datetime($inquiry['reviewed_at']) : 'Not yet', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                    </div>

                                    <div class="inquiry-description">
                                        <strong>Project Description</strong><br>
                                        <?php echo nl2br(htmlspecialchars((string)$inquiry['description'], ENT_QUOTES, 'UTF-8')); ?>
                                    </div>

                                    <hr class="inquiry-review-divider">
                                    <div class="inquiry-section-title inquiry-review-section-title">Admin Review &amp; Actions</div>
                                    <?php if ($isConvertedToProject): ?>
                                        <div class="inquiry-readonly-notice">
                                            This inquiry is already converted to a Project.
                                            <a href="/codesamplecaps/ADMIN/sidebar/projects/php/project_details.php?id=<?php echo (int)$quotationDraft['project_id']; ?>">Open Project</a>
                                        </div>
                                    <?php elseif (!empty($inquiry['archived_at'])): ?>
                                        <div class="inquiry-readonly-notice">
                                            This inquiry is archived. Restore it first before changing status, notes, or inspection schedule.
                                        </div>
                                    <?php else: ?>
                                        <form method="POST" class="inquiry-review-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry['id']; ?>">
                                            <label class="inquiry-review-form__status">
                                                <span>Status</span>
                                                <select name="status" required>
                                                    <?php foreach (inquiry_center_allowed_next_statuses($currentStatus) as $status): ?>
                                                        <option value="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $currentStatus === $status ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label class="inquiry-review-form__notes">
                                                <span>Admin Notes</span>
                                                <textarea name="admin_notes" rows="5" placeholder="Call result, scope clarification, or validation notes..."><?php echo htmlspecialchars((string)($inquiry['admin_notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                                            </label>
                                            <div class="inquiry-review-actions inquiry-review-form__actions">
                                                <button type="submit" class="btn-primary" aria-disabled="<?php echo $currentStatus === 'Pending Review' ? 'true' : 'false'; ?>">Save Review</button>
                                            </div>
                                        </form>
                                    <?php endif; ?>

                                    <?php if (!empty($inquiry['archived_at'])): ?>
                                        <div class="inquiry-archive-output">
                                            <strong>Archived</strong>
                                            <span><?php echo htmlspecialchars(inquiry_center_format_datetime($inquiry['archived_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span>Reason: <?php echo htmlspecialchars((string)($inquiry['archive_reason'] ?: 'No reason'), ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </section>

                                <section class="inquiry-tab-panel" data-inquiry-panel="inspection" hidden>
                                    <div class="inquiry-section-title">Inspection</div>
                                    <?php if ($latestInspection): ?>
                                        <?php
                                        $latestInspectionStatus = (string)($latestInspection['status'] ?? 'Assigned');
                                        $latestInspectionStatusAt = match ($latestInspectionStatus) {
                                            'Assigned' => $latestInspection['created_at'] ?? null,
                                            'Acknowledged' => $latestInspection['acknowledged_at'] ?? null,
                                            'Ongoing' => $latestInspection['started_at'] ?? null,
                                            'Completed' => $latestInspection['completed_at'] ?? null,
                                            'Submitted' => $latestInspection['submitted_at'] ?? null,
                                            default => $latestInspection['updated_at'] ?? null,
                                        };
                                        ?>
                                        <div class="inquiry-details-grid">
                                            <div class="inquiry-detail"><span>Engineer</span><strong><?php echo htmlspecialchars((string)$latestInspection['engineer_name'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                            <div class="inquiry-detail"><span>Date / Time</span><strong><?php echo htmlspecialchars(site_inspection_format_datetime($latestInspection['scheduled_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                            <div class="inquiry-detail"><span>Status</span><strong class="inquiry-status" data-status="<?php echo htmlspecialchars($latestInspectionStatus, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($latestInspectionStatus, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                            <div class="inquiry-detail"><span>Status Date / Time</span><strong><?php echo htmlspecialchars(site_inspection_format_datetime($latestInspectionStatusAt), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                            <div class="inquiry-detail inquiry-detail--wide"><span>Site Notes</span><strong><?php echo htmlspecialchars((string)($latestInspection['site_notes'] ?: 'No notes'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                        </div>

                                        <?php if (!empty($latestInspection['submitted_at'])): ?>
                                            <?php
                                            $inspectionReviewStatus = (string)($latestInspection['admin_review_status'] ?? 'Pending');
                                            $inspectionDisplayStatus = $latestInspectionStatus === 'Completed' && $inspectionReviewStatus === 'Returned'
                                                ? 'Returned for Revision'
                                                : $latestInspectionStatus;
                                            $inspectionTimeline = [
                                                'Assigned' => $latestInspection['created_at'] ?? null,
                                                'Acknowledged' => $latestInspection['acknowledged_at'] ?? null,
                                                'Ongoing' => $latestInspection['started_at'] ?? null,
                                                'Completed' => $latestInspection['completed_at'] ?? null,
                                                'Submitted' => $latestInspection['submitted_at'] ?? null,
                                            ];
                                            ?>
                                            <section class="submitted-inspection-report">
                                                <div class="submitted-inspection-report__head">
                                                    <div>
                                                        <span>Submitted Inspection Report</span>
                                                        <h3><?php echo htmlspecialchars((string)$latestInspection['engineer_name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                                                    </div>
                                                    <span class="inquiry-status" data-status="<?php echo htmlspecialchars($inspectionDisplayStatus, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($inspectionDisplayStatus, ENT_QUOTES, 'UTF-8'); ?></span>
                                                </div>

                                                <div class="inquiry-details-grid submitted-inspection-report__details">
                                                    <div class="inquiry-detail"><span>Scheduled</span><strong><?php echo htmlspecialchars(site_inspection_format_datetime($latestInspection['scheduled_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                                    <div class="inquiry-detail"><span>Actual Started</span><strong><?php echo htmlspecialchars(site_inspection_format_datetime($latestInspection['started_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                                    <div class="inquiry-detail"><span>Actual Completed</span><strong><?php echo htmlspecialchars(site_inspection_format_datetime($latestInspection['completed_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                                    <div class="inquiry-detail"><span>Submitted</span><strong><?php echo htmlspecialchars(site_inspection_format_datetime($latestInspection['submitted_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                                    <div class="inquiry-detail inquiry-detail--wide"><span>Site Address</span><strong><?php echo htmlspecialchars($fullAddress, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                                    <div class="inquiry-detail inquiry-detail--wide"><span>Engineer Findings</span><strong><?php echo nl2br(htmlspecialchars((string)($latestInspection['engineer_findings'] ?: 'No findings provided.'), ENT_QUOTES, 'UTF-8')); ?></strong></div>
                                                    <div class="inquiry-detail"><span>Risk / Safety Notes</span><strong><?php echo nl2br(htmlspecialchars((string)($latestInspection['risk_notes'] ?: 'None'), ENT_QUOTES, 'UTF-8')); ?></strong></div>
                                                    <div class="inquiry-detail"><span>Client Requests</span><strong><?php echo nl2br(htmlspecialchars((string)($latestInspection['client_requests'] ?: 'None'), ENT_QUOTES, 'UTF-8')); ?></strong></div>
                                                </div>

                                                <div class="submitted-inspection-timeline" aria-label="Inspection status timeline">
                                                    <?php foreach ($inspectionTimeline as $timelineStatus => $timelineTime): ?>
                                                        <div class="submitted-inspection-timeline__step <?php echo $timelineTime ? 'is-done' : ''; ?>">
                                                            <strong><?php echo htmlspecialchars($timelineStatus, ENT_QUOTES, 'UTF-8'); ?></strong>
                                                            <span><?php echo htmlspecialchars(site_inspection_format_datetime($timelineTime), ENT_QUOTES, 'UTF-8'); ?></span>
                                                        </div>
                                                    <?php endforeach; ?>
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
                                                            <span><?php echo htmlspecialchars(ucfirst((string)$costItem['item_type']), ENT_QUOTES, 'UTF-8'); ?></span>
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
                                                    <span>Grand Total</span>
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

                                                <?php if ($latestInspectionStatus === 'Submitted' && $inspectionReviewStatus === 'Pending'): ?>
                                                    <form method="POST" class="submitted-inspection-review-form">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="action" value="review_inspection_report">
                                                        <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry['id']; ?>">
                                                        <input type="hidden" name="inspection_id" value="<?php echo (int)$latestInspection['id']; ?>">
                                                        <label>
                                                            <span>Admin Remarks / Reason for Return</span>
                                                            <textarea name="admin_remarks" rows="3" maxlength="2000" placeholder="Required only when returning the report to the Engineer"></textarea>
                                                        </label>
                                                        <div class="inquiry-review-actions">
                                                            <button type="submit" name="review_decision" value="return" class="btn-secondary">Return to Engineer</button>
                                                            <button type="submit" name="review_decision" value="approve" class="btn-primary">Approve Inspection Report</button>
                                                        </div>
                                                    </form>
                                                <?php endif; ?>
                                            </section>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="inquiry-empty">No inspection schedule yet.</div>
                                    <?php endif; ?>

                                    <?php if (in_array($quotationStage, ['sent', 'accepted'], true) && in_array($currentStatus, ['Verified Lead', 'For Inspection'], true)): ?>
                                        <?php $inspectionTimestamp = !empty($latestInspection['scheduled_at']) ? strtotime((string)$latestInspection['scheduled_at']) : false; ?>
                                        <?php $inspectionScheduleLocked = $latestInspection && (string)($latestInspection['status'] ?? '') !== 'Assigned'; ?>
                                        <?php if ($inspectionScheduleLocked): ?>
                                            <div class="inquiry-empty">The Engineer has acknowledged or started this inspection. Assignment and schedule changes are locked.</div>
                                        <?php endif; ?>
                                        <form method="POST" class="inquiry-schedule-form" data-inquiry-inspection-form <?php echo !$canScheduleInspection || $inspectionScheduleLocked ? 'hidden' : ''; ?>>
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="schedule_inspection">
                                            <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry['id']; ?>">
                                            <strong><?php echo $latestInspection ? 'Update Final Inspection Schedule' : 'Set Final Inspection Schedule'; ?></strong>
                                            <div class="inquiry-schedule-grid">
                                                <label>
                                                    <span>Engineer</span>
                                                    <select name="engineer_id" required>
                                                        <option value="">Select engineer</option>
                                                        <?php foreach ($engineers as $engineer): ?>
                                                            <option value="<?php echo (int)$engineer['id']; ?>" <?php echo (int)($latestInspection['engineer_id'] ?? 0) === (int)$engineer['id'] ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars((string)$engineer['full_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </label>
                                                <label class="admin-date-field">
                                                    <span>Inspection Date</span>
                                                    <span class="admin-date-input-wrap">
                                                        <input type="date" class="js-admin-inspection-date" name="inspection_date" value="<?php echo $inspectionTimestamp ? date('Y-m-d', $inspectionTimestamp) : ''; ?>" required>
                                                        <button class="admin-date-picker-button js-admin-date-picker-button" type="button" aria-label="Open inspection calendar">&#128197;</button>
                                                    </span>
                                                    <small class="admin-date-tooltip js-admin-date-tooltip">Final schedule is subject to engineer availability.</small>
                                                </label>
                                                <label>
                                                    <span>Inspection Time</span>
                                                    <select class="js-admin-inspection-time" name="inspection_time" required>
                                                        <option value="">Select time</option>
                                                        <?php foreach (['08:00' => '8:00 AM', '09:00' => '9:00 AM', '10:00' => '10:00 AM', '11:00' => '11:00 AM', '13:00' => '1:00 PM', '14:00' => '2:00 PM', '15:00' => '3:00 PM', '16:00' => '4:00 PM'] as $timeValue => $timeLabel): ?>
                                                            <option value="<?php echo $timeValue; ?>" <?php echo $inspectionTimestamp && date('H:i', $inspectionTimestamp) === $timeValue ? 'selected' : ''; ?>><?php echo $timeLabel; ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <input type="hidden" class="js-admin-scheduled-at" name="scheduled_at">
                                                </label>
                                            </div>
                                            <label>
                                                <span>Site Notes</span>
                                                <textarea name="site_notes" rows="2" placeholder="Gate pass, contact person, tools needed..."><?php echo htmlspecialchars((string)($latestInspection['site_notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                                            </label>
                                            <div class="inquiry-review-actions">
                                                <button type="submit" class="btn-primary" <?php echo empty($engineers) ? 'disabled' : ''; ?>>Finalize &amp; Send Final PDF</button>
                                                <button type="button" class="btn-secondary inquiry-clear-inputs" data-inquiry-clear-inputs>Clear inputs</button>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                </section>

                                <section class="inquiry-tab-panel" data-inquiry-panel="quotation" hidden>
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
                                                <span>Quotation Draft</span>
                                                <strong><?php echo htmlspecialchars((string)$quotationDraft['quotation_no'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            </div>
                                            <div>
                                                <span>Status</span>
                                                <strong class="status-badge <?php echo $quotationStatusClass; ?>" data-quotation-status-label><?php echo htmlspecialchars(inquiry_quote_status_label((string)$quotationDraft['status']), ENT_QUOTES, 'UTF-8'); ?></strong>
                                            </div>
                                            <div>
                                                <span>Total</span>
                                                <strong><?php echo htmlspecialchars(inquiry_quote_format_money((float)$quotationDraft['grand_total']), ENT_QUOTES, 'UTF-8'); ?></strong>
                                            </div>
                                            <div class="inquiry-quote-draft__action">
                                                <?php if (inquiry_quote_normalize_status((string)$quotationDraft['status']) === 'draft'): ?>
                                                    <a class="inquiry-quote-edit-link" href="/codesamplecaps/ADMIN/sidebar/inquiries/php/create_quotation.php?edit_id=<?php echo (int)$quotationDraft['id']; ?>">
                                                        Edit Details
                                                    </a>
                                                <?php endif; ?>
                                                <a class="inquiry-quote-pdf-link" href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiry_quotation_pdf.php?id=<?php echo (int)$quotationDraft['id']; ?>">
                                                    View / Print PDF
                                                </a>
                                            </div>
                                        </div>
                                        <div class="inquiry-quotation-approved-banner" data-quotation-approved-banner <?php echo $quotationStatus !== 'accepted' ? 'hidden' : ''; ?>>
                                            <p><strong>&#127881; Quotation Approved!</strong> The financial proposal has been accepted by the client. Please proceed to the 'Inspection' tab above to assign an Engineer and finalize the project schedule.</p>
                                            <button type="button" class="btn-primary inquiry-quotation-approved-banner__action" data-go-to-inspection>Go to Inspection Stage &#10132;</button>
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
                                                    <span>Send Quotation</span>
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
                                            >
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="send_quotation_to_client">
                                                <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry['id']; ?>">
                                                <input type="hidden" name="draft_id" value="<?php echo (int)$quotationDraft['id']; ?>">
                                                <button type="submit" class="btn-primary inquiry-quote-send-button">Send Quotation to Client</button>
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
                                    <?php elseif ($currentStatus === 'Verified Lead'): ?>
                                        <a class="btn-primary inquiry-modal__primary-action inquiry-quotation-primary-action" href="/codesamplecaps/ADMIN/sidebar/inquiries/php/create_quotation.php?inquiry_id=<?php echo (int)$inquiry['id']; ?>">Create Quotation</a>
                                    <?php else: ?>
                                        <div class="inquiry-empty">Quotation is not available for this inquiry.</div>
                                    <?php endif; ?>
                                </section>

                            </div>
                            </div>
                        </div>
                        <?php if (empty($inquiry['archived_at'])): ?>
                            <div class="inquiry-archive-modal" id="archiveModal<?php echo (int)$inquiry['id']; ?>" hidden>
                                <div class="inquiry-archive-modal__panel" role="dialog" aria-modal="true" aria-labelledby="archiveModalTitle<?php echo (int)$inquiry['id']; ?>">
                                    <div class="inquiry-modal__head">
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
                                                <option value="">Select reason</option>
                                                <option value="Duplicate inquiry">Duplicate inquiry</option>
                                                <option value="Client backed out">Client backed out</option>
                                                <option value="Not qualified">Not qualified</option>
                                                <option value="No response from client">No response from client</option>
                                                <option value="Service not offered">Service not offered</option>
                                                <option value="Invalid contact details">Invalid contact details</option>
                                                <option value="Other">Other reason</option>
                                            </select>
                                        </label>
                                        <label>
                                            <span>Manual Reason / Notes <b class="archive-other-required" data-archive-other-required hidden>*</b></span>
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
