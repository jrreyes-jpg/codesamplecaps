<?php
require_once __DIR__ . '/../../../../config/auth_middleware.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/asset_unit_helpers.php';

require_role('admin');

$qrLibraryReady = false;
$qrAutoloadPath = __DIR__ . '/../../../../vendor/autoload.php';
$qrRequiredFiles = [
    __DIR__ . '/../../../../vendor/symfony/polyfill-ctype/bootstrap.php',
    __DIR__ . '/../../../../vendor/chillerlan/php-qrcode/src/QRCode.php',
];

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
        $qrLibraryReady = class_exists('chillerlan\\QRCode\\QRCode') && class_exists('chillerlan\\QRCode\\QROptions');
    }
}

function generateQRDataUri(string $value): string {
    global $qrLibraryReady;

    if (!$qrLibraryReady) {
        return '';
    }

    $optionsClass = 'chillerlan\\QRCode\\QROptions';
    $qrClass = 'chillerlan\\QRCode\\QRCode';

    $options = new $optionsClass([
        'outputType' => 'png',
        'scale' => 8
    ]);

    return (new $qrClass($options))->render($value);
}

function buildAssetQrValue(int $assetId, string $serialNumber = ''): string {
    $parts = ['asset_id=' . $assetId];

    if ($serialNumber !== '') {
        $parts[] = 'sn=' . $serialNumber;
    }

    return implode('|', $parts);
}

$assetId = isset($_GET['asset_id']) ? (int)$_GET['asset_id'] : 0;

ensure_asset_unit_tracking_schema($conn);

$sql = "SELECT
            a.id,
            a.asset_name,
            a.asset_type,
            a.serial_number,
            a.asset_status,
            au.id AS asset_unit_id,
            au.unit_code,
            au.qr_code_value AS unit_qr_code_value,
            au.status AS unit_status,
            (
                SELECT q.qr_code_value
                FROM asset_qr_codes q
                WHERE q.asset_id = a.id
                ORDER BY q.id DESC
                LIMIT 1
            ) AS qr_code_value
        FROM assets a
        LEFT JOIN asset_units au ON au.asset_id = a.id AND au.status <> 'archived'";
if ($assetId > 0) {
    $sql .= ' WHERE a.id = ?';
}
$sql .= ' ORDER BY a.id DESC, au.unit_code ASC';

$stmt = $conn->prepare($sql);
if ($assetId > 0) {
    $stmt->bind_param('i', $assetId);
}
$stmt->execute();
$result = $stmt->get_result();
$assets = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$assetUnitCounters = [];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print QR Codes - Edge Automation</title>
    <link rel="stylesheet" href="/codesamplecaps/ADMIN/sidebar/assets/css/assets.css">
</head>
<body class="asset-print-body">
<div class="asset-print-page">
    <div class="asset-print-header">
        <h1>Print QR Codes</h1>
        <button type="button" class="asset-print-button" data-print-asset-qrs>Print / Save as PDF</button>
    </div>
    <?php if (!$qrLibraryReady): ?>
        <div class="asset-print-warning">
            QR printing is unavailable because Composer packages are incomplete in vendor.
        </div>
    <?php endif; ?>

    <div class="asset-print-cards">
        <?php if (count($assets) === 0): ?>
            <div>No assets found.</div>
        <?php endif; ?>

        <?php foreach ($assets as $asset): ?>
            <?php
                $assetKey = (int)($asset['id'] ?? 0);
                $assetUnitCounters[$assetKey] = ($assetUnitCounters[$assetKey] ?? 0) + 1;
                $labelNumber = $assetUnitCounters[$assetKey];
                $qrValue = !empty($asset['unit_qr_code_value'])
                    ? (string)$asset['unit_qr_code_value']
                    : (($asset['qr_code_value'] ?: buildAssetQrValue((int)$asset['id'], (string)($asset['serial_number'] ?? ''))));
                $qrDataUri = $qrLibraryReady ? generateQRDataUri($qrValue) : '';
            ?>
            <div class="asset-print-card">
                <span class="asset-print-label-number">Label #<?php echo $labelNumber; ?></span>
                <div class="asset-print-qr">
                    <?php if ($qrLibraryReady): ?>
                        <img src="<?php echo $qrDataUri; ?>" alt="QR code" class="asset-print-qr__image">
                    <?php else: ?>
                        <div class="asset-print-qr__empty">QR unavailable</div>
                    <?php endif; ?>
                </div>
                <h3><?php echo htmlspecialchars($asset['asset_name']); ?> (ID <?php echo $asset['id']; ?>)</h3>
                <p>Unit: <?php echo htmlspecialchars((string)($asset['unit_code'] ?? 'General asset QR')); ?></p>
                <p>Type: <?php echo htmlspecialchars($asset['asset_type'] ?: 'Type not set'); ?></p>
                <p>Serial: <?php echo htmlspecialchars($asset['serial_number'] ?: '-'); ?></p>
                <p>Status: <?php echo htmlspecialchars((string)($asset['unit_status'] ?? $asset['asset_status'])); ?></p>
                <p class="asset-print-scan-value">Scan value: <code><?php echo htmlspecialchars($qrValue); ?></code></p>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<script src="/codesamplecaps/ADMIN/sidebar/assets/js/assets.js"></script>
</body>
</html>
