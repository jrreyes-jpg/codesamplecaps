<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/site_inspections.php';
require_once __DIR__ . '/../../config/user_notifications.php';
require_once __DIR__ . '/../../config/audit_log.php';

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$message = '';
$error = '';
$tokenHash = $token !== '' ? site_inspection_schedule_token_hash($token) : '';
$stmt = $conn->prepare(
    "SELECT si.*, i.client_name, i.service_category, i.site_address, i.barangay, i.city_municipality, i.province, u.full_name AS engineer_name
     FROM site_inspections si
     JOIN service_inquiries i ON i.id = si.inquiry_id
     JOIN users u ON u.id = si.engineer_id
     WHERE si.client_schedule_token_hash = ? AND si.client_schedule_token_expires_at > NOW()
     LIMIT 1"
);
$inspection = null;
if ($stmt && $tokenHash !== '') {
    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $inspection = $stmt->get_result()->fetch_assoc() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $inspection) {
    $action = (string)($_POST['action'] ?? '');
    $status = (string)$inspection['status'];
    if (in_array($status, ['Ongoing', 'Completed', 'Submitted'], true)) {
        $error = 'This inspection schedule can no longer be changed.';
    } elseif ($action === 'confirm') {
        $update = $conn->prepare("UPDATE site_inspections SET client_schedule_response = 'confirmed', client_schedule_response_note = NULL, client_schedule_preferred_at = NULL, client_schedule_responded_at = NOW() WHERE id = ? AND client_schedule_token_hash = ?");
        if ($update) { $update->bind_param('is', $inspection['id'], $tokenHash); $update->execute(); $message = 'Inspection schedule confirmed.'; }
    } elseif ($action === 'request_reschedule') {
        $note = trim((string)($_POST['reason'] ?? ''));
        $date = trim((string)($_POST['preferred_date'] ?? ''));
        $time = trim((string)($_POST['preferred_time'] ?? ''));
        $preferred = ($date !== '' && $time !== '') ? $date . ' ' . $time . ':00' : null;
        if (mb_strlen($note) < 5) {
            $error = 'Please enter a reason with at least 5 characters.';
        } else {
            $update = $conn->prepare("UPDATE site_inspections SET client_schedule_response = 'reschedule_requested', client_schedule_response_note = ?, client_schedule_preferred_at = ?, client_schedule_responded_at = NOW() WHERE id = ? AND client_schedule_token_hash = ?");
            if ($update) {
                $update->bind_param('ssis', $note, $preferred, $inspection['id'], $tokenHash);
                $update->execute();
                $schedule = (new DateTimeImmutable((string)$inspection['scheduled_at'], new DateTimeZone('Asia/Manila')))->format('M j, Y g:i A');
                user_notifications_create_if_missing($conn, (int)$inspection['created_by'], 'client_inspection_reschedule_requested', (int)$inspection['id'], 'Client Requested Inspection Reschedule', 'Client: ' . (string)$inspection['client_name'] . ' • Current schedule: ' . $schedule . ' • Reason: ' . mb_strimwidth($note, 0, 90, '…'), '/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?open=inquiryModal' . (int)$inspection['inquiry_id'] . '&tab=inspection', 'client_inspection_reschedule:' . (int)$inspection['id'] . ':' . hash('sha256', (string)$inspection['scheduled_at'] . $note));
                audit_log_event($conn, 0, 'client_reschedule_requested', 'site_inspection', (int)$inspection['id'], null, ['note' => $note]);
                $message = 'Your reschedule request was sent to Edge Automation. The current schedule remains unchanged until Admin confirms a new schedule.';
            }
        }
    }
    header('Location: /codesamplecaps/LOGIN/php/inspection_schedule.php?token=' . urlencode($token) . '&message=' . urlencode($message) . '&error=' . urlencode($error));
    exit;
}
$message = (string)($_GET['message'] ?? $message);
$error = (string)($_GET['error'] ?? $error);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Inspection Schedule | Edge Automation</title><link rel="stylesheet" href="../css/inspection_schedule.css"></head><body><main class="schedule-page"><section class="schedule-card"><h1>Inspection Schedule</h1><?php if (!$inspection): ?><p class="schedule-error">This schedule link is invalid or expired.</p><?php else: ?><?php if ($message): ?><p class="schedule-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?><?php if ($error): ?><p class="schedule-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?><dl><dt>Client</dt><dd><?= htmlspecialchars((string)$inspection['client_name'], ENT_QUOTES, 'UTF-8') ?></dd><dt>Service</dt><dd><?= htmlspecialchars((string)$inspection['service_category'], ENT_QUOTES, 'UTF-8') ?></dd><dt>Engineer</dt><dd><?= htmlspecialchars((string)$inspection['engineer_name'], ENT_QUOTES, 'UTF-8') ?></dd><dt>Schedule</dt><dd><?= htmlspecialchars((new DateTimeImmutable((string)$inspection['scheduled_at'], new DateTimeZone('Asia/Manila')))->format('D, M j, Y • g:i A'), ENT_QUOTES, 'UTF-8') ?></dd><dt>Status</dt><dd><?= htmlspecialchars(site_inspection_schedule_response_label((string)$inspection['client_schedule_response']), ENT_QUOTES, 'UTF-8') ?></dd></dl><?php if (!in_array((string)$inspection['status'], ['Ongoing','Completed','Submitted'], true)): ?><form method="post"><input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>"><button name="action" value="confirm" type="submit">Confirm Schedule</button></form><details><summary>Request Reschedule</summary><form method="post"><input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>"><label>Reason<textarea name="reason" required minlength="5"></textarea></label><label>Preferred Date<input type="date" name="preferred_date"></label><label>Preferred Time<input type="time" name="preferred_time"></label><button name="action" value="request_reschedule" type="submit">Send Request</button></form></details><?php endif; ?><?php endif; ?></section></main></body></html>
