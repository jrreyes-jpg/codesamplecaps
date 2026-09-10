-- Saves the last client-notified schedule version.
-- Run this manually after creating a database backup.

ALTER TABLE site_inspections
    ADD COLUMN IF NOT EXISTS schedule_notified_at TIMESTAMP NULL AFTER scheduled_at,
    ADD COLUMN IF NOT EXISTS schedule_notification_hash CHAR(64) NULL AFTER schedule_notified_at;
