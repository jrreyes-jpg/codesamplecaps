-- Admin review state for submitted Engineer site inspection reports.
-- Run this manually after creating a database backup.

ALTER TABLE site_inspections
    ADD COLUMN IF NOT EXISTS admin_review_status VARCHAR(20) NOT NULL DEFAULT 'Pending' AFTER submitted_at,
    ADD COLUMN IF NOT EXISTS admin_remarks TEXT NULL AFTER admin_review_status,
    ADD COLUMN IF NOT EXISTS admin_reviewed_by INT NULL AFTER admin_remarks,
    ADD COLUMN IF NOT EXISTS admin_reviewed_at TIMESTAMP NULL AFTER admin_reviewed_by;

UPDATE site_inspections
SET admin_review_status = 'Pending'
WHERE admin_review_status NOT IN ('Pending', 'Returned', 'Approved')
   OR admin_review_status IS NULL;
