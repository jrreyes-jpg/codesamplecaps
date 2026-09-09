<?php
// Luzon service area helper. Reads PSGC-style CSV files in /brgy.

function service_area_luzon_region_codes(): array
{
    return ['01', '02', '03', '04', '05', '13', '14', '17'];
}

function service_area_region_provinces(): array
{
    return [
        'Metro Manila (NCR)' => ['Metro Manila (NCR)'],
        'Cordillera Administrative Region (CAR)' => ['Abra', 'Apayao', 'Benguet', 'Ifugao', 'Kalinga', 'Mountain Province'],
        'Ilocos Region (Region I)' => ['Ilocos Norte', 'Ilocos Sur', 'La Union', 'Pangasinan'],
        'Cagayan Valley (Region II)' => ['Batanes', 'Cagayan', 'Isabela', 'Nueva Vizcaya', 'Quirino'],
        'Central Luzon (Region III)' => ['Aurora', 'Bataan', 'Bulacan', 'Nueva Ecija', 'Pampanga', 'Tarlac', 'Zambales'],
        'CALABARZON (Region IV-A)' => ['Batangas', 'Cavite', 'Laguna', 'Quezon', 'Rizal'],
        'MIMAROPA (Region IV-B)' => ['Marinduque', 'Occidental Mindoro', 'Oriental Mindoro', 'Palawan', 'Romblon'],
        'Bicol Region (Region V)' => ['Albay', 'Camarines Norte', 'Camarines Sur', 'Catanduanes', 'Masbate', 'Sorsogon'],
    ];
}

function service_area_ncr_cities(): array
{
    return [
        '133900' => 'Manila',
        '133901' => 'Manila',
        '133902' => 'Manila',
        '133903' => 'Manila',
        '133904' => 'Manila',
        '133905' => 'Manila',
        '133906' => 'Manila',
        '133907' => 'Manila',
        '133908' => 'Manila',
        '133909' => 'Manila',
        '133910' => 'Manila',
        '133911' => 'Manila',
        '133912' => 'Manila',
        '133913' => 'Manila',
        '133914' => 'Manila',
        '137401' => 'Mandaluyong',
        '137402' => 'Marikina',
        '137403' => 'Pasig',
        '137404' => 'Quezon City',
        '137405' => 'San Juan',
        '137501' => 'Caloocan',
        '137502' => 'Malabon',
        '137503' => 'Navotas',
        '137504' => 'Valenzuela',
        '137601' => 'Las Piñas',
        '137602' => 'Makati',
        '137603' => 'Muntinlupa',
        '137604' => 'Parañaque',
        '137605' => 'Pasay',
        '137606' => 'Pateros',
        '137607' => 'Taguig',
    ];
}

function service_area_allowed_provinces(): array
{
    $provinces = [];
    foreach (service_area_region_provinces() as $regionProvinces) {
        $provinces = array_merge($provinces, $regionProvinces);
    }

    return array_fill_keys($provinces, true);
}

function service_area_independent_cities(): array
{
    return array_fill_keys([
        'City of Baguio',
        'City of Dagupan',
        'City of Santiago',
        'City of Angeles',
        'City of Olongapo',
        'City of Lucena',
        'City of Puerto Princesa',
        'City of Naga',
    ], true);
}

function service_area_title_case(string $value): string
{
    $value = trim($value);
    $value = function_exists('mb_convert_case')
        ? mb_convert_case(mb_strtolower($value, 'UTF-8'), MB_CASE_TITLE, 'UTF-8')
        : ucwords(strtolower($value));

    $value = str_replace(
        ['Ncr', 'Car', 'Iv-A', 'Iv-B'],
        ['NCR', 'CAR', 'IV-A', 'IV-B'],
        $value
    );

    return str_replace(' Of ', ' of ', $value);
}

function service_area_read_csv(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $handle = fopen($path, 'r');
    if (!$handle) {
        return [];
    }

    $headers = fgetcsv($handle);
    if (!is_array($headers)) {
        fclose($handle);
        return [];
    }

    $rows = [];
    while (($data = fgetcsv($handle)) !== false) {
        $row = [];
        foreach ($headers as $index => $header) {
            $header = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header) ?? (string)$header;
            $row[$header] = $data[$index] ?? '';
        }
        $rows[] = $row;
    }

    fclose($handle);
    return $rows;
}

