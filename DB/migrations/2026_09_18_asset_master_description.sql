-- Optional asset details such as model, size, or specification.
-- Safe to re-run on MySQL versions that support ADD COLUMN IF NOT EXISTS.
ALTER TABLE assets
    ADD COLUMN IF NOT EXISTS description VARCHAR(255) NULL AFTER asset_name;
