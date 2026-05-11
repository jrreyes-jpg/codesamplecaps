<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/audit_log.php';
require_once __DIR__ . '/../../config/asset_unit_helpers.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions as QRCodeOptions;

require_role('super_admin');
$csrfToken = auth_csrf_token('super_admin');

$message = '';
$error = '';
$qrPreview = '';
$createdAssetId = 0;
$qrLibraryReady = false;

$qrAutoloadPath = __DIR__ . '/../../vendor/autoload.php';
$qrRequiredFiles = [
    __DIR__ . '/../../vendor/symfony/polyfill-ctype/bootstrap.php',
    __DIR__ . '/../../vendor/chillerlan/php-qrcode/src/QRCode.php',
];

function assets_get_redirect_target(): string {
    $redirectTo = $_POST['redirect_to'] ?? $_GET['redirect_to'] ?? '';
    $redirectTo = is_string($redirectTo) ? trim($redirectTo) : '';

    $allowedPrefixes = [
        '/codesamplecaps/SUPERADMIN/sidebar/assets.php',
        '/codesamplecaps/SUPERADMIN/sidebar/projects.php?view=trash',
        '/codesamplecaps/SUPERADMIN/sidebar/projects.php',
    ];

    foreach ($allowedPrefixes as $allowedPrefix) {
        if ($redirectTo !== '' && str_starts_with($redirectTo, $allowedPrefix)) {
            return $redirectTo;
        }
    }

    return '/codesamplecaps/SUPERADMIN/sidebar/assets.php';
}

function redirect_assets_page(): void {
    header('Location: ' . assets_get_redirect_target());
    exit();
}

if (is_file($qrAutoloadPath)) {
    $missingQrDependency = false;
    foreach ($qrRequiredFiles as $requiredFile) {
        if (!is_file($requiredFile)) {
            $missingQrDependency = true;
            break;
        }
    }

    if (!$missingQrDependency) {
        require_once $qrAutoloadPath;
        $qrLibraryReady = class_exists(QRCode::class) && class_exists(QRCodeOptions::class);
    }
}

function generateQRDataUri(string $value): string {
    global $qrLibraryReady;

    if (!$qrLibraryReady) {
        return '';
    }

    $options = [
        'version' => 5,
        'outputType' => 'png',
        'eccLevel' => 'L',
        'scale' => 6,
        'imageBase64' => false,
    ];

    return (new QRCode($options))->render($value);
}

function buildAssetSerialNumber(int $assetId, string $assetType = ''): string {
    $cleanType = preg_replace('/[^A-Za-z0-9]+/', '', strtoupper($assetType));
    $typePrefix = $cleanType !== '' ? substr($cleanType, 0, 3) : 'AST';

    return sprintf('%s-%s-%04d', $typePrefix, date('Y'), $assetId);
}

function buildAssetQrValue(int $assetId, string $serialNumber = ''): string {
    $parts = ['asset_id=' . $assetId];

    if ($serialNumber !== '') {
        $parts[] = 'sn=' . $serialNumber;
    }

    return implode('|', $parts);
}

function assets_table_has_column(mysqli $conn, string $columnName): bool {
    $stmt = $conn->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
         AND table_name = ?
         AND column_name = ?'
    );

    if (!$stmt) {
        return false;
    }

    $tableName = 'assets';
    $stmt->bind_param('ss', $tableName, $columnName);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    return (int)$count > 0;
}

function database_table_has_column(mysqli $conn, string $tableName, string $columnName): bool {
    $stmt = $conn->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
         AND table_name = ?
         AND column_name = ?'
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $tableName, $columnName);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    return (int)$count > 0;
}

function assets_get_column_type(mysqli $conn, string $columnName): ?string {
    $stmt = $conn->prepare(
        'SELECT COLUMN_TYPE
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
         AND table_name = ?
         AND column_name = ?
         LIMIT 1'
    );

    if (!$stmt) {
        return null;
    }

    $tableName = 'assets';
    $stmt->bind_param('ss', $tableName, $columnName);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row['COLUMN_TYPE'] ?? null;
}

function ensure_assets_classification_columns(mysqli $conn): void {
    if (!assets_table_has_column($conn, 'asset_category')) {
        $conn->query("ALTER TABLE assets ADD COLUMN asset_category VARCHAR(80) DEFAULT NULL AFTER asset_name");
    }

    if (!assets_table_has_column($conn, 'criticality')) {
        $conn->query("ALTER TABLE assets ADD COLUMN criticality VARCHAR(30) DEFAULT NULL AFTER asset_status");
    }

    if (!assets_table_has_column($conn, 'deleted_at')) {
        $conn->query("ALTER TABLE assets ADD COLUMN deleted_at DATETIME DEFAULT NULL AFTER criticality");
    }
}

function ensure_assets_status_values(mysqli $conn): void {
    $columnType = assets_get_column_type($conn, 'asset_status');
    if ($columnType === null || !str_starts_with($columnType, 'enum(')) {
        return;
    }

    preg_match_all("/'([^']+)'/", $columnType, $matches);
    $currentValues = $matches[1] ?? [];
    $requiredValues = ['available', 'in_use', 'maintenance', 'lost'];
    $nextValues = array_values(array_unique(array_merge($currentValues, $requiredValues)));

    if ($currentValues === $nextValues) {
        return;
    }

    $enumSql = "'" . implode("','", array_map(static fn(string $value): string => $conn->real_escape_string($value), $nextValues)) . "'";
    $conn->query("ALTER TABLE assets MODIFY COLUMN asset_status ENUM($enumSql) NOT NULL DEFAULT 'available'");
}

function asset_category_seed_defaults(): array {
    return [
        'tool' => ['label' => 'Tool', 'min_stock' => 1, 'max_stock' => 6, 'description' => 'Reusable hand tools and power tools.', 'sort_order' => 10],
        'equipment' => ['label' => 'Equipment', 'min_stock' => 1, 'max_stock' => 3, 'description' => 'Heavy or specialized operational equipment.', 'sort_order' => 20],
        'it_device' => ['label' => 'IT Device', 'min_stock' => 1, 'max_stock' => 4, 'description' => 'Laptops, tablets, routers, cameras, and other IT hardware.', 'sort_order' => 30],
        'vehicle' => ['label' => 'Vehicle', 'min_stock' => 0, 'max_stock' => 2, 'description' => 'Service vehicles and transport assets.', 'sort_order' => 40],
        'ppe' => ['label' => 'PPE', 'min_stock' => 10, 'max_stock' => 120, 'description' => 'Safety gear issued to workers.', 'sort_order' => 50],
        'consumable' => ['label' => 'Consumable', 'min_stock' => 20, 'max_stock' => 250, 'description' => 'Items that are used up and not returned.', 'sort_order' => 60],
        'electrical_material' => ['label' => 'Electrical Material', 'min_stock' => 10, 'max_stock' => 150, 'description' => 'Wires, breakers, relays, conduits, and similar materials.', 'sort_order' => 70],
        'mechanical_material' => ['label' => 'Mechanical Material', 'min_stock' => 10, 'max_stock' => 150, 'description' => 'Pipes, fittings, valves, and other mechanical materials.', 'sort_order' => 80],
        'spare_part' => ['label' => 'Spare Part', 'min_stock' => 2, 'max_stock' => 18, 'description' => 'Replacement parts for repair and maintenance.', 'sort_order' => 90],
        'office_supply' => ['label' => 'Office Supply', 'min_stock' => 5, 'max_stock' => 40, 'description' => 'Administrative and office-use supplies.', 'sort_order' => 100],
        'measuring_device' => ['label' => 'Measuring Device', 'min_stock' => 1, 'max_stock' => 4, 'description' => 'Meters, testers, and calibration-sensitive devices.', 'sort_order' => 110],
    ];
}

function ensure_asset_category_defaults_table(mysqli $conn): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS asset_category_defaults (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            category_key VARCHAR(80) NOT NULL UNIQUE,
            category_label VARCHAR(120) NOT NULL,
            recommended_min_stock INT(11) NOT NULL DEFAULT 0,
            recommended_max_stock INT(11) DEFAULT NULL,
            description TEXT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT(11) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_asset_category_defaults_active_sort (is_active, sort_order, category_label)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

function ensure_asset_inventory_threshold_columns(mysqli $conn): void {
    if (!database_table_has_column($conn, 'inventory', 'max_stock')) {
        $conn->query('ALTER TABLE inventory ADD COLUMN max_stock INT(11) DEFAULT NULL AFTER min_stock');
    }

    if (!database_table_has_column($conn, 'asset_category_defaults', 'recommended_max_stock')) {
        $conn->query('ALTER TABLE asset_category_defaults ADD COLUMN recommended_max_stock INT(11) DEFAULT NULL AFTER recommended_min_stock');
    }
}

function seed_asset_category_defaults(mysqli $conn): void {
    $seedDefaults = asset_category_seed_defaults();
    $statement = $conn->prepare(
        "INSERT INTO asset_category_defaults (
            category_key,
            category_label,
            recommended_min_stock,
            recommended_max_stock,
            description,
            is_active,
            sort_order
        ) VALUES (?, ?, ?, ?, ?, 1, ?)
        ON DUPLICATE KEY UPDATE
            category_label = VALUES(category_label),
            recommended_min_stock = VALUES(recommended_min_stock),
            recommended_max_stock = COALESCE(asset_category_defaults.recommended_max_stock, VALUES(recommended_max_stock)),
            description = COALESCE(asset_category_defaults.description, VALUES(description)),
            sort_order = VALUES(sort_order)"
    );

    if (!$statement) {
        return;
    }

    foreach ($seedDefaults as $categoryKey => $meta) {
        $categoryLabel = (string)($meta['label'] ?? $categoryKey);
        $recommendedMinStock = (int)($meta['min_stock'] ?? 0);
        $recommendedMaxStock = isset($meta['max_stock']) ? (int)$meta['max_stock'] : null;
        $description = (string)($meta['description'] ?? '');
        $sortOrder = (int)($meta['sort_order'] ?? 0);
        $statement->bind_param('ssiisi', $categoryKey, $categoryLabel, $recommendedMinStock, $recommendedMaxStock, $description, $sortOrder);
        $statement->execute();
    }
}

