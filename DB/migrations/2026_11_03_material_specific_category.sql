-- Optional detail kapag "Other" ang material category.
ALTER TABLE materials
    ADD COLUMN IF NOT EXISTS specific_category VARCHAR(80) NULL AFTER category;

-- Rollback kung kailangan:
-- ALTER TABLE materials DROP COLUMN specific_category;
