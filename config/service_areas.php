<?php
// Luzon service area helper. Reads PSGC-style CSV files in /brgy.

function service_area_luzon_region_codes(): array
{
    return ['01', '02', '03', '04', '05', '13', '14', '17'];
}

function service_area_title_case(string $value): string
{
    $value = trim($value);
    $value = function_exists('mb_convert_case')
        ? mb_convert_case(mb_strtolower($value, 'UTF-8'), MB_CASE_TITLE, 'UTF-8')
        : ucwords(strtolower($value));

    return str_replace(
        ['Ncr', 'Car', 'Iv-A', 'Iv-B'],
        ['NCR', 'CAR', 'IV-A', 'IV-B'],
        $value
    );
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
    $locations = [];

    foreach (service_area_read_csv($basePath . '/refprovince.csv') as $province) {
        $regCode = (string)($province['regCode'] ?? '');
        $provCode = (string)($province['provCode'] ?? '');
        if (!in_array($regCode, $luzonRegionCodes, true) || $provCode === '') {
            continue;
        }

        $provinceName = service_area_title_case((string)($province['provDesc'] ?? ''));
        $provinceByCode[$provCode] = $provinceName;
        $locations[$provinceName] = [];
    }

    foreach (service_area_read_csv($basePath . '/refcitymun.csv') as $city) {
        $regCode = (string)($city['regDesc'] ?? '');
        $provCode = (string)($city['provCode'] ?? '');
        if (!in_array($regCode, $luzonRegionCodes, true) || !isset($provinceByCode[$provCode])) {
            continue;
        }

        $provinceName = $provinceByCode[$provCode];
        $cityName = service_area_title_case((string)($city['citymunDesc'] ?? ''));
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

function service_area_reference_csv_path(): string
{
    $rootPath = dirname(__DIR__) . '/brgy';
    $nestedPath = $rootPath . '/philippines-region-province-citymun-brgy-master/csv';

    return is_file($nestedPath . '/refprovince.csv') ? $nestedPath : $rootPath;
}

function service_area_is_allowed(string $province, string $city): bool
{
    $locations = service_area_allowed_locations();
    return isset($locations[$province]) && in_array($city, $locations[$province], true);
}
