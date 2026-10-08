<?php
require_once dirname(__DIR__) . '/config/service_areas.php';
require_once dirname(__DIR__) . '/config/service_location_dataset.php';

function location_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$nodes = [
    ['name' => 'Region III (Central Luzon)', 'type' => 'region', 'psgc_id' => '0300000000', 'parent_psgc_id' => '0000000000'],
    ['name' => 'Bulacan', 'type' => 'province', 'psgc_id' => '0301400000', 'parent_psgc_id' => '0300000000'],
    ['name' => 'Test City', 'type' => 'municipality', 'psgc_id' => '0301401000', 'parent_psgc_id' => '0301400000'],
    ['name' => 'Renamed Barangay', 'type' => 'barangay', 'psgc_id' => '0301401001', 'parent_psgc_id' => '0301401000'],
    ['name' => 'New Barangay', 'type' => 'barangay', 'psgc_id' => '0301401002', 'parent_psgc_id' => '0301401000'],
];

$rows = service_location_dataset_build_rows($nodes);
location_test_assert(count($rows) === 2, 'Controlled rename/new rows were not built.');
location_test_assert($rows[0][4] === 'New Barangay' || $rows[1][4] === 'New Barangay', 'New location is missing.');
location_test_assert($rows[0][4] === 'Renamed Barangay' || $rows[1][4] === 'Renamed Barangay', 'Renamed location is missing.');
location_test_assert($rows[0][9] === '0301401002' || $rows[1][9] === '0301401002', 'PSGC code was not kept as a string.');
location_test_assert(!service_location_dataset_rows_are_complete($rows), 'Incomplete data must not replace the cache.');
location_test_assert(service_location_dataset_csv_is_valid(service_location_dataset_bundled_path()), 'Bundled fallback must be valid.');

if (in_array('--live', $argv, true)) {
    $settings = service_location_dataset_settings();
    $root = service_location_dataset_http_get((string)$settings['repository_api'], $settings, 300000);
    location_test_assert($root !== null, 'GitHub release check failed.');
    $metadata = in_array('--force-live', $argv, true) ? [] : service_location_dataset_metadata();
    location_test_assert(service_location_dataset_refresh($settings, $metadata), 'Live refresh failed.');
    location_test_assert(service_location_dataset_csv_is_valid(service_location_dataset_cache_path()), 'Live cache is invalid.');
    location_test_assert(service_location_dataset_cache_is_valid(), 'Live cache checksum is invalid.');
}

if (in_array('--failure', $argv, true)) {
    $beforeMetadata = service_location_dataset_metadata();
    $beforeHash = hash_file('sha256', service_location_dataset_cache_path());
    $failedSettings = service_location_dataset_settings();
    $failedSettings['repository_api'] = 'http://127.0.0.1:9/unavailable';
    $failedSettings['connect_timeout'] = 1;
    $failedSettings['request_timeout'] = 1;
    location_test_assert(
        !service_location_dataset_refresh($failedSettings, $beforeMetadata),
        'Invalid network response must fail safely.'
    );
    location_test_assert(
        hash_file('sha256', service_location_dataset_cache_path()) === $beforeHash,
        'Failed refresh changed the last valid cache.'
    );
    location_test_assert(service_location_dataset_cache_is_valid(), 'Failed refresh broke the local fallback.');
    service_location_dataset_write_metadata($beforeMetadata);
}

if (in_array('--integration', $argv, true)) {
    require_once dirname(__DIR__) . '/config/database.php';
    require_once dirname(__DIR__) . '/config/service_barangays.php';
    $hierarchy = service_area_hierarchy();
    $barangays = service_barangays_hierarchy($conn);
    location_test_assert(count($hierarchy) === 8, 'The approved eight Luzon regions are required.');
    location_test_assert(
        service_area_selection_is_allowed('Region III (Central Luzon)', 'Bulacan', 'City of Malolos'),
        'Known city relationship failed.'
    );
    location_test_assert(
        !service_area_selection_is_allowed('Region III (Central Luzon)', 'Pampanga', 'City of Malolos'),
        'Mismatched parent relationship was accepted.'
    );
    $knownBarangays = $barangays['Region III (Central Luzon)']['Bulacan']['City of Malolos'] ?? [];
    location_test_assert($knownBarangays !== [], 'Known barangays were not loaded.');
    location_test_assert(
        service_barangay_selection_is_allowed(
            $conn,
            'Region III (Central Luzon)',
            'Bulacan',
            'City of Malolos',
            (string)$knownBarangays[0]
        ),
        'Known barangay relationship failed.'
    );
    location_test_assert(
        !service_barangay_selection_is_allowed(
            $conn,
            'Region III (Central Luzon)',
            'Pampanga',
            'City of Malolos',
            (string)$knownBarangays[0]
        ),
        'Mismatched barangay relationship was accepted.'
    );
}

echo "service_location_dataset_test: OK\n";
