-- Optional details for consumable material size, grade, model, or specification.
-- Safe to re-run on MySQL versions that support ADD COLUMN IF NOT EXISTS.
ALTER TABLE materials
    ADD COLUMN IF NOT EXISTS description VARCHAR(255) NULL AFTER material_name;
