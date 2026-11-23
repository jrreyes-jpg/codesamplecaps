<?php
define('AUTH_REQUIRED_ROLE', 'inventory_clerk');
require_once __DIR__ . '/../../config/auth_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/material_delivery_verification.php';
require_once __DIR__ . '/../../config/material_physical_count.php';
require_once __DIR__ . '/../includes/page_shell.php';

$pendingDeliveries = material_delivery_verification_fetch($conn, 'for_verification');
$latestCounts = material_physical_count_fetch_latest($conn);
$waitingForReview = 0;
foreach ($latestCounts as $count) {
    if (material_physical_count_status($count)['needs_review']) {
        $waitingForReview++;
    }
}
$physicalCountsToDo = 0;
$physicalCountResult = $conn->query(
    "SELECT COUNT(*) AS total
     FROM materials
     WHERE status = 'active'
       AND opening_stock_status IN ('pending_count', 'recount')"
);
if ($physicalCountResult instanceof mysqli_result) {
    $physicalCountsToDo = (int)($physicalCountResult->fetch_assoc()['total'] ?? 0);
}

inventory_clerk_render_page('Dashboard', function () use ($pendingDeliveries, $waitingForReview, $physicalCountsToDo): void {
?>
        <div class="shared-page-container dashboard-page">
        <section class="shared-flat-panel dashboard-panel">
            <h1 class="dashboard-section-title">Dashboard</h1>
            <section class="metrics-grid">
                <article class="metric-card metric-card--warning">
                    <span>Deliveries to Count</span>
                    <strong><?php echo count($pendingDeliveries); ?></strong>
                </article>
                <article class="metric-card metric-card--info">
                    <span>Counts Waiting for Admin Review</span>
                    <strong><?php echo $waitingForReview; ?></strong>
                </article>
                <article class="metric-card metric-card--warning">
                    <span>Physical Counts to Do</span>
                    <strong><?php echo $physicalCountsToDo; ?></strong>
                </article>
            </section>
            <div class="dashboard-actions">
                <a class="btn-primary" href="/codesamplecaps/INVENTORY_CLERK/dashboards/delivery_verification.php">Open Delivery Count</a>
                <?php if ($physicalCountsToDo > 0): ?>
                    <a class="btn-secondary" href="/codesamplecaps/INVENTORY_CLERK/dashboards/materials.php">Open Physical Count</a>
                <?php endif; ?>
            </div>
        </section>
        </div>
<?php
}, [
    '/codesamplecaps/SHARED/admin_ui/css/surfaces.css',
    '/codesamplecaps/INVENTORY_CLERK/css/dashboard.css',
], 'shared-page-surface');
