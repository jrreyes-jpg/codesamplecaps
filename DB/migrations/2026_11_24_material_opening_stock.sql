-- Existing material opening stock flow.
-- Backup the database before running this file.

ALTER TABLE materials
    ADD COLUMN IF NOT EXISTS stock_entry_type ENUM('new', 'existing') NOT NULL DEFAULT 'new' AFTER maximum_stock_level,
    ADD COLUMN IF NOT EXISTS opening_stock_expected DECIMAL(12,2) NULL AFTER stock_entry_type,
    ADD COLUMN IF NOT EXISTS opening_stock_status ENUM('not_applicable', 'pending_count', 'pending_review', 'approved', 'recount') NOT NULL DEFAULT 'not_applicable' AFTER opening_stock_expected;

ALTER TABLE material_physical_counts
    ADD COLUMN IF NOT EXISTS count_type ENUM('regular', 'opening') NOT NULL DEFAULT 'regular' AFTER expected_quantity_snapshot;

