<?php

require_once __DIR__ . '/inspection_date_window.php';

// Iisang rule para pareho ang date sa form at server check.
if (!function_exists('inquiry_preferred_date_range')) {
    function inquiry_preferred_date_range(?DateTimeImmutable $now = null): array
    {
        return inspection_date_window($now);
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
