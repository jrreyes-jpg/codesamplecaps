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

// I-check kung pasok ang costing sa budget range ng client.
function inquiry_budget_cost_position(?string $value, float $cost): ?string
{
    return match (trim((string)$value)) {
        'below_50000' => $cost < 50000 ? 'within' : 'above',
        '50000_100000' => $cost < 50000 ? 'below' : ($cost <= 100000 ? 'within' : 'above'),
        '100001_250000' => $cost < 100001 ? 'below' : ($cost <= 250000 ? 'within' : 'above'),
        'above_250000' => $cost > 250000 ? 'within' : 'below',
        default => null,
    };
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
