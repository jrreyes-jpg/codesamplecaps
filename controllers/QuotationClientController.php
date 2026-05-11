<?php

require_once __DIR__ . '/../config/quotation_module.php';
require_once __DIR__ . '/../services/QuotationService.php';

require_role('client');

$userId = (int)current_user_id();
$role = (string)current_user_role();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    quotation_module_redirect('/codesamplecaps/CLIENT/dashboards/quotations.php');
}

if (!quotation_module_is_valid_csrf($_POST['csrf_token'] ?? null)) {
    quotation_module_set_flash('error', 'Security check failed. Please try again.');
    quotation_module_redirect('/codesamplecaps/CLIENT/dashboards/quotations.php');
}

$quotationId = (int)($_POST['quotation_id'] ?? 0);
$action = trim((string)($_POST['action'] ?? ''));
$note = quotation_module_normalize_text($_POST['note'] ?? '');
$service = new QuotationService();

try {
    if ($quotationId <= 0) {
        throw new RuntimeException('Quotation not found.');
    }

    if ($action === 'client_accept') {
        $service->respondAsClient($quotationId, true, $note, $userId, $role);
        quotation_module_set_flash('success', 'Quotation accepted successfully.');
    } elseif ($action === 'client_reject') {
        $service->respondAsClient($quotationId, false, $note, $userId, $role);
        quotation_module_set_flash('success', 'Quotation response submitted.');
    } else {
        throw new RuntimeException('Invalid client action.');
    }

    quotation_module_redirect('/codesamplecaps/CLIENT/dashboards/quotations.php?id=' . $quotationId);
} catch (Throwable $throwable) {
    quotation_module_set_flash('error', $throwable->getMessage());
    quotation_module_redirect('/codesamplecaps/CLIENT/dashboards/quotations.php' . ($quotationId > 0 ? '?id=' . $quotationId : ''));
}
