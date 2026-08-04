CREATE TABLE IF NOT EXISTS pending_service_inquiries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(80) NOT NULL UNIQUE,
    otp_hash VARCHAR(255) NOT NULL,
    payload_json LONGTEXT NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    verified_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pending_inquiry_token (token),
    KEY idx_pending_inquiry_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