function fetch_asset_category_defaults(mysqli $conn): array {
    $rows = [];
    $result = $conn->query(
        "SELECT
            id,
            category_key,
            category_label,
            recommended_min_stock,
            recommended_max_stock,
            description,
            is_active,
            sort_order
         FROM asset_category_defaults
         ORDER BY is_active DESC, sort_order ASC, category_label ASC"
    );

    if ($result) {
        $rows = $result->fetch_all(MYSQLI_ASSOC);
    }

    return $rows;
}

function asset_category_defaults_index(array $rows): array {
    $indexed = [];

    foreach ($rows as $row) {
        $categoryKey = trim((string)($row['category_key'] ?? ''));
        if ($categoryKey === '') {
            continue;
        }

        $indexed[$categoryKey] = [
            'id' => (int)($row['id'] ?? 0),
            'label' => (string)($row['category_label'] ?? $categoryKey),
            'min_stock' => (int)($row['recommended_min_stock'] ?? 0),
            'max_stock' => isset($row['recommended_max_stock']) ? (int)$row['recommended_max_stock'] : null,
            'description' => (string)($row['description'] ?? ''),
            'is_active' => (int)($row['is_active'] ?? 1) === 1,
            'sort_order' => (int)($row['sort_order'] ?? 0),
        ];
    }

    return $indexed;
}

function active_asset_category_defaults(array $rows): array {
    return array_values(array_filter($rows, static fn(array $row): bool => (int)($row['is_active'] ?? 1) === 1));
}

function criticality_options(): array {
    return [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
    ];
}

function asset_category_label(?string $category): string {
    $category = trim((string)$category);
    global $assetCategoryDefaults;
    $defaults = $assetCategoryDefaults;

    if ($category !== '' && isset($defaults[$category]['label'])) {
        return (string)$defaults[$category]['label'];
    }

    return $category !== '' ? ucwords(str_replace('_', ' ', $category)) : 'Uncategorized';
}

function asset_criticality_label(?string $criticality): string {
    $criticality = trim((string)$criticality);
    $options = criticality_options();

    if ($criticality !== '' && isset($options[$criticality])) {
        return $options[$criticality];
    }

    return $criticality !== '' ? ucfirst($criticality) : 'Medium';
}

function determine_asset_inventory_status(int $quantity, ?int $minStock): string {
    if ($quantity <= 0) {
        return 'out-of-stock';
    }

    if ($minStock !== null && $quantity <= $minStock) {
        return 'low-stock';
    }

    return 'available';
}

function suggest_asset_max_stock(?string $categoryKey, int $quantity, ?int $minStock, array $categoryDefaults = []): int
{
    $quantity = max(0, $quantity);
    $minStock = $minStock !== null ? max(0, $minStock) : null;

    if ($categoryKey !== null && $categoryKey !== '' && isset($categoryDefaults[$categoryKey]['max_stock'])) {
        $recommended = (int)$categoryDefaults[$categoryKey]['max_stock'];
        if ($recommended > 0) {
            return max($recommended, $quantity, (int)($minStock ?? 0));
        }
    }

    $baseline = max($quantity, (int)($minStock ?? 0));
    $buffer = max(2, (int)ceil($baseline * 0.5));
    $derived = $baseline + $buffer;

    if ($minStock !== null) {
        $derived = max($derived, $minStock * 2);
    }

    return max(1, $derived);
}

function build_asset_stock_capacity_label(?int $quantity, ?int $minStock, ?int $maxStock): string
{
    $quantityLabel = $quantity !== null ? (string)$quantity : 'N/A';
    $minLabel = $minStock !== null ? (string)$minStock : 'N/A';
    $maxLabel = $maxStock !== null ? (string)$maxStock : 'Auto';

    return $quantityLabel . ' / ' . $minLabel . ' / ' . $maxLabel;
}

function build_asset_stock_capacity_note(?int $quantity, ?int $maxStock): ?string
{
    if ($quantity === null || $maxStock === null || $maxStock <= 0) {
        return null;
    }

    if ($quantity > $maxStock) {
        return 'Above ceiling by ' . ($quantity - $maxStock);
    }

    if ($quantity === $maxStock) {
        return 'At stock ceiling';
    }

    return null;
}

function build_asset_stock_badge_label(?string $status): string {
    $normalized = trim((string)$status);
    if ($normalized === '') {
        return 'No inventory';
    }

    if ($normalized === 'out-of-stock') {
        return 'Out of Stock';
    }

    if ($normalized === 'low-stock') {
        return 'Low Stock';
    }

    if ($normalized === 'available') {
        return 'Available';
    }

    return ucwords(str_replace('-', ' ', $normalized));
}

function getAssetInventorySnapshot(mysqli $conn, int $assetId): ?array {
    $stmt = $conn->prepare(
        'SELECT id, asset_id, quantity, min_stock, max_stock, status
         FROM inventory
         WHERE asset_id = ?
         LIMIT 1'
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $assetId);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result ? $result->fetch_assoc() : null;
}

function syncAssetInventoryFromUnitStatuses(mysqli $conn, int $assetId): array {
    $inventory = getAssetInventorySnapshot($conn, $assetId);
    if (!$inventory) {
        return [
            'inventory_id' => 0,
            'available' => 0,
            'deployed' => 0,
            'maintenance' => 0,
            'lost' => 0,
            'inventory_status' => 'out-of-stock',
            'asset_status' => 'available',
        ];
    }

    $inventoryId = (int)($inventory['id'] ?? 0);
    $counts = asset_units_fetch_status_counts($conn, $inventoryId);
    $availableQuantity = (int)($counts['available'] ?? 0);
    $deployedQuantity = (int)($counts['deployed'] ?? 0);
    $maintenanceQuantity = (int)($counts['maintenance'] ?? 0);
    $lostQuantity = (int)($counts['lost'] ?? 0);
    $minStock = isset($inventory['min_stock']) ? (int)$inventory['min_stock'] : null;
    $inventoryStatus = determine_asset_inventory_status($availableQuantity, $minStock);

    $inventoryStmt = $conn->prepare(
        'UPDATE inventory
         SET quantity = ?, status = ?
         WHERE id = ?'
    );
    if ($inventoryStmt) {
        $inventoryStmt->bind_param('isi', $availableQuantity, $inventoryStatus, $inventoryId);
        $inventoryStmt->execute();
        $inventoryStmt->close();
    }

    $assetStatus = 'available';
    if ($deployedQuantity > 0) {
        $assetStatus = 'in_use';
    } elseif ($maintenanceQuantity > 0) {
        $assetStatus = 'maintenance';
    } elseif ($availableQuantity <= 0 && $lostQuantity > 0) {
        $assetStatus = 'lost';
    }

    $assetStmt = $conn->prepare('UPDATE assets SET asset_status = ? WHERE id = ?');
    if ($assetStmt) {
        $assetStmt->bind_param('si', $assetStatus, $assetId);
        $assetStmt->execute();
        $assetStmt->close();
    }

    return [
        'inventory_id' => $inventoryId,
        'available' => $availableQuantity,
        'deployed' => $deployedQuantity,
        'maintenance' => $maintenanceQuantity,
        'lost' => $lostQuantity,
        'inventory_status' => $inventoryStatus,
        'asset_status' => $assetStatus,
    ];
}

ensure_asset_unit_tracking_schema($conn);
ensure_assets_classification_columns($conn);
ensure_assets_status_values($conn);
ensure_asset_category_defaults_table($conn);
ensure_asset_inventory_threshold_columns($conn);
seed_asset_category_defaults($conn);
$assetCategoryDefaultRows = fetch_asset_category_defaults($conn);
$assetCategoryDefaults = asset_category_defaults_index($assetCategoryDefaultRows);
$activeAssetCategoryDefaultRows = active_asset_category_defaults($assetCategoryDefaultRows);
$criticalityChoices = criticality_options();

function syncAssetIdentity(mysqli $conn, array $asset): array {
    $assetId = (int)($asset['id'] ?? 0);
    if ($assetId <= 0) {
        return $asset;
    }

    $assetType = trim((string)($asset['asset_type'] ?? ''));
    $serialNumber = trim((string)($asset['serial_number'] ?? ''));

    if ($serialNumber === '') {
        $serialNumber = buildAssetSerialNumber($assetId, $assetType);
        $serialStmt = $conn->prepare('UPDATE assets SET serial_number = ? WHERE id = ?');
        if ($serialStmt) {
            $serialStmt->bind_param('si', $serialNumber, $assetId);
            $serialStmt->execute();
            $serialStmt->close();
        }
        $asset['serial_number'] = $serialNumber;
    }

    $qrValue = trim((string)($asset['qr_code_value'] ?? ''));
    if ($qrValue === '') {
        $qrValue = buildAssetQrValue($assetId, $serialNumber);
        $qrStmt = $conn->prepare('INSERT INTO asset_qr_codes (asset_id, qr_code_value) VALUES (?, ?)');
        if ($qrStmt) {
            $qrStmt->bind_param('is', $assetId, $qrValue);
            $qrStmt->execute();
            $qrStmt->close();
        }
        $asset['qr_code_value'] = $qrValue;
    }

    return $asset;
}

