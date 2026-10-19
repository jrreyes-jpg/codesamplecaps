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
    $isConfirmedInspection = $preferredEngineerId > 0;
    $inquiryId = create_walk_in_inquiry($conn, $_POST);
    audit_log_event(
        $conn,
        (int)($_SESSION['user_id'] ?? 0),
        'create_walk_in_inquiry',
        'service_inquiry',
        $inquiryId,
        null,
        [
            'status' => $isConfirmedInspection ? 'For Inspection' : 'Pending Review',
            'email_verified' => $emailWasVerified,
            'preferred_engineer_id' => $preferredEngineerId ?: null,
        ]
    );
    walk_in_email_verification_clear();
    unset($_SESSION['walk_in_inquiry_old_input']);
    $_SESSION['inquiry_center_flash'] = $isConfirmedInspection
        ? 'Confirmed inspection saved. Engineer is assigned.'
        : 'Walk-in inquiry saved as Pending. Engineer is still Unassigned.';
    $_SESSION['inquiry_center_flash_type'] = 'success';
    header('Location: /codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?' . http_build_query([
        'status' => $isConfirmedInspection ? 'For Inspection' : 'Pending Review',
        'source' => 'walk_in',
        'walk_in_saved' => '1',
        'open' => 'inquiryModal' . $inquiryId,
        'tab' => 'client',
    ]));
    exit();
} catch (Throwable $exception) {
    $_SESSION['inquiry_center_flash'] = $exception->getMessage();
    $_SESSION['inquiry_center_flash_type'] = 'error';
    header('Location: /codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?walk_in=1');
    exit();
}
