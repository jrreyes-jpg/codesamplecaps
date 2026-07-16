<?php
require_once __DIR__ . '/config/Config.php';

$config = Config::getInstance();

echo "MAIL_USERNAME: " . $config->get('MAIL_USERNAME') . "\n";
echo "MAIL_PASSWORD: " . $config->get('MAIL_PASSWORD') . "\n";
echo "MAIL_FROM_NAME: " . $config->get('MAIL_FROM_NAME') . "\n";

echo "\n--- Full Mail Config ---\n";
var_dump($config->getMailConfig());
