<?php

require_once __DIR__ . '/../config/quotation_module.php';
require_once __DIR__ . '/../services/QuotationService.php';

require_role('foreman');

$userId = (int)current_user_id();
$role = (string)current_user_role();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    quotation_module_redirect('/codesamplecaps/FOREMAN/dashboards/quotation_reviews.php');
}

if (!quotation_module_is_valid_csrf($_POST['csrf_token'] ?? null)) {
    quotation_module_set_flash('error', 'Security check failed. Please try again.');
    quotation_module_redirect('/codesamplecaps/FOREMAN/dashboards/quotation_reviews.php');
}

$quotationId = (int)($_POST['quotation_id'] ?? 0);
$action = trim((string)($_POST['action'] ?? ''));
$message = quotation_module_normalize_text($_POST['message'] ?? '');
$service = new QuotationService();

try {
    if ($quotationId <= 0) {
        throw new RuntimeException('Quotation not found.');
    }

    if ($message === '') {
        throw new RuntimeException('Please enter your review note.');
    }

    if ($action === 'save_suggestion') {
        $service->addForemanReview($quotationId, $userId, $role, $message, false);
        quotation_module_set_flash('success', 'Foreman feedback saved for the engineer.');
    } elseif ($action === 'return_to_engineer') {
        $service->addForemanReview($quotationId, $userId, $role, $message, true);
        quotation_module_set_flash('success', 'Quotation returned to the engineer.');
    } else {
        throw new RuntimeException('Invalid review action.');
    }

    quotation_module_redirect('/codesamplecaps/FOREMAN/dashboards/quotation_reviews.php?id=' . $quotationId);
} catch (Throwable $throwable) {
    quotation_module_set_flash('error', $throwable->getMessage());
    quotation_module_redirect('/codesamplecaps/FOREMAN/dashboards/quotation_reviews.php' . ($quotationId > 0 ? '?id=' . $quotationId : ''));
}
