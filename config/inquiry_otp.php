<?php
// Pending inquiry OTP flow bago ilagay sa final service_inquiries table.

function inquiry_otp_redirect(string $status, string $token = '', array $extraQuery = []): void
{
    $query = array_merge(['inquiry' => $status], $extraQuery);
    if ($token !== '') {
        $query['token'] = $token;
    }

    header('Location: /codesamplecaps/LOGIN/php/verify_inquiry.php?' . http_build_query($query));
    exit();
}
