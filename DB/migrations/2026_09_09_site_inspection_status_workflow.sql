-- Site Inspection workflow: Assigned -> Acknowledged -> Ongoing -> Completed -> Submitted.
-- Run this manually after creating a database backup.

ALTER TABLE site_inspections
    ADD COLUMN IF NOT EXISTS acknowledged_at TIMESTAMP NULL AFTER status,
    ADD COLUMN IF NOT EXISTS started_at TIMESTAMP NULL AFTER acknowledged_at,
    ADD COLUMN IF NOT EXISTS completed_at TIMESTAMP NULL AFTER started_at,
    ADD COLUMN IF NOT EXISTS submitted_at TIMESTAMP NULL AFTER completed_at;

ALTER TABLE site_inspections
    MODIFY COLUMN status VARCHAR(40) NOT NULL DEFAULT 'Assigned';

UPDATE site_inspections
SET status = 'Assigned'
WHERE status = 'Scheduled';

UPDATE site_inspections
SET status = 'Submitted',
    submitted_at = COALESCE(submitted_at, updated_at, created_at)
WHERE status = 'Submitted to Admin';

-- Costing Draft is intentionally not changed. Inspect it manually if it appears.
