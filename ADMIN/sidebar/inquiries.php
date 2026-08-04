<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/audit_log.php';
require_once __DIR__ . '/../../config/site_inspections.php';

$message = '';
$error = '';
$allowedStatuses = ['Pending Review', 'Verified Lead', 'Not Qualified', 'For Inspection'];

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

function inquiry_center_has_column(mysqli $conn, string $columnName): bool
{
    $stmt = $conn->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = "service_inquiries"
         AND COLUMN_NAME = ?
         LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $columnName);
    $stmt->execute();
    $result = $stmt->get_result();
    return (bool)($result && $result->fetch_assoc());
}

function inquiry_center_ensure_review_columns(mysqli $conn): void
{
    // Dagdag fields para may review notes, seen state, at archive ready state si Admin.
    if (!inquiry_center_has_table($conn, 'service_inquiries')) {
        return;
    }

    if (!inquiry_center_has_column($conn, 'admin_notes')) {
        $conn->query('ALTER TABLE service_inquiries ADD COLUMN admin_notes TEXT NULL AFTER status');
    }

    if (!inquiry_center_has_column($conn, 'reviewed_at')) {
        $conn->query('ALTER TABLE service_inquiries ADD COLUMN reviewed_at TIMESTAMP NULL AFTER admin_notes');
    }

    if (!inquiry_center_has_column($conn, 'viewed_at')) {
        $conn->query('ALTER TABLE service_inquiries ADD COLUMN viewed_at TIMESTAMP NULL AFTER reviewed_at');
    }

    if (!inquiry_center_has_column($conn, 'archived_at')) {
        $conn->query('ALTER TABLE service_inquiries ADD COLUMN archived_at TIMESTAMP NULL AFTER viewed_at');
    }

    if (!inquiry_center_has_column($conn, 'archived_by')) {
        $conn->query('ALTER TABLE service_inquiries ADD COLUMN archived_by INT NULL AFTER archived_at');
    }

    if (!inquiry_center_has_column($conn, 'archive_reason')) {
        $conn->query('ALTER TABLE service_inquiries ADD COLUMN archive_reason TEXT NULL AFTER archived_by');
    }
}

function inquiry_center_format_datetime(?string $dateTime): string
{
    $timestamp = $dateTime ? strtotime($dateTime) : false;
    if ($timestamp === false) {
        return 'Not set';
    }

    return date('M j, Y, g:ia', $timestamp);
}

function inquiry_center_allowed_next_statuses(string $currentStatus): array
{
    // Status rules para hindi basta-basta tumalon ang lead sa maling stage.
    $rules = [
        'Pending Review' => ['Pending Review', 'Verified Lead', 'Not Qualified'],
        'Verified Lead' => ['Verified Lead', 'For Inspection', 'Not Qualified'],
        'For Inspection' => ['For Inspection', 'Verified Lead'],
        'Not Qualified' => ['Not Qualified', 'Pending Review'],
    ];

    return $rules[$currentStatus] ?? ['Pending Review'];
}

function inquiry_center_can_change_status(string $currentStatus, string $newStatus): bool
{
    return in_array($newStatus, inquiry_center_allowed_next_statuses($currentStatus), true);
}

