<?php

require_once __DIR__ . '/../../../../config/auth_middleware.php';
require_once __DIR__ . '/../../../../config/material_detail_suggestion_service.php';

require_role('admin');
header('Content-Type: application/json; charset=utf-8');

function admin_material_suggestion_response(bool $success, array $data = []): void
{
    echo json_encode($success
        ? array_merge(['success' => true], $data)
        : ['success' => false, 'message' => 'Unable to suggest details. Please enter them manually.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'admin_material_master')) {
    http_response_code(403);
    admin_material_suggestion_response(false);
}

$materialName = material_detail_normalize_name((string)($_POST['material_name'] ?? ''));
$materialNameLength = function_exists('mb_strlen') ? mb_strlen($materialName) : strlen($materialName);
if (!material_detail_suggestion_valid_name($materialName) || $materialNameLength > 180) {
    http_response_code(422);
    admin_material_suggestion_response(false);
}

$lastRequestAt = (int)($_SESSION['admin_material_details_suggested_at'] ?? 0);
if ($lastRequestAt > 0 && time() - $lastRequestAt < 3) {
    http_response_code(429);
    admin_material_suggestion_response(false);
}
$_SESSION['admin_material_details_suggested_at'] = time();

$details = material_detail_suggest_from_ai($materialName);
if ($details === null) {
    admin_material_suggestion_response(false);
}

admin_material_suggestion_response(true, $details);
