<?php

// Mga simpleng budget range para pareho ang Online at Walk-in Inquiry.
function inquiry_budget_options(): array
{
    return [
        'below_50000' => 'Below PHP 50,000',
        '50000_100000' => 'PHP 50,000 - PHP 100,000',
        '100001_250000' => 'PHP 100,001 - PHP 250,000',
        'above_250000' => 'Above PHP 250,000',
        'not_sure' => 'Not sure yet',
    ];
}

function inquiry_budget_is_valid(string $value): bool
{
    return $value === '' || array_key_exists($value, inquiry_budget_options());
}

function inquiry_budget_label(?string $value): string
{
    $value = trim((string)$value);
    return inquiry_budget_options()[$value] ?? 'Not provided';
}

function inquiry_budget_column_exists(mysqli $conn): bool
{
    $stmt = $conn->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = "service_inquiries"
           AND COLUMN_NAME = "estimated_budget"
         LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }

    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_assoc();
}
