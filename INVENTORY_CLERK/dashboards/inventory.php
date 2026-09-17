<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/asset_unit_helpers.php';
require_once __DIR__ . '/../includes/page_shell.php';

require_role('inventory_clerk');

ensure_asset_unit_tracking_schema($conn);
$statusFilter = trim((string)($_GET['status'] ?? ''));
$allowedInventoryFilters = ['available', 'low-stock', 'out-of-stock', 'attention'];
if (!in_array($statusFilter, $allowedInventoryFilters, true)) {
    $statusFilter = '';
}

$inventoryItems = [];
$inventoryQuery = "SELECT
        i.id,
        i.asset_id,
        i.quantity,
        i.min_stock,
        i.status,
        a.asset_name,
        COALESCE(NULLIF(a.asset_category, ''), NULLIF(a.asset_type, ''), 'Not set') AS asset_category,
        NULLIF(a.criticality, '') AS criticality,
        COALESCE(unit_totals.total_units, 0) AS total_unit_instances,
        COALESCE(unit_totals.available_units, 0) AS available_unit_instances,
        COALESCE(unit_totals.deployed_units, 0) AS deployed_unit_instances,
        COALESCE(unit_totals.maintenance_units, 0) AS maintenance_unit_instances
     FROM inventory i
     INNER JOIN assets a ON a.id = i.asset_id
     LEFT JOIN (
        SELECT
            inventory_id,
            COUNT(*) AS total_units,
            SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) AS available_units,
            SUM(CASE WHEN status = 'deployed' THEN 1 ELSE 0 END) AS deployed_units,
            SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) AS maintenance_units
        FROM asset_units
        WHERE status <> 'archived'
        GROUP BY inventory_id
     ) unit_totals ON unit_totals.inventory_id = i.id";
$whereSql = '';
if ($statusFilter === 'attention') {
    $whereSql = " WHERE i.status IN ('low-stock', 'out-of-stock')";
} elseif ($statusFilter !== '') {
    $escapedStatus = $conn->real_escape_string($statusFilter);
    $whereSql = " WHERE i.status = '{$escapedStatus}'";
}
$inventoryQuery .= $whereSql . ' ORDER BY a.asset_name ASC, i.id ASC';
$inventoryResult = $conn->query($inventoryQuery);
if ($inventoryResult) {
    $inventoryItems = $inventoryResult->fetch_all(MYSQLI_ASSOC);
}

$assetTypes = [];
$totalUnits = 0;
$availableUnits = 0;
$deployedUnits = 0;
$maintenanceUnits = 0;

