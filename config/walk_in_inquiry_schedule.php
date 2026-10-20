<?php

require_once __DIR__ . '/inspection_date_window.php';

function walk_in_inquiry_timezone(): DateTimeZone
{
    return new DateTimeZone('Asia/Manila');
}

function walk_in_inquiry_preferred_date_range(?DateTimeImmutable $now = null): array
{
    return inspection_date_window($now);
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
        return ['valid' => false, 'message' => 'Before 5:00 PM, today is allowed. From 5:00 PM, choose tomorrow onward.'];
    }

    return ['valid' => true, 'message' => ''];
}
