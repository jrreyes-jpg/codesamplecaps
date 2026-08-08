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
