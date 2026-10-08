<?php
// Gumagamit ito ng local PSGC copy. Kapag may update, ligtas muna itong sinusuri.

function service_location_dataset_settings(): array
{
    $interval = (int)(getenv('PSGC_REFRESH_INTERVAL_SECONDS') ?: 604800);

    return [
        'repository_api' => 'https://api.github.com/repos/bendlikeabamboo/barangay-data-repository/contents',
        'raw_base' => 'https://raw.githubusercontent.com/bendlikeabamboo/barangay-data-repository/main',
        'source_name' => 'bendlikeabamboo/barangay-data-repository (PSA-derived, MIT)',
        'refresh_interval' => max(3600, $interval),
        'connect_timeout' => 3,
        'request_timeout' => 12,
        'maximum_download_bytes' => 12 * 1024 * 1024,
    ];
}

function service_location_dataset_bundled_path(): string
{
    return dirname(__DIR__) . '/Location/luzon_hierarchy_barangays.csv';
}

function service_location_dataset_cache_directory(): string
{
    return dirname(__DIR__) . '/storage/cache/psgc';
}

function service_location_dataset_cache_path(): string
{
    return service_location_dataset_cache_directory() . '/luzon_hierarchy_barangays.csv';
}

function service_location_dataset_metadata_path(): string
{
    return service_location_dataset_cache_directory() . '/metadata.json';
}

function service_location_dataset_bundled_metadata_path(): string
{
    return dirname(__DIR__) . '/Location/luzon_hierarchy_barangays.meta.json';
}

function service_location_dataset_path(): string
{
    $cached = service_location_dataset_cache_path();
    if (service_location_dataset_cache_is_valid()) {
        return $cached;
    }

    return service_location_dataset_bundled_path();
}

function service_location_dataset_cache_is_valid(): bool
{
    $path = service_location_dataset_cache_path();
    if (!service_location_dataset_csv_is_valid($path)) {
        return false;
    }

    $metadata = service_location_dataset_metadata();
    $expectedHash = (string)($metadata['sha256'] ?? '');
    $actualHash = hash_file('sha256', $path);
    return preg_match('/^[a-f0-9]{64}$/', $expectedHash) === 1
        && is_string($actualHash)
        && hash_equals($expectedHash, $actualHash);
}

function service_location_dataset_csv_is_valid(string $path): bool
{
    if (!is_file($path) || !is_readable($path) || filesize($path) < 500000) {
        return false;
    }

    $handle = fopen($path, 'rb');
    if (!$handle) {
        return false;
    }

    $headers = fgetcsv($handle);
    fclose($handle);

    if (isset($headers[0])) {
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]) ?? (string)$headers[0];
    }

    return $headers === [
        'region',
        'province',
        'city_municipality',
        'submunicipality',
        'barangay',
        'region_psgc',
        'province_psgc',
        'city_municipality_psgc',
        'submunicipality_psgc',
        'barangay_psgc',
    ];
}

