<?php
// Pending inquiry OTP flow bago ilagay sa final service_inquiries table.

function inquiry_otp_max_attempts(): int
{
    return 5;
}

function inquiry_otp_max_resends(): int
{
    return 3;
}

function inquiry_otp_resend_cooldown_seconds(): int
{
    return 60;
}

function inquiry_otp_redirect(string $status, string $token = '', array $extraQuery = []): void
{
    $query = array_merge(['inquiry' => $status], $extraQuery);
    if ($token !== '') {
        $query['token'] = $token;
    }

    header('Location: /codesamplecaps/LOGIN/php/verify_inquiry.php?' . http_build_query($query));
    exit();
}
