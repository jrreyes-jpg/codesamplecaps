<?php
// Common Admin auth. Gamitin ito sa lahat ng ADMIN pages para pare-pareho ang role check.
define('AUTH_REQUIRED_ROLE', 'admin');
require_once __DIR__ . '/../../config/auth_check.php';
