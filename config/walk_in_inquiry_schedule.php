<?php

function walk_in_inquiry_timezone(): DateTimeZone
{
    return new DateTimeZone('Asia/Manila');
}

function walk_in_inquiry_date_range(?DateTimeImmutable $now = null): array
{
    $now = ($now ?: new DateTimeImmutable('now', walk_in_inquiry_timezone()))
        ->setTimezone(walk_in_inquiry_timezone());

    $minimumDate = $now->format('Y-m-d');
    $minimumTime = $now->modify('+1 hour');
    $hasTimeToday = false;
    foreach (array_keys(walk_in_inquiry_time_slots()) as $time) {
        $slot = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i',
            $minimumDate . ' ' . $time,
            walk_in_inquiry_timezone()
        );
        if ($slot && $slot >= $minimumTime) {
            $hasTimeToday = true;
            break;
        }
    }

    if (!$hasTimeToday) {
        $minimumDate = $now->modify('+1 day')->format('Y-m-d');
    }

    return [
        'min' => $minimumDate,
        'max' => $now->modify('+30 days')->format('Y-m-d'),
    ];
}

function walk_in_inquiry_time_slots(): array
{
    return [
        '08:00' => '8:00 AM',
        '09:00' => '9:00 AM',
        '10:00' => '10:00 AM',
        '11:00' => '11:00 AM',
        '13:00' => '1:00 PM',
        '14:00' => '2:00 PM',
        '15:00' => '3:00 PM',
        '16:00' => '4:00 PM',
    ];
}

function walk_in_inquiry_validate_preferred_schedule(
    string $date,
    string $time,
    ?DateTimeImmutable $now = null
): array {
    $timezone = walk_in_inquiry_timezone();
    $now = ($now ?: new DateTimeImmutable('now', $timezone))->setTimezone($timezone);
    $range = walk_in_inquiry_date_range($now);
    $candidate = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $timezone);

    if (!$candidate || $candidate->format('Y-m-d') !== $date || $candidate->format('H:i') !== $time) {
        return ['valid' => false, 'message' => 'Select a valid preferred date and time.'];
    }
    if ($date < $range['min'] || $date > $range['max']) {
        return ['valid' => false, 'message' => 'Choose today up to 30 days ahead.'];
    }
    if (!array_key_exists($time, walk_in_inquiry_time_slots())) {
        return ['valid' => false, 'message' => 'Choose a time from 8:00 AM to 4:00 PM, except lunch.'];
    }
    if ($candidate < $now->modify('+1 hour')) {
        return ['valid' => false, 'message' => 'For today, choose a time at least 1 hour from now.'];
    }

    return ['valid' => true, 'message' => ''];
}
