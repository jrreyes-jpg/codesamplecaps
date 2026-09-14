-- Reusable asset planning only for initial quotations.
-- Specific QR or serial asset assignment stays in the later project/inventory stage.

CREATE TABLE IF NOT EXISTS inquiry_quotation_asset_requirements (
    id INT(11) NOT NULL AUTO_INCREMENT,
    draft_id INT(11) NOT NULL,
    asset_name VARCHAR(180) NOT NULL,
    quantity_required DECIMAL(12,2) NOT NULL DEFAULT 1.00,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_inquiry_quote_asset_requirements_draft (draft_id),
    CONSTRAINT fk_inquiry_quote_asset_requirements_draft
        FOREIGN KEY (draft_id) REFERENCES inquiry_quotation_drafts (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
