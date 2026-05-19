<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/audit_log.php';
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../includes/engineer_helpers.php';

require_role('engineer');

const ENGINEER_SUPPLIER_DRAFT_SESSION_KEY = 'engineer_supplier_form_draft';

function engineer_procurement_redirect(string $url = '/codesamplecaps/ENGINEER/dashboards/procurement.php'): void
{
    header('Location: ' . $url);
    exit();
}

function engineer_procurement_normalize_text(?string $value): string
{
    return trim((string)$value);
}

function engineer_procurement_normalize_text_or_null(?string $value): ?string
{
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function engineer_procurement_normalize_phone(?string $value): string
{
    return preg_replace('/\D+/', '', trim((string)$value)) ?? '';
}

function engineer_procurement_normalize_money_or_null($value): ?float
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

function engineer_procurement_generate_code(string $prefix): string
{
    return $prefix . '-' . date('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function engineer_procurement_supplier_form_defaults(): array
{
    return [
        'supplier_name' => '',
        'contact_person' => '',
        'contact_number' => '',
        'email' => '',
        'address' => '',
        'description' => '',
    ];
}

function engineer_procurement_supplier_form_from_post(): array
{
    return [
        'supplier_name' => engineer_procurement_normalize_text($_POST['supplier_name'] ?? ''),
        'contact_person' => engineer_procurement_normalize_text($_POST['contact_person'] ?? ''),
        'contact_number' => engineer_procurement_normalize_phone($_POST['contact_number'] ?? ''),
        'email' => engineer_procurement_normalize_text($_POST['email'] ?? ''),
        'address' => engineer_procurement_normalize_text($_POST['address'] ?? ''),
        'description' => engineer_procurement_normalize_text($_POST['description'] ?? ''),
    ];
}

function engineer_procurement_supplier_form_store(array $form, string $mode, int $supplierId = 0): void
{
    $_SESSION[ENGINEER_SUPPLIER_DRAFT_SESSION_KEY] = [
        'values' => $form,
        'mode' => $mode,
        'supplier_id' => $supplierId,
    ];
}

function engineer_procurement_supplier_form_read(): ?array
{
    $draft = $_SESSION[ENGINEER_SUPPLIER_DRAFT_SESSION_KEY] ?? null;
    return is_array($draft) ? $draft : null;
}

function engineer_procurement_supplier_form_clear(): void
{
    unset($_SESSION[ENGINEER_SUPPLIER_DRAFT_SESSION_KEY]);
}

function engineer_procurement_validate_supplier_form(array $form): ?string
{
    if ($form['supplier_name'] === '' || $form['contact_person'] === '' || $form['contact_number'] === '' || $form['address'] === '') {
        return 'Supplier name, contact person, contact number, and address are required.';
    }

    if (mb_strlen($form['supplier_name']) < 2) {
        return 'Supplier name must be at least 2 characters.';
    }

    if (mb_strlen($form['contact_person']) < 2) {
        return 'Contact person must be at least 2 characters.';
    }

    if (mb_strlen($form['address']) < 2) {
        return 'Address must be at least 2 characters.';
    }

    if (!preg_match('/^09\d{9}$/', $form['contact_number'])) {
        return 'Contact number must start with 09 and contain exactly 11 digits.';
    }

    if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        return 'Supplier email is invalid.';
    }

    return null;
}

function engineer_procurement_fetch_supplier(mysqli $conn, int $supplierId): ?array
{
    if ($supplierId <= 0) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT id, supplier_code, supplier_name, contact_person, contact_number, email, address, description, status
         FROM suppliers
         WHERE id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $supplierId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function engineer_procurement_supplier_name_exists(mysqli $conn, string $supplierName, int $ignoreSupplierId = 0): bool
{
    $sql = "SELECT id FROM suppliers WHERE LOWER(TRIM(supplier_name)) = LOWER(TRIM(?))";
    if ($ignoreSupplierId > 0) {
        $sql .= " AND id <> ?";
    }
    $sql .= " LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    if ($ignoreSupplierId > 0) {
        $stmt->bind_param('si', $supplierName, $ignoreSupplierId);
    } else {
        $stmt->bind_param('s', $supplierName);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $exists = (bool)($result && $result->fetch_assoc());
    $stmt->close();

    return $exists;
}

function engineer_procurement_ensure_purchase_requests_table(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS purchase_requests (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            request_no VARCHAR(80) NOT NULL UNIQUE,
            project_id INT(11) NOT NULL,
            requested_by INT(11) NOT NULL,
            engineer_reviewed_by INT(11) DEFAULT NULL,
            needed_date DATE DEFAULT NULL,
            request_type ENUM('material', 'tool', 'equipment', 'service') NOT NULL DEFAULT 'material',
            site_location VARCHAR(190) DEFAULT NULL,
            remarks TEXT DEFAULT NULL,
            engineer_review_notes TEXT DEFAULT NULL,
            status ENUM('draft', 'submitted', 'engineer_review', 'engineer_approved', 'engineer_rejected', 'approved', 'cancelled') NOT NULL DEFAULT 'submitted',
            engineer_reviewed_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_purchase_requests_project_status (project_id, status),
            KEY idx_purchase_requests_requested_by (requested_by),
            CONSTRAINT fk_purchase_requests_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT fk_purchase_requests_requested_by FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $columnCheck = $conn->query("SHOW COLUMNS FROM purchase_requests LIKE 'engineer_reviewed_by'");
    if (!$columnCheck || (int)$columnCheck->num_rows === 0) {
        $conn->query("ALTER TABLE purchase_requests ADD COLUMN engineer_reviewed_by INT(11) DEFAULT NULL AFTER requested_by");
        $conn->query("ALTER TABLE purchase_requests ADD COLUMN engineer_review_notes TEXT DEFAULT NULL AFTER remarks");
        $conn->query("ALTER TABLE purchase_requests ADD COLUMN engineer_reviewed_at DATETIME DEFAULT NULL AFTER engineer_review_notes");
    }
}

function engineer_procurement_ensure_purchase_request_items_table(mysqli $conn): void
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

function engineer_procurement_ensure_suppliers_table(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS suppliers (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            supplier_code VARCHAR(80) NOT NULL UNIQUE,
            supplier_name VARCHAR(190) NOT NULL,
            contact_person VARCHAR(150) DEFAULT NULL,
            contact_number VARCHAR(50) DEFAULT NULL,
            email VARCHAR(190) DEFAULT NULL,
            address TEXT DEFAULT NULL,
            description TEXT DEFAULT NULL,
            status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_suppliers_status_name (status, supplier_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

function engineer_procurement_ensure_supplier_description_column(mysqli $conn): void
{
    $columnCheck = $conn->query("SHOW COLUMNS FROM suppliers LIKE 'description'");
    if (!$columnCheck || (int)$columnCheck->num_rows === 0) {
        $conn->query("ALTER TABLE suppliers ADD COLUMN description TEXT DEFAULT NULL AFTER address");
    }
}

function engineer_procurement_ensure_purchase_orders_table(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS purchase_orders (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            po_no VARCHAR(80) NOT NULL UNIQUE,
            purchase_request_id INT(11) NOT NULL,
            project_id INT(11) NOT NULL,
            supplier_id INT(11) NOT NULL,
            order_date DATE NOT NULL,
            expected_delivery_date DATE DEFAULT NULL,
            remarks TEXT DEFAULT NULL,
            status ENUM('draft', 'issued', 'partially_delivered', 'delivered', 'cancelled', 'closed') NOT NULL DEFAULT 'issued',
            created_by INT(11) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_purchase_orders_request (purchase_request_id),
            KEY idx_purchase_orders_project_status (project_id, status),
            KEY idx_purchase_orders_supplier (supplier_id),
            CONSTRAINT fk_purchase_orders_request FOREIGN KEY (purchase_request_id) REFERENCES purchase_requests (id) ON DELETE CASCADE,
            CONSTRAINT fk_purchase_orders_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT fk_purchase_orders_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE CASCADE,
            CONSTRAINT fk_purchase_orders_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

function engineer_procurement_ensure_purchase_order_items_table(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS purchase_order_items (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            purchase_order_id INT(11) NOT NULL,
            purchase_request_item_id INT(11) DEFAULT NULL,
            item_description VARCHAR(255) NOT NULL,
            specification VARCHAR(255) DEFAULT NULL,
            unit VARCHAR(40) NOT NULL,
            quantity_ordered DECIMAL(12,2) NOT NULL,
            unit_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            line_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_purchase_order_items_order (purchase_order_id),
            CONSTRAINT fk_purchase_order_items_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id) ON DELETE CASCADE,
            CONSTRAINT fk_purchase_order_items_request_item FOREIGN KEY (purchase_request_item_id) REFERENCES purchase_request_items (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

function engineer_procurement_ensure_purchase_order_approval_columns(mysqli $conn): void
{
    $columnCheck = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'admin_approval_status'");
    if (!$columnCheck || (int)$columnCheck->num_rows === 0) {
        $conn->query(
            "ALTER TABLE purchase_orders
             ADD COLUMN admin_approval_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending' AFTER status"
        );
        $conn->query("ALTER TABLE purchase_orders ADD COLUMN admin_approved_by INT(11) DEFAULT NULL AFTER admin_approval_status");
        $conn->query("ALTER TABLE purchase_orders ADD COLUMN admin_approved_at DATETIME DEFAULT NULL AFTER admin_approved_by");
        $conn->query("ALTER TABLE purchase_orders ADD COLUMN admin_approval_notes TEXT DEFAULT NULL AFTER admin_approved_at");
    }
}

function engineer_procurement_is_assigned(mysqli $conn, int $projectId, int $engineerId): bool
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

    $stmt->bind_param('ii', $projectId, $engineerId);
    $stmt->execute();
    $result = $stmt->get_result();

    return (bool)($result && $result->fetch_assoc());
}

engineer_procurement_ensure_purchase_requests_table($conn);
engineer_procurement_ensure_purchase_request_items_table($conn);
engineer_procurement_ensure_suppliers_table($conn);
engineer_procurement_ensure_supplier_description_column($conn);
engineer_procurement_ensure_purchase_orders_table($conn);
engineer_procurement_ensure_purchase_order_items_table($conn);
engineer_procurement_ensure_purchase_order_approval_columns($conn);

$userId = (int)($_SESSION['user_id'] ?? 0);
$flash = engineer_consume_flash();
$csrfToken = engineer_get_csrf_token();

if (isset($_GET['clear_supplier_form']) && $_GET['clear_supplier_form'] === '1') {
    engineer_procurement_supplier_form_clear();
    engineer_procurement_redirect('/codesamplecaps/ENGINEER/dashboards/procurement.php#create-supplier');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!engineer_is_valid_csrf_token($_POST['csrf_token'] ?? null)) {
        engineer_set_flash('error', 'Security check failed.');
        engineer_procurement_redirect();
    }

    if ($action === 'review_purchase_request') {
        $purchaseRequestId = (int)($_POST['purchase_request_id'] ?? 0);
        $reviewAction = trim((string)($_POST['review_action'] ?? ''));
        $reviewNotes = engineer_procurement_normalize_text_or_null($_POST['engineer_review_notes'] ?? null);

        if ($purchaseRequestId <= 0 || !in_array($reviewAction, ['engineer_review', 'engineer_approved', 'engineer_rejected'], true)) {
            engineer_set_flash('error', 'Invalid procurement review action.');
            engineer_procurement_redirect();
        }

        $snapshotStmt = $conn->prepare(
            "SELECT pr.id, pr.project_id, pr.request_no, pr.status, p.project_name
             FROM purchase_requests pr
             INNER JOIN projects p ON p.id = pr.project_id
             WHERE pr.id = ?
             LIMIT 1"
        );

        if (!$snapshotStmt) {
            engineer_set_flash('error', 'Failed to load request.');
            engineer_procurement_redirect();
        }

        $snapshotStmt->bind_param('i', $purchaseRequestId);
        $snapshotStmt->execute();
        $snapshot = $snapshotStmt->get_result()->fetch_assoc();

        if (!$snapshot || !engineer_procurement_is_assigned($conn, (int)$snapshot['project_id'], $userId)) {
            engineer_set_flash('error', 'You cannot review this request.');
            engineer_procurement_redirect();
        }

        if (!in_array((string)($snapshot['status'] ?? ''), ['submitted', 'engineer_review'], true)) {
            engineer_set_flash('error', 'This request is already finalized for engineer review.');
            engineer_procurement_redirect();
        }

        $updateStmt = $conn->prepare(
            'UPDATE purchase_requests
             SET status = ?, engineer_reviewed_by = ?, engineer_review_notes = ?, engineer_reviewed_at = NOW()
             WHERE id = ?'
        );

        if (
            $updateStmt &&
            $updateStmt->bind_param('sisi', $reviewAction, $userId, $reviewNotes, $purchaseRequestId) &&
            $updateStmt->execute()
        ) {
            audit_log_event(
                $conn,
                $userId,
                'engineer_purchase_request_review',
                'purchase_request',
                $purchaseRequestId,
                ['status' => $snapshot['status'] ?? null],
                [
                    'request_no' => $snapshot['request_no'] ?? null,
                    'project_name' => $snapshot['project_name'] ?? null,
                    'status' => $reviewAction,
                    'engineer_review_notes' => $reviewNotes,
                ]
            );
            engineer_set_flash('success', 'Purchase request review saved.');
        } else {
            engineer_set_flash('error', 'Failed to save engineer review.');
        }

        engineer_procurement_redirect();
    }

    if ($action === 'create_supplier' || $action === 'update_supplier') {
        $supplierForm = engineer_procurement_supplier_form_from_post();
        $supplierId = (int)($_POST['supplier_id'] ?? 0);
        $isUpdate = $action === 'update_supplier';

        engineer_procurement_supplier_form_store($supplierForm, $isUpdate ? 'edit' : 'create', $supplierId);

        if ($isUpdate && $supplierId <= 0) {
            engineer_set_flash('error', 'Invalid supplier selected for update.');
            engineer_procurement_redirect('/codesamplecaps/ENGINEER/dashboards/procurement.php#create-supplier');
        }

        $validationMessage = engineer_procurement_validate_supplier_form($supplierForm);
        if ($validationMessage !== null) {
            engineer_set_flash('error', $validationMessage);
            $redirectUrl = $isUpdate
                ? '/codesamplecaps/ENGINEER/dashboards/procurement.php?supplier_edit=' . $supplierId . '#create-supplier'
                : '/codesamplecaps/ENGINEER/dashboards/procurement.php#create-supplier';
            engineer_procurement_redirect($redirectUrl);
        }

        if (engineer_procurement_supplier_name_exists($conn, $supplierForm['supplier_name'], $isUpdate ? $supplierId : 0)) {
            engineer_set_flash('error', 'Supplier name already exists.');
            $redirectUrl = $isUpdate
                ? '/codesamplecaps/ENGINEER/dashboards/procurement.php?supplier_edit=' . $supplierId . '#create-supplier'
                : '/codesamplecaps/ENGINEER/dashboards/procurement.php#create-supplier';
            engineer_procurement_redirect($redirectUrl);
        }

        if ($isUpdate) {
            $existingSupplier = engineer_procurement_fetch_supplier($conn, $supplierId);
            if (!$existingSupplier) {
                engineer_set_flash('error', 'Supplier not found.');
                engineer_procurement_redirect('/codesamplecaps/ENGINEER/dashboards/procurement.php#create-supplier');
            }

            $stmt = $conn->prepare(
                'UPDATE suppliers
                 SET supplier_name = ?, contact_person = ?, contact_number = ?, email = ?, address = ?, description = ?
                 WHERE id = ?'
            );

            $emailValue = $supplierForm['email'] !== '' ? $supplierForm['email'] : null;
            $descriptionValue = $supplierForm['description'] !== '' ? $supplierForm['description'] : null;

            if (
                $stmt &&
                $stmt->bind_param(
                    'ssssssi',
                    $supplierForm['supplier_name'],
                    $supplierForm['contact_person'],
                    $supplierForm['contact_number'],
                    $emailValue,
                    $supplierForm['address'],
                    $descriptionValue,
                    $supplierId
                ) &&
                $stmt->execute()
            ) {
                audit_log_event(
                    $conn,
                    $userId,
                    'engineer_update_supplier',
                    'supplier',
                    $supplierId,
                    $existingSupplier,
                    [
                        'supplier_name' => $supplierForm['supplier_name'],
                        'contact_person' => $supplierForm['contact_person'],
                        'contact_number' => $supplierForm['contact_number'],
                        'email' => $emailValue,
                        'address' => $supplierForm['address'],
                        'description' => $descriptionValue,
                    ]
                );
                engineer_procurement_supplier_form_clear();
                engineer_set_flash('success', 'Supplier updated.');
                engineer_procurement_redirect('/codesamplecaps/ENGINEER/dashboards/procurement.php?supplier_form_reset=1#create-supplier');
            } else {
                engineer_set_flash('error', 'Failed to update supplier.');
                engineer_procurement_redirect('/codesamplecaps/ENGINEER/dashboards/procurement.php?supplier_edit=' . $supplierId . '#create-supplier');
            }
        }

        $supplierCode = engineer_procurement_generate_code('SUP');
        $stmt = $conn->prepare(
            'INSERT INTO suppliers (supplier_code, supplier_name, contact_person, contact_number, email, address, description)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $emailValue = $supplierForm['email'] !== '' ? $supplierForm['email'] : null;
        $descriptionValue = $supplierForm['description'] !== '' ? $supplierForm['description'] : null;

        if (
            $stmt &&
            $stmt->bind_param(
                'sssssss',
                $supplierCode,
                $supplierForm['supplier_name'],
                $supplierForm['contact_person'],
                $supplierForm['contact_number'],
                $emailValue,
                $supplierForm['address'],
                $descriptionValue
            ) &&
            $stmt->execute()
        ) {
            audit_log_event(
                $conn,
                $userId,
                'engineer_create_supplier',
                'supplier',
                (int)$stmt->insert_id,
                null,
                [
                    'supplier_code' => $supplierCode,
                    'supplier_name' => $supplierForm['supplier_name'],
                    'contact_person' => $supplierForm['contact_person'],
                    'contact_number' => $supplierForm['contact_number'],
                    'email' => $emailValue,
                    'address' => $supplierForm['address'],
                    'description' => $descriptionValue,
                ]
            );
            engineer_procurement_supplier_form_clear();
            engineer_set_flash('success', 'Supplier added.');
            engineer_procurement_redirect('/codesamplecaps/ENGINEER/dashboards/procurement.php?supplier_form_reset=1#create-supplier');
        } else {
            engineer_set_flash('error', 'Failed to add supplier.');
            engineer_procurement_redirect('/codesamplecaps/ENGINEER/dashboards/procurement.php#create-supplier');
        }
    }

    if ($action === 'trash_supplier') {
        $supplierId = (int)($_POST['supplier_id'] ?? 0);
        if ($supplierId <= 0) {
            engineer_set_flash('error', 'Invalid supplier.');
            engineer_procurement_redirect('/codesamplecaps/ENGINEER/dashboards/procurement.php#create-supplier');
        }

        $existingSupplier = engineer_procurement_fetch_supplier($conn, $supplierId);
        if (!$existingSupplier) {
            engineer_set_flash('error', 'Supplier not found.');
            engineer_procurement_redirect('/codesamplecaps/ENGINEER/dashboards/procurement.php#create-supplier');
        }

        $stmt = $conn->prepare("UPDATE suppliers SET status = 'inactive' WHERE id = ?");
        if ($stmt && $stmt->bind_param('i', $supplierId) && $stmt->execute()) {
            audit_log_event(
                $conn,
                $userId,
                'engineer_trash_supplier',
                'supplier',
                $supplierId,
                $existingSupplier,
                ['status' => 'inactive']
            );
            engineer_set_flash('success', 'Supplier moved to trash bin.');
        } else {
            engineer_set_flash('error', 'Failed to move supplier to trash bin.');
        }

        engineer_procurement_redirect('/codesamplecaps/ENGINEER/dashboards/procurement.php#create-supplier');
    }

    if ($action === 'restore_supplier') {
        $supplierId = (int)($_POST['supplier_id'] ?? 0);
        if ($supplierId <= 0) {
            engineer_set_flash('error', 'Invalid supplier.');
            engineer_procurement_redirect('/codesamplecaps/ENGINEER/dashboards/procurement.php#create-supplier');
        }

        $existingSupplier = engineer_procurement_fetch_supplier($conn, $supplierId);
        if (!$existingSupplier) {
            engineer_set_flash('error', 'Supplier not found.');
            engineer_procurement_redirect('/codesamplecaps/ENGINEER/dashboards/procurement.php#create-supplier');
        }

        $stmt = $conn->prepare("UPDATE suppliers SET status = 'active' WHERE id = ?");
        if ($stmt && $stmt->bind_param('i', $supplierId) && $stmt->execute()) {
            audit_log_event(
                $conn,
                $userId,
                'engineer_restore_supplier',
                'supplier',
                $supplierId,
                $existingSupplier,
                ['status' => 'active']
            );
            engineer_set_flash('success', 'Supplier restored.');
        } else {
            engineer_set_flash('error', 'Failed to restore supplier.');
        }

        engineer_procurement_redirect('/codesamplecaps/ENGINEER/dashboards/procurement.php#create-supplier');
    }

    if ($action === 'create_purchase_order') {
        $purchaseRequestId = (int)($_POST['purchase_request_id'] ?? 0);
        $supplierId = (int)($_POST['supplier_id'] ?? 0);
        $orderDate = engineer_procurement_normalize_text_or_null($_POST['order_date'] ?? null);
        $expectedDeliveryDate = engineer_procurement_normalize_text_or_null($_POST['expected_delivery_date'] ?? null);
        $poRemarks = engineer_procurement_normalize_text_or_null($_POST['po_remarks'] ?? null);

        if ($purchaseRequestId <= 0 || $supplierId <= 0 || $orderDate === null) {
            engineer_set_flash('error', 'Approved request, supplier, and order date are required.');
            engineer_procurement_redirect();
        }

        $requestSnapshotStmt = $conn->prepare(
            "SELECT pr.id, pr.request_no, pr.project_id, pr.status, p.project_name
             FROM purchase_requests pr
             INNER JOIN projects p ON p.id = pr.project_id
             WHERE pr.id = ?
             LIMIT 1"
        );

        if (!$requestSnapshotStmt) {
            engineer_set_flash('error', 'Failed to load request.');
            engineer_procurement_redirect();
        }

        $requestSnapshotStmt->bind_param('i', $purchaseRequestId);
        $requestSnapshotStmt->execute();
        $requestSnapshot = $requestSnapshotStmt->get_result()->fetch_assoc();

        if (
            !$requestSnapshot ||
            !engineer_procurement_is_assigned($conn, (int)$requestSnapshot['project_id'], $userId)
        ) {
            engineer_set_flash('error', 'You cannot create a purchase order for this request.');
            engineer_procurement_redirect();
        }

        if ((string)($requestSnapshot['status'] ?? '') !== 'engineer_approved') {
            engineer_set_flash('error', 'Only engineer-approved requests can be converted to purchase orders.');
            engineer_procurement_redirect();
        }

        $duplicateCheck = $conn->prepare('SELECT id, po_no FROM purchase_orders WHERE purchase_request_id = ? LIMIT 1');
        if ($duplicateCheck) {
            $duplicateCheck->bind_param('i', $purchaseRequestId);
            $duplicateCheck->execute();
            $existingPurchaseOrder = $duplicateCheck->get_result()->fetch_assoc();
            if ($existingPurchaseOrder) {
                engineer_set_flash('error', 'This request already has a purchase order: ' . (string)$existingPurchaseOrder['po_no']);
                engineer_procurement_redirect();
            }
        }

        $requestItemStmt = $conn->prepare(
            "SELECT id, item_description, specification, unit, quantity_requested, COALESCE(estimated_unit_cost, 0) AS estimated_unit_cost
             FROM purchase_request_items
             WHERE purchase_request_id = ?
             ORDER BY id ASC"
        );

        if (!$requestItemStmt) {
            engineer_set_flash('error', 'Failed to load request items.');
            engineer_procurement_redirect();
        }

        $requestItemStmt->bind_param('i', $purchaseRequestId);
        $requestItemStmt->execute();
        $requestItems = $requestItemStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        if (empty($requestItems)) {
            engineer_set_flash('error', 'Cannot create a purchase order without request items.');
            engineer_procurement_redirect();
        }

        $poNo = engineer_procurement_generate_code('PO');
        $conn->begin_transaction();

        try {
            $insertPo = $conn->prepare(
                'INSERT INTO purchase_orders (
                    po_no,
                    purchase_request_id,
                    project_id,
                    supplier_id,
                    order_date,
                    expected_delivery_date,
                    remarks,
                    status,
                    admin_approval_status,
                    created_by
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $poStatus = 'issued';
            $approvalStatus = 'pending';

            if (
                !$insertPo ||
                !$insertPo->bind_param(
                    'siiisssssi',
                    $poNo,
                    $purchaseRequestId,
                    $requestSnapshot['project_id'],
                    $supplierId,
                    $orderDate,
                    $expectedDeliveryDate,
                    $poRemarks,
                    $poStatus,
                    $approvalStatus,
                    $userId
                ) ||
                !$insertPo->execute()
            ) {
                throw new RuntimeException('Failed to create purchase order.');
            }

            $purchaseOrderId = (int)$insertPo->insert_id;
            $insertPoItem = $conn->prepare(
                'INSERT INTO purchase_order_items (
                    purchase_order_id,
                    purchase_request_item_id,
                    item_description,
                    specification,
                    unit,
                    quantity_ordered,
                    unit_price,
                    line_total
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );

            if (!$insertPoItem) {
                throw new RuntimeException('Failed to prepare purchase order items.');
            }

            foreach ($requestItems as $item) {
                $quantityOrdered = (float)($item['quantity_requested'] ?? 0);
                $unitPrice = (float)($item['estimated_unit_cost'] ?? 0);
                $lineTotal = round($quantityOrdered * $unitPrice, 2);

                if (
                    !$insertPoItem->bind_param(
                        'iisssddd',
                        $purchaseOrderId,
                        $item['id'],
                        $item['item_description'],
                        $item['specification'],
                        $item['unit'],
                        $quantityOrdered,
                        $unitPrice,
                        $lineTotal
                    ) ||
                    !$insertPoItem->execute()
                ) {
                    throw new RuntimeException('Failed to save purchase order item.');
                }
            }

            $updateRequest = $conn->prepare('UPDATE purchase_requests SET status = ? WHERE id = ?');
            $convertedStatus = 'approved';
            if (
                !$updateRequest ||
                !$updateRequest->bind_param('si', $convertedStatus, $purchaseRequestId) ||
                !$updateRequest->execute()
            ) {
                throw new RuntimeException('Failed to update request status after PO creation.');
            }

            $conn->commit();

            audit_log_event(
                $conn,
                $userId,
                'engineer_create_purchase_order',
                'purchase_order',
                $purchaseOrderId,
                null,
                [
                    'po_no' => $poNo,
                    'request_no' => $requestSnapshot['request_no'] ?? null,
                    'project_name' => $requestSnapshot['project_name'] ?? null,
                    'supplier_id' => $supplierId,
                    'admin_approval_status' => 'pending',
                ]
            );

            engineer_set_flash('success', 'Purchase order created and sent for Super Admin approval.');
        } catch (Throwable $exception) {
            $conn->rollback();
            engineer_set_flash('error', $exception->getMessage());
        }

        engineer_procurement_redirect();
    }
}

$supplierEditId = (int)($_GET['supplier_edit'] ?? 0);
$supplierEditRecord = $supplierEditId > 0 ? engineer_procurement_fetch_supplier($conn, $supplierEditId) : null;
if ($supplierEditId > 0 && !$supplierEditRecord) {
    engineer_set_flash('error', 'Supplier not found for editing.');
    engineer_procurement_redirect('/codesamplecaps/ENGINEER/dashboards/procurement.php#create-supplier');
}

$supplierDraft = engineer_procurement_supplier_form_read();
$supplierFormValues = engineer_procurement_supplier_form_defaults();
$isSupplierEditMode = $supplierEditRecord !== null;

if ($isSupplierEditMode) {
    $supplierFormValues = [
        'supplier_name' => (string)($supplierEditRecord['supplier_name'] ?? ''),
        'contact_person' => (string)($supplierEditRecord['contact_person'] ?? ''),
        'contact_number' => (string)($supplierEditRecord['contact_number'] ?? ''),
        'email' => (string)($supplierEditRecord['email'] ?? ''),
        'address' => (string)($supplierEditRecord['address'] ?? ''),
        'description' => (string)($supplierEditRecord['description'] ?? ''),
    ];
}

if (is_array($supplierDraft)) {
    $draftMode = (string)($supplierDraft['mode'] ?? 'create');
    $draftSupplierId = (int)($supplierDraft['supplier_id'] ?? 0);
    $draftValues = is_array($supplierDraft['values'] ?? null) ? $supplierDraft['values'] : [];

    if ($draftMode === 'create' && !$isSupplierEditMode) {
        $supplierFormValues = array_merge($supplierFormValues, $draftValues);
    }

    if ($draftMode === 'edit' && $isSupplierEditMode && $draftSupplierId === $supplierEditId) {
        $supplierFormValues = array_merge($supplierFormValues, $draftValues);
    }
}

$requests = [];
$requestsStmt = $conn->prepare(
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
        p.project_name,
        requester.full_name AS requested_by_name,
        pri.item_description,
        pri.specification,
        pri.unit,
        pri.quantity_requested,
        po.po_no,
        po.admin_approval_status,
        po.admin_approval_notes
     FROM purchase_requests pr
     INNER JOIN projects p ON p.id = pr.project_id
     INNER JOIN project_assignments pa ON pa.project_id = p.id
     INNER JOIN users requester ON requester.id = pr.requested_by
     LEFT JOIN purchase_request_items pri ON pri.purchase_request_id = pr.id
     LEFT JOIN purchase_orders po ON po.purchase_request_id = pr.id
     WHERE pa.engineer_id = ?
     ORDER BY
        CASE
            WHEN pr.status = 'submitted' THEN 1
            WHEN pr.status = 'engineer_review' THEN 2
            WHEN pr.status = 'engineer_approved' THEN 3
            WHEN pr.status = 'approved' THEN 4
            WHEN pr.status = 'engineer_rejected' THEN 5
            ELSE 6
        END,
        pr.created_at DESC"
);

if ($requestsStmt) {
    $requestsStmt->bind_param('i', $userId);
    $requestsStmt->execute();
    $result = $requestsStmt->get_result();
    $requests = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

$activeSuppliers = [];
$activeSupplierResult = $conn->query(
    "SELECT id, supplier_name, supplier_code
     FROM suppliers
     WHERE status = 'active'
     ORDER BY supplier_name ASC"
);
if ($activeSupplierResult) {
    $activeSuppliers = $activeSupplierResult->fetch_all(MYSQLI_ASSOC);
}

$supplierRows = [];
$supplierResult = $conn->query(
    "SELECT id, supplier_code, supplier_name, contact_person, contact_number, email, address, description, status
     FROM suppliers
     WHERE status = 'active'
     ORDER BY created_at DESC
     LIMIT 8"
);
if ($supplierResult) {
    $supplierRows = $supplierResult->fetch_all(MYSQLI_ASSOC);
}

$trashedSupplierRows = [];
$trashedSupplierResult = $conn->query(
    "SELECT id, supplier_code, supplier_name, contact_person, contact_number, email, address, description, status
     FROM suppliers
     WHERE status = 'inactive'
     ORDER BY updated_at DESC, created_at DESC
     LIMIT 8"
);
if ($trashedSupplierResult) {
    $trashedSupplierRows = $trashedSupplierResult->fetch_all(MYSQLI_ASSOC);
}

$engineerApprovedRequests = [];
$approvedRequestsStmt = $conn->prepare(
    "SELECT
        pr.id,
        pr.request_no,
        p.project_name,
        pri.item_description
     FROM purchase_requests pr
     INNER JOIN projects p ON p.id = pr.project_id
     INNER JOIN project_assignments pa ON pa.project_id = p.id
     LEFT JOIN purchase_request_items pri ON pri.purchase_request_id = pr.id
     LEFT JOIN purchase_orders po ON po.purchase_request_id = pr.id
     WHERE pa.engineer_id = ?
     AND pr.status = 'engineer_approved'
     AND po.id IS NULL
     ORDER BY pr.created_at DESC"
);
if ($approvedRequestsStmt) {
    $approvedRequestsStmt->bind_param('i', $userId);
    $approvedRequestsStmt->execute();
    $result = $approvedRequestsStmt->get_result();
    $engineerApprovedRequests = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

$purchaseOrders = [];
$purchaseOrdersStmt = $conn->prepare(
    "SELECT
        po.id,
        po.po_no,
        po.order_date,
        po.expected_delivery_date,
        po.remarks,
        po.status,
        po.admin_approval_status,
        po.admin_approval_notes,
        po.admin_approved_at,
        p.project_name,
        pr.request_no,
        s.supplier_name
     FROM purchase_orders po
     INNER JOIN projects p ON p.id = po.project_id
     INNER JOIN project_assignments pa ON pa.project_id = p.id
     INNER JOIN purchase_requests pr ON pr.id = po.purchase_request_id
     INNER JOIN suppliers s ON s.id = po.supplier_id
     WHERE pa.engineer_id = ?
     ORDER BY po.created_at DESC
     LIMIT 10"
);
if ($purchaseOrdersStmt) {
    $purchaseOrdersStmt->bind_param('i', $userId);
    $purchaseOrdersStmt->execute();
    $result = $purchaseOrdersStmt->get_result();
    $purchaseOrders = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

$pendingCount = 0;
$approvedCount = 0;
$rejectedCount = 0;
$assignedProjectIds = [];

foreach ($requests as $request) {
    $status = (string)($request['status'] ?? '');
    $projectName = trim((string)($request['project_name'] ?? ''));
    if ($projectName !== '') {
        $assignedProjectIds[$projectName] = true;
    }
    if (in_array($status, ['submitted', 'engineer_review'], true)) {
        $pendingCount++;
    } elseif ($status === 'engineer_approved' || $status === 'approved') {
        $approvedCount++;
    } elseif ($status === 'engineer_rejected') {
        $rejectedCount++;
    }
}

$purchaseOrderStats = [
    'pending_admin' => 0,
    'approved_admin' => 0,
    'rejected_admin' => 0,
];
foreach ($purchaseOrders as $purchaseOrder) {
    $approvalStatus = (string)($purchaseOrder['admin_approval_status'] ?? 'pending');
    if ($approvalStatus === 'approved') {
        $purchaseOrderStats['approved_admin']++;
    } elseif ($approvalStatus === 'rejected') {
        $purchaseOrderStats['rejected_admin']++;
    } else {
        $purchaseOrderStats['pending_admin']++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procurement Workspace - Engineer</title>
    <link rel="stylesheet" href="../css/engineer-sidebar.css">
    <link rel="stylesheet" href="../css/engineer.css">
</head>
<body>
<div class="dashboard-layout">
    <?php include __DIR__ . '/../sidebar/sidebar_engineer.php'; ?>

    <main class="dashboard-main">
        <section class="procurement-hero">
            <div class="procurement-hero__content">
                <span class="section-kicker">Engineer Workflow</span>
                <h1>Procurement Workspace</h1>
                <p class="procurement-hero__copy">Foreman submits the item needs, the assigned engineer manages suppliers and purchase orders, then Super Admin gives the final approval.</p>
            </div>
            <div class="procurement-hero__meta">
                <span class="procurement-hero__chip">Assigned Projects: <?php echo count($assignedProjectIds); ?></span>
                <span class="procurement-hero__chip">Pending Technical Review: <?php echo $pendingCount; ?></span>
                <span class="procurement-hero__chip">Pending Admin Approval: <?php echo $purchaseOrderStats['pending_admin']; ?></span>
            </div>
        </section>

        <section class="stats-grid" aria-label="Procurement review metrics">
            <article class="metric-card">
                <span>Total Requests</span>
                <strong><?php echo count($requests); ?></strong>
            </article>
            <article class="metric-card">
                <span>Ready For PO</span>
                <strong><?php echo count($engineerApprovedRequests); ?></strong>
            </article>
            <article class="metric-card">
                <span>PO Pending Approval</span>
                <strong><?php echo $purchaseOrderStats['pending_admin']; ?></strong>
            </article>
            <article class="metric-card">
                <span>Admin Approved PO</span>
                <strong><?php echo $purchaseOrderStats['approved_admin']; ?></strong>
            </article>
        </section>

        <?php if ($flash): ?>
            <div class="alert <?php echo ($flash['type'] ?? '') === 'success' ? 'alert-success' : 'alert-error'; ?>">
                <?php echo htmlspecialchars((string)($flash['message'] ?? '')); ?>
            </div>
        <?php endif; ?>

        <section class="content-panel">
            <div class="panel-header">
                <div>
                    <h2>Requests For Procurement</h2>
                    <p>Review scope, quantity, and timing first so the assigned engineer can own the procurement decision and PO creation.</p>
                </div>
                <div class="workflow-note">Flow: Foreman requests items, Engineer creates PO, Super Admin signs off.</div>
            </div>

            <?php if (empty($requests)): ?>
                <div class="empty-state">No purchase requests assigned to your projects yet.</div>
            <?php else: ?>
                <div class="project-list">
                    <?php foreach ($requests as $request): ?>
                        <?php $status = (string)($request['status'] ?? 'submitted'); ?>
                        <article class="project-card">
                            <div class="project-card-header">
                                <div>
                                    <p class="project-card-kicker"><?php echo htmlspecialchars((string)($request['request_no'] ?? '')); ?></p>
                                    <h2><?php echo htmlspecialchars((string)($request['item_description'] ?? 'Request Item')); ?></h2>
                                    <p class="project-card-subtitle"><?php echo htmlspecialchars((string)($request['project_name'] ?? 'Unassigned Project')); ?></p>
                                </div>
                                <span class="status <?php echo htmlspecialchars(str_replace('_', '-', $status)); ?>">
                                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $status))); ?>
                                </span>
                            </div>

                            <div class="project-card-body procurement-card-grid">
                                <div class="procurement-meta-card">
                                    <span class="procurement-meta-card__label">Requested By</span>
                                    <strong><?php echo htmlspecialchars((string)($request['requested_by_name'] ?? 'N/A')); ?></strong>
                                </div>
                                <div class="procurement-meta-card">
                                    <span class="procurement-meta-card__label">Type</span>
                                    <strong><?php echo htmlspecialchars(ucfirst((string)($request['request_type'] ?? 'material'))); ?></strong>
                                </div>
                                <div class="procurement-meta-card">
                                    <span class="procurement-meta-card__label">Quantity</span>
                                    <strong><?php echo htmlspecialchars((string)($request['quantity_requested'] ?? '0')); ?> <?php echo htmlspecialchars((string)($request['unit'] ?? '')); ?></strong>
                                </div>
                                <div class="procurement-meta-card">
                                    <span class="procurement-meta-card__label">Needed Date</span>
                                    <strong><?php echo htmlspecialchars((string)($request['needed_date'] ?? 'Not set')); ?></strong>
                                </div>
                                <div class="procurement-meta-card">
                                    <span class="procurement-meta-card__label">Site Location</span>
                                    <strong><?php echo htmlspecialchars((string)($request['site_location'] ?? 'Not set')); ?></strong>
                                </div>
                                <div class="procurement-meta-card">
                                    <span class="procurement-meta-card__label">Specification</span>
                                    <strong><?php echo htmlspecialchars((string)($request['specification'] ?? 'Not set')); ?></strong>
                                </div>
                            </div>

                            <div class="procurement-notes">
                                <p><strong>Request Note:</strong> <?php echo htmlspecialchars((string)($request['remarks'] ?? 'None')); ?></p>
                                <?php if (!empty($request['engineer_review_notes'])): ?>
                                    <p><strong>Engineer Note:</strong> <?php echo htmlspecialchars((string)$request['engineer_review_notes']); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($request['po_no'])): ?>
                                    <p><strong>Purchase Order:</strong> <?php echo htmlspecialchars((string)$request['po_no']); ?> | Admin Approval: <?php echo htmlspecialchars(ucfirst((string)($request['admin_approval_status'] ?? 'pending'))); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($request['admin_approval_notes'])): ?>
                                    <p><strong>Admin Note:</strong> <?php echo htmlspecialchars((string)$request['admin_approval_notes']); ?></p>
                                <?php endif; ?>
                            </div>

                            <?php if (in_array($status, ['submitted', 'engineer_review'], true)): ?>
                                <form method="POST" class="task-update-form">
                                    <input type="hidden" name="action" value="review_purchase_request">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                    <input type="hidden" name="purchase_request_id" value="<?php echo (int)$request['id']; ?>">

                                    <div class="task-update-fields">
                                        <label>
                                            Decision
                                            <select name="review_action" required>
                                                <option value="engineer_review" <?php echo $status === 'engineer_review' ? 'selected' : ''; ?>>Mark Under Review</option>
                                                <option value="engineer_approved">Ready For Purchase Order</option>
                                                <option value="engineer_rejected">Reject Request</option>
                                            </select>
                                        </label>
                                        <label>
                                            Note
                                            <textarea name="engineer_review_notes" rows="3" placeholder="Technical validation note, correction, or rejection reason"></textarea>
                                        </label>
                                    </div>

                                    <div class="task-update-actions">
                                        <button type="submit" class="btn">Save Review</button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <div class="procurement-review-stamp">
                                    <span class="procurement-review-stamp__label">Latest Engineer Review</span>
                                    <strong><?php echo htmlspecialchars((string)($request['engineer_reviewed_at'] ?? 'Already finalized')); ?></strong>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="content-panel" id="create-supplier">
            <div class="panel-header">
                <div>
                    <h2>Supplier Management</h2>
                    <p>Maintain supplier records here before assigning them to purchase orders.</p>
                </div>
            </div>

            <form
                method="POST"
                class="task-update-form supplier-form-panel"
                data-supplier-form
                data-supplier-form-mode="<?php echo $isSupplierEditMode ? 'edit' : 'create'; ?>"
                data-supplier-form-id="<?php echo $isSupplierEditMode ? (int)$supplierEditRecord['id'] : 0; ?>"
                novalidate
            >
                <input type="hidden" name="action" value="<?php echo $isSupplierEditMode ? 'update_supplier' : 'create_supplier'; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <?php if ($isSupplierEditMode): ?>
                    <input type="hidden" name="supplier_id" value="<?php echo (int)$supplierEditRecord['id']; ?>">
                <?php endif; ?>

                <div class="supplier-form-panel__header">
                    <div>
                        <span class="supplier-form-panel__eyebrow"><?php echo $isSupplierEditMode ? 'Edit Mode' : 'Create Mode'; ?></span>
                        <h3><?php echo $isSupplierEditMode ? 'Update Supplier' : 'Create Supplier'; ?></h3>
                    </div>
                    <?php if ($isSupplierEditMode): ?>
                        <span class="supplier-form-panel__code"><?php echo htmlspecialchars((string)($supplierEditRecord['supplier_code'] ?? '')); ?></span>
                    <?php endif; ?>
                </div>

                <div class="task-update-fields supplier-form-grid">
                    <label>
                        <span class="field-label">Supplier Name <span class="field-required">*</span></span>
                        <input
                            type="text"
                            id="supplier_name"
                            name="supplier_name"
                            value="<?php echo htmlspecialchars((string)$supplierFormValues['supplier_name']); ?>"
                            required
                            minlength="2"
                            autocomplete="organization"
                            data-supplier-draft-field
                        >
                    </label>
                    <label>
                        <span class="field-label">Contact Person <span class="field-required">*</span></span>
                        <input
                            type="text"
                            id="contact_person"
                            name="contact_person"
                            value="<?php echo htmlspecialchars((string)$supplierFormValues['contact_person']); ?>"
                            required
                            minlength="2"
                            autocomplete="name"
                            data-supplier-draft-field
                        >
                    </label>
                    <label>
                        <span class="field-label">Contact Number <span class="field-required">*</span></span>
                        <input
                            type="tel"
                            id="contact_number"
                            name="contact_number"
                            value="<?php echo htmlspecialchars((string)$supplierFormValues['contact_number']); ?>"
                            required
                            inputmode="numeric"
                            maxlength="11"
                            pattern="09[0-9]{9}"
                            placeholder="09XXXXXXXXX"
                            autocomplete="tel"
                            data-phone-field
                            data-supplier-draft-field
                        >
                        <span class="field-hint">Must start with 09 and be 11 digits.</span>
                    </label>
                    <label>
                        Email
                        <input
                            type="email"
                            id="email"
                            name="email"
                            value="<?php echo htmlspecialchars((string)$supplierFormValues['email']); ?>"
                            autocomplete="email"
                            data-supplier-draft-field
                        >
                        <span class="field-hint">Optional</span>
                    </label>
                    <label class="supplier-form-grid__wide">
                        <span class="field-label">Address <span class="field-required">*</span></span>
                        <textarea
                            id="address"
                            name="address"
                            rows="3"
                            required
                            minlength="2"
                            data-supplier-draft-field
                        ><?php echo htmlspecialchars((string)$supplierFormValues['address']); ?></textarea>
                    </label>
                    <label class="supplier-form-grid__wide">
                        Description
                        <textarea
                            id="description"
                            name="description"
                            rows="3"
                            placeholder="Optional notes about what this supplier provides"
                            data-supplier-draft-field
                        ><?php echo htmlspecialchars((string)$supplierFormValues['description']); ?></textarea>
                        <span class="field-hint">Optional</span>
                    </label>
                </div>

                <div class="task-update-actions supplier-form-actions">
                    <button type="submit" class="btn"><?php echo $isSupplierEditMode ? 'Update' : 'Create Supplier'; ?></button>
                    <button type="button" class="btn-secondary btn-secondary--danger" data-supplier-clear>Clear Form</button>
                    <?php if ($isSupplierEditMode): ?>
                        <a href="/codesamplecaps/ENGINEER/dashboards/procurement.php#create-supplier" class="btn-secondary">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if (!empty($supplierRows)): ?>
                <div class="project-list supplier-card-list">
                    <?php foreach ($supplierRows as $supplier): ?>
                        <article class="project-card supplier-card">
                            <div class="project-card-header">
                                <div>
                                    <p class="project-card-kicker"><?php echo htmlspecialchars((string)($supplier['supplier_code'] ?? '')); ?></p>
                                    <h2><?php echo htmlspecialchars((string)($supplier['supplier_name'] ?? 'Supplier')); ?></h2>
                                    <p class="project-card-subtitle"><?php echo htmlspecialchars((string)($supplier['contact_person'] ?? 'No contact person')); ?></p>
                                </div>
                            </div>
                            <div class="procurement-notes supplier-card__notes">
                                <p><strong>Number:</strong> <?php echo htmlspecialchars((string)($supplier['contact_number'] ?? 'Not set')); ?></p>
                                <p><strong>Email:</strong> <?php echo htmlspecialchars((string)($supplier['email'] ?? 'Not set')); ?></p>
                                <p><strong>Address:</strong> <?php echo htmlspecialchars((string)($supplier['address'] ?? 'Not set')); ?></p>
                                <?php if (!empty($supplier['description'])): ?>
                                    <p><strong>Description:</strong> <?php echo htmlspecialchars((string)$supplier['description']); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="supplier-card__footer">
                                <div class="supplier-card__meta">
                                    <span class="supplier-card__meta-chip">Supplier Record</span>
                                </div>
                                <div class="task-update-actions supplier-card__actions">
                                    <a href="/codesamplecaps/ENGINEER/dashboards/procurement.php?supplier_edit=<?php echo (int)$supplier['id']; ?>#create-supplier" class="btn btn-card-action btn-card-action--secondary">Edit</a>
                                    <form method="POST" onsubmit="return confirm('Move this supplier to trash bin?');">
                                        <div class="supplier-card__action-wrap">
                                            <input type="hidden" name="action" value="trash_supplier">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="supplier_id" value="<?php echo (int)$supplier['id']; ?>">
                                            <button type="submit" class="btn btn-card-action btn-card-action--danger">Move to Trash</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="panel-header supplier-trash-header">
                <div>
                    <h2>Supplier Trash Bin</h2>
                    <p>Recently removed suppliers stay here until you decide to restore them.</p>
                </div>
            </div>

            <?php if (empty($trashedSupplierRows)): ?>
                <div class="empty-state empty-state--compact">No suppliers in trash bin.</div>
            <?php else: ?>
                <div class="project-list supplier-card-list supplier-card-list--trash">
                    <?php foreach ($trashedSupplierRows as $supplier): ?>
                        <article class="project-card supplier-card supplier-card--trashed">
                            <div class="project-card-header">
                                <div>
                                    <p class="project-card-kicker"><?php echo htmlspecialchars((string)($supplier['supplier_code'] ?? '')); ?></p>
                                    <h2><?php echo htmlspecialchars((string)($supplier['supplier_name'] ?? 'Supplier')); ?></h2>
                                    <p class="project-card-subtitle"><?php echo htmlspecialchars((string)($supplier['contact_person'] ?? 'No contact person')); ?></p>
                                </div>
                            </div>
                            <div class="procurement-notes supplier-card__notes">
                                <p><strong>Number:</strong> <?php echo htmlspecialchars((string)($supplier['contact_number'] ?? 'Not set')); ?></p>
                                <p><strong>Email:</strong> <?php echo htmlspecialchars((string)($supplier['email'] ?? 'Not set')); ?></p>
                                <p><strong>Address:</strong> <?php echo htmlspecialchars((string)($supplier['address'] ?? 'Not set')); ?></p>
                                <?php if (!empty($supplier['description'])): ?>
                                    <p><strong>Description:</strong> <?php echo htmlspecialchars((string)$supplier['description']); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="supplier-card__footer">
                                <div class="supplier-card__meta">
                                    <span class="supplier-card__meta-chip supplier-card__meta-chip--trashed">In Trash Bin</span>
                                </div>
                                <div class="task-update-actions supplier-card__actions">
                                    <form method="POST">
                                        <div class="supplier-card__action-wrap">
                                            <input type="hidden" name="action" value="restore_supplier">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="supplier_id" value="<?php echo (int)$supplier['id']; ?>">
                                            <button type="submit" class="btn btn-card-action btn-card-action--restore">Restore</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="content-panel">
            <div class="panel-header">
                <div>
                    <h2>Create Purchase Order</h2>
                    <p>After engineer review, create the purchase order here and send it to Super Admin for final approval.</p>
                </div>
            </div>

            <?php if (empty($engineerApprovedRequests)): ?>
                <div class="empty-state">No engineer-approved requests are waiting for purchase order creation.</div>
            <?php elseif (empty($activeSuppliers)): ?>
                <div class="empty-state">Add an active supplier first before creating a purchase order.</div>
            <?php else: ?>
                <form method="POST" class="task-update-form">
                    <input type="hidden" name="action" value="create_purchase_order">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <div class="task-update-fields">
                        <label>
                            Approved Request
                            <select name="purchase_request_id" required>
                                <option value="">Select approved request</option>
                                <?php foreach ($engineerApprovedRequests as $request): ?>
                                    <option value="<?php echo (int)$request['id']; ?>">
                                        <?php echo htmlspecialchars(($request['request_no'] ?? '') . ' - ' . ($request['project_name'] ?? 'Project') . ' - ' . ($request['item_description'] ?? 'Item')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            Supplier
                            <select name="supplier_id" required>
                                <option value="">Select supplier</option>
                                <?php foreach ($activeSuppliers as $supplier): ?>
                                    <option value="<?php echo (int)$supplier['id']; ?>">
                                        <?php echo htmlspecialchars(($supplier['supplier_name'] ?? 'Supplier') . ' - ' . ($supplier['supplier_code'] ?? '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            Order Date
                            <input type="date" name="order_date" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>" required>
                        </label>
                        <label>
                            Expected Delivery Date
                            <input type="date" name="expected_delivery_date">
                        </label>
                        <label>
                            Remarks
                            <textarea name="po_remarks" rows="3" placeholder="Terms, schedule, or procurement note"></textarea>
                        </label>
                    </div>
                    <div class="task-update-actions">
                        <button type="submit" class="btn">Create Purchase Order</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <section class="content-panel">
            <div class="panel-header">
                <div>
                    <h2>Purchase Orders</h2>
                    <p>Track which procurement packages are still waiting for Super Admin approval and which ones already cleared.</p>
                </div>
            </div>

            <?php if (empty($purchaseOrders)): ?>
                <div class="empty-state">No purchase orders yet.</div>
            <?php else: ?>
                <div class="project-list">
                    <?php foreach ($purchaseOrders as $purchaseOrder): ?>
                        <article class="project-card">
                            <div class="project-card-header">
                                <div>
                                    <p class="project-card-kicker"><?php echo htmlspecialchars((string)($purchaseOrder['po_no'] ?? '')); ?></p>
                                    <h2><?php echo htmlspecialchars((string)($purchaseOrder['project_name'] ?? 'Project')); ?></h2>
                                    <p class="project-card-subtitle"><?php echo htmlspecialchars((string)($purchaseOrder['supplier_name'] ?? 'Supplier')); ?></p>
                                </div>
                                <span class="status <?php echo htmlspecialchars((string)($purchaseOrder['admin_approval_status'] ?? 'pending') === 'approved' ? 'completed' : ((string)($purchaseOrder['admin_approval_status'] ?? 'pending') === 'rejected' ? 'delayed' : 'ongoing')); ?>">
                                    <?php echo htmlspecialchars('Admin ' . ucfirst((string)($purchaseOrder['admin_approval_status'] ?? 'pending'))); ?>
                                </span>
                            </div>
                            <div class="procurement-notes">
                                <p><strong>Request:</strong> <?php echo htmlspecialchars((string)($purchaseOrder['request_no'] ?? 'N/A')); ?></p>
                                <p><strong>Order Date:</strong> <?php echo htmlspecialchars((string)($purchaseOrder['order_date'] ?? '')); ?></p>
                                <p><strong>Expected Delivery:</strong> <?php echo htmlspecialchars((string)($purchaseOrder['expected_delivery_date'] ?? 'Not set')); ?></p>
                                <p><strong>PO Status:</strong> <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($purchaseOrder['status'] ?? 'issued')))); ?></p>
                                <?php if (!empty($purchaseOrder['remarks'])): ?>
                                    <p><strong>Remarks:</strong> <?php echo htmlspecialchars((string)$purchaseOrder['remarks']); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($purchaseOrder['admin_approval_notes'])): ?>
                                    <p><strong>Admin Note:</strong> <?php echo htmlspecialchars((string)$purchaseOrder['admin_approval_notes']); ?></p>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>
<script src="../js/engineer.js"></script>
<script>
    (function () {
        var supplierForm = document.querySelector('[data-supplier-form]');
        if (!supplierForm) {
            return;
        }

        var storageKey = 'engineer_procurement_supplier_form';
        var clearButton = supplierForm.querySelector('[data-supplier-clear]');
        var phoneField = supplierForm.querySelector('[data-phone-field]');
        var draftFields = Array.prototype.slice.call(supplierForm.querySelectorAll('[data-supplier-draft-field]'));
        var formMode = supplierForm.getAttribute('data-supplier-form-mode') || 'create';
        var formSupplierId = supplierForm.getAttribute('data-supplier-form-id') || '0';

        var applyPhoneValidation = function () {
            if (!phoneField) {
                return;
            }

            var normalized = (phoneField.value || '').replace(/\D+/g, '').slice(0, 11);
            phoneField.value = normalized;

            if (normalized === '') {
                phoneField.setCustomValidity('');
                phoneField.removeAttribute('data-validation-state');
                return;
            }

            if (!/^09\d{9}$/.test(normalized)) {
                phoneField.setCustomValidity('Contact number must start with 09 and contain exactly 11 digits.');
                phoneField.setAttribute('data-validation-state', 'invalid');
                return;
            }

            phoneField.setCustomValidity('');
            phoneField.setAttribute('data-validation-state', 'valid');
        };

        var syncFieldState = function (field) {
            if (!field) {
                return;
            }

            if (field === phoneField) {
                applyPhoneValidation();
            } else if (field.value.trim() === '') {
                field.removeAttribute('data-validation-state');
            } else if (field.checkValidity()) {
                field.setAttribute('data-validation-state', 'valid');
            } else {
                field.setAttribute('data-validation-state', 'invalid');
            }
        };

        var saveDraft = function () {
            var payload = {
                mode: formMode,
                supplierId: formSupplierId,
                values: {}
            };

            draftFields.forEach(function (field) {
                payload.values[field.name] = field.value;
            });

            window.localStorage.setItem(storageKey, JSON.stringify(payload));
        };

        var loadDraft = function () {
            var currentUrl = new URL(window.location.href);
            if (currentUrl.searchParams.get('supplier_form_reset') === '1') {
                window.localStorage.removeItem(storageKey);
                currentUrl.searchParams.delete('supplier_form_reset');
                window.history.replaceState({}, '', currentUrl.toString());
                return;
            }

            var raw = window.localStorage.getItem(storageKey);
            if (!raw) {
                return;
            }

            try {
                var payload = JSON.parse(raw);
                if (!payload || payload.mode !== formMode || String(payload.supplierId || '0') !== String(formSupplierId)) {
                    return;
                }

                draftFields.forEach(function (field) {
                    var nextValue = payload.values && typeof payload.values[field.name] === 'string'
                        ? payload.values[field.name]
                        : null;
                    if (nextValue !== null && field.value.trim() === '') {
                        field.value = nextValue;
                    }
                });
            } catch (error) {
                window.localStorage.removeItem(storageKey);
            }
        };

        var clearDraft = function () {
            draftFields.forEach(function (field) {
                field.value = '';
                field.removeAttribute('data-validation-state');
                field.setCustomValidity('');
            });
            window.localStorage.removeItem(storageKey);
            supplierForm.reset();
        };

        loadDraft();
        draftFields.forEach(function (field) {
            syncFieldState(field);
            field.addEventListener('input', function () {
                syncFieldState(field);
                saveDraft();
            });
            field.addEventListener('blur', function () {
                syncFieldState(field);
            });
        });

        if (phoneField) {
            applyPhoneValidation();
        }

        clearButton?.addEventListener('click', function () {
            clearDraft();
            if (formMode === 'edit') {
                window.location.href = '/codesamplecaps/ENGINEER/dashboards/procurement.php?clear_supplier_form=1#create-supplier';
            }
        });

        supplierForm.addEventListener('submit', function () {
            draftFields.forEach(syncFieldState);
            if (!supplierForm.checkValidity()) {
                supplierForm.reportValidity();
            }
            saveDraft();
        });
    }());
</script>
</body>
</html>
