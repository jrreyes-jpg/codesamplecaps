<?php
require_once __DIR__ . '/../config/auth_middleware.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/asset_unit_helpers.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

require_role('super_admin');

$qrLibraryReady = false;
$qrAutoloadPath = __DIR__ . '/../vendor/autoload.php';
$qrRequiredFiles = [
    __DIR__ . '/../vendor/symfony/polyfill-ctype/bootstrap.php',
    __DIR__ . '/../vendor/chillerlan/php-qrcode/src/QRCode.php',
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
        $qrLibraryReady = class_exists(QRCode::class) && class_exists(QROptions::class);
    }
}

function generateQRDataUri(string $value): string {
    global $qrLibraryReady;

    if (!$qrLibraryReady) {
        return '';
    }

    $options = new QROptions([
        'outputType' => 'png',
        'scale' => 8
    ]);

    return (new QRCode($options))->render($value);
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
    <style>
        body { font-family: 'Poppins', sans-serif; background: #f4f6f8; margin: 0; padding: 20px; }
        .page { max-width: 900px; margin: 0 auto; }
        .header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; }
        .header h1 { margin: 0; font-size: 20px; }
        .btn { padding: 10px 16px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; }
        .btn-primary { background: #2d3a56; color: #fff; }
        .cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 18px; }
        .card { background: #fff; border-radius: 12px; padding: 16px; box-shadow: 0 12px 30px rgba(0,0,0,0.08); min-height: 320px; display: flex; flex-direction: column; }
        .card h3 { margin: 0 0 6px; font-size: 16px; }
        .card p { margin: 4px 0; font-size: 13px; color: #444; }
        .card .qr { width: 130px; height: 130px; margin: 10px auto; }
        .card .scan-value { margin-top: auto; font-size: 12px; color: #555; word-break: break-all; overflow-wrap: anywhere; line-height: 1.35; }
        .card .label-number { display: inline-flex; margin-bottom: 6px; padding: 4px 8px; border-radius: 999px; background: #eef2ff; color: #3730a3; font-size: 12px; font-weight: 700; }
        @media print {
            @page { size: auto; margin: 10mm; }
            body { padding: 0; background: #fff; }
            .header { display: none; }
            .cards { gap: 10px; }
            .card { page-break-inside: avoid; break-inside: avoid; box-shadow: none; border: 1px solid #d1d5db; min-height: 280px; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="header">
        <h1>Print QR Codes</h1>
        <button class="btn btn-primary" onclick="window.print();">Print / Save as PDF</button>
    </div>
    <?php if (!$qrLibraryReady): ?>
        <div style="margin-bottom:16px; padding:12px 14px; border-radius:10px; background:#fee2e2; color:#991b1b;">
            QR printing is unavailable because Composer packages are incomplete in vendor.
        </div>
    <?php endif; ?>

    <div class="cards">
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
            <div class="card">
                <span class="label-number">Label #<?php echo $labelNumber; ?></span>
                <div class="qr">
                    <?php if ($qrLibraryReady): ?>
                        <img src="<?php echo $qrDataUri; ?>" alt="QR code" style="width:100%; height:auto; display:block;">
                    <?php else: ?>
                        <div style="display:flex; align-items:center; justify-content:center; height:100%; text-align:center; font-size:12px; color:#666;">QR unavailable</div>
                    <?php endif; ?>
                </div>
                <h3><?php echo htmlspecialchars($asset['asset_name']); ?> (ID <?php echo $asset['id']; ?>)</h3>
                <p>Unit: <?php echo htmlspecialchars((string)($asset['unit_code'] ?? 'General asset QR')); ?></p>
                <p>Type: <?php echo htmlspecialchars($asset['asset_type'] ?: 'Type not set'); ?></p>
                <p>Serial: <?php echo htmlspecialchars($asset['serial_number'] ?: '-'); ?></p>
                <p>Status: <?php echo htmlspecialchars((string)($asset['unit_status'] ?? $asset['asset_status'])); ?></p>
                <p class="scan-value">Scan value: <code><?php echo htmlspecialchars($qrValue); ?></code></p>
            </div>
        <?php endforeach; ?>
    </div>
</div>
</body>
</html>
