-- Public reason shown only when an inquiry is marked Not Qualified.
-- Run after making a database backup.

ALTER TABLE service_inquiries
    ADD COLUMN IF NOT EXISTS client_review_reason VARCHAR(500) NULL AFTER admin_notes;
