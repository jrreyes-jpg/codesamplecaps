-- Client and Engineer can respond to a schedule, but Admin owns the official schedule.
-- Run this only after making a database backup.

ALTER TABLE site_inspections
    ADD COLUMN IF NOT EXISTS client_schedule_response VARCHAR(24) NOT NULL DEFAULT 'pending' AFTER schedule_notification_hash,
    ADD COLUMN IF NOT EXISTS client_schedule_response_note TEXT NULL AFTER client_schedule_response,
    ADD COLUMN IF NOT EXISTS client_schedule_preferred_at DATETIME NULL AFTER client_schedule_response_note,
    ADD COLUMN IF NOT EXISTS client_schedule_responded_at TIMESTAMP NULL AFTER client_schedule_preferred_at,
    ADD COLUMN IF NOT EXISTS client_schedule_token_hash CHAR(64) NULL AFTER client_schedule_responded_at,
    ADD COLUMN IF NOT EXISTS client_schedule_token_expires_at DATETIME NULL AFTER client_schedule_token_hash,
    ADD COLUMN IF NOT EXISTS engineer_schedule_response VARCHAR(24) NOT NULL DEFAULT 'pending' AFTER client_schedule_token_expires_at,
    ADD COLUMN IF NOT EXISTS engineer_schedule_response_note TEXT NULL AFTER engineer_schedule_response,
    ADD COLUMN IF NOT EXISTS engineer_schedule_preferred_at DATETIME NULL AFTER engineer_schedule_response_note,
    ADD COLUMN IF NOT EXISTS engineer_schedule_responded_at TIMESTAMP NULL AFTER engineer_schedule_preferred_at;
