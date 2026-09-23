-- User-specific in-app notifications.
-- Run this manually after creating a database backup.

CREATE TABLE IF NOT EXISTS user_notifications (
    id INT(11) NOT NULL AUTO_INCREMENT,
    user_id INT(11) NOT NULL,
    type VARCHAR(80) NOT NULL,
    reference_id INT(11) NOT NULL,
    title VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    target_url VARCHAR(255) NOT NULL,
    read_at TIMESTAMP NULL DEFAULT NULL,
    dedupe_key VARCHAR(191) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_notifications_dedupe (dedupe_key),
    KEY idx_user_notifications_unread (user_id, read_at, created_at),
    KEY idx_user_notifications_reference (type, reference_id),
    CONSTRAINT fk_user_notifications_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
