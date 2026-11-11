-- Run this after making a database backup.
ALTER TABLE assets
    ADD COLUMN IF NOT EXISTS description VARCHAR(255) DEFAULT NULL AFTER asset_name;

-- Rollback:
-- ALTER TABLE assets DROP COLUMN description;
