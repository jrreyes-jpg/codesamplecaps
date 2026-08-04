<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/service_areas.php';
require_once __DIR__ . '/../config/service_barangays.php';

service_barangays_ensure_table($conn);
if (service_barangays_count($conn) === 0) {
    $imported = service_barangays_import_from_reference($conn);
    echo 'Imported barangays: ' . $imported . PHP_EOL;
}

$locations = service_area_allowed_locations();
$missing = [];
$totalCities = 0;
$citiesWithBarangays = 0;

foreach ($locations as $province => $cities) {
    foreach ($cities as $city) {
        $totalCities++;
        if (service_barangay_city_has_data($conn, $province, $city)) {
            $citiesWithBarangays++;
            continue;
        }

        $missing[] = $province . ' - ' . $city;
    }
}

echo 'Luzon provinces: ' . count($locations) . PHP_EOL;
echo 'Luzon cities/municipalities: ' . $totalCities . PHP_EOL;
echo 'Cities with barangay data: ' . $citiesWithBarangays . PHP_EOL;
echo 'Cities missing barangay data: ' . count($missing) . PHP_EOL;

if ($missing !== []) {
    echo PHP_EOL . 'Missing barangay data:' . PHP_EOL;
    foreach (array_slice($missing, 0, 80) as $item) {
        echo '- ' . $item . PHP_EOL;
    }

    if (count($missing) > 80) {
        echo '- ...and ' . (count($missing) - 80) . ' more' . PHP_EOL;
    }
}
