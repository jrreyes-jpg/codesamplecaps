-- Client inquiry OTP resend recovery.
-- Run manually after creating a database backup outside the repository.

ALTER TABLE pending_service_inquiries
    ADD COLUMN IF NOT EXISTS resend_count INT NOT NULL DEFAULT 0 AFTER attempts,
    ADD COLUMN IF NOT EXISTS last_resend_at DATETIME NULL AFTER resend_count;
