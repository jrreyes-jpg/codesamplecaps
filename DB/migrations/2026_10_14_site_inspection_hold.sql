-- Manual migration. Do not run this from a PHP page.
-- Keeps old inspections compatible; On Hold uses the existing status field.
ALTER TABLE site_inspections
    ADD COLUMN IF NOT EXISTS hold_reason TEXT NULL AFTER started_at,
    ADD COLUMN IF NOT EXISTS held_at TIMESTAMP NULL AFTER hold_reason,
    ADD COLUMN IF NOT EXISTS resumed_at TIMESTAMP NULL AFTER held_at;
