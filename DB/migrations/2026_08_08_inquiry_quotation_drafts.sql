CREATE TABLE IF NOT EXISTS inquiry_quotation_drafts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inquiry_id INT NOT NULL,
    inspection_id INT NOT NULL,
    project_id INT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inquiry_quotation_items (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
