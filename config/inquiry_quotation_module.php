<?php
// Shared Inquiry Quotation helpers para system-generated quote galing sa Engineer costing.

function inquiry_quote_table_exists(mysqli $conn, string $tableName): bool
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
    return (bool)$stmt->get_result()->fetch_assoc();
}

function inquiry_quote_ensure_tables(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS inquiry_quotation_drafts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            inquiry_id INT NOT NULL,
            inspection_id INT NOT NULL,
            quotation_no VARCHAR(80) NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'Draft',
            subtotal DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            profit_margin_percent DECIMAL(7,2) NOT NULL DEFAULT 0.00,
            profit_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            grand_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            approved_by INT NULL,
            approved_at DATETIME NULL,
            sent_at DATETIME NULL,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_inquiry_quote_inspection (inspection_id),
            KEY idx_inquiry_quote_inquiry (inquiry_id),
            KEY idx_inquiry_quote_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS inquiry_quotation_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            draft_id INT NOT NULL,
            item_type VARCHAR(30) NOT NULL,
            item_name VARCHAR(180) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 1.00,
            unit VARCHAR(30) NOT NULL DEFAULT 'unit',
            unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            line_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_inquiry_quote_items_draft (draft_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    if (!inquiry_quote_column_exists($conn, 'inquiry_quotation_drafts', 'project_id')) {
        $conn->query("ALTER TABLE inquiry_quotation_drafts ADD COLUMN project_id INT NULL AFTER inspection_id");
    }
}

function inquiry_quote_column_exists(mysqli $conn, string $tableName, string $columnName): bool
{
    $stmt = $conn->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $tableName, $columnName);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_assoc();
}

function inquiry_quote_project_status(mysqli $conn): string
{
    $stmt = $conn->prepare(
        'SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "projects" AND COLUMN_NAME = "status" LIMIT 1'
    );
    if (!$stmt) {
        return 'pending';
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $type = (string)($row['COLUMN_TYPE'] ?? '');

    return str_contains($type, "'draft'") ? 'draft' : 'pending';
}

function inquiry_quote_ensure_project_columns(mysqli $conn): void
{
    $columns = [
        'contact_person' => "ALTER TABLE projects ADD COLUMN contact_person VARCHAR(190) DEFAULT NULL AFTER client_id",
        'contact_number' => "ALTER TABLE projects ADD COLUMN contact_number VARCHAR(40) DEFAULT NULL AFTER contact_person",
        'project_site' => "ALTER TABLE projects ADD COLUMN project_site VARCHAR(190) DEFAULT NULL AFTER client_id",
        'project_address' => "ALTER TABLE projects ADD COLUMN project_address TEXT DEFAULT NULL AFTER client_id",
        'project_email' => "ALTER TABLE projects ADD COLUMN project_email VARCHAR(190) DEFAULT NULL AFTER project_address",
        'project_code' => "ALTER TABLE projects ADD COLUMN project_code VARCHAR(80) DEFAULT NULL AFTER project_email",
        'project_start_date' => "ALTER TABLE projects ADD COLUMN project_start_date DATE DEFAULT NULL AFTER start_date",
        'estimated_completion_date' => "ALTER TABLE projects ADD COLUMN estimated_completion_date DATE DEFAULT NULL AFTER project_start_date",
    ];

    foreach ($columns as $column => $sql) {
        if (!inquiry_quote_column_exists($conn, 'projects', $column)) {
            $conn->query($sql);
        }
    }
}

function inquiry_quote_format_money(float $amount): string
{
    return 'PHP ' . number_format($amount, 2);
}

function inquiry_quote_generate_number(): string
{
    return 'IQ-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function inquiry_quote_fetch_by_inquiry(mysqli $conn): array
{
    $drafts = [];
    if (!inquiry_quote_table_exists($conn, 'inquiry_quotation_drafts')) {
        return $drafts;
    }

    $result = $conn->query(
        "SELECT q.*, u.full_name AS approved_by_name
         FROM inquiry_quotation_drafts q
         LEFT JOIN users u ON u.id = q.approved_by
         ORDER BY q.updated_at DESC, q.id DESC"
    );

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $inquiryId = (int)($row['inquiry_id'] ?? 0);
            if ($inquiryId > 0 && !isset($drafts[$inquiryId])) {
                $drafts[$inquiryId] = $row;
            }
        }
    }

    return $drafts;
}