function getAssetSnapshot(mysqli $conn, int $assetId): ?array {
    $stmt = $conn->prepare(
        'SELECT a.id, a.asset_name, a.asset_category, a.asset_type, a.serial_number, a.asset_status, a.criticality, a.deleted_at, q.id AS qr_id
         FROM assets a
         LEFT JOIN asset_qr_codes q ON q.asset_id = a.id
         WHERE a.id = ?
         LIMIT 1'
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $assetId);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result ? $result->fetch_assoc() : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'super_admin')) {
        $_SESSION['assets_error'] = 'Security check failed. Please try again.';
        redirect_assets_page();
    }

    if ($action === 'save_asset_category_default') {
        $categoryDefaultId = (int)($_POST['category_default_id'] ?? 0);
        $categoryLabel = trim((string)($_POST['category_label'] ?? ''));
        $recommendedMinStock = max(0, (int)($_POST['recommended_min_stock'] ?? 0));
        $recommendedMaxStockRaw = trim((string)($_POST['recommended_max_stock'] ?? ''));
        $recommendedMaxStock = $recommendedMaxStockRaw === '' ? null : max(0, (int)$recommendedMaxStockRaw);
        $description = trim((string)($_POST['description'] ?? ''));
        $sortOrder = max(0, (int)($_POST['sort_order'] ?? 0));
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($categoryDefaultId <= 0 || $categoryLabel === '') {
            $_SESSION['assets_error'] = 'Category label is required.';
            redirect_assets_page();
        }

        if ($recommendedMaxStock !== null && $recommendedMaxStock < $recommendedMinStock) {
            $_SESSION['assets_error'] = 'Recommended max stock must be greater than or equal to the recommended min stock.';
            redirect_assets_page();
        }

        $updateDefault = $conn->prepare(
            'UPDATE asset_category_defaults
             SET category_label = ?, recommended_min_stock = ?, recommended_max_stock = ?, description = ?, is_active = ?, sort_order = ?
             WHERE id = ?'
        );

        if (
            $updateDefault &&
            $updateDefault->bind_param('siisiii', $categoryLabel, $recommendedMinStock, $recommendedMaxStock, $description, $isActive, $sortOrder, $categoryDefaultId) &&
            $updateDefault->execute()
        ) {
            $_SESSION['assets_message'] = 'Asset category defaults updated.';
        } else {
            $_SESSION['assets_error'] = 'Failed to update asset category defaults.';
        }

        redirect_assets_page();
    }

    if ($action === 'create_asset') {
        $assetName = trim($_POST['asset_name'] ?? '');
        $assetCategory = trim((string)($_POST['asset_category'] ?? ''));
        $assetType = trim($_POST['asset_type'] ?? '');
        $criticality = trim((string)($_POST['criticality'] ?? 'medium'));
        $quantity = max(0, (int)($_POST['quantity'] ?? 0));
        $minStockRaw = trim((string)($_POST['min_stock'] ?? ''));
        $minStock = $minStockRaw === '' ? null : max(0, (int)$minStockRaw);
        $maxStockRaw = trim((string)($_POST['max_stock'] ?? ''));
        $maxStock = $maxStockRaw === '' ? null : max(0, (int)$maxStockRaw);

        if (!isset($assetCategoryDefaults[$assetCategory]) || empty($assetCategoryDefaults[$assetCategory]['is_active'])) {
            $assetCategory = 'tool';
        }

        if (!isset($criticalityChoices[$criticality])) {
            $criticality = 'medium';
        }

        if ($minStock === null && isset($assetCategoryDefaults[$assetCategory]['min_stock'])) {
            $minStock = (int)$assetCategoryDefaults[$assetCategory]['min_stock'];
        }

        if ($maxStock === null) {
            $maxStock = suggest_asset_max_stock($assetCategory, $quantity, $minStock, $assetCategoryDefaults);
        }

        if ($assetName === '') {
            $_SESSION['assets_error'] = 'Asset name is required.';
        } elseif ($quantity <= 0) {
            $_SESSION['assets_error'] = 'Quantity must be at least 1 when creating an asset.';
        } elseif ($maxStock !== null && $minStock !== null && $maxStock < $minStock) {
            $_SESSION['assets_error'] = 'Max stock must be greater than or equal to min stock.';
        } elseif ($maxStock !== null && $maxStock < $quantity) {
            $_SESSION['assets_error'] = 'Max stock cannot be lower than the starting quantity.';
        } else {
            $emptySerial = '';
            $inventoryStatus = determine_asset_inventory_status($quantity, $minStock);

            try {
                $conn->begin_transaction();

                $stmt = $conn->prepare('INSERT INTO assets (asset_name, asset_category, asset_type, serial_number, criticality) VALUES (?, ?, ?, ?, ?)');
                if (
                    !$stmt ||
                    !$stmt->bind_param('sssss', $assetName, $assetCategory, $assetType, $emptySerial, $criticality) ||
                    !$stmt->execute()
                ) {
                    throw new RuntimeException('Failed to create asset.');
                }

                $assetId = (int)$stmt->insert_id;
                $serial = buildAssetSerialNumber($assetId, $assetType);
                $qrValue = buildAssetQrValue($assetId, $serial);

                $serialStmt = $conn->prepare('UPDATE assets SET serial_number = ? WHERE id = ?');
                if (
                    !$serialStmt ||
                    !$serialStmt->bind_param('si', $serial, $assetId) ||
                    !$serialStmt->execute()
                ) {
                    throw new RuntimeException('Failed to save the asset serial number.');
                }

                $qrStmt = $conn->prepare('INSERT INTO asset_qr_codes (asset_id, qr_code_value) VALUES (?, ?)');
                if (
                    !$qrStmt ||
                    !$qrStmt->bind_param('is', $assetId, $qrValue) ||
                    !$qrStmt->execute()
                ) {
                    throw new RuntimeException('Failed to save the asset QR code.');
                }

                $inventoryStmt = $conn->prepare(
                    'INSERT INTO inventory (asset_id, quantity, min_stock, max_stock, status)
                     VALUES (?, ?, ?, ?, ?)'
                );
                if (
                    !$inventoryStmt ||
                    !$inventoryStmt->bind_param('iiiis', $assetId, $quantity, $minStock, $maxStock, $inventoryStatus) ||
                    !$inventoryStmt->execute()
                ) {
                    throw new RuntimeException('Failed to create the inventory record.');
                }

                $inventoryId = (int)$inventoryStmt->insert_id;
                asset_units_sync_for_inventory($conn, $inventoryId, $quantity);

                audit_log_event(
                    $conn,
                    (int)($_SESSION['user_id'] ?? 0),
                    'create_asset',
                    'asset',
                    $assetId,
                    null,
                    [
                        'asset_name' => $assetName,
                        'asset_category' => $assetCategory,
                        'asset_type' => $assetType,
                        'serial_number' => $serial,
                        'criticality' => $criticality,
                        'quantity' => $quantity,
                        'min_stock' => $minStock,
                        'max_stock' => $maxStock,
                        'inventory_status' => $inventoryStatus,
                    ]
                );

                audit_log_event(
                    $conn,
                    (int)($_SESSION['user_id'] ?? 0),
                    'create_inventory_item',
                    'inventory',
                    $inventoryId,
                    null,
                    [
                        'asset_id' => $assetId,
                        'asset_name' => $assetName,
                        'quantity' => $quantity,
                        'min_stock' => $minStock,
                        'max_stock' => $maxStock,
                        'status' => $inventoryStatus,
                    ]
                );

                $conn->commit();

                $_SESSION['assets_message'] = 'Asset created with inventory and QR generated.';
                $_SESSION['assets_qr_preview'] = $qrValue;
                $_SESSION['assets_created_asset_id'] = $assetId;
            } catch (Throwable $exception) {
                $conn->rollback();
                $_SESSION['assets_error'] = $exception->getMessage();
            }
        }

        redirect_assets_page();
    }

    if ($action === 'return_asset') {
        $assetId = (int)($_POST['asset_id'] ?? 0);
        $requestedQuantity = max(1, (int)($_POST['status_quantity'] ?? 1));

        if ($assetId > 0) {
            $beforeReturn = getAssetSnapshot($conn, $assetId);
            $inventory = getAssetInventorySnapshot($conn, $assetId);

            if (!$inventory) {
                $_SESSION['assets_error'] = 'Inventory record was not found for this asset.';
                redirect_assets_page();
            }

            $deployedQuantity = asset_units_fetch_status_counts($conn, (int)$inventory['id'])['deployed'] ?? 0;
            $effectiveQuantity = min($requestedQuantity, max(1, (int)$deployedQuantity));

            try {
                $conn->begin_transaction();
                $updatedUnitCodes = asset_units_restore_units_to_available($conn, (int)$inventory['id'], $effectiveQuantity, 'deployed');
                $state = syncAssetInventoryFromUnitStatuses($conn, $assetId);

                audit_log_event(
                    $conn,
                    (int)($_SESSION['user_id'] ?? 0),
                    'return_asset',
                    'asset',
                    $assetId,
                    $beforeReturn ? ['asset_status' => $beforeReturn['asset_status'] ?? null] : null,
                    [
                        'asset_name' => $beforeReturn['asset_name'] ?? null,
                        'asset_status' => $state['asset_status'],
                        'quantity_returned' => count($updatedUnitCodes),
                        'unit_codes' => $updatedUnitCodes,
                        'available_units' => $state['available'],
                        'deployed_units' => $state['deployed'],
                    ]
                );

                $conn->commit();
                $_SESSION['assets_message'] = count($updatedUnitCodes) === 1
                    ? '1 deployed asset unit returned to available.'
                    : count($updatedUnitCodes) . ' deployed asset units returned to available.';
            } catch (Throwable $exception) {
                $conn->rollback();
                $_SESSION['assets_error'] = $exception->getMessage();
            }
        }

        redirect_assets_page();
    }

    if ($action === 'resolve_asset_maintenance') {
        $assetId = (int)($_POST['asset_id'] ?? 0);
        $requestedQuantity = max(1, (int)($_POST['status_quantity'] ?? 1));

        if ($assetId > 0) {
            $beforeStatus = getAssetSnapshot($conn, $assetId);
            $inventory = getAssetInventorySnapshot($conn, $assetId);

            if (!$inventory) {
                $_SESSION['assets_error'] = 'Inventory record was not found for this asset.';
                redirect_assets_page();
            }

            $maintenanceQuantity = asset_units_fetch_status_counts($conn, (int)$inventory['id'])['maintenance'] ?? 0;
            $effectiveQuantity = min($requestedQuantity, max(1, (int)$maintenanceQuantity));

            try {
                $conn->begin_transaction();
                $updatedUnitCodes = asset_units_restore_units_to_available($conn, (int)$inventory['id'], $effectiveQuantity, 'maintenance');
                $state = syncAssetInventoryFromUnitStatuses($conn, $assetId);

                audit_log_event(
                    $conn,
                    (int)($_SESSION['user_id'] ?? 0),
                    'resolve_asset_maintenance',
                    'asset',
                    $assetId,
                    $beforeStatus ? ['asset_status' => $beforeStatus['asset_status'] ?? null] : null,
                    [
                        'asset_name' => $beforeStatus['asset_name'] ?? null,
                        'asset_status' => $state['asset_status'],
                        'quantity_restored' => count($updatedUnitCodes),
                        'unit_codes' => $updatedUnitCodes,
                        'available_units' => $state['available'],
                        'maintenance_units' => $state['maintenance'],
                    ]
                );

                $conn->commit();
                $_SESSION['assets_message'] = count($updatedUnitCodes) === 1
                    ? '1 maintenance asset unit restored to available.'
                    : count($updatedUnitCodes) . ' maintenance asset units restored to available.';
            } catch (Throwable $exception) {
                $conn->rollback();
                $_SESSION['assets_error'] = $exception->getMessage();
            }
        }

        redirect_assets_page();
    }

    if ($action === 'recover_asset_lost') {
        $assetId = (int)($_POST['asset_id'] ?? 0);
        $requestedQuantity = max(1, (int)($_POST['status_quantity'] ?? 1));

        if ($assetId > 0) {
            $beforeStatus = getAssetSnapshot($conn, $assetId);
            $inventory = getAssetInventorySnapshot($conn, $assetId);

            if (!$inventory) {
                $_SESSION['assets_error'] = 'Inventory record was not found for this asset.';
                redirect_assets_page();
            }

            $lostQuantity = asset_units_fetch_status_counts($conn, (int)$inventory['id'])['lost'] ?? 0;
            $effectiveQuantity = min($requestedQuantity, max(1, (int)$lostQuantity));

            try {
                $conn->begin_transaction();
                $updatedUnitCodes = asset_units_restore_units_to_available($conn, (int)$inventory['id'], $effectiveQuantity, 'lost');
                $state = syncAssetInventoryFromUnitStatuses($conn, $assetId);

                audit_log_event(
                    $conn,
                    (int)($_SESSION['user_id'] ?? 0),
                    'recover_asset_lost',
                    'asset',
                    $assetId,
                    $beforeStatus ? ['asset_status' => $beforeStatus['asset_status'] ?? null] : null,
                    [
                        'asset_name' => $beforeStatus['asset_name'] ?? null,
                        'asset_status' => $state['asset_status'],
                        'quantity_restored' => count($updatedUnitCodes),
                        'unit_codes' => $updatedUnitCodes,
                        'available_units' => $state['available'],
                        'lost_units' => $state['lost'],
                    ]
                );

                $conn->commit();
                $_SESSION['assets_message'] = count($updatedUnitCodes) === 1
                    ? '1 lost asset unit recovered and restored to available.'
                    : count($updatedUnitCodes) . ' lost asset units recovered and restored to available.';
            } catch (Throwable $exception) {
                $conn->rollback();
                $_SESSION['assets_error'] = $exception->getMessage();
            }
        }

        redirect_assets_page();
    }

    if ($action === 'mark_asset_maintenance') {
        $assetId = (int)($_POST['asset_id'] ?? 0);
        $requestedQuantity = max(1, (int)($_POST['status_quantity'] ?? 1));

        if ($assetId > 0) {
            $beforeStatus = getAssetSnapshot($conn, $assetId);
            $inventory = getAssetInventorySnapshot($conn, $assetId);

            if (!$inventory) {
                $_SESSION['assets_error'] = 'Inventory record was not found for this asset.';
                redirect_assets_page();
            }

            $availableQuantity = (int)($inventory['quantity'] ?? 0);
            $effectiveQuantity = min($requestedQuantity, max(1, $availableQuantity));

            try {
                $conn->begin_transaction();
                $updatedUnitCodes = asset_units_mark_available_units($conn, (int)$inventory['id'], $effectiveQuantity, 'maintenance');
                $state = syncAssetInventoryFromUnitStatuses($conn, $assetId);

                audit_log_event(
                    $conn,
                    (int)($_SESSION['user_id'] ?? 0),
                    'mark_asset_maintenance',
                    'asset',
                    $assetId,
                    $beforeStatus ? ['asset_status' => $beforeStatus['asset_status'] ?? null] : null,
                    [
                        'asset_name' => $beforeStatus['asset_name'] ?? null,
                        'asset_status' => $state['asset_status'],
                        'quantity_marked' => count($updatedUnitCodes),
                        'unit_codes' => $updatedUnitCodes,
                        'remaining_available' => $state['available'],
                        'maintenance_units' => $state['maintenance'],
                    ]
                );

                $conn->commit();
                $_SESSION['assets_message'] = count($updatedUnitCodes) === 1
                    ? '1 asset unit marked for maintenance.'
                    : count($updatedUnitCodes) . ' asset units marked for maintenance.';
            } catch (Throwable $exception) {
                $conn->rollback();
                $_SESSION['assets_error'] = $exception->getMessage();
            }
        }

        redirect_assets_page();
    }

    if ($action === 'mark_asset_lost') {
        $assetId = (int)($_POST['asset_id'] ?? 0);
        $requestedQuantity = max(1, (int)($_POST['status_quantity'] ?? 1));

        if ($assetId > 0) {
            $beforeStatus = getAssetSnapshot($conn, $assetId);
            $inventory = getAssetInventorySnapshot($conn, $assetId);

            if (!$inventory) {
                $_SESSION['assets_error'] = 'Inventory record was not found for this asset.';
                redirect_assets_page();
            }

            $availableQuantity = (int)($inventory['quantity'] ?? 0);
            $effectiveQuantity = min($requestedQuantity, max(1, $availableQuantity));

            try {
                $conn->begin_transaction();
                $updatedUnitCodes = asset_units_mark_available_units($conn, (int)$inventory['id'], $effectiveQuantity, 'lost');
                $state = syncAssetInventoryFromUnitStatuses($conn, $assetId);

                audit_log_event(
                    $conn,
                    (int)($_SESSION['user_id'] ?? 0),
                    'mark_asset_lost',
                    'asset',
                    $assetId,
                    $beforeStatus ? [
                        'asset_status' => $beforeStatus['asset_status'] ?? null,
                    ] : null,
                    [
                        'asset_name' => $beforeStatus['asset_name'] ?? null,
                        'asset_status' => $state['asset_status'],
                        'quantity_marked' => count($updatedUnitCodes),
                        'unit_codes' => $updatedUnitCodes,
                        'remaining_available' => $state['available'],
                        'lost_units' => $state['lost'],
                    ]
                );

                $conn->commit();
                $_SESSION['assets_message'] = count($updatedUnitCodes) === 1
                    ? '1 asset unit marked as lost.'
                    : count($updatedUnitCodes) . ' asset units marked as lost.';
            } catch (Throwable $exception) {
                $conn->rollback();
                $_SESSION['assets_error'] = $exception->getMessage();
            }
        }

        redirect_assets_page();
    }

    if ($action === 'trash_asset') {
        $assetId = (int)($_POST['asset_id'] ?? 0);

        if ($assetId > 0) {
            $beforeTrash = getAssetSnapshot($conn, $assetId);
            $stmt = $conn->prepare('UPDATE assets SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL');
            if ($stmt && $stmt->bind_param('i', $assetId) && $stmt->execute()) {
                audit_log_event(
                    $conn,
                    (int)($_SESSION['user_id'] ?? 0),
                    'trash_asset',
                    'asset',
                    $assetId,
                    $beforeTrash,
                    [
                        'asset_name' => $beforeTrash['asset_name'] ?? null,
                        'deleted_at' => date('Y-m-d H:i:s'),
                    ]
                );
                $_SESSION['assets_message'] = 'Asset moved to trash bin.';
            } else {
                $_SESSION['assets_error'] = 'Failed to move asset to trash bin.';
            }
        }

        redirect_assets_page();
    }

    if ($action === 'restore_asset') {
        $assetId = (int)($_POST['asset_id'] ?? 0);

        if ($assetId > 0) {
            $beforeRestore = getAssetSnapshot($conn, $assetId);
            $stmt = $conn->prepare('UPDATE assets SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL');
            if ($stmt && $stmt->bind_param('i', $assetId) && $stmt->execute()) {
                audit_log_event(
                    $conn,
                    (int)($_SESSION['user_id'] ?? 0),
                    'restore_asset',
                    'asset',
                    $assetId,
                    $beforeRestore,
                    [
                        'asset_name' => $beforeRestore['asset_name'] ?? null,
                        'deleted_at' => null,
                    ]
                );
                $_SESSION['assets_message'] = 'Asset restored from trash bin.';
            } else {
                $_SESSION['assets_error'] = 'Failed to restore asset.';
            }
        }

        redirect_assets_page();
    }

    if ($action === 'permanently_delete_asset') {
        $assetId = (int)($_POST['asset_id'] ?? 0);

        if ($assetId > 0) {
            $beforeDelete = getAssetSnapshot($conn, $assetId);
            $stmtQR = $conn->prepare('DELETE FROM asset_qr_codes WHERE asset_id = ?');
            if ($stmtQR) {
                $stmtQR->bind_param('i', $assetId);
                $stmtQR->execute();
            }

            $stmt = $conn->prepare('DELETE FROM assets WHERE id = ? AND deleted_at IS NOT NULL');
            if ($stmt && $stmt->bind_param('i', $assetId) && $stmt->execute()) {
                audit_log_event(
                    $conn,
                    (int)($_SESSION['user_id'] ?? 0),
                    'permanently_delete_asset',
                    'asset',
                    $assetId,
                    $beforeDelete ? [
                        'asset_name' => $beforeDelete['asset_name'] ?? null,
                        'asset_type' => $beforeDelete['asset_type'] ?? null,
                        'serial_number' => $beforeDelete['serial_number'] ?? null,
                        'asset_status' => $beforeDelete['asset_status'] ?? null,
                        'deleted_at' => $beforeDelete['deleted_at'] ?? null,
                    ] : null,
                    null
                );
                $_SESSION['assets_message'] = 'Asset permanently deleted from trash bin.';
            } else {
                $_SESSION['assets_error'] = 'Failed to permanently delete asset.';
            }
        }

        redirect_assets_page();
    }
}

