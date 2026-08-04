-- Admin review fields for landing page service inquiries.
ALTER TABLE service_inquiries
    ADD COLUMN IF NOT EXISTS admin_notes TEXT NULL AFTER status,
    ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMP NULL AFTER admin_notes;
