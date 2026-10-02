<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/site_inspections.php';
require_once __DIR__ . '/../../config/user_notifications.php';
require_once __DIR__ . '/../../config/audit_log.php';

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$tokenHash = $token !== '' ? site_inspection_schedule_token_hash($token) : '';
$isAjaxRequest = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

$loadInspection = static function (mysqli $conn, string $hash): ?array {
    $stmt = $conn->prepare(
        "SELECT si.*, i.client_name, i.service_category, i.site_address, i.barangay, i.city_municipality, i.province, u.full_name AS engineer_name
         FROM site_inspections si
         JOIN service_inquiries i ON i.id = si.inquiry_id
         JOIN users u ON u.id = si.engineer_id
         WHERE si.client_schedule_token_hash = ? AND si.client_schedule_token_expires_at > NOW()
         LIMIT 1"
    );
    if (!$stmt || $hash === '') return null;
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
};

$reply = static function (bool $success, string $text) use ($isAjaxRequest, $token): void {
    if ($isAjaxRequest) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode(['success' => $success, 'message' => $text]);
        exit;
    }
    header('Location: /codesamplecaps/LOGIN/php/inspection_schedule.php?' . http_build_query([
        'token' => $token,
        $success ? 'message' : 'error' => $text,
    ]));
    exit;
};

$inspection = $loadInspection($conn, $tokenHash);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$inspection) $reply(false, 'This inspection schedule link is invalid or expired.');

    $action = (string)($_POST['action'] ?? '');
    $inspectionId = (int)$inspection['id'];
    $status = (string)$inspection['status'];
    $clientResponse = (string)($inspection['client_schedule_response'] ?? 'pending');
    $engineerResponse = (string)($inspection['engineer_schedule_response'] ?? 'pending');
    $isClosed = in_array($status, ['Ongoing', 'Completed', 'Submitted'], true);
    $hasRescheduleRequest = $clientResponse === 'reschedule_requested' || $engineerResponse === 'reschedule_requested';
    if ($isClosed) $reply(false, 'This inspection schedule can no longer be changed.');
    if ($clientResponse !== 'pending' || $hasRescheduleRequest) $reply(false, 'This schedule response is already recorded or is waiting for Admin review.');

    if ($action === 'confirm') {
        $update = $conn->prepare(
            "UPDATE site_inspections
             SET client_schedule_response = 'confirmed', client_schedule_response_note = NULL,
                 client_schedule_preferred_at = NULL, client_schedule_responded_at = NOW()
             WHERE id = ? AND client_schedule_token_hash = ?
               AND client_schedule_response = 'pending'
               AND engineer_schedule_response <> 'reschedule_requested'
               AND status IN ('Assigned', 'Acknowledged')"
        );
        if (!$update) $reply(false, 'Unable to confirm the schedule. Please try again.');
        $update->bind_param('is', $inspectionId, $tokenHash);
        $update->execute();
        if ($update->affected_rows !== 1) $reply(false, 'This schedule was updated. Please refresh the page.');
        $reply(true, 'Schedule confirmed successfully!');
    }

    if ($action !== 'request_reschedule') $reply(false, 'Invalid schedule response.');
    $note = trim((string)($_POST['reason'] ?? ''));
    $date = trim((string)($_POST['preferred_date'] ?? ''));
    $time = trim((string)($_POST['preferred_time'] ?? ''));
    $timeZone = new DateTimeZone('Asia/Manila');
    $meaningfulNote = preg_replace('/\s+/', '', $note) ?? '';
    if ($note === '' || mb_strlen($meaningfulNote) < 5) $reply(false, 'Please enter a reason with at least 5 characters.');
    if (mb_strlen($note) > 2000) $reply(false, 'Please keep the reason under 2000 characters.');
    if ($date === '' || $time === '') $reply(false, 'Please choose both a preferred date and time.');

    $allowedPreferredTimes = ['08:00', '09:00', '10:00', '11:00', '13:00', '14:00', '15:00', '16:00', '17:00'];
    if (!in_array($time, $allowedPreferredTimes, true)) $reply(false, 'Please choose a valid preferred time.');
    $preferredDateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $timeZone);
    $dateErrors = DateTimeImmutable::getLastErrors();
    $hasDateErrors = is_array($dateErrors) && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0);
    if (!$preferredDateTime || $hasDateErrors || $preferredDateTime <= new DateTimeImmutable('now', $timeZone)) {
        $reply(false, 'Please choose a future preferred date and time.');
    }
    $preferred = $preferredDateTime->format('Y-m-d H:i:s');

    $update = $conn->prepare(
        "UPDATE site_inspections
         SET client_schedule_response = 'reschedule_requested', client_schedule_response_note = ?,
             client_schedule_preferred_at = ?, client_schedule_responded_at = NOW()
         WHERE id = ? AND client_schedule_token_hash = ?
           AND client_schedule_response = 'pending'
           AND engineer_schedule_response <> 'reschedule_requested'
           AND status IN ('Assigned', 'Acknowledged')"
    );
    if (!$update) $reply(false, 'Unable to send the request. Please try again.');
    $update->bind_param('ssis', $note, $preferred, $inspectionId, $tokenHash);
    $update->execute();
    if ($update->affected_rows !== 1) $reply(false, 'This schedule was updated. Please refresh the page.');

    $schedule = (new DateTimeImmutable((string)$inspection['scheduled_at'], $timeZone))->format('M j, Y g:i A');
    user_notifications_create_if_missing(
        $conn, (int)$inspection['created_by'], 'client_inspection_reschedule_requested', $inspectionId,
        'Client Requested Inspection Reschedule',
        'Client: ' . (string)$inspection['client_name'] . ' • Current schedule: ' . $schedule . ' • Reason: ' . mb_strimwidth($note, 0, 90, '…'),
        '/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?open=inquiryModal' . (int)$inspection['inquiry_id'] . '&tab=inspection',
        'client_inspection_reschedule:' . $inspectionId . ':' . hash('sha256', (string)$inspection['scheduled_at'] . $note)
    );
    audit_log_event($conn, 0, 'client_reschedule_requested', 'site_inspection', $inspectionId, null, ['note' => $note]);
    $reply(true, 'Reschedule request sent. Edge Automation will review it.');
}

