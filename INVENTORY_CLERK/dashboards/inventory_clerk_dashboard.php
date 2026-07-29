<?php
define('AUTH_REQUIRED_ROLE', 'inventory_clerk');
require_once __DIR__ . '/../../config/auth_check.php';
require_once __DIR__ . '/../../config/database.php';

$metricResult = $conn->query(
    "SELECT
        COUNT(*) AS inventory_items,
        COALESCE(SUM(quantity), 0) AS total_units,
        SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) AS available_items,
        SUM(CASE WHEN status = 'low-stock' THEN 1 ELSE 0 END) AS low_stock_items,
        SUM(CASE WHEN status = 'out-of-stock' THEN 1 ELSE 0 END) AS out_of_stock_items
     FROM inventory"
);
$metrics = $metricResult ? $metricResult->fetch_assoc() : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Clerk Dashboard</title>
    <link rel="stylesheet" href="../css/inventory_clerk_dashboard.css">
    <link rel="stylesheet" href="/codesamplecaps/assets/css/responsive-foundation.css">
    <link rel="icon" type="image/x-icon" href="../../IMAGES/edge.jpg">
</head>
<body>
<div class="container">
    <?php include __DIR__ . '/../sidebar/inventory_clerk_sidebar.php'; ?>
    <main class="main-content">
        <section class="dashboard-panel">
            <div class="panel-heading">
                <div>
                    <h1 class="dashboard-section-title">Inventory Clerk Dashboard</h1>
                    <p class="panel-copy">Monitor stock levels and update inventory records.</p>
                </div>
            </div>
            <section class="metrics-grid">
                <div class="metric-card"><span>Inventory Items</span><strong><?php echo (int)($metrics['inventory_items'] ?? 0); ?></strong></div>
                <div class="metric-card"><span>Total Units</span><strong><?php echo (int)($metrics['total_units'] ?? 0); ?></strong></div>
                <div class="metric-card"><span>Available</span><strong><?php echo (int)($metrics['available_items'] ?? 0); ?></strong></div>
                <div class="metric-card"><span>Low Stock</span><strong><?php echo (int)($metrics['low_stock_items'] ?? 0); ?></strong></div>
                <div class="metric-card"><span>Out of Stock</span><strong><?php echo (int)($metrics['out_of_stock_items'] ?? 0); ?></strong></div>
            </section>
            <div class="dashboard-actions">
                <a class="action-chip active-chip" href="/codesamplecaps/INVENTORY_CLERK/sidebar/inventory.php">Open Inventory</a>
                <a class="action-chip" href="/codesamplecaps/INVENTORY_CLERK/sidebar/inventory.php?status=attention">View Alerts</a>
            </div>
        </section>
    </main>
</div>
<script src="../js/inventory_clerk_dashboard.js"></script>
</body>
</html>
