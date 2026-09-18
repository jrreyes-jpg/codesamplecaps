<?php

require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/asset_master_service.php';
require_once __DIR__ . '/../../config/asset_category_suggestion_service.php';

require_role('inventory_clerk');
header('Content-Type: application/json; charset=utf-8');

function asset_category_suggestion_response(bool $success, ?string $category = null): void
{
    echo json_encode($success
        ? ['success' => true, 'category' => $category]
        : ['success' => false, 'message' => 'Unable to suggest a category. Please select manually.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'inventory_clerk_add_asset')) {
    http_response_code(403);
    asset_category_suggestion_response(false);
}

$assetName = asset_master_normalize_text((string)($_POST['asset_name'] ?? ''));
if ($assetName === '' || !preg_match('/\p{L}/u', $assetName) || asset_master_text_length($assetName) > 255) {
    http_response_code(422);
    asset_category_suggestion_response(false);
}

$lastRequestAt = (int)($_SESSION['inventory_asset_category_suggested_at'] ?? 0);
if ($lastRequestAt > 0 && time() - $lastRequestAt < 3) {
    http_response_code(429);
    asset_category_suggestion_response(false);
}
$_SESSION['inventory_asset_category_suggested_at'] = time();

$category = asset_category_suggest_from_web($assetName, asset_master_fetch_active_categories($conn));
if ($category === null) {
    asset_category_suggestion_response(false);
}

asset_category_suggestion_response(true, $category);
