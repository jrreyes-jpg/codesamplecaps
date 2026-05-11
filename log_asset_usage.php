<?php
require_once __DIR__ . '/config/auth_middleware.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/asset_unit_helpers.php';

require_any_role(['foreman']);

header('Content-Type: application/json; charset=utf-8');

function usage_table_exists(mysqli $conn, string $tableName): bool
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

function usage_column_exists(mysqli $conn, string $tableName, string $columnName): bool
{
    static $cache = [];
    $key = $tableName . '.' . $columnName;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $statement = $conn->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
         AND table_name = ?
         AND column_name = ?'
    );

    if (!$statement) {
        $cache[$key] = false;
        return false;
    }

    $statement->bind_param('ss', $tableName, $columnName);
    $statement->execute();
    $statement->bind_result($count);
    $statement->fetch();
    $statement->close();

    $cache[$key] = (int)$count > 0;
    return $cache[$key];
}

function usage_enum_allows_value(mysqli $conn, string $tableName, string $columnName, string $value): bool
{
    static $cache = [];
    $key = $tableName . '.' . $columnName;

    if (!array_key_exists($key, $cache)) {
        $statement = $conn->prepare(
            'SELECT COLUMN_TYPE
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
             AND table_name = ?
             AND column_name = ?
             LIMIT 1'
        );

        $columnType = '';

        if ($statement) {
            $statement->bind_param('ss', $tableName, $columnName);
            $statement->execute();
            $statement->bind_result($columnType);
            $statement->fetch();
            $statement->close();
        }

        preg_match_all("/'([^']+)'/", $columnType, $matches);
        $cache[$key] = $matches[1] ?? [];
    }

    return in_array($value, $cache[$key], true);
}

function ensure_usage_tables(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS asset_usage_logs (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            asset_id INT(11) NOT NULL,
            foreman_id INT(11) NOT NULL,
            worker_name VARCHAR(255) NOT NULL,
            notes TEXT DEFAULT NULL,
            used_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_asset (asset_id),
            KEY idx_foreman (foreman_id),
            KEY idx_used_at (used_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS asset_scan_history (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            asset_id INT(11) NOT NULL,
            foreman_id INT(11) NOT NULL,
            scan_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            scan_device VARCHAR(255) DEFAULT NULL,
            KEY idx_asset (asset_id),
            KEY idx_foreman (foreman_id),
            KEY idx_scan_time (scan_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    ensure_asset_unit_tracking_schema($conn);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

$rawPayload = file_get_contents('php://input');
$payload = json_decode($rawPayload, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload.']);
    exit;
}

$assetId = (int)($payload['asset_id'] ?? 0);
$assetUnitId = isset($payload['asset_unit_id']) ? (int)$payload['asset_unit_id'] : 0;
$unitCode = trim((string)($payload['unit_code'] ?? ''));
$workerName = trim((string)($payload['worker_name'] ?? ''));
$notes = trim((string)($payload['notes'] ?? ''));
$device = trim((string)($payload['device'] ?? ''));
$foremanId = (int)($_SESSION['user_id'] ?? 0);

if ($assetId <= 0 || $workerName === '' || $foremanId <= 0) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Asset and worker details are required.']);
    exit;
}

if (!usage_table_exists($conn, 'assets')) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Assets table is not available.']);
    exit;
}

ensure_usage_tables($conn);

$assetCheck = $conn->prepare('SELECT id FROM assets WHERE id = ? LIMIT 1');
if (!$assetCheck) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to validate asset.']);
    exit;
}

$assetCheck->bind_param('i', $assetId);
$assetCheck->execute();
$assetResult = $assetCheck->get_result();
$assetExists = $assetResult && $assetResult->num_rows === 1;
$assetCheck->close();

if (!$assetExists) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Asset not found.']);
    exit;
}

$assetUnit = null;
if ($assetUnitId > 0 || $unitCode !== '') {
    $assetUnit = asset_units_find_by_scan_context($conn, $assetId, $assetUnitId > 0 ? $assetUnitId : null, $unitCode !== '' ? $unitCode : null);

    if (!$assetUnit) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Asset unit not found for this QR code.']);
        exit;
    }

    $assetUnitId = (int)($assetUnit['asset_unit_id'] ?? 0);
}

$notesValue = $notes === '' ? null : $notes;
$deviceValue = $device === '' ? null : mb_substr($device, 0, 255);

$conn->begin_transaction();

try {
    $usageInsert = $conn->prepare(
        'INSERT INTO asset_usage_logs (asset_id, asset_unit_id, foreman_id, worker_name, notes)
         VALUES (?, ?, ?, ?, ?)'
    );

    if (
        !$usageInsert ||
        !$usageInsert->bind_param('iiiss', $assetId, $assetUnitId, $foremanId, $workerName, $notesValue) ||
        !$usageInsert->execute()
    ) {
        throw new RuntimeException('Failed to save usage log.');
    }

    $usageInsert->close();

    $scanInsert = $conn->prepare(
        'INSERT INTO asset_scan_history (asset_id, asset_unit_id, foreman_id, scan_device)
         VALUES (?, ?, ?, ?)'
    );

    if (
        !$scanInsert ||
        !$scanInsert->bind_param('iiis', $assetId, $assetUnitId, $foremanId, $deviceValue) ||
        !$scanInsert->execute()
    ) {
        throw new RuntimeException('Failed to save scan history.');
    }

    $scanInsert->close();

    if ($assetUnitId > 0) {
        $unitUpdate = $conn->prepare(
            'UPDATE asset_units
             SET last_scanned_at = NOW(),
                 last_scanned_by = ?
             WHERE id = ?'
        );

        if (
            !$unitUpdate ||
            !$unitUpdate->bind_param('ii', $foremanId, $assetUnitId) ||
            !$unitUpdate->execute()
        ) {
            throw new RuntimeException('Failed to update the scanned unit record.');
        }

        $unitUpdate->close();
    }

    if (
        usage_column_exists($conn, 'assets', 'asset_status') &&
        usage_enum_allows_value($conn, 'assets', 'asset_status', 'in_use')
    ) {
        $assetUpdate = $conn->prepare('UPDATE assets SET asset_status = ? WHERE id = ?');
        $inUse = 'in_use';

        if (
            !$assetUpdate ||
            !$assetUpdate->bind_param('si', $inUse, $assetId) ||
            !$assetUpdate->execute()
        ) {
            throw new RuntimeException('Failed to update asset status.');
        }

        $assetUpdate->close();
    }

    if (
        usage_column_exists($conn, 'assets', 'status') &&
        usage_enum_allows_value($conn, 'assets', 'status', 'in_use')
    ) {
        $statusUpdate = $conn->prepare('UPDATE assets SET status = ? WHERE id = ?');
        $status = 'in_use';

        if (
            !$statusUpdate ||
            !$statusUpdate->bind_param('si', $status, $assetId) ||
            !$statusUpdate->execute()
        ) {
            throw new RuntimeException('Failed to sync asset status.');
        }

        $statusUpdate->close();
    }

    $conn->commit();

    echo json_encode([
        'status' => 'success',
        'message' => 'Asset usage logged successfully.',
        'asset_unit_id' => $assetUnitId > 0 ? $assetUnitId : null,
        'unit_code' => $assetUnit['unit_code'] ?? null,
    ]);
} catch (Throwable $exception) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $exception->getMessage(),
    ]);
}
