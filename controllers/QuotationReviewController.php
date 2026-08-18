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
    throw new RuntimeException('Foreman quotation review is disabled. Admin now handles quotation review and approval.');

    quotation_module_redirect('/codesamplecaps/FOREMAN/dashboards/quotation_reviews.php?id=' . $quotationId);
} catch (Throwable $throwable) {
    quotation_module_set_flash('error', $throwable->getMessage());
    quotation_module_redirect('/codesamplecaps/FOREMAN/dashboards/quotation_reviews.php' . ($quotationId > 0 ? '?id=' . $quotationId : ''));
}