function inquiry_quote_fetch_full(mysqli $conn, int $draftId): ?array
{
    $stmt = $conn->prepare(
        "SELECT
            q.*,
            si.scheduled_at,
            si.engineer_id,
            si.engineer_findings,
            si.risk_notes,
            si.client_requests,
            e.full_name AS engineer_name,
            a.full_name AS approved_by_name,
            inquiry.client_name,
            inquiry.company_name,
            inquiry.email,
            inquiry.contact_no,
            inquiry.province,
            inquiry.city_municipality,
            inquiry.barangay,
            inquiry.site_address,
            inquiry.service_category,
            inquiry.description,
            inquiry.preferred_inspection_date
         FROM inquiry_quotation_drafts q
         INNER JOIN service_inquiries inquiry ON inquiry.id = q.inquiry_id
         INNER JOIN site_inspections si ON si.id = q.inspection_id
         INNER JOIN users e ON e.id = si.engineer_id
         LEFT JOIN users a ON a.id = q.approved_by
         WHERE q.id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $draftId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return $row ?: null;
}

function inquiry_quote_fetch_items(mysqli $conn, int $draftId): array
{
    $stmt = $conn->prepare(
        'SELECT item_type, item_name, quantity, unit, unit_cost, line_total, notes
         FROM inquiry_quotation_items
         WHERE draft_id = ?
         ORDER BY id ASC'
    );
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $draftId);
    $stmt->execute();

    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function inquiry_quote_create_from_inspection(mysqli $conn, int $inquiryId, int $inspectionId, int $adminId, float $marginPercent = 15.0): int
{
    $itemStmt = $conn->prepare(
        'SELECT item_type, item_name, quantity, unit, unit_cost, line_total, notes
         FROM site_inspection_cost_items
         WHERE inspection_id = ?
         ORDER BY id ASC'
    );
    if (!$itemStmt) {
        throw new RuntimeException('Unable to load costing items.');
    }

    $itemStmt->bind_param('i', $inspectionId);
    $itemStmt->execute();
    $items = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    if (empty($items)) {
        throw new RuntimeException('Engineer costing is empty.');
    }

    $subtotal = 0.0;
    foreach ($items as $item) {
        $subtotal += (float)($item['line_total'] ?? 0);
    }

    if ($subtotal <= 0) {
        throw new RuntimeException('Engineer costing total must be greater than zero.');
    }

    $profitAmount = round($subtotal * ($marginPercent / 100), 2);
    $grandTotal = round($subtotal + $profitAmount, 2);
    $quotationNo = inquiry_quote_generate_number();

    $conn->begin_transaction();

    try {
        $draftStmt = $conn->prepare(
            'INSERT INTO inquiry_quotation_drafts
             (inquiry_id, inspection_id, quotation_no, subtotal, profit_margin_percent, profit_amount, grand_total, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$draftStmt) {
            throw new RuntimeException('Unable to prepare quotation draft.');
        }

        $draftStmt->bind_param('iisddddi', $inquiryId, $inspectionId, $quotationNo, $subtotal, $marginPercent, $profitAmount, $grandTotal, $adminId);
        $draftStmt->execute();
        $draftId = (int)$conn->insert_id;

        $insertItem = $conn->prepare(
            'INSERT INTO inquiry_quotation_items
             (draft_id, item_type, item_name, quantity, unit, unit_cost, line_total, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$insertItem) {
            throw new RuntimeException('Unable to prepare quotation items.');
        }

        foreach ($items as $item) {
            $itemType = (string)($item['item_type'] ?? 'material');
            $itemName = (string)($item['item_name'] ?? '');
            $quantity = (float)($item['quantity'] ?? 0);
            $unit = (string)($item['unit'] ?? 'unit');
            $unitCost = (float)($item['unit_cost'] ?? 0);
            $lineTotal = (float)($item['line_total'] ?? 0);
            $notes = (string)($item['notes'] ?? '');
            $insertItem->bind_param('issdsdds', $draftId, $itemType, $itemName, $quantity, $unit, $unitCost, $lineTotal, $notes);
            $insertItem->execute();
        }

        $conn->commit();
        return $draftId;
    } catch (Throwable $throwable) {
        $conn->rollback();
        throw $throwable;
    }
}

function inquiry_quote_approve(mysqli $conn, int $draftId, int $adminId): void
{
    $stmt = $conn->prepare(
        "UPDATE inquiry_quotation_drafts
         SET status = 'Approved', approved_by = ?, approved_at = NOW()
         WHERE id = ? AND status = 'Draft'"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare quotation approval.');
    }

    $stmt->bind_param('ii', $adminId, $draftId);
    $stmt->execute();

    if ($stmt->affected_rows <= 0) {
        throw new RuntimeException('Only draft quotations can be approved.');
    }
}

function inquiry_quote_generate_project_code(mysqli $conn): string
{
    $year = date('Y');
    for ($i = 1; $i <= 9999; $i++) {
        $code = 'EDGE-' . $year . '-' . str_pad((string)$i, 4, '0', STR_PAD_LEFT);
        $stmt = $conn->prepare('SELECT 1 FROM projects WHERE project_code = ? LIMIT 1');
        if (!$stmt) {
            return $code;
        }

        $stmt->bind_param('s', $code);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            return $code;
        }
    }

    return 'EDGE-' . $year . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function inquiry_quote_build_project_title(array $quotation): string
{
    $service = trim((string)($quotation['service_category'] ?? 'Project'));
    $city = trim((string)($quotation['city_municipality'] ?? ''));
    $suffix = $city !== '' ? ' - ' . $city : '';

    return $service . $suffix;
}

function inquiry_quote_get_or_create_client(mysqli $conn, array $quotation, int $adminId): int
{
    $email = strtolower(trim((string)($quotation['email'] ?? '')));
    if ($email !== '') {
        $stmt = $conn->prepare("SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                return (int)$row['id'];
            }
        }
    }

    $name = trim((string)($quotation['client_name'] ?? 'Client'));
    $phone = trim((string)($quotation['contact_no'] ?? ''));
    $password = password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT);
    $role = 'client';
    $status = 'active';

    $stmt = $conn->prepare(
        'INSERT INTO users (full_name, email, password, role, phone, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to create client account for project.');
    }

    $stmt->bind_param('ssssssi', $name, $email, $password, $role, $phone, $status, $adminId);
    $stmt->execute();

    return (int)$conn->insert_id;
}