if (isset($_SESSION['assets_message'])) {
    $message = (string)$_SESSION['assets_message'];
    unset($_SESSION['assets_message']);
}

if (isset($_SESSION['assets_error'])) {
    $error = (string)$_SESSION['assets_error'];
    unset($_SESSION['assets_error']);
}

if (isset($_SESSION['assets_qr_preview'])) {
    $qrPreview = (string)$_SESSION['assets_qr_preview'];
    unset($_SESSION['assets_qr_preview']);
}

if (isset($_SESSION['assets_created_asset_id'])) {
    $createdAssetId = (int)$_SESSION['assets_created_asset_id'];
    unset($_SESSION['assets_created_asset_id']);
}

$view = trim((string)($_GET['view'] ?? ''));
$isTrashView = $view === 'trash';
$assets = [];
$createdAssetPreviewRows = [];
$assetQrGalleryMap = [];
$assetUnitMetrics = [
    'total_units' => 0,
    'in_use_units' => 0,
    'maintenance_units' => 0,
    'lost_units' => 0,
];
$assetMetricsResult = $conn->query(
    "SELECT
        COUNT(*) AS total_units,
        SUM(CASE WHEN au.status = 'deployed' THEN 1 ELSE 0 END) AS in_use_units,
        SUM(CASE WHEN au.status = 'maintenance' THEN 1 ELSE 0 END) AS maintenance_units,
        SUM(CASE WHEN au.status = 'lost' THEN 1 ELSE 0 END) AS lost_units
     FROM asset_units au
     INNER JOIN assets a ON a.id = au.asset_id
     WHERE a.deleted_at IS NULL
     AND au.status <> 'archived'"
);
if ($assetMetricsResult) {
    $assetUnitMetrics = $assetMetricsResult->fetch_assoc() ?: $assetUnitMetrics;
}

