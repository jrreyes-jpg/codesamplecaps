<?php
require_once __DIR__ . '/../../../includes/admin_auth.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/audit_log.php';
require_once __DIR__ . '/../../../../config/site_inspections.php';
require_once __DIR__ . '/../../../../config/inquiry_quotation_module.php';

$message = '';
$error = '';
$allowedStatuses = ['Pending Review', 'Verified Lead', 'Not Qualified', 'For Inspection'];
$inquiryFilterStatuses = array_merge($allowedStatuses, ['Converted to Project']);

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

function inquiry_center_redirect_to_open_modal(int $inquiryId, string $status, string $message): void
{
    $_SESSION['inquiry_center_flash'] = $message;

    $query = [
        'status' => $status,
        'open' => 'inquiryModal' . $inquiryId,
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

                if (!empty($quotation['project_id'])) {
                    inquiry_center_redirect_with_project((int)$quotation['project_id'], 'Project was already created from this quotation.');
                }

                $recipient = inquiry_quote_resolve_recipient($conn, $draftId);
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
                    'focus_field' => empty($recipient['client_id']) ? 'client_id' : 'engineer_ids',
                ];
                $_SESSION['projects_flash'] = [
                    'type' => 'success',
                    'message' => 'Review the accepted quotation details, then select the Client and project team.',
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
                        'redirect' => '/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?status=Verified+Lead&open=inquiryModal' . $inquiryId,
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
                inquiry_center_redirect_to_open_modal($inquiryId, 'Verified Lead', 'Quotation reopened for revision.');
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
                    if (inquiry_quote_normalize_status((string)($quotationRow['status'] ?? '')) !== 'accepted') {
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
            $existingStmt = $conn->prepare("SELECT id FROM site_inspections WHERE inquiry_id = ? AND status = 'Scheduled' ORDER BY id DESC LIMIT 1");
            if ($existingStmt) {
                $existingStmt->bind_param('i', $inquiryId);
                $existingStmt->execute();
                $existingRow = $existingStmt->get_result()->fetch_assoc();
                $existingInspectionId = (int)($existingRow['id'] ?? 0);
            }

            $stmt = $existingInspectionId > 0
                ? $conn->prepare(
                    "UPDATE site_inspections
                     SET engineer_id = ?, scheduled_at = ?, site_notes = ?, status = 'Scheduled'
                     WHERE id = ?"
                )
                : $conn->prepare(
                    "INSERT INTO site_inspections (inquiry_id, engineer_id, scheduled_at, site_notes, status, created_by)
                     VALUES (?, ?, ?, ?, 'Scheduled', ?)"
                );

            if (!$stmt) {
                $error = 'Failed to prepare inspection schedule.';
            } else {
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
                    $message = $existingInspectionId > 0 ? 'Site inspection rescheduled.' : 'Site inspection scheduled.';
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
                            inquiry_center_redirect_to_open_modal(
                                $inquiryId,
                                $newStatus,
                                $newStatus === 'Verified Lead'
                                    ? 'Inquiry marked as verified.'
                                    : 'Inquiry updated successfully.'
                            );
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

$hasQuotationProjectLink = inquiry_quote_table_exists($conn, 'inquiry_quotation_drafts')
    && inquiry_quote_column_exists($conn, 'inquiry_quotation_drafts', 'project_id');
$inquiryRows = [];
if (inquiry_center_has_table($conn, 'service_inquiries')) {
    $where = [];
    $types = '';
    $params = [];

    $where[] = $view === 'archive' ? 'archived_at IS NOT NULL' : 'archived_at IS NULL';

    if ($statusFilter === 'Converted to Project' && $hasQuotationProjectLink) {
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
        si.scheduled_at,
        si.status,
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
     ORDER BY (si.status = 'Submitted to Admin') DESC, si.updated_at DESC, si.id DESC"
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

$pendingCount = 0;
$verifiedCount = 0;
$inspectionCount = 0;
$notQualifiedCount = 0;
$convertedCount = 0;
if (inquiry_center_has_table($conn, 'service_inquiries')) {
    // Global counts ito, hindi lang current search result.
    $countWhere = $hasQuotationProjectLink
        ? ' AND NOT EXISTS (
            SELECT 1 FROM inquiry_quotation_drafts quote_count
            WHERE quote_count.inquiry_id = service_inquiries.id
            AND quote_count.project_id IS NOT NULL
        )'
        : '';
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

    if ($hasQuotationProjectLink) {
        $convertedResult = $conn->query(
            'SELECT COUNT(DISTINCT inquiry.id) AS total
             FROM service_inquiries inquiry
             INNER JOIN inquiry_quotation_drafts quote ON quote.inquiry_id = inquiry.id
             WHERE inquiry.archived_at IS NULL AND quote.project_id IS NOT NULL'
        );
        $convertedCount = (int)(($convertedResult ? $convertedResult->fetch_assoc() : [])['total'] ?? 0);
    }
}

$adminPageTitle = 'Inquiry Center - Edge Automation';
$adminCssFiles = [
    '/codesamplecaps/ADMIN/common/css/admin-common.css',
    '/codesamplecaps/ADMIN/sidebar/inquiries/css/inquiries.css',
];
$adminJsFiles = [
    '/codesamplecaps/ADMIN/common/js/admin-common.js',
    '/codesamplecaps/ADMIN/sidebar/inquiries/js/inquiries.js',
];
include __DIR__ . '/../../../layout/header.php';
include __DIR__ . '/../../../admin_sidebar.php';
?>

<main class="main-content admin-dashboard-content">
    <div class="inquiries-shell">
        <?php if ($message || $error): ?>
            <div
                class="inquiry-toast <?php echo $message ? 'inquiry-toast--success' : 'inquiry-toast--error'; ?>"
                role="status"
                data-inquiry-toast
            >
                <span><?php echo htmlspecialchars($message ?: $error, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php if ($message && $flashProjectId > 0): ?>
                    <a href="/codesamplecaps/ADMIN/sidebar/projects/php/project_details.php?id=<?php echo (int)$flashProjectId; ?>">
                        Open Project
                    </a>
                <?php endif; ?>
                <button type="button" data-inquiry-toast-close aria-label="Close notification">&times;</button>
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
            <a class="inquiry-status inquiry-status-link <?php echo $statusFilter === 'Pending Review' ? 'is-active' : ''; ?>" data-status="Pending Review" href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?status=Pending+Review<?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>">Pending: <?php echo $pendingCount; ?></a>
            <a class="inquiry-status inquiry-status-link <?php echo $statusFilter === 'Verified Lead' ? 'is-active' : ''; ?>" data-status="Verified Lead" href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?status=Verified+Lead<?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>">Verified: <?php echo $verifiedCount; ?></a>
            <a class="inquiry-status inquiry-status-link <?php echo $statusFilter === 'For Inspection' ? 'is-active' : ''; ?>" data-status="For Inspection" href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?status=For+Inspection<?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>">For Inspection: <?php echo $inspectionCount; ?></a>
            <a class="inquiry-status inquiry-status-link <?php echo $statusFilter === 'Not Qualified' ? 'is-active' : ''; ?>" data-status="Not Qualified" href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?status=Not+Qualified<?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>">Not Qualified: <?php echo $notQualifiedCount; ?></a>
            <a class="inquiry-status inquiry-status-link <?php echo $statusFilter === 'Converted to Project' ? 'is-active' : ''; ?>" data-status="Converted to Project" href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?status=Converted+to+Project<?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>">Converted: <?php echo $convertedCount; ?></a>
            <a class="inquiry-view-link <?php echo $view === 'active' ? 'is-active' : ''; ?>" href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php">Active</a>
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
                    <?php $isConvertedToProject = !empty($quotationDraft['project_id']); ?>
                    <?php $displayStatus = $isConvertedToProject ? 'Converted to Project' : $currentStatus; ?>
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
                        $nextActionTab = 'actions';
                        $quotationStage = $quotationDraft
                            ? inquiry_quote_normalize_status((string)$quotationDraft['status'])
                            : '';
                        $canScheduleInspection = $quotationStage === 'accepted';
                        $showInspection = $latestInspection || $canScheduleInspection;
                        $showQuotation = $quotationDraft || $showCosting || $currentStatus === 'Verified Lead';

                        if ($quotationStage === 'accepted') {
                            $nextActionLabel = $latestInspection ? 'View Inspection' : 'Schedule Inspection';
                            $nextActionTab = $latestInspection ? 'inspection' : 'actions';
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
                            $nextActionLabel = (string)($latestInspection['status'] ?? '') === 'Submitted to Admin'
                                ? 'Review Costing'
                                : 'View Inspection';
                            $nextActionTab = (string)($latestInspection['status'] ?? '') === 'Submitted to Admin' && $showCosting
                                ? 'costing'
                                : 'inspection';
                        } elseif ($currentStatus === 'Verified Lead') {
                            $nextActionLabel = 'Create Quotation';
                            $nextActionTab = 'quotation';
                        } elseif ($currentStatus === 'Not Qualified') {
                            $nextActionLabel = 'View Review';
                        }
                    ?>
                    <article class="inquiry-card <?php echo $isViewed ? 'is-viewed' : 'is-unviewed'; ?>">
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

                        <div class="inquiry-modal" id="inquiryModal<?php echo (int)$inquiry['id']; ?>" data-inquiry-id="<?php echo (int)$inquiry['id']; ?>" hidden>
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
                                        <?php if ($currentStatus === 'Verified Lead' && !$quotationDraft && empty($inquiry['archived_at'])): ?>
                                            <a class="btn-primary inquiry-modal__primary-action" href="/codesamplecaps/ADMIN/sidebar/inquiries/php/create_quotation.php?inquiry_id=<?php echo (int)$inquiry['id']; ?>">Create Quotation</a>
                                        <?php endif; ?>
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
                                    <button type="button" class="inquiry-modal-tab is-active" data-inquiry-tab="client">Contact &amp; Request</button>
                                    <button type="button" class="inquiry-modal-tab" data-inquiry-tab="actions">Admin Review</button>
                                    <?php if ($showInspection): ?>
                                        <button type="button" class="inquiry-modal-tab<?php echo $latestInspection ? ' has-data' : ''; ?>" data-inquiry-tab="inspection">Inspection</button>
                                    <?php endif; ?>
                                    <?php if ($showCosting): ?>
                                        <button type="button" class="inquiry-modal-tab has-data" data-inquiry-tab="costing">Engineer Costing</button>
                                    <?php endif; ?>
                                    <?php if ($showQuotation): ?>
                                        <button type="button" class="inquiry-modal-tab<?php echo $quotationDraft ? ' has-data' : ''; ?>" data-inquiry-tab="quotation">Quotation</button>
                                    <?php endif; ?>
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
                                </section>

                                <section class="inquiry-tab-panel" data-inquiry-panel="inspection" hidden>
                                    <div class="inquiry-section-title">Inspection</div>
                                    <?php if ($latestInspection): ?>
                                        <div class="inquiry-details-grid">
                                            <div class="inquiry-detail"><span>Engineer</span><strong><?php echo htmlspecialchars((string)$latestInspection['engineer_name'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                            <div class="inquiry-detail"><span>Date / Time</span><strong><?php echo htmlspecialchars(site_inspection_format_datetime($latestInspection['scheduled_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                            <div class="inquiry-detail"><span>Status</span><strong><?php echo htmlspecialchars((string)$latestInspection['status'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                            <div class="inquiry-detail"><span>Updated</span><strong><?php echo htmlspecialchars(site_inspection_format_datetime($latestInspection['updated_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                            <div class="inquiry-detail inquiry-detail--wide"><span>Site Notes</span><strong><?php echo htmlspecialchars((string)($latestInspection['site_notes'] ?: 'No notes'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                        </div>
                                    <?php else: ?>
                                        <div class="inquiry-empty">No inspection schedule yet.</div>
                                    <?php endif; ?>
                                </section>

                                <section class="inquiry-tab-panel" data-inquiry-panel="costing" hidden>
                                    <?php if ($costingReview && !empty($latestCostItems)): ?>
                                        <div class="inquiry-section-title">Engineer Costing Review</div>
                                        <div class="inquiry-costing-review">
                                            <div class="inquiry-details-grid">
                                                <div class="inquiry-detail">
                                                    <span>Submitted By</span>
                                                    <strong><?php echo htmlspecialchars((string)$costingReview['engineer_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                                </div>
                                                <div class="inquiry-detail">
                                                    <span>Status</span>
                                                    <strong><?php echo htmlspecialchars((string)$costingReview['status'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                                </div>
                                                <div class="inquiry-detail">
                                                    <span>Total Cost</span>
                                                    <strong><?php echo htmlspecialchars(inquiry_center_format_money($latestCostTotal), ENT_QUOTES, 'UTF-8'); ?></strong>
                                                </div>
                                            </div>

                                            <?php if (!empty($costingReview['engineer_findings'])): ?>
                                                <div class="inquiry-detail inquiry-detail--wide">
                                                    <span>Engineer Findings</span>
                                                    <strong><?php echo nl2br(htmlspecialchars((string)$costingReview['engineer_findings'], ENT_QUOTES, 'UTF-8')); ?></strong>
                                                </div>
                                            <?php endif; ?>

                                            <div class="inquiry-costing-table">
                                                <div class="inquiry-costing-table__row inquiry-costing-table__row--head">
                                                    <span>Type</span>
                                                    <span>Item</span>
                                                    <span>Qty</span>
                                                    <span>Unit Cost</span>
                                                    <span>Total</span>
                                                </div>
                                                <?php foreach ($latestCostItems as $costItem): ?>
                                                    <div class="inquiry-costing-table__row">
                                                        <span><?php echo htmlspecialchars(ucfirst((string)$costItem['item_type']), ENT_QUOTES, 'UTF-8'); ?></span>
                                                        <span><?php echo htmlspecialchars((string)$costItem['item_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                        <span><?php echo htmlspecialchars(rtrim(rtrim(number_format((float)$costItem['quantity'], 2), '0'), '.') . ' ' . (string)$costItem['unit'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                        <span><?php echo htmlspecialchars(inquiry_center_format_money((float)$costItem['unit_cost']), ENT_QUOTES, 'UTF-8'); ?></span>
                                                        <span><?php echo htmlspecialchars(inquiry_center_format_money((float)$costItem['line_total']), ENT_QUOTES, 'UTF-8'); ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="inquiry-empty">No engineer costing yet.</div>
                                    <?php endif; ?>
                                </section>

                                <section class="inquiry-tab-panel" data-inquiry-panel="quotation" hidden>
                                    <div class="inquiry-section-title">Quotation</div>
                                    <?php if ($quotationDraft): ?>
                                        <div class="inquiry-quote-draft">
                                            <div>
                                                <span>Quotation Draft</span>
                                                <strong><?php echo htmlspecialchars((string)$quotationDraft['quotation_no'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            </div>
                                            <div>
                                                <span>Status</span>
                                                <strong><?php echo htmlspecialchars(inquiry_quote_status_label((string)$quotationDraft['status']), ENT_QUOTES, 'UTF-8'); ?></strong>
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
                                        <?php $quotationStatus = inquiry_quote_normalize_status((string)$quotationDraft['status']); ?>
                                        <?php $quotationRecipient = null; ?>
                                        <?php if (in_array($quotationStatus, ['draft', 'approved', 'accepted'], true)): ?>
                                            <?php try { $quotationRecipient = inquiry_quote_resolve_recipient($conn, (int)$quotationDraft['id']); } catch (Throwable $throwable) { $quotationRecipient = null; } ?>
                                        <?php endif; ?>
                                        <?php if (!empty($quotationDraft['client_decision_note'])): ?>
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
                                                <button type="submit" class="btn-primary">Send Quotation to Client</button>
                                            </form>
                                            <?php endif; ?>
                                        <?php elseif ($quotationStatus === 'revision_requested' && empty($quotationDraft['project_id'])): ?>
                                            <form method="POST" class="inquiry-quote-revision-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="reopen_quotation_revision">
                                                <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry['id']; ?>">
                                                <input type="hidden" name="draft_id" value="<?php echo (int)$quotationDraft['id']; ?>">
                                                <button type="submit" class="btn-secondary">Reopen For Revision</button>
                                            </form>
                                        <?php elseif ($quotationStatus === 'accepted' && empty($quotationDraft['project_id'])): ?>
                                            <?php if (!$quotationRecipient || empty($quotationRecipient['client_id'])): ?>
                                                <div class="inquiry-detail inquiry-detail--wide">
                                                    <span>Project Creation</span>
                                                    <strong>Select the matching Client account in Project Setup before saving.</strong>
                                                </div>
                                            <?php endif; ?>
                                            <form method="POST" class="inquiry-project-create-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="prepare_project_from_quote">
                                                <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry['id']; ?>">
                                                <input type="hidden" name="draft_id" value="<?php echo (int)$quotationDraft['id']; ?>">
                                                <button type="submit" class="btn-primary">Continue to Project Setup</button>
                                            </form>
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
                                        <div class="inquiry-empty">
                                            Create the quotation before assigning an Engineer or setting the inspection date. Use the Create Quotation button at the top-right.
                                        </div>
                                    <?php else: ?>
                                        <div class="inquiry-empty">Quotation is not available for this inquiry.</div>
                                    <?php endif; ?>
                                </section>

                                <section class="inquiry-tab-panel inquiry-expanded-actions" data-inquiry-panel="actions" hidden>
                                    <div class="inquiry-section-title">Admin Review</div>
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
                                            <textarea name="admin_notes" rows="5" placeholder="Call result, budget, seriousness, next step..."><?php echo htmlspecialchars((string)($inquiry['admin_notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                                        </label>
                                        <div class="inquiry-review-actions inquiry-review-form__actions">
                                            <button type="submit" class="btn-primary">Save Review</button>
                                        </div>
                                    </form>

                                    <?php if ($canScheduleInspection && in_array($currentStatus, ['Verified Lead', 'For Inspection'], true)): ?>
                                        <form method="POST" class="inquiry-schedule-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="schedule_inspection">
                                            <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry['id']; ?>">
                                            <strong><?php echo $latestInspection ? 'Reschedule Site Inspection' : 'Schedule Site Inspection'; ?></strong>
                                            <div class="inquiry-schedule-grid">
                                                <label>
                                                    <span>Engineer</span>
                                                    <select name="engineer_id" required>
                                                        <option value="">Select engineer</option>
                                                        <?php foreach ($engineers as $engineer): ?>
                                                            <option value="<?php echo (int)$engineer['id']; ?>">
                                                                <?php echo htmlspecialchars((string)$engineer['full_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </label>
                                                <label class="admin-date-field">
                                                    <span>Inspection Date</span>
                                                    <span class="admin-date-input-wrap">
                                                        <input type="date" class="js-admin-inspection-date" name="inspection_date" required>
                                                        <button class="admin-date-picker-button js-admin-date-picker-button" type="button" aria-label="Open inspection calendar">&#128197;</button>
                                                    </span>
                                                    <small class="admin-date-tooltip js-admin-date-tooltip">Final schedule is subject to engineer availability.</small>
                                                </label>
                                                <label>
                                                    <span>Inspection Time</span>
                                                    <select class="js-admin-inspection-time" name="inspection_time" required>
                                                        <option value="">Select time</option>
                                                        <option value="08:00">8:00 AM</option>
                                                        <option value="09:00">9:00 AM</option>
                                                        <option value="10:00">10:00 AM</option>
                                                        <option value="11:00">11:00 AM</option>
                                                        <option value="13:00">1:00 PM</option>
                                                        <option value="14:00">2:00 PM</option>
                                                        <option value="15:00">3:00 PM</option>
                                                        <option value="16:00">4:00 PM</option>
                                                    </select>
                                                    <input type="hidden" class="js-admin-scheduled-at" name="scheduled_at">
                                                </label>
                                            </div>
                                            <label>
                                                <span>Site Notes</span>
                                                <textarea name="site_notes" rows="2" placeholder="Gate pass, contact person, tools needed..."></textarea>
                                            </label>
                                            <div class="inquiry-review-actions">
                                                <button type="submit" class="btn-primary" <?php echo empty($engineers) ? 'disabled' : ''; ?>><?php echo $latestInspection ? 'Save Reschedule' : 'Schedule Inspection'; ?></button>
                                                <button type="button" class="btn-secondary inquiry-clear-inputs" data-inquiry-clear-inputs>Clear inputs</button>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if (!empty($inquiry['archived_at'])): ?>
                                        <div class="inquiry-archive-output">
                                            <strong>Archived</strong>
                                            <span><?php echo htmlspecialchars(inquiry_center_format_datetime($inquiry['archived_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span>Reason: <?php echo htmlspecialchars((string)($inquiry['archive_reason'] ?: 'No reason'), ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
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
