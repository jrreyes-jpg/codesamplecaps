<?php
// Run this script once to provision quotation and project budgeting tables.
// Usage: php scripts/setup_quotation_tables.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/quotation_schema.php';

foreach (quotation_module_required_tables() as $tableName) {
    echo '[TABLE] ' . $tableName . PHP_EOL;
}

$result = quotation_module_ensure_schema($conn);

if (!empty($result['errors'])) {
    foreach ($result['errors'] as $error) {
        echo '[ERROR] ' . $error . PHP_EOL;
    }
}

echo ($result['success'] ? 'Quotation tables are set up.' : 'Quotation setup completed with errors.') . PHP_EOL;
