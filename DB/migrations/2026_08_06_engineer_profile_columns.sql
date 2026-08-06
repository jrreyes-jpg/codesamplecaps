ALTER TABLE users
    ADD COLUMN IF NOT EXISTS nickname VARCHAR(80) NULL AFTER full_name,
    ADD COLUMN IF NOT EXISTS profile_photo_path VARCHAR(255) NULL AFTER token_expiry;
