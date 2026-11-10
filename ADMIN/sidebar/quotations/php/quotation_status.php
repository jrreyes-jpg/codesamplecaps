<?php

require_once __DIR__ . '/../../../../config/auth_middleware.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/inquiry_quotation_module.php';

require_role('admin');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$quotationId = max(0, (int)($_GET['id'] ?? 0));
$quotation = $quotationId > 0 ? inquiry_quote_fetch_full($conn, $quotationId) : null;
if (!$quotation) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Quotation not found.']);
    exit();
}

$status = inquiry_quote_normalize_status((string)($quotation['status'] ?? 'draft'));
$labels = [
    'draft' => 'Draft',
    'approved' => 'Draft',
    'sent' => 'Awaiting Client',
    'revision_requested' => 'Revision Requested',
    'accepted' => 'Accepted',
    'rejected' => 'Rejected',
];
$classMap = [
    'accepted' => 'is-success',
    'sent' => 'is-active',
    'revision_requested' => 'is-warning',
    'rejected' => 'is-error',
];
$history = array_map(static function (array $entry) use ($labels): array {
    $entryStatus = inquiry_quote_normalize_status((string)($entry['to_status'] ?? 'draft'));
    $actor = trim((string)($entry['full_name'] ?? ''));
    if ($actor === '' && (string)($entry['actor_role'] ?? '') === 'prospect') {
        $actor = 'Client';
    }
    if ($actor === '') {
        $actor = 'System';
    }
    $time = strtotime((string)($entry['created_at'] ?? ''));

    return [
        'status' => $entryStatus,
        'label' => $labels[$entryStatus] ?? inquiry_quote_status_label($entryStatus),
        'actor' => $actor,
        'date' => $time ? date('M d, Y g:i A', $time) : 'Not recorded',
    ];
}, inquiry_quote_fetch_history($conn, $quotationId));

echo json_encode([
    'success' => true,
    'status' => $status,
    'label' => $labels[$status] ?? inquiry_quote_status_label($status),
    'status_class' => $classMap[$status] ?? 'is-neutral',
    'updated_at' => (string)($quotation['updated_at'] ?? ''),
    'client_note' => trim((string)($quotation['client_decision_note'] ?? '')),
    'history' => $history,
]);
