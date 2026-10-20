-- Optional budget guide para sa Online at Walk-in Inquiry.
-- Run after gumawa ng database backup.

ALTER TABLE service_inquiries
    ADD COLUMN IF NOT EXISTS estimated_budget VARCHAR(30) NULL AFTER service_category;
