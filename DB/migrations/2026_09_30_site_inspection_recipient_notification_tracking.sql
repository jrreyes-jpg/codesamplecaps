-- Track each successful schedule email separately.
-- Run this only after making a database backup.

ALTER TABLE site_inspections
    ADD COLUMN IF NOT EXISTS client_schedule_notified_at TIMESTAMP NULL AFTER scheduled_at,
    ADD COLUMN IF NOT EXISTS engineer_schedule_notified_at TIMESTAMP NULL AFTER client_schedule_notified_at;
