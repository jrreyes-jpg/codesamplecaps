-- Admin review fields for landing page service inquiries.
ALTER TABLE service_inquiries
    ADD COLUMN IF NOT EXISTS admin_notes TEXT NULL AFTER status,
    ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMP NULL AFTER admin_notes,
    ADD COLUMN IF NOT EXISTS viewed_at TIMESTAMP NULL AFTER reviewed_at;
