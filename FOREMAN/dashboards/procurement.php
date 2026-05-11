<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/audit_log.php';
require_once __DIR__ . '/../includes/foreman_helpers.php';

require_role('foreman');

function foreman_procurement_redirect(): void
{
    header('Location: /codesamplecaps/FOREMAN/dashboards/procurement.php');
    exit();
}

function foreman_procurement_set_flash(string $type, string $message): void
{
    $_SESSION['foreman_procurement_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function foreman_procurement_consume_flash(): ?array
{
    $flash = $_SESSION['foreman_procurement_flash'] ?? null;
    unset($_SESSION['foreman_procurement_flash']);
    return is_array($flash) ? $flash : null;
}

function foreman_procurement_normalize_text(?string $value): string
{
    return trim((string)$value);
}

function foreman_procurement_normalize_text_or_null(?string $value): ?string
{
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function foreman_procurement_normalize_money_or_null($value): ?float
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $normalized = str_ireplace(['PHP', 'P'], '', $value);
    $normalized = str_replace([',', ' '], '', $normalized);

    if (!is_numeric($normalized)) {
        return null;
    }

    return round((float)$normalized, 2);
}

function foreman_procurement_generate_code(string $prefix): string
{
    return $prefix . '-' . date('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function foreman_procurement_ensure_purchase_requests_table(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS purchase_requests (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            request_no VARCHAR(80) NOT NULL UNIQUE,
            project_id INT(11) NOT NULL,
            requested_by INT(11) NOT NULL,
            needed_date DATE DEFAULT NULL,
            request_type ENUM('material', 'tool', 'equipment', 'service') NOT NULL DEFAULT 'material',
            site_location VARCHAR(190) DEFAULT NULL,
            remarks TEXT DEFAULT NULL,
            status ENUM('draft', 'submitted', 'engineer_review', 'engineer_approved', 'engineer_rejected', 'approved', 'cancelled') NOT NULL DEFAULT 'submitted',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_purchase_requests_project_status (project_id, status),
            KEY idx_purchase_requests_requested_by (requested_by),
            CONSTRAINT fk_purchase_requests_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT fk_purchase_requests_requested_by FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

function foreman_procurement_ensure_purchase_request_review_columns(mysqli $conn): void
{
    $columnCheck = $conn->query("SHOW COLUMNS FROM purchase_requests LIKE 'engineer_reviewed_by'");
    if (!$columnCheck || (int)$columnCheck->num_rows === 0) {
        $conn->query("ALTER TABLE purchase_requests ADD COLUMN engineer_reviewed_by INT(11) DEFAULT NULL AFTER requested_by");
        $conn->query("ALTER TABLE purchase_requests ADD COLUMN engineer_review_notes TEXT DEFAULT NULL AFTER remarks");
        $conn->query("ALTER TABLE purchase_requests ADD COLUMN engineer_reviewed_at DATETIME DEFAULT NULL AFTER engineer_review_notes");
        $conn->query("ALTER TABLE purchase_requests ADD CONSTRAINT fk_purchase_requests_engineer_reviewed_by FOREIGN KEY (engineer_reviewed_by) REFERENCES users (id) ON DELETE SET NULL");
    }
}

function foreman_procurement_ensure_purchase_request_items_table(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS purchase_request_items (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            purchase_request_id INT(11) NOT NULL,
            item_description VARCHAR(255) NOT NULL,
            specification VARCHAR(255) DEFAULT NULL,
            unit VARCHAR(40) NOT NULL,
            quantity_requested DECIMAL(12,2) NOT NULL,
            estimated_unit_cost DECIMAL(14,2) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_purchase_request_items_request (purchase_request_id),
            CONSTRAINT fk_purchase_request_items_request FOREIGN KEY (purchase_request_id) REFERENCES purchase_requests (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

function foreman_procurement_is_assigned(mysqli $conn, int $projectId, int $foremanId): bool
{
    $stmt = $conn->prepare(
        "SELECT 1
         FROM project_assignments
         WHERE project_id = ?
         AND engineer_id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ii', $projectId, $foremanId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return (bool)$row;
}

function foreman_procurement_fetch_editable_request(mysqli $conn, int $requestId, int $foremanId): ?array
{
    if ($requestId <= 0) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT
            pr.id,
            pr.project_id,
            pr.request_no,
            pr.status,
            pr.needed_date,
            pr.request_type,
            pr.site_location,
            pr.remarks,
            pri.item_description,
            pri.specification,
            pri.unit,
            pri.quantity_requested,
            pri.estimated_unit_cost
         FROM purchase_requests pr
         LEFT JOIN purchase_request_items pri ON pri.purchase_request_id = pr.id
         WHERE pr.id = ?
         AND pr.requested_by = ?
         AND pr.status = 'submitted'
         LIMIT 1"
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('ii', $requestId, $foremanId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

foreman_procurement_ensure_purchase_requests_table($conn);
foreman_procurement_ensure_purchase_request_items_table($conn);
foreman_procurement_ensure_purchase_request_review_columns($conn);

$userId = (int)($_SESSION['user_id'] ?? 0);
$foremanProfileName = (string)($_SESSION['name'] ?? 'Foreman');
$foremanProfile = foreman_fetch_profile($conn, $userId);
$foremanProfileName = (string)($foremanProfile['full_name'] ?? $foremanProfileName);
$dashboardData = foreman_fetch_dashboard_data($conn, $userId);
$assetSummary = $dashboardData['asset_summary'];
$usageSummary = $dashboardData['usage_summary'];
$scanSummary = $dashboardData['scan_summary'];
$supportSummary = $dashboardData['support_summary'];
$assignedProjects = $dashboardData['assigned_projects'];
$foremanNotifications = [
    'attention_count' => (int)($assetSummary['maintenance_assets'] ?? 0) + (int)($assetSummary['damaged_assets'] ?? 0),
    'logs_today' => (int)($usageSummary['logs_today'] ?? 0),
    'scans_today' => (int)($scanSummary['scans_today'] ?? 0),
];
$projectRoleSummary = project_role_summary_label('foreman');
$flash = foreman_procurement_consume_flash();
$csrfToken = auth_csrf_token('foreman_module');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'foreman_module')) {
        foreman_procurement_set_flash('error', 'Security check failed. Please try again.');
        foreman_procurement_redirect();
    }

    if ($action === 'create_purchase_request' || $action === 'update_purchase_request') {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $purchaseRequestId = (int)($_POST['purchase_request_id'] ?? 0);
        $neededDate = foreman_procurement_normalize_text_or_null($_POST['needed_date'] ?? null);
        $requestType = foreman_procurement_normalize_text($_POST['request_type'] ?? 'material');
        $siteLocation = foreman_procurement_normalize_text_or_null($_POST['site_location'] ?? null);
        $remarks = foreman_procurement_normalize_text_or_null($_POST['remarks'] ?? null);
        $itemDescription = foreman_procurement_normalize_text($_POST['item_description'] ?? '');
        $specification = foreman_procurement_normalize_text_or_null($_POST['specification'] ?? null);
        $unit = foreman_procurement_normalize_text($_POST['unit'] ?? '');
        $quantityRequested = foreman_procurement_normalize_money_or_null($_POST['quantity_requested'] ?? null);
        $estimatedUnitCost = foreman_procurement_normalize_money_or_null($_POST['estimated_unit_cost'] ?? null);
        $allowedTypes = ['material', 'tool', 'equipment', 'service'];
        $isUpdate = $action === 'update_purchase_request';

        if ($isUpdate && !foreman_procurement_fetch_editable_request($conn, $purchaseRequestId, $userId)) {
            foreman_procurement_set_flash('error', 'Only your submitted requests can be edited.');
            foreman_procurement_redirect();
        }

        if ($projectId <= 0 || $itemDescription === '' || $unit === '' || $quantityRequested === null || $quantityRequested <= 0) {
            foreman_procurement_set_flash('error', 'Project, item, unit, and quantity are required.');
            foreman_procurement_redirect();
        }

        if (!foreman_procurement_is_assigned($conn, $projectId, $userId)) {
            foreman_procurement_set_flash('error', 'You can only request procurement for your assigned projects.');
            foreman_procurement_redirect();
        }

        if ($neededDate === null) {
            foreman_procurement_set_flash('error', 'Needed date is required.');
            foreman_procurement_redirect();
        }

        if ($siteLocation === null) {
            foreman_procurement_set_flash('error', 'Site location is required.');
            foreman_procurement_redirect();
        }

        if (mb_strlen($itemDescription) < 2) {
            foreman_procurement_set_flash('error', 'Item description is too short.');
            foreman_procurement_redirect();
        }

        if (mb_strlen($unit) < 2) {
            foreman_procurement_set_flash('error', 'Unit is too short.');
            foreman_procurement_redirect();
        }

        if (!in_array($requestType, $allowedTypes, true)) {
            foreman_procurement_set_flash('error', 'Invalid request type.');
            foreman_procurement_redirect();
        }

        $conn->begin_transaction();

        try {
            $status = 'submitted';
            if ($isUpdate) {
                $updateRequest = $conn->prepare(
                    "UPDATE purchase_requests
                     SET project_id = ?, needed_date = ?, request_type = ?, site_location = ?, remarks = ?, status = ?
                     WHERE id = ?
                     AND requested_by = ?
                     AND status = 'submitted'"
                );

                if (
                    !$updateRequest ||
                    !$updateRequest->bind_param('isssssii', $projectId, $neededDate, $requestType, $siteLocation, $remarks, $status, $purchaseRequestId, $userId) ||
                    !$updateRequest->execute()
                ) {
                    throw new RuntimeException('Failed to update purchase request.');
                }

                $updateItem = $conn->prepare(
                    'UPDATE purchase_request_items
                     SET item_description = ?, specification = ?, unit = ?, quantity_requested = ?, estimated_unit_cost = ?
                     WHERE purchase_request_id = ?'
                );

                if (
                    !$updateItem ||
                    !$updateItem->bind_param('sssddi', $itemDescription, $specification, $unit, $quantityRequested, $estimatedUnitCost, $purchaseRequestId) ||
                    !$updateItem->execute()
                ) {
                    throw new RuntimeException('Failed to update request item.');
                }
            } else {
                $requestNo = foreman_procurement_generate_code('PR');
                $insertRequest = $conn->prepare(
                    'INSERT INTO purchase_requests (request_no, project_id, requested_by, needed_date, request_type, site_location, remarks, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );

                if (
                    !$insertRequest ||
                    !$insertRequest->bind_param('siisssss', $requestNo, $projectId, $userId, $neededDate, $requestType, $siteLocation, $remarks, $status) ||
                    !$insertRequest->execute()
                ) {
                    throw new RuntimeException('Failed to create purchase request.');
                }

                $purchaseRequestId = (int)$insertRequest->insert_id;
                $insertItem = $conn->prepare(
                    'INSERT INTO purchase_request_items (purchase_request_id, item_description, specification, unit, quantity_requested, estimated_unit_cost)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );

                if (
                    !$insertItem ||
                    !$insertItem->bind_param('isssdd', $purchaseRequestId, $itemDescription, $specification, $unit, $quantityRequested, $estimatedUnitCost) ||
                    !$insertItem->execute()
                ) {
                    throw new RuntimeException('Failed to save request item.');
                }
            }

            $conn->commit();

            audit_log_event(
                $conn,
                $userId,
                $isUpdate ? 'update_purchase_request' : 'create_purchase_request',
                'purchase_request',
                $purchaseRequestId,
                null,
                [
                    'project_id' => $projectId,
                    'requested_by' => $userId,
                    'request_type' => $requestType,
                    'item_description' => $itemDescription,
                    'quantity_requested' => $quantityRequested,
                ]
            );

            foreman_procurement_set_flash('success', $isUpdate ? 'Purchase request updated.' : 'Purchase request submitted for engineer review.');
        } catch (Throwable $exception) {
            $conn->rollback();
            foreman_procurement_set_flash('error', $exception->getMessage());
        }

        foreman_procurement_redirect();
    }

    if ($action === 'cancel_purchase_request') {
        $purchaseRequestId = (int)($_POST['purchase_request_id'] ?? 0);
        $editableRequest = foreman_procurement_fetch_editable_request($conn, $purchaseRequestId, $userId);

        if (!$editableRequest) {
            foreman_procurement_set_flash('error', 'Only your submitted requests can be cancelled.');
            foreman_procurement_redirect();
        }

        $cancelRequest = $conn->prepare(
            "UPDATE purchase_requests
             SET status = 'cancelled'
             WHERE id = ?
             AND requested_by = ?
             AND status = 'submitted'"
        );

        if (
            $cancelRequest &&
            $cancelRequest->bind_param('ii', $purchaseRequestId, $userId) &&
            $cancelRequest->execute() &&
            (int)$cancelRequest->affected_rows > 0
        ) {
            audit_log_event(
                $conn,
                $userId,
                'cancel_purchase_request',
                'purchase_request',
                $purchaseRequestId,
                [
                    'request_no' => $editableRequest['request_no'] ?? null,
                    'status' => 'submitted',
                ],
                [
                    'request_no' => $editableRequest['request_no'] ?? null,
                    'status' => 'cancelled',
                ]
            );
            foreman_procurement_set_flash('success', 'Purchase request cancelled.');
        } else {
            foreman_procurement_set_flash('error', 'Failed to cancel purchase request.');
        }

        foreman_procurement_redirect();
    }
}

$editRequestId = max(0, (int)($_GET['edit'] ?? 0));
$editRequest = foreman_procurement_fetch_editable_request($conn, $editRequestId, $userId);
$requestFormValues = [
    'project_id' => (string)($editRequest['project_id'] ?? ''),
    'request_type' => (string)($editRequest['request_type'] ?? 'material'),
    'needed_date' => (string)($editRequest['needed_date'] ?? ''),
    'site_location' => (string)($editRequest['site_location'] ?? ''),
    'item_description' => (string)($editRequest['item_description'] ?? ''),
    'specification' => (string)($editRequest['specification'] ?? ''),
    'unit' => (string)($editRequest['unit'] ?? ''),
    'quantity_requested' => isset($editRequest['quantity_requested']) ? (string)$editRequest['quantity_requested'] : '',
    'estimated_unit_cost' => isset($editRequest['estimated_unit_cost']) && $editRequest['estimated_unit_cost'] !== null ? (string)$editRequest['estimated_unit_cost'] : '',
    'remarks' => (string)($editRequest['remarks'] ?? ''),
];

$requestRows = [];
$requestStatement = $conn->prepare(
    "SELECT
        pr.id,
        pr.request_no,
        pr.status,
        pr.request_type,
        pr.needed_date,
        pr.site_location,
        pr.remarks,
        pr.engineer_review_notes,
        pr.engineer_reviewed_at,
        pr.created_at,
        p.project_name,
        reviewer.full_name AS engineer_reviewer_name,
        pri.item_description,
        pri.specification,
        pri.unit,
        pri.quantity_requested,
        pri.estimated_unit_cost
     FROM purchase_requests pr
     INNER JOIN projects p ON p.id = pr.project_id
     LEFT JOIN users reviewer ON reviewer.id = pr.engineer_reviewed_by
     LEFT JOIN purchase_request_items pri ON pri.purchase_request_id = pr.id
     WHERE pr.requested_by = ?
     ORDER BY pr.created_at DESC, pri.id ASC
     LIMIT 20"
);

if ($requestStatement) {
    $requestStatement->bind_param('i', $userId);
    $requestStatement->execute();
    $result = $requestStatement->get_result();
    $requestRows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $requestStatement->close();
}

$pendingCount = 0;
$approvedCount = 0;
$rejectedCount = 0;

foreach ($requestRows as $requestRow) {
    $status = (string)($requestRow['status'] ?? '');
    if (in_array($status, ['submitted', 'engineer_review'], true)) {
        $pendingCount++;
    } elseif (in_array($status, ['engineer_approved', 'approved'], true)) {
        $approvedCount++;
    } elseif ($status === 'engineer_rejected') {
        $rejectedCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Foreman Procurement - Edge Automation</title>
    <link rel="stylesheet" href="../css/sidebar_foreman.css">
    <link rel="stylesheet" href="../css/foreman_dashboard.css">
    <link rel="stylesheet" href="../css/qr_scanner.css">
</head>
<body>
<?php include __DIR__ . '/../sidebar/sidebar_foreman.php'; ?>

<main class="main-content">
    <div class="page-shell">
        <section class="page-hero">
            <div class="page-hero__content">
                <span class="page-hero__eyebrow">Procurement</span>
                <h1 class="page-hero__title">Purchase Request Intake</h1>
                <p class="page-hero__copy">
                    Submit material, tool, equipment, or service requests for your assigned projects.
                    Engineer reviews the request first before procurement and supplier handling move forward.
                </p>
                <p class="page-hero__copy page-hero__copy--compact"><?php echo htmlspecialchars($projectRoleSummary); ?></p>
                <div class="hero-actions">
                    <a class="btn-primary" href="#create-purchase-request">Create Request</a>
                    <a class="btn-secondary" href="/codesamplecaps/FOREMAN/dashboards/projects.php">View Projects</a>
                </div>
            </div>

            <aside class="page-hero__aside">
                <div class="aside-stat">
                    <span>Assigned Projects</span>
                    <strong><?php echo count($assignedProjects); ?></strong>
                </div>
                <div class="aside-stat">
                    <span>Pending Review</span>
                    <strong><?php echo $pendingCount; ?></strong>
                </div>
                <div class="aside-stat">
                    <span>Approved</span>
                    <strong><?php echo $approvedCount; ?></strong>
                </div>
                <div class="aside-stat">
                    <span>Rejected</span>
                    <strong><?php echo $rejectedCount; ?></strong>
                </div>
            </aside>
        </section>

        <?php if ($flash): ?>
            <div class="foreman-flash foreman-flash--<?php echo htmlspecialchars((string)($flash['type'] ?? 'error')); ?>">
                <?php echo htmlspecialchars((string)($flash['message'] ?? '')); ?>
            </div>
        <?php endif; ?>

        <section class="panel-card" id="create-purchase-request">
            <div class="section-heading">
                <div>
                    <span class="section-badge">Request Form</span>
                    <h2><?php echo $editRequest ? 'Edit Purchase Request' : 'Create Purchase Request'; ?></h2>
                    <p>Foreman submits the request, Engineer validates it and creates the purchase order, then Super Admin gives the final approval.</p>
                </div>
            </div>

            <?php if (empty($assignedProjects)): ?>
                <div class="empty-state">You need an assigned project first before creating a purchase request.</div>
            <?php else: ?>
                <form method="POST" class="foreman-form-grid">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="<?php echo $editRequest ? 'update_purchase_request' : 'create_purchase_request'; ?>">
                    <?php if ($editRequest): ?>
                        <input type="hidden" name="purchase_request_id" value="<?php echo (int)$editRequest['id']; ?>">
                    <?php endif; ?>
                    <div class="foreman-input-group">
                        <label for="project_id">Project</label>
                        <select id="project_id" name="project_id" required>
                            <option value="">Select assigned project</option>
                            <?php foreach ($assignedProjects as $project): ?>
                                <option value="<?php echo (int)($project['id'] ?? 0); ?>" <?php echo $requestFormValues['project_id'] === (string)($project['id'] ?? '') ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string)($project['project_name'] ?? 'Project')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="foreman-input-group">
                        <label for="request_type">Type</label>
                        <select id="request_type" name="request_type" required>
                            <option value="material" <?php echo $requestFormValues['request_type'] === 'material' ? 'selected' : ''; ?>>Material</option>
                            <option value="tool" <?php echo $requestFormValues['request_type'] === 'tool' ? 'selected' : ''; ?>>Tool</option>
                            <option value="equipment" <?php echo $requestFormValues['request_type'] === 'equipment' ? 'selected' : ''; ?>>Equipment</option>
                            <option value="service" <?php echo $requestFormValues['request_type'] === 'service' ? 'selected' : ''; ?>>Service</option>
                        </select>
                    </div>
                    <div class="foreman-input-group">
                        <label for="needed_date">Needed Date</label>
                        <input type="date" id="needed_date" name="needed_date" value="<?php echo htmlspecialchars($requestFormValues['needed_date']); ?>" required>
                    </div>
                    <div class="foreman-input-group">
                        <label for="site_location">Site Location</label>
                        <input type="text" id="site_location" name="site_location" value="<?php echo htmlspecialchars($requestFormValues['site_location']); ?>" placeholder="Area, floor, or work zone" required>
                    </div>
                    <div class="foreman-input-group foreman-input-group--wide">
                        <label for="item_description">Item Description</label>
                        <input type="text" id="item_description" name="item_description" value="<?php echo htmlspecialchars($requestFormValues['item_description']); ?>" placeholder="Cement, conduit, rebar, hand tool" required>
                    </div>
                    <div class="foreman-input-group">
                        <label for="specification">Specification</label>
                        <input type="text" id="specification" name="specification" value="<?php echo htmlspecialchars($requestFormValues['specification']); ?>" placeholder="Size, grade, brand">
                    </div>
                    <div class="foreman-input-group">
                        <label for="unit">Unit</label>
                        <input type="text" id="unit" name="unit" value="<?php echo htmlspecialchars($requestFormValues['unit']); ?>" placeholder="bags, pcs, rolls" required>
                    </div>
                    <div class="foreman-input-group">
                        <label for="quantity_requested">Quantity</label>
                        <input type="number" id="quantity_requested" name="quantity_requested" min="0.01" step="0.01" value="<?php echo htmlspecialchars($requestFormValues['quantity_requested']); ?>" required>
                    </div>
                    <div class="foreman-input-group">
                        <label for="estimated_unit_cost">Estimated Unit Cost</label>
                        <input type="number" id="estimated_unit_cost" name="estimated_unit_cost" min="0" step="0.01" value="<?php echo htmlspecialchars($requestFormValues['estimated_unit_cost']); ?>">
                    </div>
                    <div class="foreman-input-group foreman-input-group--wide">
                        <label for="remarks">Remarks</label>
                        <textarea id="remarks" name="remarks" rows="3" placeholder="Why this is needed and what activity it supports"><?php echo htmlspecialchars($requestFormValues['remarks']); ?></textarea>
                    </div>
                    <div class="foreman-form-actions">
                        <button type="submit" class="btn-primary"><?php echo $editRequest ? 'Update Request' : 'Submit Request'; ?></button>
                        <?php if ($editRequest): ?>
                            <a href="/codesamplecaps/FOREMAN/dashboards/procurement.php#create-purchase-request" class="btn-secondary">Cancel Edit</a>
                        <?php endif; ?>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <section class="panel-card">
            <div class="section-heading">
                <div>
                    <span class="section-badge">Tracking</span>
                    <h2>My Purchase Requests</h2>
                    <p>Follow request status here while waiting for engineer review and approval.</p>
                </div>
            </div>

            <?php if (empty($requestRows)): ?>
                <div class="empty-state">No purchase requests submitted yet.</div>
            <?php else: ?>
                <div class="project-list">
                    <?php foreach ($requestRows as $requestRow): ?>
                        <article class="project-card">
                            <div class="project-card__header">
                                <div>
                                    <span class="section-badge"><?php echo htmlspecialchars((string)($requestRow['request_no'] ?? '')); ?></span>
                                    <h3><?php echo htmlspecialchars((string)($requestRow['item_description'] ?? 'Request Item')); ?></h3>
                                </div>
                                <span class="status-badge status-badge--<?php echo htmlspecialchars((string)($requestRow['status'] ?? 'submitted')); ?>">
                                    <?php echo htmlspecialchars(foreman_status_label((string)($requestRow['status'] ?? 'submitted'))); ?>
                                </span>
                            </div>

                            <div class="project-meta">
                                <div>
                                    <span>Project</span>
                                    <strong><?php echo htmlspecialchars((string)($requestRow['project_name'] ?? 'N/A')); ?></strong>
                                </div>
                                <div>
                                    <span>Type</span>
                                    <strong><?php echo htmlspecialchars(ucfirst((string)($requestRow['request_type'] ?? 'material'))); ?></strong>
                                </div>
                                <div>
                                    <span>Quantity</span>
                                    <strong><?php echo htmlspecialchars((string)($requestRow['quantity_requested'] ?? '0')); ?> <?php echo htmlspecialchars((string)($requestRow['unit'] ?? '')); ?></strong>
                                </div>
                                <div>
                                    <span>Needed Date</span>
                                    <strong><?php echo htmlspecialchars(foreman_format_date($requestRow['needed_date'] ?? null)); ?></strong>
                                </div>
                                <div>
                                    <span>Site Location</span>
                                    <strong><?php echo htmlspecialchars((string)($requestRow['site_location'] ?? 'Not set')); ?></strong>
                                </div>
                                <div>
                                    <span>Engineer Review</span>
                                    <strong><?php echo htmlspecialchars((string)($requestRow['engineer_reviewer_name'] ?? 'Pending')); ?></strong>
                                </div>
                            </div>

                            <?php if (!empty($requestRow['specification'])): ?>
                                <p><strong>Specification:</strong> <?php echo htmlspecialchars((string)$requestRow['specification']); ?></p>
                            <?php endif; ?>

                            <?php if (!empty($requestRow['remarks'])): ?>
                                <p><strong>Request Note:</strong> <?php echo htmlspecialchars((string)$requestRow['remarks']); ?></p>
                            <?php endif; ?>

                            <?php if (!empty($requestRow['engineer_review_notes'])): ?>
                                <p><strong>Engineer Note:</strong> <?php echo htmlspecialchars((string)$requestRow['engineer_review_notes']); ?></p>
                            <?php endif; ?>

                            <?php if (($requestRow['status'] ?? '') === 'submitted'): ?>
                                <div class="project-card__actions">
                                    <a href="/codesamplecaps/FOREMAN/dashboards/procurement.php?edit=<?php echo (int)$requestRow['id']; ?>#create-purchase-request" class="btn-secondary">Edit</a>
                                    <form method="POST" onsubmit="return confirm('Cancel this submitted purchase request?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                        <input type="hidden" name="action" value="cancel_purchase_request">
                                        <input type="hidden" name="purchase_request_id" value="<?php echo (int)$requestRow['id']; ?>">
                                        <button type="submit" class="btn-danger">Cancel Request</button>
                                    </form>
                                </div>
                            <?php elseif (($requestRow['status'] ?? '') === 'engineer_review'): ?>
                                <p class="foreman-inline-note">Engineer review already started, so this request is now locked for editing.</p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>

<div class="qr-modal" id="qrScannerModal" aria-hidden="true">
    <div class="qr-modal-content">
        <div class="qr-modal-header">
            <h2>QR Asset Scanner</h2>
            <button id="qrScannerClose" class="qr-close" type="button" aria-label="Close">X</button>
        </div>
        <div class="qr-modal-body">
            <div class="qr-status" id="qrStatus">Ready to scan.</div>
            <div class="qr-scanner-area" id="qr-reader"></div>
            <div class="qr-error" id="qrScannerError"></div>
            <div class="qr-asset-info" id="qrAssetInfo"></div>
            <div class="qr-input-row">
                <input id="qrWorkerName" placeholder="Worker / personnel name" aria-label="Worker name">
                <textarea id="qrNotes" rows="2" placeholder="Optional notes" aria-label="Notes"></textarea>
            </div>
            <div class="qr-actions">
                <button class="btn-primary" id="qrLogUsage" type="button">Log Usage</button>
                <button class="btn-secondary" id="qrScannerCloseSecondary" type="button">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="../js/sidebar_foreman.js"></script>
<script src="../js/html5-qrcode.min.js"></script>
<script src="../js/qr_scanner_foreman.js"></script>
</body>
</html>
