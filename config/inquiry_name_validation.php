<?php

function inquiry_name_max_length(): int
{
    return 150;
}

function inquiry_name_is_valid(string $name): bool
{
    $name = trim($name);
    if ($name === '' || mb_strlen($name, 'UTF-8') > inquiry_name_max_length()) {
        return false;
    }

    if (!preg_match('/^[\p{L}\p{M} .\'\x{2019}-]+$/u', $name)) {
        return false;
    }

    preg_match_all('/\p{L}/u', $name, $letters);
    return count($letters[0] ?? []) >= 2;
}
