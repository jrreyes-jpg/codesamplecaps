<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';

require_role('super_admin');

function superadmin_render_simple_page(string $title, string $copy): void
{
    // Shared shell ito para hindi paulit-ulit ang HTML ng simple Super Admin pages.
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?> - Super Admin</title>
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
                    <h1 class="dashboard-section-title"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h1>
                    <p class="panel-copy"><?php echo htmlspecialchars($copy, ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
            </div>
        </section>
    </main>
</div>
<script src="../js/super_admin_dashboard.js"></script>
</body>
</html>
    <?php
}
