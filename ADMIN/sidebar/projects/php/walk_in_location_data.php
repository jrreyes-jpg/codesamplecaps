<?php

require_once __DIR__ . '/../../../../config/auth_middleware.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/service_areas.php';
require_once __DIR__ . '/../../../../config/service_barangays.php';
require_once __DIR__ . '/../../../../config/service_location_dataset.php';

require_role('admin');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, max-age=300');

service_location_dataset_maybe_refresh();

echo json_encode([
    'success' => true,
    'areas' => service_area_hierarchy(),
    'barangays' => service_barangays_hierarchy($conn),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
