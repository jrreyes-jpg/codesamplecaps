<?php

function walk_in_inquiry_timezone(): DateTimeZone
{
    return new DateTimeZone('Asia/Manila');
}

function walk_in_inquiry_preferred_date_range(?DateTimeImmutable $now = null): array
{
    $now = ($now ?: new DateTimeImmutable('now', walk_in_inquiry_timezone()))
        ->setTimezone(walk_in_inquiry_timezone());

    return [
        'min' => $now->format('Y-m-d'),
        'max' => $now->modify('+30 days')->format('Y-m-d'),
    ];
}

function walk_in_inquiry_validate_preferred_date(
    string $date,
    ?DateTimeImmutable $now = null
): array {
    $timezone = walk_in_inquiry_timezone();
    $range = walk_in_inquiry_preferred_date_range($now);
    $candidate = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);

    if (!$candidate || $candidate->format('Y-m-d') !== $date) {
        return ['valid' => false, 'message' => 'Select a valid preferred inspection date.'];
    }
    if ($date < $range['min'] || $date > $range['max']) {
        return ['valid' => false, 'message' => 'Choose today up to 30 days ahead.'];
    }

    return ['valid' => true, 'message' => ''];
}
