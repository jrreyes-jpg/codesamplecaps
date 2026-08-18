<?php

require_once __DIR__ . '/../config/quotation_module.php';
require_once __DIR__ . '/../config/inquiry_quotation_module.php';
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
$source = trim((string)($_POST['source'] ?? 'generic'));
$service = new QuotationService();

try {
    if ($quotationId <= 0) {
        throw new RuntimeException('Quotation not found.');
    }

    if ($source === 'inquiry') {
        if ($action === 'client_accept') {
            inquiry_quote_client_respond($conn, $quotationId, $userId, 'accepted', $note);
            quotation_module_set_flash('success', 'Quotation accepted successfully.');
        } elseif ($action === 'client_revision') {
            inquiry_quote_client_respond($conn, $quotationId, $userId, 'revision_requested', $note);
            quotation_module_set_flash('success', 'Revision request submitted.');
        } elseif ($action === 'client_reject') {
            inquiry_quote_client_respond($conn, $quotationId, $userId, 'rejected', $note);
            quotation_module_set_flash('success', 'Quotation rejected.');
        } else {
            throw new RuntimeException('Invalid client action.');
        }

        quotation_module_redirect('/codesamplecaps/CLIENT/dashboards/quotations.php?source=inquiry&id=' . $quotationId);
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
    $query = $quotationId > 0 ? '?id=' . $quotationId : '';
    if ($source === 'inquiry' && $quotationId > 0) {
        $query = '?source=inquiry&id=' . $quotationId;
    }
    quotation_module_redirect('/codesamplecaps/CLIENT/dashboards/quotations.php' . $query);
}
