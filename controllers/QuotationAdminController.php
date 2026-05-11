<?php

require_once __DIR__ . '/../config/quotation_module.php';
require_once __DIR__ . '/../services/QuotationService.php';

require_role('super_admin');

$userId = (int)current_user_id();
$role = (string)current_user_role();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    quotation_module_redirect('/codesamplecaps/SUPERADMIN/sidebar/quotations.php');
}

if (!quotation_module_is_valid_csrf($_POST['csrf_token'] ?? null)) {
    quotation_module_set_flash('error', 'Security check failed. Please try again.');
    quotation_module_redirect('/codesamplecaps/SUPERADMIN/sidebar/quotations.php');
}

$quotationId = (int)($_POST['quotation_id'] ?? 0);
$action = trim((string)($_POST['action'] ?? ''));
$remarks = quotation_module_normalize_text($_POST['remarks'] ?? '');
$profitMarginPercent = (float)($_POST['profit_margin_percent'] ?? 0);
$service = new QuotationService();

try {
    if ($quotationId <= 0) {
        throw new RuntimeException('Quotation not found.');
    }

    if ($action === 'approve') {
        $service->approveQuotation($quotationId, $profitMarginPercent, $userId, $role, $remarks);
        quotation_module_set_flash('success', 'Quotation approved and locked.');
    } elseif ($action === 'send_to_client') {
        $service->sendToClient($quotationId, $userId, $role, $remarks);
        quotation_module_set_flash('success', 'Quotation sent to client.');
    } else {
        throw new RuntimeException('Invalid approval action.');
    }

    quotation_module_redirect('/codesamplecaps/SUPERADMIN/sidebar/quotations.php?id=' . $quotationId);
} catch (Throwable $throwable) {
    quotation_module_set_flash('error', $throwable->getMessage());
    quotation_module_redirect('/codesamplecaps/SUPERADMIN/sidebar/quotations.php' . ($quotationId > 0 ? '?id=' . $quotationId : ''));
}
