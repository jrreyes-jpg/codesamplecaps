-- Rollback for the existing material opening stock flow.
-- Run only after checking that no opening count is still needed.

ALTER TABLE materials
    DROP COLUMN IF EXISTS opening_stock_status,
    DROP COLUMN IF EXISTS opening_stock_expected,
    DROP COLUMN IF EXISTS stock_entry_type;

ALTER TABLE material_physical_counts
    DROP COLUMN IF EXISTS count_type;

