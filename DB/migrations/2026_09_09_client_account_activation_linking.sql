-- Client account linking and activation. Run after a database backup.

ALTER TABLE service_inquiries
    ADD COLUMN IF NOT EXISTS client_id INT NULL AFTER id,
    ADD INDEX IF NOT EXISTS idx_service_inquiries_client_id (client_id);

SET @add_service_inquiry_client_fk = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND TABLE_NAME = 'service_inquiries'
              AND CONSTRAINT_NAME = 'fk_service_inquiries_client'
        ),
        'SELECT 1',
        'ALTER TABLE service_inquiries ADD CONSTRAINT fk_service_inquiries_client FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE SET NULL'
    )
);
PREPARE service_inquiry_client_fk FROM @add_service_inquiry_client_fk;
EXECUTE service_inquiry_client_fk;
DEALLOCATE PREPARE service_inquiry_client_fk;

ALTER TABLE password_reset_tokens
    ADD COLUMN IF NOT EXISTS purpose VARCHAR(30) NOT NULL DEFAULT 'password_reset' AFTER user_id,
    ADD INDEX IF NOT EXISTS idx_password_reset_tokens_user_purpose (user_id, purpose, used, expires_at);

UPDATE password_reset_tokens
SET purpose = 'password_reset'
WHERE purpose IS NULL OR purpose = '';
