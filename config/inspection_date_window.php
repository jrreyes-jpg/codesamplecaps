<?php

// Iisang cutoff para sa Online, Walk-in, at Admin.
if (!function_exists('inspection_date_window')) {
    function inspection_date_window(?DateTimeImmutable $now = null): array
    {
        $timezone = new DateTimeZone('Asia/Manila');
        $manilaNow = ($now ?: new DateTimeImmutable('now', $timezone))->setTimezone($timezone);
        $today = $manilaNow->setTime(0, 0, 0);
        $earliest = (int)$manilaNow->format('G') >= 17
            ? $today->modify('+1 day')
            : $today;

        return [
            'min' => $earliest->format('Y-m-d'),
            'max' => $today->modify('+30 days')->format('Y-m-d'),
        ];
    }
}
