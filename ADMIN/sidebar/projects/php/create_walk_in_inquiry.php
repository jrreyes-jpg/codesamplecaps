<?php

require_once __DIR__ . '/../../../../config/auth_middleware.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/audit_log.php';
require_once __DIR__ . '/../../../services/walk_in_inquiry_service.php';
require_once __DIR__ . '/../../../../config/walk_in_email_verification.php';

require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php');
    exit();
}

if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'super_admin')) {
    $_SESSION['inquiry_center_flash'] = 'Security check failed. Try again.';
    $_SESSION['inquiry_center_flash_type'] = 'error';
    header('Location: /codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?walk_in=1');
    exit();
}

$_SESSION['walk_in_inquiry_old_input'] = $_POST;

try {
    $contactCheck = inquiry_contact_validation_result(
        $conn,
        (string)($_POST['client_name'] ?? ''),
        (string)($_POST['email'] ?? ''),
        (string)($_POST['contact_no'] ?? '')
    );
    $emailWasVerified = walk_in_email_is_verified((string)($_POST['email'] ?? ''))
        || !empty($contactCheck['email_verified']);
    $preferredEngineerId = max(0, (int)($_POST['preferred_engineer_id'] ?? 0));
    $notificationResult = [];
    $inquiryId = create_walk_in_inquiry($conn, $_POST, $notificationResult);
    $savedAction = (string)($notificationResult['action'] ?? 'pending');
    $savedStatus = $savedAction === 'confirmed' ? 'For Inspection' : 'Pending Review';
    audit_log_event(
        $conn,
        (int)($_SESSION['user_id'] ?? 0),
        'create_walk_in_inquiry',
        'service_inquiry',
        $inquiryId,
        null,
        [
            'status' => $savedStatus,
            'email_verified' => $emailWasVerified,
            'preferred_engineer_id' => $preferredEngineerId ?: null,
            'inspection_id' => (int)($notificationResult['inspection_id'] ?? 0) ?: null,
        ]
    );
    walk_in_email_verification_clear();
    unset($_SESSION['walk_in_inquiry_old_input']);
    // Ito ang sariling save ni Admin. Huwag ulitin bilang "new inquiry" toast sa page reload.
    $_SESSION['admin_inquiry_notification_last_id'] = max(
        (int)($_SESSION['admin_inquiry_notification_last_id'] ?? 0),
        $inquiryId
    );
    if ($savedAction === 'confirmed') {
        $clientSent = !empty($notificationResult['client_sent']);
        $engineerSent = !empty($notificationResult['engineer_sent']);
        $_SESSION['inquiry_center_flash'] = $clientSent && $engineerSent
            ? 'Inspection saved. Client and Engineer notified.'
            : 'Inspection saved, but one or more emails were not sent.';
        $_SESSION['inquiry_center_flash_type'] = $clientSent && $engineerSent ? 'success' : 'warning';
    } else {
        $clientSent = !empty($notificationResult['client_sent']);
        $_SESSION['inquiry_center_flash'] = $clientSent
            ? 'Walk-in inquiry saved. Client receipt sent.'
            : 'Walk-in inquiry saved, but the client email was not sent.';
        $_SESSION['inquiry_center_flash_type'] = $clientSent ? 'success' : 'warning';
    }
    header('Location: /codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?' . http_build_query([
        'status' => $savedStatus,
        'source' => 'walk_in',
        'walk_in_saved' => '1',
    ]));
    exit();
} catch (Throwable $exception) {
    $_SESSION['inquiry_center_flash'] = $exception->getMessage();
    $_SESSION['inquiry_center_flash_type'] = 'error';
    header('Location: /codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?walk_in=1');
    exit();
}