$assetQuery = 'SELECT
    a.*,
    i.id AS inventory_id,
    i.quantity AS inventory_quantity,
    i.min_stock AS inventory_min_stock,
    i.max_stock AS inventory_max_stock,
    i.status AS inventory_status,
    COALESCE(unit_counts.available_units, 0) AS available_units,
    COALESCE(unit_counts.deployed_units, 0) AS deployed_units,
    COALESCE(unit_counts.maintenance_units, 0) AS maintenance_units,
    COALESCE(unit_counts.lost_units, 0) AS lost_units,
    COALESCE(unit_counts.total_units, 0) AS total_units,
    (
        SELECT q.qr_code_value
        FROM asset_qr_codes q
        WHERE q.asset_id = a.id
        ORDER BY q.id DESC
        LIMIT 1
    ) AS qr_code_value
    FROM assets a
    LEFT JOIN inventory i ON i.asset_id = a.id
    LEFT JOIN (
        SELECT
            asset_id,
            SUM(CASE WHEN status = \'available\' THEN 1 ELSE 0 END) AS available_units,
            SUM(CASE WHEN status = \'deployed\' THEN 1 ELSE 0 END) AS deployed_units,
            SUM(CASE WHEN status = \'maintenance\' THEN 1 ELSE 0 END) AS maintenance_units,
            SUM(CASE WHEN status = \'lost\' THEN 1 ELSE 0 END) AS lost_units,
            COUNT(*) AS total_units
        FROM asset_units
        WHERE status <> \'archived\'
        GROUP BY asset_id
    ) unit_counts ON unit_counts.asset_id = a.id';
$assetQuery .= $isTrashView ? ' WHERE a.deleted_at IS NOT NULL' : ' WHERE a.deleted_at IS NULL';
$assetQuery .= ' ORDER BY a.created_at DESC';
$result = $conn->query($assetQuery);

if ($result) {
    $assets = $result->fetch_all(MYSQLI_ASSOC);
    foreach ($assets as $index => $asset) {
        $assets[$index] = syncAssetIdentity($conn, $asset);
    }
}

if ($assets !== []) {
    $assetIds = array_values(array_filter(array_map(static fn(array $asset): int => (int)($asset['id'] ?? 0), $assets)));

    if ($assetIds !== []) {
        $placeholders = implode(',', array_fill(0, count($assetIds), '?'));
        $types = str_repeat('i', count($assetIds));
        $galleryStmt = $conn->prepare(
            "SELECT
                au.asset_id,
                au.unit_code,
                au.qr_code_value
             FROM asset_units au
             WHERE au.asset_id IN ($placeholders)
             AND au.status <> 'archived'
             ORDER BY au.asset_id ASC, au.unit_number ASC, au.id ASC"
        );

        if ($galleryStmt) {
            $galleryStmt->bind_param($types, ...$assetIds);
            $galleryStmt->execute();
            $galleryResult = $galleryStmt->get_result();
            $galleryRows = $galleryResult ? $galleryResult->fetch_all(MYSQLI_ASSOC) : [];

            foreach ($galleryRows as $galleryRow) {
                $assetId = (int)($galleryRow['asset_id'] ?? 0);
                if ($assetId <= 0) {
                    continue;
                }

                $assetQrGalleryMap[$assetId][] = [
                    'label' => (string)($galleryRow['unit_code'] ?? ('Asset #' . $assetId)),
                    'scan_value' => (string)($galleryRow['qr_code_value'] ?? ''),
                    'src' => $qrLibraryReady ? generateQRDataUri((string)($galleryRow['qr_code_value'] ?? '')) : '',
                ];
            }
        }
    }

    foreach ($assets as $asset) {
        $assetId = (int)($asset['id'] ?? 0);
        if ($assetId <= 0 || isset($assetQrGalleryMap[$assetId])) {
            continue;
        }

        $fallbackQrValue = (string)($asset['qr_code_value'] ?? '');
        if ($fallbackQrValue === '') {
            $fallbackQrValue = buildAssetQrValue($assetId, (string)($asset['serial_number'] ?? ''));
        }

        $assetQrGalleryMap[$assetId] = [[
            'label' => (string)($asset['serial_number'] ?? ('Asset #' . $assetId)),
            'scan_value' => $fallbackQrValue,
            'src' => $qrLibraryReady ? generateQRDataUri($fallbackQrValue) : '',
        ]];
    }
}

if ($createdAssetId > 0) {
    $previewStmt = $conn->prepare(
        "SELECT
            a.id AS asset_id,
            a.asset_name,
            a.serial_number,
            au.id AS asset_unit_id,
            au.unit_code,
            au.qr_code_value
         FROM assets a
         LEFT JOIN asset_units au ON au.asset_id = a.id AND au.status <> 'archived'
         WHERE a.id = ?
         ORDER BY au.unit_code ASC, au.id ASC"
    );
    if ($previewStmt) {
        $previewStmt->bind_param('i', $createdAssetId);
        $previewStmt->execute();
        $previewResult = $previewStmt->get_result();
        $createdAssetPreviewRows = $previewResult ? $previewResult->fetch_all(MYSQLI_ASSOC) : [];
    }

    if ($createdAssetPreviewRows === []) {
        $assetPreviewStmt = $conn->prepare(
            "SELECT id AS asset_id, asset_name, serial_number
             FROM assets
             WHERE id = ?
             LIMIT 1"
        );
        if ($assetPreviewStmt) {
            $assetPreviewStmt->bind_param('i', $createdAssetId);
            $assetPreviewStmt->execute();
            $assetPreviewResult = $assetPreviewStmt->get_result();
            $assetPreviewRow = $assetPreviewResult ? $assetPreviewResult->fetch_assoc() : null;
            if ($assetPreviewRow) {
                $createdAssetPreviewRows[] = [
                    'asset_id' => (int)$assetPreviewRow['asset_id'],
                    'asset_name' => $assetPreviewRow['asset_name'],
                    'serial_number' => $assetPreviewRow['serial_number'],
                    'asset_unit_id' => null,
                    'unit_code' => null,
                    'qr_code_value' => buildAssetQrValue((int)$assetPreviewRow['asset_id'], (string)($assetPreviewRow['serial_number'] ?? '')),
                ];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assets & QR Codes - Super Admin</title>
    <link rel="stylesheet" href="../css/super_admin_dashboard.css">
    <link rel="stylesheet" href="../css/assets.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
</head>
<body>
<div class="container">
    <?php include __DIR__ . '/../super_admin_sidebar.php'; ?>

    <main class="main-content assets-content">
        <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if (!$qrLibraryReady): ?><div class="alert alert-error">QR preview/print is temporarily unavailable because Composer packages are incomplete in `vendor/`.</div><?php endif; ?>

        <section class="metrics-grid">
            <div class="metric-card">
                <span>Tracked Units</span>
                <strong><?php echo (int)($assetUnitMetrics['total_units'] ?? 0); ?></strong>
            </div>
            <div class="metric-card">
                <span>In Use</span>
                <strong><?php echo (int)($assetUnitMetrics['in_use_units'] ?? 0); ?></strong>
            </div>
            <div class="metric-card">
                <span>Maintenance</span>
                <strong><?php echo (int)($assetUnitMetrics['maintenance_units'] ?? 0); ?></strong>
            </div>
            <div class="metric-card">
                <span>Loss</span>
                <strong><?php echo (int)($assetUnitMetrics['lost_units'] ?? 0); ?></strong>
            </div>
        </section>

        <?php if ($qrPreview !== '' && !$isTrashView): ?>
            <section class="form-section asset-preview-section">
                <h2 class="dashboard-section-title">QR Preview (Newest Asset)</h2>
                <div class="asset-preview-meta">
                    <?php if ($createdAssetId > 0): ?>
                        <p>Asset ID: <strong><?php echo htmlspecialchars((string)$createdAssetId); ?></strong></p>
                        <p>Generated labels: <strong><?php echo count($createdAssetPreviewRows); ?></strong></p>
                        <?php if ($qrLibraryReady): ?>
                            <a href="/codesamplecaps/SUPERADMIN/print_qr_codes.php?asset_id=<?php echo $createdAssetId; ?>" target="_blank" class="btn-secondary" rel="noreferrer noopener">Print This Asset Labels</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="asset-preview-grid">
                    <?php if (!$qrLibraryReady): ?>
                        <div class="empty-state-solid">QR preview unavailable until vendor packages are restored.</div>
                    <?php else: ?>
                        <?php foreach ($createdAssetPreviewRows as $previewIndex => $previewRow): ?>
                            <?php $previewQrValue = (string)($previewRow['qr_code_value'] ?? $qrPreview); ?>
                            <article class="asset-preview-card">
                                <span class="asset-preview-card__count">#<?php echo $previewIndex + 1; ?></span>
                                <img class="asset-preview-image" src="<?php echo generateQRDataUri($previewQrValue); ?>" alt="Asset QR preview <?php echo $previewIndex + 1; ?>">
                                <strong><?php echo htmlspecialchars((string)($previewRow['asset_name'] ?? 'Asset')); ?></strong>
                                <small><?php echo htmlspecialchars((string)($previewRow['unit_code'] ?? ('Asset ID ' . $createdAssetId))); ?></small>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!$isTrashView): ?>
        <section class="form-section">
            <h2 class="dashboard-section-title">Create New Asset</h2>
            <form method="POST" class="asset-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="create_asset">
                <div class="form-row">
                    <div class="form-group">
                        <label for="asset_name">Asset Name *</label>
                        <input type="text" id="asset_name" name="asset_name" required>
                    </div>
                    <div class="form-group">
                        <label for="asset_category">Asset Category</label>
                        <select id="asset_category" name="asset_category" data-default-min-stock-target="min_stock">
                            <?php foreach ($activeAssetCategoryDefaultRows as $categoryRow): ?>
                                <option
                                    value="<?php echo htmlspecialchars((string)($categoryRow['category_key'] ?? '')); ?>"
                                    data-default-min-stock="<?php echo (int)($categoryRow['recommended_min_stock'] ?? 0); ?>"
                                    data-default-max-stock="<?php echo htmlspecialchars((string)($categoryRow['recommended_max_stock'] ?? '')); ?>"
                                    <?php echo (string)($categoryRow['category_key'] ?? '') === 'tool' ? 'selected' : ''; ?>
                                >
                                    <?php echo htmlspecialchars((string)($categoryRow['category_label'] ?? ($categoryRow['category_key'] ?? 'Category'))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="criticality">Criticality</label>
                        <select id="criticality" name="criticality">
                            <?php foreach ($criticalityChoices as $criticalityValue => $criticalityLabel): ?>
                                <option value="<?php echo htmlspecialchars($criticalityValue); ?>" <?php echo $criticalityValue === 'medium' ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($criticalityLabel); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="min_stock">Min Stock</label>
                        <input type="number" id="min_stock" name="min_stock" min="0" step="1" value="<?php echo (int)($assetCategoryDefaults['tool']['min_stock'] ?? 0); ?>" placeholder="Auto-filled by category">
                    </div>
                    <div class="form-group">
                        <div class="field-label-row">
                            <label for="max_stock">Max Stock</label>
                            <button type="button" class="field-tip" aria-label="Max stock help">
                                <span class="field-tip__icon" aria-hidden="true">i</span>
                                <span class="field-tip__bubble">Auto-guided ceiling based on category and starting stock. Increase it if this asset usually needs extra on-hand buffer.</span>
                            </button>
                        </div>
                        <input type="number" id="max_stock" name="max_stock" min="1" step="1" value="<?php echo (int)suggest_asset_max_stock('tool', 1, (int)($assetCategoryDefaults['tool']['min_stock'] ?? 0), $assetCategoryDefaults); ?>" placeholder="Smart auto-fill based on category">
                    </div>
                </div>
                <input type="hidden" id="quantity" name="quantity" value="1">
                <button type="submit" class="btn-primary">Create Asset + Inventory + QR</button>
            </form>
        </section>
        <?php endif; ?>

        <?php
        $assetFilterCounts = [
            'all' => count($assets),
            'available' => 0,
            'in_use' => 0,
            'maintenance' => 0,
            'lost' => 0,
        ];

        foreach ($assets as $assetRow) {
            $statusKey = (string)($assetRow['asset_status'] ?? '');
            if (isset($assetFilterCounts[$statusKey])) {
                $assetFilterCounts[$statusKey]++;
            }
        }
        ?>

        <section class="asset-listing-section">
            <div class="asset-section-header">
                <div>
                    <h2 class="dashboard-section-title"><?php echo $isTrashView ? 'Trashed Assets' : 'Existing Assets'; ?></h2>
                </div>
                <div class="asset-section-actions">
                    <?php if ($qrLibraryReady && !$isTrashView): ?>
                        <a href="/codesamplecaps/SUPERADMIN/print_qr_codes.php" target="_blank" class="btn-secondary">Print QR Codes</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!$isTrashView && count($assets) > 0): ?>
                <div class="asset-filter-tabs" role="tablist" aria-label="Filter existing assets by status">
                    <button type="button" class="asset-filter-tab is-active" data-filter="all">
                        <span>All</span>
                        <strong><?php echo (int)$assetFilterCounts['all']; ?></strong>
                    </button>
                    <button type="button" class="asset-filter-tab" data-filter="available">
                        <span>Available</span>
                        <strong><?php echo (int)$assetFilterCounts['available']; ?></strong>
                    </button>
                    <button type="button" class="asset-filter-tab" data-filter="in_use">
                        <span>In Use</span>
                        <strong><?php echo (int)$assetFilterCounts['in_use']; ?></strong>
                    </button>
                    <button type="button" class="asset-filter-tab" data-filter="maintenance">
                        <span>Maintenance</span>
                        <strong><?php echo (int)$assetFilterCounts['maintenance']; ?></strong>
                    </button>
                    <button type="button" class="asset-filter-tab" data-filter="lost">
                        <span>Lost</span>
                        <strong><?php echo (int)$assetFilterCounts['lost']; ?></strong>
                    </button>
                </div>
            <?php endif; ?>
            <div class="table-wrapper">
                <table class="responsive-table mobile-card-table assets-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Asset Information</th>
                            <th>Stock / Usage Information</th>
                            <th>QR Code</th>
                            <th>Created On</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($assets) === 0): ?>
                            <tr>
                                <td colspan="6" class="table-empty-cell"><?php echo $isTrashView ? 'No trashed assets yet.' : 'No assets yet.'; ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($assets as $asset): ?>
                                <?php
                                $availableUnits = (int)($asset['available_units'] ?? 0);
                                $deployedUnits = (int)($asset['deployed_units'] ?? 0);
                                $maintenanceUnits = (int)($asset['maintenance_units'] ?? 0);
                                $lostUnits = (int)($asset['lost_units'] ?? 0);
                                $totalTrackedUnits = max(1, $availableUnits + $deployedUnits + $maintenanceUnits + $lostUnits);
                                $availableWidth = ($availableUnits / $totalTrackedUnits) * 100;
                                $deployedWidth = ($deployedUnits / $totalTrackedUnits) * 100;
                                $maintenanceWidth = ($maintenanceUnits / $totalTrackedUnits) * 100;
                                $lostWidth = ($lostUnits / $totalTrackedUnits) * 100;
                                ?>
                                <tr class="asset-table-row" data-asset-status="<?php echo htmlspecialchars((string)($asset['asset_status'] ?? 'available')); ?>">
                                    <td data-label="ID"><?php echo htmlspecialchars($asset['id']); ?></td>
                                    <td data-label="Asset Information">
                                        <div class="asset-info-card">
                                            <div class="asset-info-card__header">
                                                <div>
                                                    <strong class="asset-info-card__name"><?php echo htmlspecialchars($asset['asset_name']); ?></strong>
                                                    <div class="asset-meta-list">
                                                        <span class="asset-meta-pill"><?php echo htmlspecialchars(asset_category_label($asset['asset_category'] ?? null)); ?></span>
                                                        <span class="asset-meta-text">SN: <?php echo htmlspecialchars($asset['serial_number'] ?: 'Generating...'); ?></span>
                                                    </div>
                                                </div>
                                                <span class="asset-status asset-status--<?php echo htmlspecialchars((string)($asset['asset_status'] ?? 'available')); ?>">
                                                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($asset['asset_status'] ?? 'available')))); ?>
                                                </span>
                                            </div>
                                            <div class="asset-info-card__footer">
                                                <div class="asset-meta-inline">
                                                    <span class="asset-meta-inline__item"><strong>Type:</strong> <?php echo htmlspecialchars($asset['asset_type'] ?: 'Not set'); ?></span>
                                                    <span class="asset-meta-inline__item"><strong>Criticality:</strong> <?php echo htmlspecialchars(asset_criticality_label($asset['criticality'] ?? null)); ?></span>
                                                    <span class="asset-meta-inline__item asset-meta-inline__item--status">
                                                        <strong>Stock Health:</strong>
                                                        <span class="status-pill status-<?php echo htmlspecialchars((string)($asset['inventory_status'] ?? 'available')); ?>">
                                                            <?php echo htmlspecialchars(build_asset_stock_badge_label($asset['inventory_status'] ?? null)); ?>
                                                        </span>
                                                    </span>
                                                    <span class="asset-meta-inline__item"><strong>Qty / Min / Max:</strong> <?php echo htmlspecialchars(build_asset_stock_capacity_label(isset($asset['inventory_quantity']) ? (int)$asset['inventory_quantity'] : null, $asset['inventory_min_stock'] !== null ? (int)$asset['inventory_min_stock'] : null, $asset['inventory_max_stock'] !== null ? (int)$asset['inventory_max_stock'] : null)); ?></span>
                                                    <?php $capacityNote = build_asset_stock_capacity_note(isset($asset['inventory_quantity']) ? (int)$asset['inventory_quantity'] : null, $asset['inventory_max_stock'] !== null ? (int)$asset['inventory_max_stock'] : null); ?>
                                                    <?php if ($capacityNote !== null): ?>
                                                        <span class="asset-meta-inline__item asset-meta-inline__item--status">
                                                            <strong>Capacity:</strong>
                                                            <span class="status-pill status-low-stock"><?php echo htmlspecialchars($capacityNote); ?></span>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td data-label="Stock / Usage Information">
                                        <div class="asset-stock-panel">
                                            <div class="asset-stock-panel__chips">
                                                <span class="stock-chip stock-chip--available">Available <strong><?php echo $availableUnits; ?></strong></span>
                                                <span class="stock-chip stock-chip--in-use">In Use <strong><?php echo $deployedUnits; ?></strong></span>
                                                <span class="stock-chip stock-chip--maintenance">Maintenance <strong><?php echo $maintenanceUnits; ?></strong></span>
                                                <span class="stock-chip stock-chip--lost">Lost <strong><?php echo $lostUnits; ?></strong></span>
                                            </div>
                                            <div class="asset-distribution">
                                                <div class="asset-distribution__label-row">
                                                    <span>Stock distribution</span>
                                                    <strong><?php echo $availableUnits + $deployedUnits + $maintenanceUnits + $lostUnits; ?> total tracked</strong>
                                                </div>
                                                <div class="asset-distribution__bar" aria-hidden="true">
                                                    <span class="asset-distribution__segment asset-distribution__segment--available" style="width: <?php echo round($availableWidth, 2); ?>%"></span>
                                                    <span class="asset-distribution__segment asset-distribution__segment--in-use" style="width: <?php echo round($deployedWidth, 2); ?>%"></span>
                                                    <span class="asset-distribution__segment asset-distribution__segment--maintenance" style="width: <?php echo round($maintenanceWidth, 2); ?>%"></span>
                                                    <span class="asset-distribution__segment asset-distribution__segment--lost" style="width: <?php echo round($lostWidth, 2); ?>%"></span>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td data-label="QR Code">
                                        <div class="asset-qr-actions">
                                            <?php if (!$isTrashView && !empty($asset['qr_code_value'])): ?>
                                                <?php if ($qrLibraryReady): ?>
                                                    <button type="button" onclick="showAssetQrGallery(<?php echo (int)$asset['id']; ?>)" class="btn-secondary">Preview</button>
                                                    <a href="/codesamplecaps/SUPERADMIN/print_qr_codes.php?asset_id=<?php echo $asset['id']; ?>" target="_blank" rel="noreferrer noopener" class="asset-link-btn">Print</a>
                                                <?php else: ?>
                                                    <span class="asset-inline-note">QR unavailable</span>
                                                <?php endif; ?>
                                            <?php elseif ($isTrashView): ?>
                                                <span class="asset-inline-note">Printing disabled while in trash</span>
                                            <?php else: ?>
                                                <span class="asset-inline-note">No QR</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td data-label="Created"><?php echo htmlspecialchars($asset['created_at']); ?></td>
                                    <td data-label="Actions">
                                        <div class="asset-row-actions">
                                            <?php if ($isTrashView): ?>
                                                <form method="POST" class="asset-inline-form" onsubmit="return confirm('Restore this asset from trash bin?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                    <input type="hidden" name="action" value="restore_asset">
                                                    <input type="hidden" name="asset_id" value="<?php echo $asset['id']; ?>">
                                                    <input type="hidden" name="redirect_to" value="/codesamplecaps/SUPERADMIN/sidebar/projects.php?view=trash">
                                                    <button type="submit" class="btn-secondary">Restore</button>
                                                </form>
                                                <form method="POST" class="asset-inline-form" onsubmit="return confirm('Permanently delete this asset from trash bin?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                    <input type="hidden" name="action" value="permanently_delete_asset">
                                                    <input type="hidden" name="asset_id" value="<?php echo $asset['id']; ?>">
                                                    <input type="hidden" name="redirect_to" value="/codesamplecaps/SUPERADMIN/sidebar/projects.php?view=trash">
                                                    <button type="submit" class="btn-danger">Delete Permanently</button>
                                                </form>
                                            <?php else: ?>
                                                <?php if ($availableUnits > 0): ?>
                                                    <form method="POST" class="asset-inline-form asset-action-form" data-confirm-lost="Mark the selected quantity as lost?">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                        <input type="hidden" name="action" value="mark_asset_maintenance" class="asset-action-input">
                                                        <input type="hidden" name="asset_id" value="<?php echo $asset['id']; ?>">
                                                        <div class="asset-action-card">
                                                            <span class="asset-action-card__label">Update available stock</span>
                                                            <div class="asset-action-controls">
                                                                <input
                                                                    type="number"
                                                                    name="status_quantity"
                                                                    min="1"
                                                                    max="<?php echo $availableUnits; ?>"
                                                                    value="1"
                                                                    class="asset-action-qty"
                                                                    aria-label="Quantity to update"
                                                                >
                                                                <select class="asset-action-select" aria-label="Select stock action">
                                                                    <option value="mark_asset_maintenance">Send to maintenance</option>
                                                                    <option value="mark_asset_lost">Mark as lost</option>
                                                                </select>
                                                                <button type="submit" class="btn-secondary">Apply</button>
                                                            </div>
                                                            <small class="asset-inline-note">Up to <?php echo $availableUnits; ?> available unit<?php echo $availableUnits === 1 ? '' : 's'; ?> can be updated.</small>
                                                        </div>
                                                    </form>
                                                <?php else: ?>
                                                    <div class="asset-action-card asset-action-card--muted">
                                                        <span class="asset-action-card__label">Update available stock</span>
                                                        <small class="asset-inline-note">No available units to move to maintenance or lost.</small>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($deployedUnits > 0 || $maintenanceUnits > 0 || $lostUnits > 0): ?>
                                                    <form method="POST" class="asset-inline-form asset-recovery-form">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                        <input type="hidden" name="action" value="<?php echo $deployedUnits > 0 ? 'return_asset' : ($maintenanceUnits > 0 ? 'resolve_asset_maintenance' : 'recover_asset_lost'); ?>" class="asset-recovery-action-input">
                                                        <input type="hidden" name="asset_id" value="<?php echo $asset['id']; ?>">
                                                        <div class="asset-action-card asset-action-card--muted">
                                                            <span class="asset-action-card__label">Restore non-available units</span>
                                                            <div class="asset-action-controls">
                                                                <input
                                                                    type="number"
                                                                    name="status_quantity"
                                                                    min="1"
                                                                    max="<?php echo max($deployedUnits, $maintenanceUnits, $lostUnits); ?>"
                                                                    value="1"
                                                                    class="asset-action-qty"
                                                                    aria-label="Quantity to restore"
                                                                >
                                                                <select class="asset-action-select asset-recovery-select" aria-label="Select restore action">
                                                                    <?php if ($deployedUnits > 0): ?>
                                                                        <option value="return_asset" data-max-qty="<?php echo $deployedUnits; ?>">Return in-use to available</option>
                                                                    <?php endif; ?>
                                                                    <?php if ($maintenanceUnits > 0): ?>
                                                                        <option value="resolve_asset_maintenance" data-max-qty="<?php echo $maintenanceUnits; ?>">Maintenance fixed to available</option>
                                                                    <?php endif; ?>
                                                                    <?php if ($lostUnits > 0): ?>
                                                                        <option value="recover_asset_lost" data-max-qty="<?php echo $lostUnits; ?>">Recovered lost to available</option>
                                                                    <?php endif; ?>
                                                                </select>
                                                                <button type="submit" class="btn-secondary">Restore</button>
                                                            </div>
                                                            <small class="asset-inline-note">
                                                                In use: <?php echo $deployedUnits; ?> | Maintenance: <?php echo $maintenanceUnits; ?> | Lost: <?php echo $lostUnits; ?>
                                                            </small>
                                                        </div>
                                                    </form>
                                                <?php endif; ?>
                                                <div class="asset-row-actions__secondary">
                                                    <form method="POST" class="asset-inline-form" onsubmit="return confirm('Move this asset to trash bin?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                        <input type="hidden" name="action" value="trash_asset">
                                                        <input type="hidden" name="asset_id" value="<?php echo $asset['id']; ?>">
                                                        <button type="submit" class="btn-danger">Delete</button>
                                                    </form>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="asset-filter-empty" hidden>
                                <td colspan="6" class="table-empty-cell">No assets match the selected filter.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<div id="qrModal" class="modal-backdrop asset-qr-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.72); justify-content:center; align-items:center; padding:24px; z-index:1000;">
    <div class="asset-qr-modal__panel" onclick="event.stopPropagation();">
        <div class="asset-qr-modal__header">
            <h3>Asset QR Preview</h3>
            <button type="button" class="btn-secondary" onclick="closeAssetQrModal()">Close</button>
        </div>
        <div id="qrModalContent" class="asset-qr-modal__grid"></div>
    </div>
</div>

<script src="../js/super_admin_dashboard.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const categoryField = document.getElementById('asset_category');
    const minStockField = document.getElementById('min_stock');
    const maxStockField = document.getElementById('max_stock');
    const quantityField = document.getElementById('quantity');

    if (!categoryField || !minStockField || !maxStockField || !quantityField) {
        return;
    }

    const computeSmartMaxStock = (quantityValue, minStockValue, categoryDefaultMax) => {
        const quantity = Math.max(0, Number(quantityValue || 0));
        const minStock = Math.max(0, Number(minStockValue || 0));
        const defaultMax = Number(categoryDefaultMax || 0);

        if (defaultMax > 0) {
            return Math.max(defaultMax, quantity, minStock);
        }

        const baseline = Math.max(quantity, minStock);
        const buffer = Math.max(2, Math.ceil(baseline * 0.5));
        return Math.max(1, baseline + buffer, minStock * 2);
    };

    const syncThresholdsFromCategory = () => {
        const selectedOption = categoryField.options[categoryField.selectedIndex];
        if (!selectedOption) {
            return;
        }

        const recommendedMinStock = selectedOption.getAttribute('data-default-min-stock');
        const recommendedMaxStock = selectedOption.getAttribute('data-default-max-stock');

        if (recommendedMinStock !== null) {
            minStockField.value = recommendedMinStock;
        }

        const shouldAutoSyncMax = maxStockField.dataset.userEdited !== 'true';
        if (shouldAutoSyncMax) {
            maxStockField.value = String(
                computeSmartMaxStock(quantityField.value, recommendedMinStock, recommendedMaxStock)
            );
        }
    };

    categoryField.addEventListener('change', syncThresholdsFromCategory);
    minStockField.addEventListener('input', () => {
        if (maxStockField.dataset.userEdited === 'true') {
            return;
        }
        const selectedOption = categoryField.options[categoryField.selectedIndex];
        maxStockField.value = String(
            computeSmartMaxStock(
                quantityField.value,
                minStockField.value,
                selectedOption?.getAttribute('data-default-max-stock')
            )
        );
    });
    quantityField.addEventListener('input', () => {
        if (maxStockField.dataset.userEdited === 'true') {
            return;
        }
        const selectedOption = categoryField.options[categoryField.selectedIndex];
        maxStockField.value = String(
            computeSmartMaxStock(
                quantityField.value,
                minStockField.value,
                selectedOption?.getAttribute('data-default-max-stock')
            )
        );
    });
    maxStockField.addEventListener('input', () => {
        maxStockField.dataset.userEdited = 'true';
    });
    syncThresholdsFromCategory();

    const filterTabs = Array.from(document.querySelectorAll('.asset-filter-tab'));
    const assetRows = Array.from(document.querySelectorAll('.asset-table-row'));
    const filterEmptyRow = document.querySelector('.asset-filter-empty');

    if (filterTabs.length > 0 && assetRows.length > 0) {
        const applyAssetFilter = (filterValue) => {
            let visibleRows = 0;

            assetRows.forEach((row) => {
                const rowStatus = row.getAttribute('data-asset-status') || 'available';
                const isVisible = filterValue === 'all' || rowStatus === filterValue;
                row.hidden = !isVisible;
                if (isVisible) {
                    visibleRows++;
                }
            });

            if (filterEmptyRow) {
                filterEmptyRow.hidden = visibleRows !== 0;
            }
        };

        filterTabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                filterTabs.forEach((button) => button.classList.remove('is-active'));
                tab.classList.add('is-active');
                applyAssetFilter(tab.getAttribute('data-filter') || 'all');
            });
        });
    }

    document.querySelectorAll('.asset-action-form').forEach((form) => {
        const actionInput = form.querySelector('.asset-action-input');
        const actionSelect = form.querySelector('.asset-action-select');

        if (!actionInput || !actionSelect) {
            return;
        }

        form.addEventListener('submit', (event) => {
            actionInput.value = actionSelect.value;

            if (actionSelect.value === 'mark_asset_lost') {
                const lostMessage = form.getAttribute('data-confirm-lost') || 'Mark the selected quantity as lost?';
                if (!window.confirm(lostMessage)) {
                    event.preventDefault();
                }
            }
        });
    });

    document.querySelectorAll('.asset-recovery-form').forEach((form) => {
        const actionInput = form.querySelector('.asset-recovery-action-input');
        const actionSelect = form.querySelector('.asset-recovery-select');
        const quantityInput = form.querySelector('.asset-action-qty');

        if (!actionInput || !actionSelect || !quantityInput) {
            return;
        }

        const syncRecoveryQuantity = () => {
            const selectedOption = actionSelect.options[actionSelect.selectedIndex];
            const maxQuantity = Number(selectedOption?.getAttribute('data-max-qty') || '1');
            quantityInput.max = String(Math.max(1, maxQuantity));
            if (Number(quantityInput.value) > maxQuantity) {
                quantityInput.value = String(Math.max(1, maxQuantity));
            }
        };

        actionSelect.addEventListener('change', syncRecoveryQuantity);
        syncRecoveryQuantity();

        form.addEventListener('submit', () => {
            actionInput.value = actionSelect.value;
        });
    });
});

