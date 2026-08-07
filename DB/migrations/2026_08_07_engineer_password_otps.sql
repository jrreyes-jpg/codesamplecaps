CREATE TABLE IF NOT EXISTS engineer_password_otps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    sent_at DATETIME NOT NULL,
    verified_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_engineer_password_otp_user (user_id),
    KEY idx_engineer_password_otp_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
