-- Phone is contact data. Email remains the unique account identity.
-- Run only after a database backup.

SET @drop_users_phone_unique = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'users'
              AND INDEX_NAME = 'uq_users_phone'
        ),
        'ALTER TABLE users DROP INDEX uq_users_phone',
        'SELECT 1'
    )
);
PREPARE users_phone_drop_unique FROM @drop_users_phone_unique;
EXECUTE users_phone_drop_unique;
DEALLOCATE PREPARE users_phone_drop_unique;

ALTER TABLE users
    ADD INDEX IF NOT EXISTS idx_users_phone (phone);
