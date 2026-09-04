<?php
// Official barangay lookup table. Import PSGC/PSA barangays here for accuracy.
require_once __DIR__ . '/service_areas.php';

function service_barangay_display_name(string $barangay): string
{
    $name = service_area_title_case($barangay);
    $fixes = [
        'Banadero' => 'Bañadero',
    ];

    return $fixes[$name] ?? $name;
}

function service_barangay_storage_name(string $barangay): string
{
    $fixes = [
        'Bañadero' => 'Banadero',
    ];

    return $fixes[$barangay] ?? $barangay;
}

function service_barangays_ensure_table(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS service_barangays (
            id INT AUTO_INCREMENT PRIMARY KEY,
            province VARCHAR(80) NOT NULL,
            city_municipality VARCHAR(120) NOT NULL,
            barangay VARCHAR(150) NOT NULL,
            psgc_code VARCHAR(30) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_service_barangay (province, city_municipality, barangay),
            KEY idx_service_barangays_city (province, city_municipality)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function service_barangays_count(mysqli $conn): int
{
    service_barangays_ensure_table($conn);
    $result = $conn->query('SELECT COUNT(*) AS total FROM service_barangays');
    return $result ? (int)($result->fetch_assoc()['total'] ?? 0) : 0;
}

function service_barangays_import_from_reference(mysqli $conn): int
{
    service_barangays_ensure_table($conn);

    $basePath = service_area_reference_csv_path();
    $barangayPath = $basePath . '/refbrgy.csv';
    if (!is_file($barangayPath) || !is_readable($barangayPath)) {
        return 0;
    }

    $luzonRegionCodes = service_area_luzon_region_codes();
    $provinceByCode = [];
    $cityByCode = [];

    foreach (service_area_read_csv($basePath . '/refprovince.csv') as $province) {
        $regCode = (string)($province['regCode'] ?? '');
        $provCode = (string)($province['provCode'] ?? '');
        if (in_array($regCode, $luzonRegionCodes, true) && $provCode !== '') {
            $provinceByCode[$provCode] = service_area_title_case((string)($province['provDesc'] ?? ''));
        }
    }

    foreach (service_area_read_csv($basePath . '/refcitymun.csv') as $city) {
        $regCode = (string)($city['regDesc'] ?? '');
        $provCode = (string)($city['provCode'] ?? '');
        $cityCode = (string)($city['citymunCode'] ?? '');
        if (in_array($regCode, $luzonRegionCodes, true) && isset($provinceByCode[$provCode]) && $cityCode !== '') {
            $cityByCode[$cityCode] = [
                'province' => $provinceByCode[$provCode],
                'city' => service_area_title_case((string)($city['citymunDesc'] ?? '')),
            ];
        }
    }

    $stmt = $conn->prepare(
        'INSERT IGNORE INTO service_barangays (province, city_municipality, barangay, psgc_code)
         VALUES (?, ?, ?, ?)'
    );
    if (!$stmt) {
        return 0;
    }

    $imported = 0;
    foreach (service_area_read_csv($barangayPath) as $barangay) {
        $regCode = (string)($barangay['regCode'] ?? '');
        $cityCode = (string)($barangay['citymunCode'] ?? '');
        if (($regCode !== '' && !in_array($regCode, $luzonRegionCodes, true)) || !isset($cityByCode[$cityCode])) {
            continue;
        }

        $province = $cityByCode[$cityCode]['province'];
        $city = $cityByCode[$cityCode]['city'];
        $barangayName = service_barangay_display_name((string)($barangay['brgyDesc'] ?? $barangay['barangay'] ?? ''));
        $psgcCode = (string)($barangay['psgcCode'] ?? $barangay['brgyCode'] ?? '');
        if ($province === '' || $city === '' || $barangayName === '') {
            continue;
        }

        $stmt->bind_param('ssss', $province, $city, $barangayName, $psgcCode);
        $stmt->execute();
        $imported += $stmt->affected_rows > 0 ? 1 : 0;
    }

    return $imported;
}

function service_barangays_grouped(mysqli $conn): array
{
    service_barangays_ensure_table($conn);
    if (service_barangays_count($conn) === 0) {
        service_barangays_import_from_reference($conn);
    }

    $rows = [];
    $result = $conn->query(
        'SELECT province, city_municipality, barangay
         FROM service_barangays
         ORDER BY province ASC, city_municipality ASC, barangay ASC'
    );

    if (!$result) {
        return $rows;
    }

    while ($row = $result->fetch_assoc()) {
        $province = (string)$row['province'];
        $city = (string)$row['city_municipality'];
        $rows[$province][$city][] = service_barangay_display_name((string)$row['barangay']);
    }

    foreach ($rows as $province => $cities) {
        foreach ($cities as $city => $barangays) {
            $barangays = array_values(array_unique($barangays));
            sort($barangays, SORT_NATURAL | SORT_FLAG_CASE);
            $rows[$province][$city] = $barangays;
        }
    }

    return $rows;
}

function service_barangay_is_allowed(mysqli $conn, string $province, string $city, string $barangay): bool
{
    service_barangays_ensure_table($conn);
    $storageBarangay = service_barangay_storage_name($barangay);

    $stmt = $conn->prepare(
        'SELECT 1 FROM service_barangays
         WHERE province = ? AND city_municipality = ?
         AND (barangay = ? OR barangay = ?)
         LIMIT 1'
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ssss', $province, $city, $barangay, $storageBarangay);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_assoc();
}

function service_barangay_city_has_data(mysqli $conn, string $province, string $city): bool
{
    service_barangays_ensure_table($conn);

    $stmt = $conn->prepare(
        'SELECT 1 FROM service_barangays
         WHERE province = ? AND city_municipality = ?
         LIMIT 1'
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $province, $city);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_assoc();
}
