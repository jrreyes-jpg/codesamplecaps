-- Optional stockroom ceiling per consumable material.
ALTER TABLE materials
    ADD COLUMN IF NOT EXISTS maximum_stock_level DECIMAL(12,2) NULL AFTER reorder_level;

-- Rollback kung kailangan:
-- ALTER TABLE materials DROP COLUMN maximum_stock_level;
