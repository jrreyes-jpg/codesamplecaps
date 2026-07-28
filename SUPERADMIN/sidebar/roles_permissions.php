<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';

require_role('super_admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roles & Permissions - Super Admin</title>
    <link rel="stylesheet" href="../css/super_admin_dashboard.css">
    <link rel="stylesheet" href="/codesamplecaps/assets/css/responsive-foundation.css">
    <link rel="icon" type="image/x-icon" href="../../IMAGES/edge.jpg">
</head>
<body>
<div class="container">
    <?php include __DIR__ . '/../super_admin_sidebar.php'; ?>
    <main class="main-content">
        <section class="dashboard-panel">
            <div class="panel-heading">
                <div>
                    <h1 class="dashboard-section-title">Roles & Permissions</h1>
                    <p class="panel-copy">Manage role access rules here. This module is ready for permission matrix setup.</p>
                </div>
            </div>
        </section>
    </main>
</div>
<script src="../js/super_admin_dashboard.js"></script>
</body>
</html>
