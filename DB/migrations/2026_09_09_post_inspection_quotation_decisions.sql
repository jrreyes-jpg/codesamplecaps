-- Post-inspection quotation choice and revision history.
-- Run only after a database backup.

ALTER TABLE inquiry_quotation_drafts
    ADD COLUMN IF NOT EXISTS parent_draft_id INT NULL AFTER id,
    ADD COLUMN IF NOT EXISTS revision_no INT UNSIGNED NOT NULL DEFAULT 0 AFTER parent_draft_id;

-- Existing quotations are original records and remain revision 0.
UPDATE inquiry_quotation_drafts
SET revision_no = 0,
    parent_draft_id = NULL
WHERE revision_no IS NULL;

-- One inspection can need more than one quotation revision.
SET @drop_unique_inspection_index = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'inquiry_quotation_drafts'
              AND INDEX_NAME = 'uniq_inquiry_quote_inspection'
        ),
        'ALTER TABLE inquiry_quotation_drafts DROP INDEX uniq_inquiry_quote_inspection',
        'SELECT 1'
    )
);
PREPARE post_inspection_drop_index FROM @drop_unique_inspection_index;
EXECUTE post_inspection_drop_index;
DEALLOCATE PREPARE post_inspection_drop_index;

ALTER TABLE inquiry_quotation_drafts
    ADD INDEX IF NOT EXISTS idx_inquiry_quote_inspection (inspection_id),
    ADD INDEX IF NOT EXISTS idx_inquiry_quote_parent_revision (parent_draft_id, revision_no);

CREATE TABLE IF NOT EXISTS inspection_quotation_decisions (
    id INT NOT NULL AUTO_INCREMENT,
    inquiry_id INT NOT NULL,
    inspection_id INT NOT NULL,
    initial_quotation_draft_id INT NOT NULL,
    revised_quotation_draft_id INT NULL,
    final_quotation_draft_id INT NULL,
    inspection_costing_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    variance_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    decision VARCHAR(40) NOT NULL,
    admin_remarks TEXT NULL,
    decided_by INT NOT NULL,
    decided_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_inspection_quotation_decision (inspection_id),
    KEY idx_inspection_quotation_decision_inquiry (inquiry_id),
    KEY idx_inspection_quotation_decision_initial (initial_quotation_draft_id),
    KEY idx_inspection_quotation_decision_revised (revised_quotation_draft_id),
    KEY idx_inspection_quotation_decision_final (final_quotation_draft_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
