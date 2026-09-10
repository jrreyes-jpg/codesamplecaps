-- Normalized Philippine mobile number uniqueness.
-- Run only after fixing duplicate phone numbers and creating a database backup.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS phone_normalized VARCHAR(11) NULL AFTER phone;

UPDATE users
SET phone_normalized = CASE
    WHEN phone IS NULL OR TRIM(phone) = '' THEN NULL
    WHEN REGEXP_REPLACE(TRIM(phone), '[^0-9]', '') REGEXP '^639[0-9]{9}$'
        THEN CONCAT('09', SUBSTRING(REGEXP_REPLACE(TRIM(phone), '[^0-9]', ''), 4))
    WHEN REGEXP_REPLACE(TRIM(phone), '[^0-9]', '') REGEXP '^9[0-9]{9}$'
        THEN CONCAT('0', REGEXP_REPLACE(TRIM(phone), '[^0-9]', ''))
    ELSE REGEXP_REPLACE(TRIM(phone), '[^0-9]', '')
END;

ALTER TABLE users
    ADD UNIQUE INDEX IF NOT EXISTS uq_users_phone_normalized (phone_normalized);
