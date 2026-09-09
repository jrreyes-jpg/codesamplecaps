-- Separate the P.O. date from the project execution start date.
-- Existing start_date values were historically used as the P.O. date.
-- Run after a database backup.

ALTER TABLE projects
    ADD COLUMN IF NOT EXISTS po_date DATE NULL AFTER po_number;

UPDATE projects
SET po_date = start_date
WHERE po_date IS NULL
  AND start_date IS NOT NULL;
