<?php

require_once __DIR__ . '/../../../../config/auth_middleware.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/site_inspections.php';

require_role('admin');
header('Content-Type: application/json; charset=UTF-8');

$engineerId = max(0, (int)($_GET['engineer_id'] ?? 0));
$date = trim((string)($_GET['date'] ?? ''));
$excludeInspectionId = max(0, (int)($_GET['exclude_inspection_id'] ?? 0));

if ($engineerId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['success' => true, 'occupied_times' => []]);
    exit();
}

try {
    $start = new DateTimeImmutable($date . ' 00:00:00', site_inspection_timezone());
    // Isang shared check lang para pareho ang UI at server rule.
    $isBusy = site_inspection_engineer_has_schedule_on_date(
        $conn,
        $engineerId,
        $start,
        $excludeInspectionId,
        false
    );
    echo json_encode(['success' => true, 'engineer_busy' => $isBusy]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to check the Engineer schedule.']);
}
