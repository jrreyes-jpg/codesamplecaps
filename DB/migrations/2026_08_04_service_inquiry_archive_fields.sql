-- Archive-ready fields for service inquiries. Do not delete leads; archive them safely.
ALTER TABLE service_inquiries
    ADD COLUMN IF NOT EXISTS archived_at TIMESTAMP NULL AFTER viewed_at,
    ADD COLUMN IF NOT EXISTS archived_by INT NULL AFTER archived_at,
    ADD COLUMN IF NOT EXISTS archive_reason TEXT NULL AFTER archived_by;
