<?php
// Shared Site Inspection helpers para hindi duplicate sa Admin at Engineer.

if (!function_exists('site_inspection_format_datetime')) {
    function site_inspection_format_datetime(?string $dateTime): string
    {
        $timestamp = $dateTime ? strtotime($dateTime) : false;
        if ($timestamp === false) {
            return 'Not set';
        }

        return date('M j, Y, g:ia', $timestamp);
    }
}