$message = (string)($_GET['message'] ?? '');
$error = (string)($_GET['error'] ?? '');
$timeZone = new DateTimeZone('Asia/Manila');
$clientResponse = (string)($inspection['client_schedule_response'] ?? 'pending');
$engineerResponse = (string)($inspection['engineer_schedule_response'] ?? 'pending');
$isWorkflowLocked = $inspection && in_array((string)$inspection['status'], ['Ongoing', 'Completed', 'Submitted'], true);
$isWaitingForAdmin = $inspection && ($clientResponse === 'reschedule_requested' || $engineerResponse === 'reschedule_requested');
$rescheduleStateTitle = $clientResponse === 'reschedule_requested' ? 'Reschedule Request Sent' : 'Schedule Under Review';
$rescheduleStateMessage = $clientResponse === 'reschedule_requested'
    ? 'A schedule change is waiting for Admin review. A new schedule will need confirmation.'
    : 'A schedule change was requested. Admin will send a new official schedule for confirmation.';
$scheduleText = $inspection ? (new DateTimeImmutable((string)$inspection['scheduled_at'], $timeZone))->format('D, M j, Y • g:i A') . ' PHT' : '';
$siteAddress = $inspection ? implode(', ', array_filter([
    trim((string)$inspection['site_address']), trim((string)$inspection['barangay']), trim((string)$inspection['city_municipality']), trim((string)$inspection['province']),
], static fn(string $part): bool => $part !== '')) : '';
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Inspection Schedule | Edge Automation</title><link rel="stylesheet" href="../css/inspection_schedule.css"></head>
<body><main class="schedule-page"><section class="schedule-card" aria-labelledby="scheduleTitle">
    <header class="schedule-card__head"><div class="schedule-brand"><img src="../../IMAGES/edge.jpg" alt="Edge Automation" class="schedule-brand__logo"><span>Edge Automation</span></div><p>Inspection schedule response</p><h1 id="scheduleTitle">Review your site inspection schedule</h1></header>
    <?php if (!$inspection): ?>
        <p class="schedule-alert schedule-alert--error" role="alert">This schedule link is invalid or expired.</p>
    <?php else: ?>
        <div class="schedule-live-message" aria-live="polite" data-schedule-live-message hidden></div>
        <?php if ($message): ?><p class="schedule-alert schedule-alert--success" role="status"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
        <?php if ($error): ?><p class="schedule-alert schedule-alert--error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
        <section class="schedule-details" aria-label="Inspection details">
            <div><span>Client</span><strong><?php echo htmlspecialchars((string)$inspection['client_name'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div><span>Service</span><strong><?php echo htmlspecialchars((string)$inspection['service_category'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div><span>Assigned Engineer</span><strong><?php echo htmlspecialchars((string)$inspection['engineer_name'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="schedule-details__time"><span>Official Schedule</span><strong><?php echo htmlspecialchars($scheduleText, ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <?php if ($siteAddress !== ''): ?><div class="schedule-details__wide"><span>Site Address</span><strong><?php echo htmlspecialchars($siteAddress, ENT_QUOTES, 'UTF-8'); ?></strong></div><?php endif; ?>
        </section>
        <?php if ($clientResponse === 'confirmed' && !$isWaitingForAdmin): ?>
            <section class="schedule-response-state schedule-response-state--confirmed"><strong><span aria-hidden="true">✓</span> Schedule Confirmed</strong><p>Your response has been recorded.</p></section>
        <?php elseif ($isWaitingForAdmin): ?>
            <section class="schedule-response-state schedule-response-state--reschedule"><strong><?php echo htmlspecialchars($rescheduleStateTitle, ENT_QUOTES, 'UTF-8'); ?></strong><p><?php echo htmlspecialchars($rescheduleStateMessage, ENT_QUOTES, 'UTF-8'); ?></p><?php if ($clientResponse === 'reschedule_requested' && !empty($inspection['client_schedule_response_note'])): ?><p class="schedule-response-state__note">Your reason: <?php echo htmlspecialchars((string)$inspection['client_schedule_response_note'], ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?></section>
        <?php elseif ($isWorkflowLocked): ?>
            <section class="schedule-response-state"><strong>Schedule actions are closed</strong><p>This inspection is already in progress or finished.</p></section>
        <?php else: ?>
            <section class="schedule-actions"><p>Please confirm the official date and time, or ask Admin to reschedule it.</p>
                <form method="post" class="schedule-action-form" data-schedule-action-form><input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>"><button name="action" value="confirm" type="submit" class="schedule-button schedule-button--primary">Confirm Schedule</button></form>
                <details class="schedule-reschedule">
                    <summary>Request Reschedule</summary>
                    <form method="post" class="schedule-reschedule-form" data-schedule-reschedule-form novalidate>
                        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="request_reschedule">
                        <label>Reason <span aria-hidden="true">*</span>
                            <textarea name="reason" required minlength="5" maxlength="2000" aria-describedby="scheduleReasonError"></textarea>
                            <small id="scheduleReasonError" data-field-error="reason"></small>
                        </label>
                        <div class="schedule-reschedule-form__dates">
                            <label>Preferred Date <span aria-hidden="true">*</span>
                                <input type="date" name="preferred_date" required min="<?php echo (new DateTimeImmutable('today', $timeZone))->format('Y-m-d'); ?>" aria-describedby="scheduleDateError">
                                <small id="scheduleDateError" data-field-error="preferred_date"></small>
                            </label>
                            <label>Preferred Time <span aria-hidden="true">*</span>
                                <select name="preferred_time" required aria-describedby="scheduleTimeError">
                                    <option value="">Select time</option>
                                    <?php foreach (['08:00' => '8:00 AM', '09:00' => '9:00 AM', '10:00' => '10:00 AM', '11:00' => '11:00 AM', '13:00' => '1:00 PM', '14:00' => '2:00 PM', '15:00' => '3:00 PM', '16:00' => '4:00 PM', '17:00' => '5:00 PM'] as $timeValue => $timeLabel): ?>
                                        <option value="<?php echo $timeValue; ?>"><?php echo $timeLabel; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small id="scheduleTimeError" data-field-error="preferred_time"></small>
                            </label>
                        </div>
                        <button type="submit" class="schedule-button schedule-button--secondary">Send Request</button>
                    </form>
                </details>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</section></main><script src="../js/inspection_schedule.js"></script></body></html>
