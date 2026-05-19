<?php
require_once __DIR__ . '/config/auth_middleware.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/asset_unit_helpers.php';

require_any_role(['foreman']);

header('Content-Type: application/json; charset=utf-8');

function asset_table_exists(mysqli $conn, string $tableName): bool
{
    static $cache = [];

    if (array_key_exists($tableName, $cache)) {
        return $cache[$tableName];
    }

    $statement = $conn->prepare(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
         AND table_name = ?'
    );

    if (!$statement) {
        $cache[$tableName] = false;
        return false;
    }

    $statement->bind_param('s', $tableName);
    $statement->execute();
    $statement->bind_result($count);
    $statement->fetch();
    $statement->close();

    $cache[$tableName] = (int)$count > 0;
    return $cache[$tableName];
}

$assetId = isset($_GET['asset_id']) ? (int)$_GET['asset_id'] : 0;
$assetUnitId = isset($_GET['unit_id']) ? (int)$_GET['unit_id'] : 0;
$unitCode = trim((string)($_GET['unit_code'] ?? ''));
if ($assetId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing asset_id.']);
    exit;
}

if (!asset_table_exists($conn, 'assets')) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Assets table is not available.']);
    exit;
}

$assetStatement = $conn->prepare(
    'SELECT id, asset_name, asset_type, serial_number, asset_status
     FROM assets
     WHERE id = ?
     LIMIT 1'
);

if (!$assetStatement) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to prepare asset lookup.']);
    exit;
}

$assetStatement->bind_param('i', $assetId);
$assetStatement->execute();
$assetResult = $assetStatement->get_result();
$asset = $assetResult ? $assetResult->fetch_assoc() : null;
$assetStatement->close();

if (!$asset) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Asset not found.']);
    exit;
}

$assetUnit = null;
if ($assetUnitId > 0 || $unitCode !== '') {
    $assetUnit = asset_units_find_by_scan_context($conn, $assetId, $assetUnitId > 0 ? $assetUnitId : null, $unitCode !== '' ? $unitCode : null);

    if (!$assetUnit) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Asset unit not found.']);
        exit;
    }

    $asset['asset_unit_id'] = (int)($assetUnit['asset_unit_id'] ?? 0);
    $asset['unit_code'] = $assetUnit['unit_code'] ?? null;
    $asset['unit_status'] = $assetUnit['unit_status'] ?? null;
    $asset['unit_qr_code_value'] = $assetUnit['qr_code_value'] ?? null;
}

if (asset_table_exists($conn, 'asset_qr_codes')) {
    $qrStatement = $conn->prepare(
        'SELECT qr_code_value
         FROM asset_qr_codes
         WHERE asset_id = ?
         ORDER BY id DESC
         LIMIT 1'
    );

    if ($qrStatement) {
        $qrStatement->bind_param('i', $assetId);
        $qrStatement->execute();
        $qrResult = $qrStatement->get_result();
        $qrRow = $qrResult ? $qrResult->fetch_assoc() : null;
        $asset['qr_code_value'] = $qrRow['qr_code_value'] ?? null;
        $qrStatement->close();
    }
}

echo json_encode([
    'status' => 'success',
    'asset' => $asset,
]);