function service_location_dataset_metadata(): array
{
    $path = service_location_dataset_metadata_path();
    if (!is_file($path) || !is_readable($path)) {
        $path = service_location_dataset_bundled_metadata_path();
    }
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function service_location_dataset_is_stale(array $metadata, int $interval): bool
{
    $checkedAt = strtotime((string)($metadata['last_checked_at'] ?? ''));
    return $checkedAt === false || $checkedAt <= time() - $interval;
}

function service_location_dataset_maybe_refresh(): void
{
    if (filter_var(getenv('PSGC_AUTO_REFRESH') ?: '1', FILTER_VALIDATE_BOOLEAN) === false) {
        return;
    }

    $settings = service_location_dataset_settings();
    $metadata = service_location_dataset_metadata();
    if (!service_location_dataset_is_stale($metadata, (int)$settings['refresh_interval'])) {
        return;
    }

    $cacheDirectory = service_location_dataset_cache_directory();
    if (!is_dir($cacheDirectory) && !@mkdir($cacheDirectory, 0775, true) && !is_dir($cacheDirectory)) {
        return;
    }

    $lock = @fopen($cacheDirectory . '/refresh.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        return;
    }

    try {
        // Baka may naunang request na nakatapos habang naghihintay tayo.
        $metadata = service_location_dataset_metadata();
        if (!service_location_dataset_is_stale($metadata, (int)$settings['refresh_interval'])) {
            return;
        }

        service_location_dataset_refresh($settings, $metadata);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function service_location_dataset_refresh(array $settings, array $previousMetadata = []): bool
{
    $cacheDirectory = service_location_dataset_cache_directory();
    if (!is_dir($cacheDirectory) && !@mkdir($cacheDirectory, 0775, true) && !is_dir($cacheDirectory)) {
        return false;
    }

    $now = gmdate('c');
    $rootJson = service_location_dataset_http_get((string)$settings['repository_api'], $settings, 300000);
    $rootItems = $rootJson !== null ? json_decode($rootJson, true) : null;
    $versions = [];

    if (is_array($rootItems)) {
        foreach ($rootItems as $item) {
            $name = (string)($item['name'] ?? '');
            if (($item['type'] ?? '') === 'dir' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $name)) {
                $versions[] = $name;
            }
        }
    }

    if ($versions === []) {
        service_location_dataset_record_check($previousMetadata, $now, false);
        return false;
    }

    rsort($versions, SORT_STRING);
    $version = $versions[0];
    if (($previousMetadata['dataset_version'] ?? '') === $version
        && service_location_dataset_cache_is_valid()) {
        service_location_dataset_record_check($previousMetadata, $now, true);
        return true;
    }

    $sourceUrl = rtrim((string)$settings['raw_base'], '/')
        . '/' . rawurlencode($version) . '/barangay_flat.json';
    $payload = service_location_dataset_http_get(
        $sourceUrl,
        $settings,
        (int)$settings['maximum_download_bytes']
    );
    $nodes = $payload !== null ? json_decode($payload, true) : null;
    unset($payload);

    if (!is_array($nodes)) {
        service_location_dataset_record_check($previousMetadata, $now, false);
        return false;
    }

    $rows = service_location_dataset_build_rows($nodes);
    unset($nodes);
    if (!service_location_dataset_rows_are_complete($rows)) {
        service_location_dataset_record_check($previousMetadata, $now, false);
        return false;
    }

    $cachePath = service_location_dataset_cache_path();
    $temporaryPath = $cachePath . '.tmp.' . bin2hex(random_bytes(5));
    if (!service_location_dataset_write_csv($temporaryPath, $rows)) {
        @unlink($temporaryPath);
        service_location_dataset_record_check($previousMetadata, $now, false);
        return false;
    }

    unset($rows);
    if (!service_location_dataset_csv_is_valid($temporaryPath)
        || !service_location_dataset_atomic_replace($temporaryPath, $cachePath)) {
        @unlink($temporaryPath);
        service_location_dataset_record_check($previousMetadata, $now, false);
        return false;
    }

    service_location_dataset_write_metadata([
        'source' => (string)$settings['source_name'],
        'source_url' => $sourceUrl,
        'dataset_version' => $version,
        'last_checked_at' => $now,
        'last_successful_refresh_at' => $now,
        'sha256' => (string)hash_file('sha256', $cachePath),
        'status' => 'ok',
    ]);

    return true;
}

function service_location_dataset_http_get(string $url, array $settings, int $maximumBytes): ?string
{
    if (function_exists('curl_init')) {
        $body = '';
        $tooLarge = false;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => (int)$settings['connect_timeout'],
            CURLOPT_TIMEOUT => (int)$settings['request_timeout'],
            CURLOPT_USERAGENT => 'Edge-Automation-PSGC-Updater/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge, $maximumBytes): int {
                if (strlen($body) + strlen($chunk) > $maximumBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $result = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return $result !== false && !$tooLarge && $status >= 200 && $status < 300 ? $body : null;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => (int)$settings['request_timeout'],
            'follow_location' => 1,
            'header' => "User-Agent: Edge-Automation-PSGC-Updater/1.0\r\nAccept: application/vnd.github+json\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $context, 0, $maximumBytes + 1);
    return is_string($body) && strlen($body) <= $maximumBytes ? $body : null;
}

function service_location_dataset_build_rows(array $nodes): array
{
    $byCode = [];
    foreach ($nodes as $node) {
        $code = (string)($node['psgc_id'] ?? '');
        if (preg_match('/^\d{10}$/', $code)) {
            $byCode[$code] = $node;
        }
    }

    $allowedRegions = array_fill_keys(service_area_luzon_region_codes(), true);
    $allowedProvinces = service_area_allowed_provinces();
    $independentCities = service_area_independent_cities();
    $rows = [];

    foreach ($byCode as $barangayCode => $barangay) {
        if (($barangay['type'] ?? '') !== 'barangay' || !isset($allowedRegions[substr($barangayCode, 0, 2)])) {
            continue;
        }

        $path = service_location_dataset_parent_path($barangay, $byCode);
        $region = $path['region'] ?? null;
        $province = $path['province'] ?? null;
        $city = $path['city'] ?? null;
        $submunicipality = $path['submunicipality'] ?? null;
        if (!$region || !$city) {
            continue;
        }

        $regionName = (string)$region['name'];
        $provinceName = (string)($province['name'] ?? '');
        $cityName = service_location_dataset_city_name((string)$city['name']);
        if ($regionName === 'National Capital Region (NCR)') {
            $provinceName = '';
        } elseif ($provinceName !== '' && !isset($allowedProvinces[$provinceName])) {
            continue;
        } elseif ($provinceName === '' && !isset($independentCities[$cityName])) {
            continue;
        }

        $rows[] = [
            $regionName,
            $provinceName,
            $cityName,
            (string)($submunicipality['name'] ?? ''),
            trim((string)$barangay['name']),
            (string)$region['psgc_id'],
            (string)($province['psgc_id'] ?? ''),
            (string)$city['psgc_id'],
            (string)($submunicipality['psgc_id'] ?? ''),
            $barangayCode,
        ];
    }

    usort($rows, static fn(array $left, array $right): int => strnatcasecmp(
        implode('|', array_slice($left, 0, 5)),
        implode('|', array_slice($right, 0, 5))
    ));
    return $rows;
}

function service_location_dataset_parent_path(array $node, array $byCode): array
{
    $path = [];
    $visited = [];
    $current = $node;
    $cityTypes = ['city', 'municipality', 'component_city', 'highly_urbanized_city', 'independent_component_city'];

    for ($depth = 0; $depth < 7; $depth++) {
        $parentCode = (string)($current['parent_psgc_id'] ?? '');
        if ($parentCode === '' || isset($visited[$parentCode]) || !isset($byCode[$parentCode])) {
            break;
        }
        $visited[$parentCode] = true;
        $current = $byCode[$parentCode];
        $type = (string)($current['type'] ?? '');
        if ($type === 'region') {
            $path['region'] = $current;
        } elseif ($type === 'province') {
            $path['province'] = $current;
        } elseif ($type === 'submunicipality') {
            $path['submunicipality'] = $current;
        } elseif (in_array($type, $cityTypes, true)) {
            $path['city'] = $current;
        }
    }

    return $path;
}

function service_location_dataset_city_name(string $name): string
{
    return trim($name);
}

function service_location_dataset_rows_are_complete(array $rows): bool
{
    if (count($rows) < 20000) {
        return false;
    }

    $regions = [];
    $cities = [];
    $barangayCodes = [];
    foreach ($rows as $row) {
        if (count($row) !== 10
            || !preg_match('/^\d{10}$/', (string)$row[5])
            || !preg_match('/^\d{10}$/', (string)$row[7])
            || !preg_match('/^\d{10}$/', (string)$row[9])
            || trim((string)$row[0]) === ''
            || trim((string)$row[2]) === ''
            || trim((string)$row[4]) === ''
            || isset($barangayCodes[(string)$row[9]])) {
            return false;
        }
        $regions[(string)$row[0]] = true;
        $cities[(string)$row[7]] = true;
        $barangayCodes[(string)$row[9]] = true;
    }

    return count($regions) === count(service_area_luzon_region_codes()) && count($cities) >= 650;
}

function service_location_dataset_write_csv(string $path, array $rows): bool
{
    $handle = @fopen($path, 'wb');
    if (!$handle) {
        return false;
    }

    fputcsv($handle, [
        'region', 'province', 'city_municipality', 'submunicipality', 'barangay',
        'region_psgc', 'province_psgc', 'city_municipality_psgc', 'submunicipality_psgc', 'barangay_psgc',
    ]);
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    fflush($handle);
    fclose($handle);
    return true;
}

function service_location_dataset_atomic_replace(string $temporaryPath, string $targetPath): bool
{
    if (@rename($temporaryPath, $targetPath)) {
        return true;
    }

    // Windows cannot always rename over an existing file.
    $backupPath = $targetPath . '.previous';
    if (is_file($targetPath) && !@rename($targetPath, $backupPath)) {
        return false;
    }
    if (@rename($temporaryPath, $targetPath)) {
        @unlink($backupPath);
        return true;
    }
    if (is_file($backupPath)) {
        @rename($backupPath, $targetPath);
    }
    return false;
}

function service_location_dataset_record_check(array $metadata, string $checkedAt, bool $success): void
{
    $metadata['last_checked_at'] = $checkedAt;
    $metadata['status'] = $success ? 'ok' : 'refresh_failed_using_local_fallback';
    service_location_dataset_write_metadata($metadata);
}

function service_location_dataset_write_metadata(array $metadata): void
{
    $path = service_location_dataset_metadata_path();
    $temporaryPath = $path . '.tmp.' . bin2hex(random_bytes(5));
    $json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
        @unlink($temporaryPath);
        return;
    }
    service_location_dataset_atomic_replace($temporaryPath, $path);
}