function inquiry_quote_create_project(mysqli $conn, int $draftId, int $adminId): int
{
    inquiry_quote_ensure_project_columns($conn);

    $quotation = inquiry_quote_fetch_full($conn, $draftId);
    if (!$quotation) {
        throw new RuntimeException('Quotation draft not found.');
    }

    if ((string)($quotation['status'] ?? '') !== 'Approved') {
        throw new RuntimeException('Approve the quotation first before creating a project.');
    }

    if (!empty($quotation['project_id'])) {
        return (int)$quotation['project_id'];
    }

    $clientId = inquiry_quote_get_or_create_client($conn, $quotation, $adminId);
    $projectCode = inquiry_quote_generate_project_code($conn);
    $projectName = inquiry_quote_build_project_title($quotation);
    $description = trim((string)($quotation['engineer_findings'] ?: $quotation['description'] ?? ''));
    $contactPerson = trim((string)($quotation['client_name'] ?? ''));
    $contactNumber = trim((string)($quotation['contact_no'] ?? ''));
    $projectSite = trim((string)($quotation['city_municipality'] ?? ''));
    $projectAddress = trim((string)($quotation['site_address'] ?? ''));
    $projectEmail = trim((string)($quotation['email'] ?? ''));
    $startDate = date('Y-m-d');
    $status = inquiry_quote_project_status($conn);

    $conn->begin_transaction();

    try {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS project_budget_profiles (
                project_id INT(11) NOT NULL PRIMARY KEY,
                budget_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                budget_notes TEXT DEFAULT NULL,
                created_by INT(11) NOT NULL,
                updated_by INT(11) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $stmt = $conn->prepare(
            'INSERT INTO projects
             (project_name, description, client_id, contact_person, contact_number, project_site, project_address, project_email, project_code, start_date, project_start_date, estimated_completion_date, end_date, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare project creation.');
        }

        $estimatedCompletion = date('Y-m-d', strtotime('+7 days'));
        $endDate = null;
        $stmt->bind_param(
            'ssisssssssssssi',
            $projectName,
            $description,
            $clientId,
            $contactPerson,
            $contactNumber,
            $projectSite,
            $projectAddress,
            $projectEmail,
            $projectCode,
            $startDate,
            $startDate,
            $estimatedCompletion,
            $endDate,
            $status,
            $adminId
        );
        $stmt->execute();
        $projectId = (int)$conn->insert_id;

        $assignment = $conn->prepare(
            'INSERT IGNORE INTO project_assignments (project_id, engineer_id, assigned_by)
             VALUES (?, ?, ?)'
        );
        if ($assignment) {
            $engineerId = (int)($quotation['engineer_id'] ?? 0);
            $assignment->bind_param('iii', $projectId, $engineerId, $adminId);
            $assignment->execute();
        }

        $budget = $conn->prepare(
            'INSERT INTO project_budget_profiles (project_id, budget_amount, budget_notes, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE budget_amount = VALUES(budget_amount), budget_notes = VALUES(budget_notes), updated_by = VALUES(updated_by)'
        );
        if ($budget) {
            $budgetAmount = (float)($quotation['grand_total'] ?? 0);
            $budgetNotes = 'Auto-created from approved quotation ' . (string)$quotation['quotation_no'];
            $budget->bind_param('idsii', $projectId, $budgetAmount, $budgetNotes, $adminId, $adminId);
            $budget->execute();
        }

        $link = $conn->prepare('UPDATE inquiry_quotation_drafts SET project_id = ? WHERE id = ?');
        if ($link) {
            $link->bind_param('ii', $projectId, $draftId);
            $link->execute();
        }

        $conn->commit();
        return $projectId;
    } catch (Throwable $throwable) {
        $conn->rollback();
        throw $throwable;
    }
}