function service_area_allowed_locations(): array
{
    static $locations = null;
    if ($locations !== null) {
        return $locations;
    }

    $basePath = service_area_reference_csv_path();
    $luzonRegionCodes = service_area_luzon_region_codes();
    $provinceByCode = [];
    $allowedProvinces = service_area_allowed_provinces();
    $locations = [];

    $locations['Metro Manila (NCR)'] = [];

    foreach (service_area_read_csv($basePath . '/refprovince.csv') as $province) {
        $regCode = (string)($province['regCode'] ?? '');
        $provCode = (string)($province['provCode'] ?? '');
        if (!in_array($regCode, $luzonRegionCodes, true) || $provCode === '') {
            continue;
        }

        if ($regCode === '13') {
            continue;
        }

        $provinceName = service_area_title_case((string)($province['provDesc'] ?? ''));
        if (!isset($allowedProvinces[$provinceName])) {
            continue;
        }

        $provinceByCode[$provCode] = $provinceName;
        $locations[$provinceName] = [];
    }

    foreach (service_area_read_csv($basePath . '/refcitymun.csv') as $city) {
        $regCode = (string)($city['regDesc'] ?? '');
        $provCode = (string)($city['provCode'] ?? '');
        $cityName = service_area_title_case((string)($city['citymunDesc'] ?? ''));
        if (!in_array($regCode, $luzonRegionCodes, true)) {
            continue;
        }

        if ($regCode === '13') {
            $provinceName = 'Metro Manila (NCR)';
            $cityName = service_area_ncr_cities()[(string)($city['citymunCode'] ?? '')] ?? '';
        } elseif (isset(service_area_independent_cities()[$cityName])) {
            continue;
        } elseif (isset($provinceByCode[$provCode])) {
            $provinceName = $provinceByCode[$provCode];
        } else {
            continue;
        }

        if ($cityName !== '') {
            $locations[$provinceName][] = $cityName;
        }
    }

    foreach ($locations as $province => $cities) {
        $cities = array_values(array_unique($cities));
        sort($cities, SORT_NATURAL | SORT_FLAG_CASE);
        $locations[$province] = $cities;
    }

    ksort($locations, SORT_NATURAL | SORT_FLAG_CASE);
    return $locations;
}

function service_area_hierarchy(): array
{
    static $hierarchy = null;
    if ($hierarchy !== null) {
        return $hierarchy;
    }

    $hierarchy = [];
    $path = dirname(__DIR__) . '/Location/luzon_hierarchy_barangays.csv';
    foreach (service_area_read_csv($path) as $row) {
        $region = trim((string)($row['region'] ?? ''));
        $province = trim((string)($row['province'] ?? ''));
        $city = trim((string)($row['city_municipality'] ?? ''));
        if ($region === '' || $city === '') {
            continue;
        }

        if ($region === 'National Capital Region (NCR)') {
            $area = 'Metro Manila (NCR)';
        } elseif ($province === '' || isset(service_area_independent_cities()[$city])) {
            $area = 'Independent City';
        } else {
            $area = $province;
        }

        $hierarchy[$region][$area][] = $city;
    }

    foreach ($hierarchy as $region => $areas) {
        foreach ($areas as $area => $cities) {
            $cities = array_values(array_unique($cities));
            sort($cities, SORT_NATURAL | SORT_FLAG_CASE);
            $hierarchy[$region][$area] = $cities;
        }
        uksort($hierarchy[$region], static function (string $left, string $right): int {
            if ($left === 'Independent City') {
                return 1;
            }
            if ($right === 'Independent City') {
                return -1;
            }
            return strnatcasecmp($left, $right);
        });
    }

    return $hierarchy;
}

function service_area_selection_is_allowed(string $region, string $area, string $city): bool
{
    $hierarchy = service_area_hierarchy();
    return isset($hierarchy[$region][$area])
        && in_array($city, $hierarchy[$region][$area], true);
}

function service_area_storage_province(string $region, string $area): string
{
    if ($region === 'National Capital Region (NCR)' || $area === 'Independent City') {
        return '';
    }

    return $area;
}

function service_area_reference_csv_path(): string
{
    $luzonPath = dirname(__DIR__) . '/Location/luzon_psgc_csv';
    if (is_file($luzonPath . '/refprovince.csv')
        && is_file($luzonPath . '/refcitymun.csv')
        && is_file($luzonPath . '/refbrgy.csv')) {
        return $luzonPath;
    }

    $rootPath = dirname(__DIR__) . '/brgy';
    $nestedPath = $rootPath . '/philippines-region-province-citymun-brgy-master/csv';

    return is_file($nestedPath . '/refprovince.csv') ? $nestedPath : $rootPath;
}

function service_area_is_allowed(string $province, string $city): bool
{
    $locations = service_area_allowed_locations();
    return isset($locations[$province]) && in_array($city, $locations[$province], true);
}
