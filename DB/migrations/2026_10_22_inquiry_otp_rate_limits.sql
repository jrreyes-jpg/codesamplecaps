-- Public inquiry OTP protection.
-- Run this after making a database backup.

CREATE TABLE IF NOT EXISTS inquiry_otp_rate_limits (
    scope_key CHAR(64) NOT NULL PRIMARY KEY,
    scope_type VARCHAR(30) NOT NULL,
    attempt_count INT NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    blocked_until DATETIME NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_inquiry_otp_scope_type (scope_type),
    KEY idx_inquiry_otp_blocked_until (blocked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