const assetQrGalleryMap = <?php echo json_encode($assetQrGalleryMap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

function closeAssetQrModal() {
    const modal = document.getElementById('qrModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

function showAssetQrGallery(assetId) {
    const modal = document.getElementById('qrModal');
    const modalContent = document.getElementById('qrModalContent');
    const galleryItems = assetQrGalleryMap[String(assetId)] || assetQrGalleryMap[assetId] || [];

    if (!modal || !modalContent || galleryItems.length === 0) {
        return;
    }

    modalContent.innerHTML = galleryItems.map((item, index) => {
        const safeLabel = String(item.label || 'Asset QR')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
        const safeScanValue = String(item.scan_value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
        const safeSrc = String(item.src || '').replace(/"/g, '&quot;');

        return `
            <article class="asset-preview-card">
                <span class="asset-preview-card__count">#${index + 1}</span>
                <img class="asset-preview-image" src="${safeSrc}" alt="${safeLabel}">
                <strong>${safeLabel}</strong>
                <small class="asset-qr-modal__scan">${safeScanValue}</small>
            </article>
        `;
    }).join('');

    modal.style.display = 'flex';
}

const assetQrModal = document.getElementById('qrModal');
if (assetQrModal) {
    assetQrModal.addEventListener('click', closeAssetQrModal);
}
</script>
</body>
</html>
