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
        $provinceName = service_area_title_case((string)($province['provDesc'] ?? ''));
        if (in_array($regCode, $luzonRegionCodes, true)
            && $regCode !== '13'
            && $provCode !== ''
            && isset(service_area_allowed_provinces()[$provinceName])) {
            $provinceByCode[$provCode] = $provinceName;
        }
    }

    foreach (service_area_read_csv($basePath . '/refcitymun.csv') as $city) {
        $regCode = (string)($city['regDesc'] ?? '');
        $provCode = (string)($city['provCode'] ?? '');
        $cityCode = (string)($city['citymunCode'] ?? '');
        if ($regCode === '13' && isset(service_area_ncr_cities()[$cityCode])) {
            $cityByCode[$cityCode] = [
                'province' => 'Metro Manila (NCR)',
                'city' => service_area_ncr_cities()[$cityCode],
            ];
        } elseif (in_array($regCode, $luzonRegionCodes, true) && isset($provinceByCode[$provCode]) && $cityCode !== '') {
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
    static $referenceRows = null;
    if ($referenceRows !== null) {
        return $referenceRows;
    }

    $hierarchyPath = dirname(__DIR__) . '/Location/luzon_hierarchy_barangays.csv';
    if (is_file($hierarchyPath) && is_readable($hierarchyPath)) {
        $referenceRows = [];
        foreach (service_area_read_csv($hierarchyPath) as $row) {
            $region = trim((string)($row['region'] ?? ''));
            $province = trim((string)($row['province'] ?? ''));
            $city = service_area_title_case((string)($row['city_municipality'] ?? ''));
            $barangay = service_barangay_display_name((string)($row['barangay'] ?? ''));

            if ($province === '') {
                $province = $region === 'National Capital Region (NCR)'
                    ? 'Metro Manila (NCR)'
                    : (service_area_huc_provinces()[$city] ?? '');
            } else {
                $province = service_area_title_case($province);
            }

            [$province, $city] = service_barangay_normalize_location($province, $city);
            if (!isset(service_area_allowed_provinces()[$province]) || $city === '' || $barangay === '') {
                continue;
            }

            $referenceRows[$province][$city][] = $barangay;
        }

        foreach ($referenceRows as $province => $cities) {
            foreach ($cities as $city => $barangays) {
                $barangays = array_values(array_unique($barangays));
                sort($barangays, SORT_NATURAL | SORT_FLAG_CASE);
                $referenceRows[$province][$city] = $barangays;
            }
        }

        return $referenceRows;
    }

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
        [$province, $city] = service_barangay_normalize_location(
            (string)$row['province'],
            (string)$row['city_municipality']
        );
        if (!isset(service_area_allowed_provinces()[$province]) || $city === '') {
            continue;
        }
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
    $locations = service_barangays_grouped($conn);
    return isset($locations[$province][$city])
        && in_array($barangay, $locations[$province][$city], true);
}

function service_barangay_city_has_data(mysqli $conn, string $province, string $city): bool
{
    $locations = service_barangays_grouped($conn);
    return isset($locations[$province][$city]) && $locations[$province][$city] !== [];
}

function service_barangay_normalize_location(string $province, string $city): array
{
    $ncrCityAliases = [
        'Tondo I / Ii' => 'Manila',
        'Binondo' => 'Manila',
        'Quiapo' => 'Manila',
        'San Nicolas' => 'Manila',
        'Santa Cruz' => 'Manila',
        'Sampaloc' => 'Manila',
        'San Miguel' => 'Manila',
        'Ermita' => 'Manila',
        'Intramuros' => 'Manila',
        'Malate' => 'Manila',
        'Paco' => 'Manila',
        'Pandacan' => 'Manila',
        'Port Area' => 'Manila',
        'Santa Ana' => 'Manila',
        'City Of Manila' => 'Manila',
        'City of Manila' => 'Manila',
        'City of Caloocan' => 'Caloocan',
        'City of Las Piñas' => 'Las Piñas',
        'City of Makati' => 'Makati',
        'City of Malabon' => 'Malabon',
        'City Of Mandaluyong' => 'Mandaluyong',
        'City of Mandaluyong' => 'Mandaluyong',
        'City Of Marikina' => 'Marikina',
        'City of Marikina' => 'Marikina',
        'City Of Pasig' => 'Pasig',
        'City of Pasig' => 'Pasig',
        'City Of San Juan' => 'San Juan',
        'City of San Juan' => 'San Juan',
        'Caloocan City' => 'Caloocan',
        'City Of Malabon' => 'Malabon',
        'City Of Navotas' => 'Navotas',
        'City of Navotas' => 'Navotas',
        'City Of Valenzuela' => 'Valenzuela',
        'City of Valenzuela' => 'Valenzuela',
        'City Of Las Piñas' => 'Las Piñas',
        'City Of Makati' => 'Makati',
        'City Of Muntinlupa' => 'Muntinlupa',
        'City of Muntinlupa' => 'Muntinlupa',
        'City Of Parañaque' => 'Parañaque',
        'City of Parañaque' => 'Parañaque',
        'Pasay City' => 'Pasay',
        'City of Pasay' => 'Pasay',
        'Taguig City' => 'Taguig',
        'City of Taguig' => 'Taguig',
    ];

    if ($province === 'Metro Manila (NCR)'
        || str_starts_with($province, 'NCR,')
        || $province === 'City Of Manila'
        || $province === 'City of Manila') {
        return ['Metro Manila (NCR)', $ncrCityAliases[$city] ?? $city];
    }

    return [$province, $city];
}
