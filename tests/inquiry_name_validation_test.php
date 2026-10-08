<?php
require_once dirname(__DIR__) . '/config/inquiry_name_validation.php';

$validNames = [
    'Juan Dela Cruz',
    'José Peña',
    'Maria-Jose Santos',
    'Anne O’Neill',
    'Juan Dela Cruz Jr.',
    "Jose\u{0301} Pen\u{0303}a",
];

foreach ($validNames as $name) {
    if (!inquiry_name_is_valid($name)) {
        throw new RuntimeException('Valid name was rejected: ' . $name);
    }
}

foreach (['', '   ', "... -- ''", '<script>alert(1)</script>', str_repeat('A', 151)] as $name) {
    if (inquiry_name_is_valid($name)) {
        throw new RuntimeException('Invalid name was accepted.');
    }
}

echo "inquiry_name_validation_test: OK\n";