inquiry_center_ensure_review_columns($conn);
site_inspection_ensure_table($conn);
$conn->query("UPDATE service_inquiries SET status = 'Verified Lead' WHERE status = 'Verified'");
$conn->query("UPDATE service_inquiries SET status = 'Not Qualified' WHERE status = 'Rejected'");
$csrfToken = inquiry_center_csrf_token();

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
    header('Location: /codesamplecaps/ADMIN/sidebar/inquiries.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!inquiry_center_is_valid_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid request. Please try again.';
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
                    $message = 'Inquiry restored.';
                } else {
                    $error = 'Failed to restore inquiry.';
                }
            }
        }
    } elseif (($_POST['action'] ?? '') === 'archive_inquiry') {
        $inquiryId = (int)($_POST['inquiry_id'] ?? 0);
        $archiveReason = trim((string)($_POST['archive_reason'] ?? ''));

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
                    $message = 'Inquiry archived.';
                } else {
                    $error = 'Failed to archive inquiry.';
                }
            }
        }
    } elseif (($_POST['action'] ?? '') === 'schedule_inspection') {
        $inquiryId = (int)($_POST['inquiry_id'] ?? 0);
        $engineerId = (int)($_POST['engineer_id'] ?? 0);
        $scheduledAt = trim((string)($_POST['scheduled_at'] ?? ''));
        if ($scheduledAt === '') {
            $scheduleDate = trim((string)($_POST['inspection_date'] ?? ''));
            $scheduleTime = trim((string)($_POST['inspection_time'] ?? ''));
            $scheduledAt = ($scheduleDate !== '' && $scheduleTime !== '') ? $scheduleDate . ' ' . $scheduleTime : '';
        }
        $siteNotes = trim((string)($_POST['site_notes'] ?? ''));
        $scheduleTimestamp = $scheduledAt !== '' ? strtotime($scheduledAt) : false;

        if ($inquiryId <= 0 || $engineerId <= 0 || $scheduleTimestamp === false) {
            $error = 'Please select engineer and valid inspection schedule.';
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
                            $message = 'Inquiry updated successfully.';
                        } else {
                            $error = 'Failed to update inquiry.';
                        }
                    }
                }
            }
        }
    }
}

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
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

