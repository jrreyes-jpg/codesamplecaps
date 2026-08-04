<?php
// Pending inquiry OTP flow bago ilagay sa final service_inquiries table.

function inquiry_otp_ensure_table(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS pending_service_inquiries (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function inquiry_otp_redirect(string $status, string $token = ''): void
{
    $query = ['inquiry' => $status];
    if ($token !== '') {
        $query['token'] = $token;
    }

    header('Location: /codesamplecaps/LOGIN/php/verify_inquiry.php?' . http_build_query($query));
    exit();
}
