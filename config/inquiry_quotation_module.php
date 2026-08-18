<?php
// Shared Inquiry Quotation helpers para system-generated quote galing sa Engineer costing.

require_once __DIR__ . '/project_history.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/../services/EmailService.php';

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

    $draftColumns = [
        'sent_by' => "ALTER TABLE inquiry_quotation_drafts ADD COLUMN sent_by INT NULL AFTER approved_at",
        'sent_to_client_id' => "ALTER TABLE inquiry_quotation_drafts ADD COLUMN sent_to_client_id INT NULL AFTER sent_by",
        'sent_to_name' => "ALTER TABLE inquiry_quotation_drafts ADD COLUMN sent_to_name VARCHAR(190) NULL AFTER sent_to_client_id",
        'sent_to_email' => "ALTER TABLE inquiry_quotation_drafts ADD COLUMN sent_to_email VARCHAR(190) NULL AFTER sent_to_name",
        'sent_to_contact' => "ALTER TABLE inquiry_quotation_drafts ADD COLUMN sent_to_contact VARCHAR(40) NULL AFTER sent_to_email",
        'recipient_source' => "ALTER TABLE inquiry_quotation_drafts ADD COLUMN recipient_source VARCHAR(40) NULL AFTER sent_to_contact",
        'public_access_token_hash' => "ALTER TABLE inquiry_quotation_drafts ADD COLUMN public_access_token_hash VARCHAR(255) NULL AFTER recipient_source",
        'public_token_expires_at' => "ALTER TABLE inquiry_quotation_drafts ADD COLUMN public_token_expires_at DATETIME NULL AFTER public_access_token_hash",
        'client_decision_note' => "ALTER TABLE inquiry_quotation_drafts ADD COLUMN client_decision_note TEXT NULL AFTER sent_at",
        'client_decision_at' => "ALTER TABLE inquiry_quotation_drafts ADD COLUMN client_decision_at DATETIME NULL AFTER client_decision_note",
    ];

    foreach ($draftColumns as $column => $sql) {
        if (!inquiry_quote_column_exists($conn, 'inquiry_quotation_drafts', $column)) {
            $conn->query($sql);
        }
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS inquiry_quotation_status_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            draft_id INT NOT NULL,
            from_status VARCHAR(40) NULL,
            to_status VARCHAR(40) NOT NULL,
            note TEXT NULL,
            actor_id INT NOT NULL,
            actor_role VARCHAR(40) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_inquiry_quote_history_draft (draft_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function inquiry_quote_normalize_status(?string $status): string
{
    $status = strtolower(trim((string)$status));
    return str_replace(' ', '_', $status);
}

function inquiry_quote_status_label(?string $status): string
{
    $labels = [
        'draft' => 'Draft',
        'approved' => 'Approved',
        'sent' => 'Sent',
        'accepted' => 'Accepted',
        'revision_requested' => 'Revision Requested',
        'rejected' => 'Rejected',
    ];

    $normalized = inquiry_quote_normalize_status($status);
    return $labels[$normalized] ?? ucwords(str_replace('_', ' ', $normalized));
}

function inquiry_quote_status_class(?string $status): string
{
    $map = [
        'draft' => 'is-draft',
        'approved' => 'is-approved',
        'sent' => 'is-sent',
        'accepted' => 'is-accepted',
        'revision_requested' => 'is-sent',
        'rejected' => 'is-rejected',
    ];

    return $map[inquiry_quote_normalize_status($status)] ?? 'is-draft';
}

function inquiry_quote_public_token_hash(string $token): string
{
    return hash('sha256', $token);
}

function inquiry_quote_public_link(string $token): string
{
    $appUrl = rtrim((string)Config::getInstance()->get('APP_URL', 'http://localhost/codesamplecaps'), '/');
    return $appUrl . '/LOGIN/php/inquiry_quotation.php?token=' . urlencode($token);
}

function inquiry_quote_add_history(mysqli $conn, int $draftId, ?string $fromStatus, string $toStatus, string $note, int $actorId, string $actorRole): void
{
    $stmt = $conn->prepare(
        'INSERT INTO inquiry_quotation_status_history (draft_id, from_status, to_status, note, actor_id, actor_role)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        return;
    }

    $from = $fromStatus !== null ? inquiry_quote_normalize_status($fromStatus) : null;
    $to = inquiry_quote_normalize_status($toStatus);
    $stmt->bind_param('isssis', $draftId, $from, $to, $note, $actorId, $actorRole);
    $stmt->execute();
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

    if (str_contains($type, "'pending'")) {
        return 'pending';
    }

    return str_contains($type, "'draft'") ? 'draft' : 'ongoing';
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
        'project_source' => "ALTER TABLE projects ADD COLUMN project_source VARCHAR(40) NOT NULL DEFAULT 'walk_in' AFTER project_code",
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

function inquiry_quote_resolve_recipient(mysqli $conn, int $draftId): array
{
    $quotation = inquiry_quote_fetch_full($conn, $draftId);
    if (!$quotation) {
        throw new RuntimeException('Quotation draft not found.');
    }

    $inquiryEmail = strtolower(trim((string)($quotation['email'] ?? '')));
    $inquiryName = trim((string)($quotation['client_name'] ?? ''));
    $inquiryContact = trim((string)($quotation['contact_no'] ?? ''));

    $client = null;
    if (inquiry_quote_column_exists($conn, 'service_inquiries', 'client_id')) {
        $stmt = $conn->prepare(
            'SELECT u.id, u.full_name, u.email, u.phone
             FROM service_inquiries si
             INNER JOIN users u ON u.id = si.client_id
             WHERE si.id = ? AND u.role = "client" AND u.status = "active"
             LIMIT 1'
        );
        if ($stmt) {
            $inquiryId = (int)($quotation['inquiry_id'] ?? 0);
            $stmt->bind_param('i', $inquiryId);
            $stmt->execute();
            $client = $stmt->get_result()->fetch_assoc() ?: null;
        }
    }

    if (!$client && filter_var($inquiryEmail, FILTER_VALIDATE_EMAIL)) {
        $stmt = $conn->prepare(
            'SELECT id, full_name, email, phone
             FROM users
             WHERE LOWER(email) = ? AND role = "client" AND status = "active"
             LIMIT 1'
        );
        if ($stmt) {
            $stmt->bind_param('s', $inquiryEmail);
            $stmt->execute();
            $client = $stmt->get_result()->fetch_assoc() ?: null;
        }
    }

    if ($client) {
        return [
            'client_id' => (int)$client['id'],
            'name' => trim((string)($client['full_name'] ?? '')) ?: $inquiryName,
            'email' => strtolower(trim((string)($client['email'] ?? ''))),
            'contact' => trim((string)($client['phone'] ?? '')) ?: $inquiryContact,
            'source' => 'existing_client',
            'source_label' => 'Existing Client Account',
        ];
    }

    return [
        'client_id' => null,
        'name' => $inquiryName,
        'email' => $inquiryEmail,
        'contact' => $inquiryContact,
        'source' => 'inquiry',
        'source_label' => 'Inquiry',
    ];
}

function inquiry_quote_fetch_by_public_token(mysqli $conn, string $token): ?array
{
    inquiry_quote_ensure_tables($conn);
    $token = trim($token);
    if ($token === '') {
        return null;
    }

    $tokenHash = inquiry_quote_public_token_hash($token);
    $stmt = $conn->prepare(
        "SELECT id
         FROM inquiry_quotation_drafts
         WHERE public_access_token_hash = ?
         AND public_token_expires_at > NOW()
         AND status IN ('sent', 'accepted', 'revision_requested', 'rejected')
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return null;
    }

    return inquiry_quote_fetch_full($conn, (int)$row['id']);
}

function inquiry_quote_fetch_history(mysqli $conn, int $draftId): array
{
    if (!inquiry_quote_table_exists($conn, 'inquiry_quotation_status_history')) {
        return [];
    }

    $stmt = $conn->prepare(
        "SELECT h.*, u.full_name
         FROM inquiry_quotation_status_history h
         LEFT JOIN users u ON u.id = h.actor_id
         WHERE h.draft_id = ?
         ORDER BY h.created_at ASC, h.id ASC"
    );
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $draftId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function inquiry_quote_fetch_for_client(mysqli $conn, int $clientId): array
{
    inquiry_quote_ensure_tables($conn);

    $stmt = $conn->prepare('SELECT email FROM users WHERE id = ? AND role = "client" LIMIT 1');
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $clientId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $email = strtolower(trim((string)($user['email'] ?? '')));
    if ($email === '') {
        return [];
    }

    $stmt = $conn->prepare(
        "SELECT
            q.*,
            inquiry.client_name,
            inquiry.email,
            inquiry.service_category,
            inquiry.site_address,
            si.engineer_id,
            e.full_name AS engineer_name
         FROM inquiry_quotation_drafts q
         INNER JOIN service_inquiries inquiry ON inquiry.id = q.inquiry_id
         INNER JOIN site_inspections si ON si.id = q.inspection_id
         INNER JOIN users e ON e.id = si.engineer_id
         WHERE LOWER(inquiry.email) = ?
         AND q.status IN ('sent', 'accepted', 'revision_requested', 'rejected')
         ORDER BY q.updated_at DESC, q.id DESC"
    );
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('s', $email);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function inquiry_quote_client_can_access(mysqli $conn, int $draftId, int $clientId): bool
{
    $quotation = inquiry_quote_fetch_full($conn, $draftId);
    if (!$quotation) {
        return false;
    }

    $stmt = $conn->prepare('SELECT email FROM users WHERE id = ? AND role = "client" LIMIT 1');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $clientId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    $allowedClientStatuses = ['sent', 'accepted', 'revision_requested', 'rejected'];
    $emailMatches = strtolower(trim((string)($user['email'] ?? ''))) === strtolower(trim((string)($quotation['email'] ?? '')));

    return $emailMatches && in_array(inquiry_quote_normalize_status((string)($quotation['status'] ?? '')), $allowedClientStatuses, true);
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
    $quotation = inquiry_quote_fetch_full($conn, $draftId);
    $fromStatus = inquiry_quote_normalize_status($quotation['status'] ?? '');

    $stmt = $conn->prepare(
        "UPDATE inquiry_quotation_drafts
         SET status = 'approved', approved_by = ?, approved_at = NOW(), client_decision_note = NULL, client_decision_at = NULL
         WHERE id = ? AND status IN ('Draft', 'draft')"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare quotation approval.');
    }

    $stmt->bind_param('ii', $adminId, $draftId);
    $stmt->execute();

    if ($stmt->affected_rows <= 0) {
        throw new RuntimeException('Only draft quotations can be approved.');
    }

    inquiry_quote_add_history($conn, $draftId, $fromStatus, 'approved', 'Admin approved quotation draft.', $adminId, 'admin');
}

function inquiry_quote_send_to_client(mysqli $conn, int $draftId, int $adminId): void
{
    $quotation = inquiry_quote_fetch_full($conn, $draftId);
    if (!$quotation) {
        throw new RuntimeException('Quotation draft not found.');
    }

    $fromStatus = inquiry_quote_normalize_status($quotation['status'] ?? '');
    if ($fromStatus !== 'approved') {
        throw new RuntimeException('Only approved quotations can be sent to client.');
    }

    $recipient = inquiry_quote_resolve_recipient($conn, $draftId);
    $recipientName = trim((string)$recipient['name']);
    $recipientEmail = strtolower(trim((string)$recipient['email']));
    $recipientContact = trim((string)$recipient['contact']);
    $recipientSource = (string)$recipient['source'];
    $recipientClientId = $recipient['client_id'] !== null ? (int)$recipient['client_id'] : null;

    if ($recipientName === '') {
        throw new RuntimeException('Recipient name is missing.');
    }

    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Recipient email is missing or invalid.');
    }

    $publicToken = bin2hex(random_bytes(32));
    $publicTokenHash = inquiry_quote_public_token_hash($publicToken);
    $publicLink = inquiry_quote_public_link($publicToken);
    $emailService = new EmailService();

    if (!$emailService->sendInquiryQuotationLink($recipientEmail, $recipientName, (string)$quotation['quotation_no'], $publicLink, 14)) {
        error_log('Inquiry quotation email failed for draft #' . $draftId . ': ' . $emailService->getError());
        throw new RuntimeException('Quotation email cannot be sent right now. Please check email settings and try again.');
    }

    $stmt = $conn->prepare(
        "UPDATE inquiry_quotation_drafts
         SET status = 'sent',
             sent_by = ?,
             sent_to_client_id = ?,
             sent_to_name = ?,
             sent_to_email = ?,
             sent_to_contact = ?,
             recipient_source = ?,
             public_access_token_hash = ?,
             public_token_expires_at = DATE_ADD(NOW(), INTERVAL 14 DAY),
             sent_at = NOW(),
             client_decision_note = NULL,
             client_decision_at = NULL
         WHERE id = ? AND status IN ('Approved', 'approved')"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare quotation send.');
    }

    $stmt->bind_param(
        'iisssssi',
        $adminId,
        $recipientClientId,
        $recipientName,
        $recipientEmail,
        $recipientContact,
        $recipientSource,
        $publicTokenHash,
        $draftId
    );
    $stmt->execute();
    if ($stmt->affected_rows <= 0) {
        throw new RuntimeException('Only approved quotations can be sent to client.');
    }

    inquiry_quote_add_history($conn, $draftId, $fromStatus, 'sent', 'Admin sent quotation to ' . $recipientName . '.', $adminId, 'admin');
}

function inquiry_quote_client_respond(mysqli $conn, int $draftId, int $clientId, string $decision, string $note): void
{
    $decision = inquiry_quote_normalize_status($decision);
    if (!in_array($decision, ['accepted', 'revision_requested', 'rejected'], true)) {
        throw new RuntimeException('Invalid client quotation decision.');
    }

    if (!inquiry_quote_client_can_access($conn, $draftId, $clientId)) {
        throw new RuntimeException('Quotation not found in your account.');
    }

    $note = trim($note);
    if (in_array($decision, ['revision_requested', 'rejected'], true) && strlen($note) < 5) {
        throw new RuntimeException('Please enter a clear reason before submitting this decision.');
    }

    $quotation = inquiry_quote_fetch_full($conn, $draftId);
    $fromStatus = inquiry_quote_normalize_status($quotation['status'] ?? '');
    if ($fromStatus !== 'sent') {
        throw new RuntimeException('Client decision is only allowed after Admin sends the quotation.');
    }

    $stmt = $conn->prepare(
        "UPDATE inquiry_quotation_drafts
         SET status = ?, client_decision_note = ?, client_decision_at = NOW()
         WHERE id = ? AND status = 'sent'"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare client quotation decision.');
    }

    $stmt->bind_param('ssi', $decision, $note, $draftId);
    $stmt->execute();
    if ($stmt->affected_rows <= 0) {
        throw new RuntimeException('This quotation can no longer be changed.');
    }

    inquiry_quote_add_history($conn, $draftId, $fromStatus, $decision, $note, $clientId, 'client');
}

function inquiry_quote_public_respond(mysqli $conn, string $token, string $decision, string $note): void
{
    $quotation = inquiry_quote_fetch_by_public_token($conn, $token);
    if (!$quotation) {
        throw new RuntimeException('Quotation link is invalid or expired.');
    }

    $decision = inquiry_quote_normalize_status($decision);
    if (!in_array($decision, ['accepted', 'revision_requested', 'rejected'], true)) {
        throw new RuntimeException('Invalid quotation decision.');
    }

    $note = trim($note);
    if (in_array($decision, ['revision_requested', 'rejected'], true) && strlen($note) < 5) {
        throw new RuntimeException('Please enter a clear reason before submitting this decision.');
    }

    $draftId = (int)$quotation['id'];
    $fromStatus = inquiry_quote_normalize_status($quotation['status'] ?? '');
    if ($fromStatus !== 'sent') {
        throw new RuntimeException('This quotation can no longer be changed.');
    }

    $stmt = $conn->prepare(
        "UPDATE inquiry_quotation_drafts
         SET status = ?, client_decision_note = ?, client_decision_at = NOW()
         WHERE id = ? AND public_access_token_hash = ? AND status = 'sent'"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare quotation decision.');
    }

    $tokenHash = inquiry_quote_public_token_hash($token);
    $stmt->bind_param('ssis', $decision, $note, $draftId, $tokenHash);
    $stmt->execute();
    if ($stmt->affected_rows <= 0) {
        throw new RuntimeException('This quotation can no longer be changed.');
    }

    inquiry_quote_add_history($conn, $draftId, $fromStatus, $decision, $note, 0, 'prospect');
}

function inquiry_quote_reopen_for_revision(mysqli $conn, int $draftId, int $adminId): void
{
    $quotation = inquiry_quote_fetch_full($conn, $draftId);
    if (!$quotation) {
        throw new RuntimeException('Quotation draft not found.');
    }

    $fromStatus = inquiry_quote_normalize_status($quotation['status'] ?? '');
    if ($fromStatus !== 'revision_requested') {
        throw new RuntimeException('Only revision-requested quotations can be reopened.');
    }

    $stmt = $conn->prepare(
        "UPDATE inquiry_quotation_drafts
         SET status = 'draft'
         WHERE id = ? AND status = 'revision_requested'"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare quotation revision.');
    }

    $stmt->bind_param('i', $draftId);
    $stmt->execute();
    if ($stmt->affected_rows <= 0) {
        throw new RuntimeException('Only revision-requested quotations can be reopened.');
    }

    inquiry_quote_add_history($conn, $draftId, $fromStatus, 'draft', 'Admin reopened quotation for revision.', $adminId, 'admin');
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
    $client = trim((string)($quotation['client_name'] ?? ''));
    $city = trim((string)($quotation['city_municipality'] ?? ''));
    $parts = array_filter([$service, $client, $city], static fn($part) => $part !== '');

    return implode(' - ', $parts);
}

function inquiry_quote_project_name_exists(mysqli $conn, string $projectName): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM projects WHERE LOWER(TRIM(project_name)) = LOWER(TRIM(?)) LIMIT 1');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $projectName);
    $stmt->execute();

    return (bool)$stmt->get_result()->fetch_assoc();
}

function inquiry_quote_unique_project_title(mysqli $conn, array $quotation): string
{
    $baseTitle = inquiry_quote_build_project_title($quotation);
    $quotationNo = trim((string)($quotation['quotation_no'] ?? ''));
    $fallbackTitle = $quotationNo !== '' ? $baseTitle . ' - ' . $quotationNo : $baseTitle;

    if (!inquiry_quote_project_name_exists($conn, $baseTitle)) {
        return $baseTitle;
    }

    if (!inquiry_quote_project_name_exists($conn, $fallbackTitle)) {
        return $fallbackTitle;
    }

    return $baseTitle . ' - ' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
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

    if (inquiry_quote_normalize_status($quotation['status'] ?? '') !== 'accepted') {
        throw new RuntimeException('Client must accept the quotation before creating a project.');
    }

    if (!empty($quotation['project_id'])) {
        return (int)$quotation['project_id'];
    }

    $recipient = inquiry_quote_resolve_recipient($conn, $draftId);
    $clientId = (int)($recipient['client_id'] ?? 0);
    if ($clientId <= 0) {
        throw new RuntimeException('Create or link a Client account before creating a project from this accepted quotation.');
    }

    $projectCode = inquiry_quote_generate_project_code($conn);
    $projectName = inquiry_quote_unique_project_title($conn, $quotation);
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
        $projectSource = 'inquiry_quotation';
        $quotationNo = (string)$quotation['quotation_no'];
        $budgetAmount = (float)($quotation['grand_total'] ?? 0);
        $engineerId = (int)($quotation['engineer_id'] ?? 0);
        $engineerName = trim((string)($quotation['engineer_name'] ?? 'Engineer'));
        $engineerWasAssigned = false;

        $assignment = $conn->prepare(
            'INSERT IGNORE INTO project_assignments (project_id, engineer_id, assigned_by)
             VALUES (?, ?, ?)'
        );
        if ($assignment) {
            if ($engineerId > 0) {
                $assignment->bind_param('iii', $projectId, $engineerId, $adminId);
                $assignment->execute();
                $engineerWasAssigned = $assignment->affected_rows > 0;
            }
        }

        $sourceUpdate = $conn->prepare('UPDATE projects SET project_source = ? WHERE id = ?');
        if ($sourceUpdate) {
            $sourceUpdate->bind_param('si', $projectSource, $projectId);
            $sourceUpdate->execute();
        }

        $budget = $conn->prepare(
            'INSERT INTO project_budget_profiles (project_id, budget_amount, budget_notes, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE budget_amount = VALUES(budget_amount), budget_notes = VALUES(budget_notes), updated_by = VALUES(updated_by)'
        );
        if ($budget) {
            $budgetNotes = 'Auto-created from accepted quotation ' . $quotationNo;
            $budget->bind_param('idsii', $projectId, $budgetAmount, $budgetNotes, $adminId, $adminId);
            $budget->execute();
        }

        $link = $conn->prepare('UPDATE inquiry_quotation_drafts SET project_id = ? WHERE id = ?');
        if ($link) {
            $link->bind_param('ii', $projectId, $draftId);
            $link->execute();
        }

        if (
            inquiry_quote_column_exists($conn, 'service_inquiries', 'admin_notes') &&
            inquiry_quote_column_exists($conn, 'service_inquiries', 'reviewed_at')
        ) {
            $note = 'Project created from quotation ' . (string)$quotation['quotation_no'] . ' as ' . $projectCode . '.';
            $inquiryId = (int)($quotation['inquiry_id'] ?? 0);
            $inquiryUpdate = $conn->prepare(
                "UPDATE service_inquiries
                 SET status = 'For Inspection',
                     reviewed_at = COALESCE(reviewed_at, NOW()),
                     admin_notes = TRIM(CONCAT(COALESCE(admin_notes, ''), CASE WHEN COALESCE(admin_notes, '') = '' THEN '' ELSE '\n' END, ?))
                 WHERE id = ?"
            );
            if ($inquiryUpdate && $inquiryId > 0) {
                $inquiryUpdate->bind_param('si', $note, $inquiryId);
                $inquiryUpdate->execute();
            }
        }

        project_history_add(
            $conn,
            $projectId,
            $adminId,
            'Project Created',
            'Project was created from accepted quotation ' . $quotationNo . '.',
            null,
            $projectCode,
            'inquiry_quotation',
            $draftId
        );

        project_history_add(
            $conn,
            $projectId,
            $adminId,
            'Quotation Accepted',
            'Accepted quotation ' . $quotationNo . ' was used as the project source.',
            null,
            inquiry_quote_format_money($budgetAmount),
            'inquiry_quotation',
            $draftId
        );

        project_history_add(
            $conn,
            $projectId,
            $adminId,
            'Project Budget Created',
            'Initial project budget was copied from the accepted quotation total.',
            null,
            inquiry_quote_format_money($budgetAmount),
            'inquiry_quotation',
            $draftId
        );

        if ($engineerWasAssigned) {
            project_history_add(
                $conn,
                $projectId,
                $adminId,
                'Engineer Assigned',
                $engineerName . ' was assigned from the approved site inspection.',
                null,
                $engineerName,
                'site_inspection',
                (int)($quotation['inspection_id'] ?? 0)
            );
        }

        $conn->commit();
        return $projectId;
    } catch (Throwable $throwable) {
        $conn->rollback();
        throw $throwable;
    }
}