$inquiryRows = [];
if (inquiry_center_has_table($conn, 'service_inquiries')) {
    $where = [];
    $types = '';
    $params = [];

    $where[] = $view === 'archive' ? 'archived_at IS NOT NULL' : 'archived_at IS NULL';

    if ($statusFilter !== '') {
        $where[] = 'status = ?';
        $types .= 's';
        $params[] = $statusFilter;
    }

    if ($search !== '') {
        // Smart search: hanapin sa important fields para mas mabilis ang lead filtering.
        $where[] = '(client_name LIKE ? OR company_name LIKE ? OR email LIKE ? OR contact_no LIKE ? OR site_address LIKE ? OR service_category LIKE ?)';
        $keyword = '%' . $search . '%';
        $types .= 'ssssss';
        array_push($params, $keyword, $keyword, $keyword, $keyword, $keyword, $keyword);
    }

    $sql = 'SELECT id, client_name, company_name, email, contact_no, site_address,
                   service_category, description, preferred_inspection_date,
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
        si.inquiry_id,
        si.scheduled_at,
        si.status,
        si.site_notes,
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

$pendingCount = 0;
$verifiedCount = 0;
$inspectionCount = 0;
$notQualifiedCount = 0;
if (inquiry_center_has_table($conn, 'service_inquiries')) {
    // Global counts ito, hindi lang current search result.
    $countResult = $conn->query('SELECT status, COUNT(*) AS total FROM service_inquiries WHERE archived_at IS NULL GROUP BY status');
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
}

$adminPageTitle = 'Inquiry Center - Edge Automation';
$adminCssFiles = [
    '/codesamplecaps/ADMIN/css/super_admin_dashboard.css',
    '/codesamplecaps/ADMIN/css/inquiries.css',
];
$adminJsFiles = [
    '/codesamplecaps/ADMIN/js/super_admin_dashboard.js',
    '/codesamplecaps/ADMIN/js/inquiries.js',
];
include __DIR__ . '/../layout/header.php';
include __DIR__ . '/../admin_sidebar.php';
?>

<main class="main-content admin-dashboard-content">
    <div class="inquiries-shell">
        <?php if ($message || $error): ?>
            <div
                class="inquiry-toast <?php echo $message ? 'inquiry-toast--success' : 'inquiry-toast--error'; ?>"
                role="status"
                data-inquiry-toast
            >
                <?php echo htmlspecialchars($message ?: $error, ENT_QUOTES, 'UTF-8'); ?>
                <button type="button" data-inquiry-toast-close aria-label="Close notification">&times;</button>
            </div>
        <?php endif; ?>



        <form class="inquiry-filter-bar" method="GET">
            <input type="search" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search name, company, email, contact, site, or service">
            <select name="status">
                <option value="">All statuses</option>
                <?php foreach ($allowedStatuses as $status): ?>
                    <option value="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="inquiry-filter-actions">
                <button type="submit" class="btn-primary">Filter</button>
                <a href="/codesamplecaps/ADMIN/sidebar/inquiries.php" class="btn-secondary">Reset</a>
            </div>
        </form>

        <div class="inquiry-status-strip" aria-label="Inquiry status summary">
            <a class="inquiry-status inquiry-status-link <?php echo $statusFilter === 'Pending Review' ? 'is-active' : ''; ?>" data-status="Pending Review" href="/codesamplecaps/ADMIN/sidebar/inquiries.php?status=Pending+Review<?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>">Pending: <?php echo $pendingCount; ?></a>
            <a class="inquiry-status inquiry-status-link <?php echo $statusFilter === 'Verified Lead' ? 'is-active' : ''; ?>" data-status="Verified Lead" href="/codesamplecaps/ADMIN/sidebar/inquiries.php?status=Verified+Lead<?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>">Verified: <?php echo $verifiedCount; ?></a>
            <a class="inquiry-status inquiry-status-link <?php echo $statusFilter === 'For Inspection' ? 'is-active' : ''; ?>" data-status="For Inspection" href="/codesamplecaps/ADMIN/sidebar/inquiries.php?status=For+Inspection<?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>">For Inspection: <?php echo $inspectionCount; ?></a>
            <a class="inquiry-status inquiry-status-link <?php echo $statusFilter === 'Not Qualified' ? 'is-active' : ''; ?>" data-status="Not Qualified" href="/codesamplecaps/ADMIN/sidebar/inquiries.php?status=Not+Qualified<?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>">Not Qualified: <?php echo $notQualifiedCount; ?></a>
            <a class="inquiry-view-link <?php echo $view === 'active' ? 'is-active' : ''; ?>" href="/codesamplecaps/ADMIN/sidebar/inquiries.php">Active</a>
            <a class="inquiry-view-link <?php echo $view === 'archive' ? 'is-active' : ''; ?>" href="/codesamplecaps/ADMIN/sidebar/inquiries.php?view=archive">Archive</a>
        </div>

        <?php if (empty($inquiryRows)): ?>
            <div class="inquiry-empty">No inquiries found.</div>
        <?php else: ?>
            <div class="inquiry-list">
                <?php foreach ($inquiryRows as $inquiry): ?>
                    <?php $currentStatus = (string)($inquiry['status'] ?? 'Pending Review'); ?>
                    <?php $isViewed = !empty($inquiry['viewed_at']); ?>
                    <article class="inquiry-card <?php echo $isViewed ? 'is-viewed' : 'is-unviewed'; ?>">
                        <div class="inquiry-card__head">
                            <div>
                                <h2><?php echo htmlspecialchars((string)$inquiry['client_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                                <div class="inquiry-meta">
                                    <?php echo htmlspecialchars((string)$inquiry['service_category'], ENT_QUOTES, 'UTF-8'); ?>
                                    |
                                    <?php echo htmlspecialchars((string)$inquiry['email'], ENT_QUOTES, 'UTF-8'); ?>
                                    |
                                    <?php echo htmlspecialchars((string)$inquiry['contact_no'], ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            </div>
                            <span class="inquiry-status" data-status="<?php echo htmlspecialchars($currentStatus, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($currentStatus, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>

                        <button type="button" class="inquiry-open-modal" data-inquiry-modal-open="inquiryModal<?php echo (int)$inquiry['id']; ?>">
                            View details and review
                        </button>

                        <div class="inquiry-modal" id="inquiryModal<?php echo (int)$inquiry['id']; ?>" data-inquiry-id="<?php echo (int)$inquiry['id']; ?>" hidden>
                            <div class="inquiry-modal__panel" role="dialog" aria-modal="true" aria-labelledby="inquiryModalTitle<?php echo (int)$inquiry['id']; ?>">
                                <div class="inquiry-modal__head">
                                    <div>
                                        <span class="reports-kicker">Inquiry Review</span>
                                        <h2 id="inquiryModalTitle<?php echo (int)$inquiry['id']; ?>"><?php echo htmlspecialchars((string)$inquiry['client_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                                        <div class="inquiry-modal__meta">
                                            <span><?php echo htmlspecialchars((string)$inquiry['service_category'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span><?php echo htmlspecialchars((string)$inquiry['email'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span><?php echo htmlspecialchars((string)$inquiry['contact_no'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span class="inquiry-status inquiry-status--modal" data-status="<?php echo htmlspecialchars($currentStatus, ENT_QUOTES, 'UTF-8'); ?>">
                                                <?php echo htmlspecialchars($currentStatus, ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="inquiry-modal__tools">
                                        <?php if (empty($inquiry['archived_at'])): ?>
                                            <button type="button" class="inquiry-modal__archive-icon" data-archive-modal-open="archiveModal<?php echo (int)$inquiry['id']; ?>" aria-label="Archive inquiry">Archive</button>
                                        <?php endif; ?>
                                        <button type="button" class="inquiry-modal__close" data-inquiry-modal-close aria-label="Close inquiry review">&times;</button>
                                    </div>
                                </div>
                            <div class="inquiry-expanded-grid inquiry-expanded-grid--stacked">
                                <div class="inquiry-expanded-main">
                                    <div class="inquiry-section-title">Client and Project Details</div>
                                    <div class="inquiry-details-grid">
                                        <div class="inquiry-detail"><span>Company</span><strong><?php echo htmlspecialchars((string)($inquiry['company_name'] ?: 'N/A'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                        <div class="inquiry-detail"><span>Preferred Date</span><strong><?php echo htmlspecialchars((string)($inquiry['preferred_inspection_date'] ?: 'Not set'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                        <div class="inquiry-detail"><span>Submitted</span><strong><?php echo htmlspecialchars(inquiry_center_format_datetime($inquiry['created_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                        <div class="inquiry-detail"><span>Reviewed</span><strong><?php echo htmlspecialchars(!empty($inquiry['reviewed_at']) ? inquiry_center_format_datetime($inquiry['reviewed_at']) : 'Not yet', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                        <div class="inquiry-detail inquiry-detail--wide"><span>Site Address</span><strong><?php echo htmlspecialchars((string)$inquiry['site_address'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                    </div>

                                    <div class="inquiry-description">
                                        <strong>Project Description</strong><br>
                                        <?php echo nl2br(htmlspecialchars((string)$inquiry['description'], ENT_QUOTES, 'UTF-8')); ?>
                                    </div>
                                </div>

                                <div class="inquiry-expanded-actions">
                                    <div class="inquiry-section-title">Admin Actions</div>
                                    <form method="POST" class="inquiry-review-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry['id']; ?>">
                                        <label>
                                            <span>Status</span>
                                            <select name="status" required>
                                                <?php foreach (inquiry_center_allowed_next_statuses($currentStatus) as $status): ?>
                                                    <option value="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $currentStatus === $status ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>
                                            <span>Admin Notes</span>
                                            <textarea name="admin_notes" rows="2" placeholder="Call result, budget, seriousness, next step..."><?php echo htmlspecialchars((string)($inquiry['admin_notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                                        </label>
                                        <div class="inquiry-review-actions">
                                            <button type="submit" class="btn-primary">Save Review</button>
                                        </div>
                                    </form>

                                    <?php if (in_array($currentStatus, ['Verified Lead', 'For Inspection'], true)): ?>
                                        <?php $latestInspection = $inspectionByInquiry[(int)$inquiry['id']] ?? null; ?>
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
                                        <?php if ($latestInspection): ?>
                                            <div class="inquiry-schedule-output">
                                                <strong>Latest Schedule</strong>
                                                <span>Engineer: <?php echo htmlspecialchars((string)$latestInspection['engineer_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <span>Date/Time: <?php echo htmlspecialchars(site_inspection_format_datetime($latestInspection['scheduled_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></span>
                                                <span>Status: <?php echo htmlspecialchars((string)$latestInspection['status'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if (!empty($inquiry['archived_at'])): ?>
                                        <div class="inquiry-archive-output">
                                            <strong>Archived</strong>
                                            <span><?php echo htmlspecialchars(inquiry_center_format_datetime($inquiry['archived_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span>Reason: <?php echo htmlspecialchars((string)($inquiry['archive_reason'] ?: 'No reason'), ENT_QUOTES, 'UTF-8'); ?></span>
                                            <form method="POST" class="inquiry-restore-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="restore_inquiry">
                                                <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry['id']; ?>">
                                                <button type="submit" class="btn-primary">Restore Inquiry</button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
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
                                            </select>
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

<?php include __DIR__ . '/../layout/footer.php'; ?>
