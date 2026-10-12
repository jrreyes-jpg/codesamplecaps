<?php

// Iisang rule para pareho ang date sa form at server check.
if (!function_exists('inquiry_preferred_date_range')) {
    function inquiry_preferred_date_range(?DateTimeImmutable $now = null): array
    {
        $timezone = new DateTimeZone('Asia/Manila');
        $manilaNow = $now === null
            ? new DateTimeImmutable('now', $timezone)
            : $now->setTimezone($timezone);
        $today = $manilaNow->setTime(0, 0, 0);

        return [
            'min' => $today->modify('+1 day')->format('Y-m-d'),
            'max' => $today->modify('+14 days')->format('Y-m-d'),
        ];
    }
}

if (!function_exists('inquiry_preferred_date_is_allowed')) {
    function inquiry_preferred_date_is_allowed(string $value, ?DateTimeImmutable $now = null): bool
    {
        $timezone = new DateTimeZone('Asia/Manila');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        if (!$date || $date->format('Y-m-d') !== $value) {
            return false;
        }

        $range = inquiry_preferred_date_range($now);
        return $value >= $range['min'] && $value <= $range['max'];
    }
}
