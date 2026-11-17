<?php

// Isang oras lang valid ang verified email sa inquiry forms.
function inquiry_email_verification_lifetime_seconds(): int
{
    return 3600;
}

function inquiry_email_is_valid_format(string $email): bool
{
    $email = mb_strtolower(trim($email), 'UTF-8');
    if (preg_match('/^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/', $email) !== 1) {
        return false;
    }

    $domain = substr(strrchr($email, '@') ?: '', 1);
    $commonTypos = [
        'gmail.co', 'gmail.con', 'gmail.cm', 'gmai.com', 'gmial.com',
        'yahoo.co', 'yahoo.con', 'yaho.com',
        'outlook.co', 'outlook.con', 'hotmail.co', 'hotmail.con',
    ];

    return !in_array($domain, $commonTypos, true);
}

function inquiry_email_invalid_message(): string
{
    return 'Please enter a complete email address.';
}

function inquiry_email_verification_state(string $sessionKey, bool $needsVerifiedFlag = false): ?array
{
    $state = $_SESSION[$sessionKey] ?? null;
    if (!is_array($state) || ($needsVerifiedFlag && empty($state['verified']))) {
        return null;
    }

    $verifiedAt = (int)($state['verified_at'] ?? 0);
    if ($verifiedAt <= 0 || ($verifiedAt + inquiry_email_verification_lifetime_seconds()) <= time()) {
        unset($_SESSION[$sessionKey]);
        return null;
    }

    return $state;
}

function inquiry_email_verified_value(string $sessionKey, bool $needsVerifiedFlag = false): string
{
    $state = inquiry_email_verification_state($sessionKey, $needsVerifiedFlag);
    return mb_strtolower(trim((string)($state['email'] ?? '')), 'UTF-8');
}

function inquiry_email_is_verified(string $sessionKey, string $email, bool $needsVerifiedFlag = false): bool
{
    $verifiedEmail = inquiry_email_verified_value($sessionKey, $needsVerifiedFlag);
    $email = mb_strtolower(trim($email), 'UTF-8');

    return $email !== '' && $verifiedEmail !== '' && hash_equals($verifiedEmail, $email);
}

function inquiry_email_verification_clear(string $sessionKey): void
{
    unset($_SESSION[$sessionKey]);
}
