<?php

require_once __DIR__ . '/../../../../config/auth_middleware.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/audit_log.php';
require_once __DIR__ . '/../../../services/walk_in_inquiry_service.php';

require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /codesamplecaps/ADMIN/sidebar/projects/php/projects.php');
    exit();
}

if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'super_admin')) {
    $_SESSION['projects_flash'] = ['type' => 'error', 'message' => 'Security check failed. Try again.'];
    header('Location: /codesamplecaps/ADMIN/sidebar/projects/php/projects.php?walk_in=1');
    exit();
}

$_SESSION['walk_in_inquiry_old_input'] = $_POST;

try {
    $nextAction = (string)($_POST['next_action'] ?? '') === 'review' ? 'review' : 'pending';
    $inquiryId = create_walk_in_inquiry($conn, $_POST);
    audit_log_event(
        $conn,
        (int)($_SESSION['user_id'] ?? 0),
        'create_walk_in_inquiry',
        'service_inquiry',
        $inquiryId,
        null,
        ['status' => 'Pending Review', 'engineer' => 'Unassigned']
    );
    unset($_SESSION['walk_in_inquiry_old_input']);
    $_SESSION['inquiry_center_flash'] = $nextAction === 'review'
        ? 'Walk-in inquiry saved. Review it before preparing the initial quotation.'
        : 'Walk-in inquiry saved as Pending. Engineer is still Unassigned.';
    $_SESSION['inquiry_center_flash_type'] = 'success';
    header('Location: /codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php?' . http_build_query([
        'status' => 'Pending Review',
        'source' => 'walk_in',
        'walk_in_saved' => '1',
        'open' => 'inquiryModal' . $inquiryId,
        'tab' => 'client',
    ]));
    exit();
} catch (Throwable $exception) {
    $_SESSION['projects_flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
    header('Location: /codesamplecaps/ADMIN/sidebar/projects/php/projects.php?walk_in=1');
    exit();
}
