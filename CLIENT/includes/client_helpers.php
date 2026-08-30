<?php

function client_format_date(?string $value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return 'Not set';
    }

    try {
        return (new DateTimeImmutable($value))->format('M j, Y');
    } catch (Throwable $exception) {
        return $value;
    }
}

function client_project_timeline(
    ?string $poDate,
    ?string $completedDate,
    string $status
): string {
    $parts = [
        'P.O Date: ' . client_format_date($poDate)
    ];

    if ($status === 'completed') {
        $parts[] = 'Completed: ' . client_format_date($completedDate);
    }

    return implode(' | ', $parts);
}

function client_status_label(string $status): string
{
    $labels = [
        'pending' => 'Pending',
        'ongoing' => 'In Progress',
        'completed' => 'Completed',
        'on-hold' => 'On Hold',
    ];

    return $labels[$status]
        ?? ucfirst(str_replace('-', ' ', $status));
}

function client_column_exists(
    mysqli $conn,
    string $tableName,
    string $columnName
): bool {
    static $cache = [];

    $key = $tableName . '.' . $columnName;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $statement = $conn->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
         AND table_name = ?
         AND column_name = ?'
    );

    if (!$statement) {
        $cache[$key] = false;
        return false;
    }

    $statement->bind_param(
        'ss',
        $tableName,
        $columnName
    );

    $statement->execute();
    $statement->bind_result($count);
    $statement->fetch();
    $statement->close();

    $cache[$key] = (int)$count > 0;

    return $cache[$key];
}

function client_build_deadline_meta(
    ?string $deadline,
    string $status
): array {
    $deadline = trim((string)$deadline);

    if ($deadline === '') {
        return [
            'label' => 'No deadline',
            'class' => 'deadline-flag--neutral',
        ];
    }

    $deadlineDate = DateTimeImmutable::createFromFormat(
        'Y-m-d',
        $deadline
    );

    if (!$deadlineDate) {
        return [
            'label' => $deadline,
            'class' => 'deadline-flag--neutral',
        ];
    }

    if ($status === 'completed') {
        return [
            'label' => 'Delivered',
            'class' => 'deadline-flag--ok',
        ];
    }

    $today = new DateTimeImmutable('today');

    $days = (int)$today
        ->diff($deadlineDate)
        ->format('%r%a');

    if ($days < 0) {
        return [
            'label' => 'Overdue by '
                . abs($days)
                . ' day'
                . (abs($days) === 1 ? '' : 's'),
            'class' => 'deadline-flag--danger',
        ];
    }

    if ($days <= 2) {
        return [
            'label' => $days === 0
                ? 'Due today'
                : 'Due in '
                    . $days
                    . ' day'
                    . ($days === 1 ? '' : 's'),
            'class' => 'deadline-flag--warning',
        ];
    }

    return [
        'label' => 'Due ' . $deadlineDate->format('M j, Y'),
        'class' => 'deadline-flag--ok',
    ];
}