foreach ($inventoryItems as $item) {
    $assetTypes[(int)$item['asset_id']] = true;
    $hasPhysicalUnits = (int)($item['total_unit_instances'] ?? 0) > 0;
    $totalUnits += $hasPhysicalUnits ? (int)$item['total_unit_instances'] : (int)$item['quantity'];
    $availableUnits += $hasPhysicalUnits ? (int)$item['available_unit_instances'] : (int)$item['quantity'];
    $deployedUnits += $hasPhysicalUnits ? (int)$item['deployed_unit_instances'] : 0;
    $maintenanceUnits += $hasPhysicalUnits ? (int)$item['maintenance_unit_instances'] : 0;
}
inventory_clerk_render_page(
    'Inventory Management',
    function () use ($assetTypes, $totalUnits, $availableUnits, $deployedUnits, $maintenanceUnits, $inventoryItems, $statusFilter): void {
?>
        <div class="page-stack">
        <section class="form-panel">
            <h1 class="section-title-inline">Inventory Management</h1>
            <section class="metrics-grid">
                <div class="metric-card">
                    <span>Asset Types</span>
                    <strong><?php echo count($assetTypes); ?></strong>
                </div>
                <div class="metric-card">
                    <span>Total Units</span>
                    <strong><?php echo $totalUnits; ?></strong>
                </div>
                <div class="metric-card">
                    <span>Available</span>
                    <strong><?php echo $availableUnits; ?></strong>
                </div>
                <div class="metric-card">
                    <span>Deployed / In Use</span>
                    <strong><?php echo $deployedUnits; ?></strong>
                </div>
                <div class="metric-card">
                    <span>Maintenance</span>
                    <strong><?php echo $maintenanceUnits; ?></strong>
                </div>
            </section>
        </section>

        <section class="form-panel">
            <h1 class="section-title-inline">Inventory Items</h1>
            <div class="dashboard-actions">
                <a href="/codesamplecaps/INVENTORY_CLERK/dashboards/inventory.php" class="action-chip<?php echo $statusFilter === '' ? ' active-chip' : ''; ?>">All</a>
                <a href="/codesamplecaps/INVENTORY_CLERK/dashboards/inventory.php?status=attention" class="action-chip<?php echo $statusFilter === 'attention' ? ' active-chip' : ''; ?>">Attention</a>
                <a href="/codesamplecaps/INVENTORY_CLERK/dashboards/inventory.php?status=low-stock" class="action-chip<?php echo $statusFilter === 'low-stock' ? ' active-chip' : ''; ?>">Low Stock</a>
                <a href="/codesamplecaps/INVENTORY_CLERK/dashboards/inventory.php?status=out-of-stock" class="action-chip<?php echo $statusFilter === 'out-of-stock' ? ' active-chip' : ''; ?>">Out of Stock</a>
                <a href="/codesamplecaps/INVENTORY_CLERK/dashboards/inventory.php?status=available" class="action-chip<?php echo $statusFilter === 'available' ? ' active-chip' : ''; ?>">Available</a>
            </div>

                <?php if (empty($inventoryItems)): ?>
                    <div class="empty-state">No inventory records yet.</div>
                <?php else: ?>
                    <div class="projects-grid">
                        <?php foreach ($inventoryItems as $item): ?>
                            <?php
                            $hasPhysicalUnits = (int)($item['total_unit_instances'] ?? 0) > 0;
                            $totalUnitCount = $hasPhysicalUnits
                                ? (int)$item['total_unit_instances']
                                : (int)$item['quantity'];
                            $availableUnitCount = $hasPhysicalUnits
                                ? (int)$item['available_unit_instances']
                                : (int)$item['quantity'];
                            $deployedUnitCount = $hasPhysicalUnits ? (int)$item['deployed_unit_instances'] : 0;
                            $maintenanceUnitCount = $hasPhysicalUnits ? (int)$item['maintenance_unit_instances'] : 0;
                            $statusLabel = ucwords(str_replace('-', ' ', (string)$item['status']));
                            ?>
                            <article class="project-card asset-summary-card">
                                <div class="asset-summary-card__header">
                                    <div>
                                        <h3><?php echo htmlspecialchars($item['asset_name']); ?></h3>
                                        <p class="asset-summary-card__category"><?php echo htmlspecialchars((string)$item['asset_category']); ?></p>
                                    </div>
                                    <span class="status-pill status-<?php echo htmlspecialchars($item['status']); ?>">
                                        <?php echo htmlspecialchars($statusLabel); ?>
                                    </span>
                                </div>

                                <dl class="asset-summary-card__details">
                                    <div><dt>Criticality</dt><dd><?php echo htmlspecialchars((string)($item['criticality'] ?: 'Not set')); ?></dd></div>
                                    <div><dt>Total Units</dt><dd><?php echo $totalUnitCount; ?></dd></div>
                                    <div><dt>Available</dt><dd><?php echo $availableUnitCount; ?></dd></div>
                                    <div><dt>Deployed / In Use</dt><dd><?php echo $deployedUnitCount; ?></dd></div>
                                    <div><dt>Maintenance</dt><dd><?php echo $maintenanceUnitCount; ?></dd></div>
                                    <div><dt>Minimum Available</dt><dd><?php echo $item['min_stock'] !== null ? (int)$item['min_stock'] : 'Not set'; ?></dd></div>
                                </dl>

                                <div class="asset-summary-card__actions" aria-label="Asset actions">
                                    <a href="/codesamplecaps/INVENTORY_CLERK/dashboards/stock_in.php" class="btn-secondary">Stock In</a>
                                    <a href="/codesamplecaps/INVENTORY_CLERK/dashboards/stock_out.php" class="btn-secondary">Stock Out</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
<?php
    },
    ['/codesamplecaps/INVENTORY_CLERK/css/inventory.css'],
    'inventory-page',
    ['/codesamplecaps/INVENTORY_CLERK/js/inventory.js']
);
