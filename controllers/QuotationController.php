<?php

require_once __DIR__ . '/../config/quotation_module.php';
require_once __DIR__ . '/../services/QuotationService.php';

require_role('engineer');

$userId = (int)current_user_id();
$role = (string)current_user_role();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    quotation_module_redirect('/codesamplecaps/ENGINEER/dashboards/quotations.php');
}

if (!quotation_module_is_valid_csrf($_POST['csrf_token'] ?? null)) {
    quotation_module_set_flash('error', 'Security check failed. Please try again.');
    quotation_module_redirect('/codesamplecaps/ENGINEER/dashboards/quotations.php');
}

if (!quotation_module_tables_ready($conn)) {
    $bootstrap = quotation_module_bootstrap_tables($conn);
    if (!($bootstrap['ready'] ?? false)) {
        $message = 'Quotation tables are not ready yet.';
        if (!empty($bootstrap['errors'])) {
            $message .= ' ' . implode(' | ', array_unique(array_map('strval', $bootstrap['errors'])));
        } else {
            $message .= ' Run scripts/setup_quotation_tables.php first.';
        }

        quotation_module_set_flash('error', $message);
        quotation_module_redirect('/codesamplecaps/ENGINEER/dashboards/quotations.php');
    }
}

$action = trim((string)($_POST['action'] ?? ''));
$service = new QuotationService();
$quotationId = (int)($_POST['quotation_id'] ?? 0);

try {
    if ($action === 'save_draft') {
        $payload = quotation_module_build_form_payload($conn, $userId, $_POST);
        $items = quotation_module_parse_items_from_post($_POST);

        if ($payload === null || empty($items)) {
            throw new RuntimeException('Project, title, and at least one quotation item are required.');
        }

        if (($payload['estimated_duration_days'] ?? null) === null || (int)$payload['estimated_duration_days'] <= 0) {
            throw new RuntimeException('Project duration is missing. Update the project timeline first.');
        }

        foreach ($items as $item) {
            $itemType = (string)($item['item_type'] ?? 'other');
            $itemName = trim((string)($item['item_name'] ?? ''));
            $rate = (float)($item['rate'] ?? 0);
            $quantity = (float)($item['quantity'] ?? 0);
            $hours = (float)($item['hours'] ?? 0);

            if ($itemName === '') {
                throw new RuntimeException('Every quotation row needs an item name.');
            }

            if ($rate <= 0) {
                throw new RuntimeException('Every quotation row needs a rate greater than zero.');
            }

            if ($itemType === 'manpower') {
                if ($hours <= 0) {
                    throw new RuntimeException('Manpower rows require hours greater than zero.');
                }
            } elseif ($quantity <= 0) {
                throw new RuntimeException('Material, asset, and other rows require quantity greater than zero.');
            }
        }

        if ($quotationId > 0) {
            $service->updateDraft($quotationId, $payload, $items, $userId, $role);
            quotation_module_set_flash('success', 'Quotation draft updated.');
            quotation_module_redirect('/codesamplecaps/ENGINEER/dashboards/quotation_form.php?id=' . $quotationId);
        }

        $newId = $service->createDraft($payload, $items, $userId, $role);
        quotation_module_set_flash('success', 'Quotation draft created.');
        quotation_module_redirect('/codesamplecaps/ENGINEER/dashboards/quotation_form.php?id=' . $newId);
    }

    if ($action === 'submit_review') {
        if ((int)($_POST['foreman_reviewer_id'] ?? 0) <= 0) {
            throw new RuntimeException('Please assign a foreman reviewer before submitting this quotation.');
        }

        $service->submitForReview($quotationId, $userId, $role, 'Engineer submitted quotation for foreman review.');
        quotation_module_set_flash('success', 'Quotation submitted to foreman review.');
        quotation_module_redirect('/codesamplecaps/ENGINEER/dashboards/quotation_form.php?id=' . $quotationId);
    }

    if ($action === 'submit_for_approval') {
        $service->submitForApproval($quotationId, $userId, $role, 'Engineer submitted quotation for super admin approval.');
        quotation_module_set_flash('success', 'Quotation submitted for super admin approval.');
        quotation_module_redirect('/codesamplecaps/ENGINEER/dashboards/quotation_form.php?id=' . $quotationId);
    }

    throw new RuntimeException('Invalid quotation action.');
} catch (Throwable $throwable) {
    quotation_module_set_flash('error', $throwable->getMessage());
    if ($quotationId > 0) {
        quotation_module_redirect('/codesamplecaps/ENGINEER/dashboards/quotation_form.php?id=' . $quotationId);
    }

    quotation_module_redirect('/codesamplecaps/ENGINEER/dashboards/quotations.php');
}
