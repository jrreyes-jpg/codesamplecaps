<?php
define('AUTH_REQUIRED_ROLE', 'inventory_clerk');
require_once __DIR__ . '/../../config/auth_check.php';

// Inventory Clerk dashboard: diretso sa inventory page para simple ang flow.
header('Location: /codesamplecaps/INVENTORY_CLERK/sidebar/inventory.php');
exit;
