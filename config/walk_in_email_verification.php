<?php

function walk_in_email_verification_key(): string
{
    return 'walk_in_email_verification';
}

function walk_in_email_verification_start(string $email, string $otp, int $validSeconds): void
{
    $_SESSION[walk_in_email_verification_key()] = [
        'email' => mb_strtolower(trim($email), 'UTF-8'),
        'otp_hash' => password_hash($otp, PASSWORD_DEFAULT),
        'expires_at' => time() + $validSeconds,
        'attempts' => 0,
        'sent_at' => time(),
        'verified' => false,
    ];
}

function walk_in_email_verification_resend_wait(string $email): int
{
    $state = $_SESSION[walk_in_email_verification_key()] ?? null;
    if (!is_array($state) || ($state['email'] ?? '') !== mb_strtolower(trim($email), 'UTF-8')) {
        return 0;
    }
    return max(0, 60 - (time() - (int)($state['sent_at'] ?? 0)));
}

function walk_in_email_verification_verify(string $email, string $otp): array
{
    $state = $_SESSION[walk_in_email_verification_key()] ?? null;
    if (!is_array($state) || ($state['email'] ?? '') !== mb_strtolower(trim($email), 'UTF-8')) {
        return ['valid' => false, 'message' => 'Send a new code first.'];
    }
    if ((int)($state['expires_at'] ?? 0) < time()) {
        return ['valid' => false, 'message' => 'The code expired. Send a new code.'];
    }
    if ((int)($state['attempts'] ?? 0) >= 5) {
        return ['valid' => false, 'message' => 'Too many tries. Send a new code.'];
    }

    $_SESSION[walk_in_email_verification_key()]['attempts'] = (int)$state['attempts'] + 1;
    if (!password_verify($otp, (string)($state['otp_hash'] ?? ''))) {
        return ['valid' => false, 'message' => 'Incorrect code.'];
    }

    $_SESSION[walk_in_email_verification_key()]['verified'] = true;
    return ['valid' => true, 'message' => 'Email verified.'];
}

function walk_in_email_is_verified(string $email): bool
{
    $state = $_SESSION[walk_in_email_verification_key()] ?? null;
    return is_array($state)
        && !empty($state['verified'])
        && ($state['email'] ?? '') === mb_strtolower(trim($email), 'UTF-8');
}

function walk_in_email_verification_clear(): void
{
    unset($_SESSION[walk_in_email_verification_key()]);
}
