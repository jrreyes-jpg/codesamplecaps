<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/audit_log.php';
require_once __DIR__ . '/../../config/asset_master_service.php';
require_once __DIR__ . '/../../config/asset_unit_helpers.php';
require_once __DIR__ . '/../includes/page_shell.php';

require_role('inventory_clerk');

ensure_asset_unit_tracking_schema($conn);
$csrfToken = auth_csrf_token('inventory_clerk_add_asset');
$assetCategories = asset_master_fetch_active_categories($conn);
$criticalityChoices = asset_master_criticality_options();
$addAssetValues = [
    'asset_name' => '',
    'asset_category' => '',
    'criticality' => '',
    'min_stock' => '',
    'description' => '',
];
$addAssetErrors = [];
$openAddAssetModal = false;
$assetFlash = $_SESSION['inventory_asset_flash'] ?? null;
unset($_SESSION['inventory_asset_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_asset_master') {
    $openAddAssetModal = true;

    if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'inventory_clerk_add_asset')) {
        $addAssetErrors['general'] = 'Security check failed. Please try again.';
    } else {
        [$addAssetValues, $addAssetErrors] = asset_master_validate_inventory_clerk_input($_POST, $assetCategories);

        if (empty($addAssetErrors) && asset_master_active_name_exists($conn, $addAssetValues['asset_name'])) {
            $addAssetErrors['asset_name'] = 'An active asset with this name already exists.';
        }

        if (empty($addAssetErrors)) {
            try {
                $createdAsset = asset_master_create_zero_quantity_inventory($conn, $addAssetValues);
                audit_log_event(
                    $conn,
                    (int)($_SESSION['user_id'] ?? 0),
                    'create_asset_master',
                    'asset',
                    (int)$createdAsset['asset_id'],
                    null,
                    [
                        'asset_name' => $addAssetValues['asset_name'],
                        'asset_category' => $addAssetValues['asset_category'],
                        'criticality' => $addAssetValues['criticality'],
                        'min_stock' => $addAssetValues['min_stock'],
                        'quantity' => 0,
                        'inventory_id' => (int)$createdAsset['inventory_id'],
                    ]
                );
                $_SESSION['inventory_asset_flash'] = [
                    'type' => 'success',
                    'message' => 'Asset Master added. Add physical units through Stock In.',
                ];
                header('Location: /codesamplecaps/INVENTORY_CLERK/dashboards/inventory.php');
                exit();
            } catch (Throwable $exception) {
                $addAssetErrors['general'] = $exception->getMessage();
            }
        }
    }
}

$inventoryFilter = trim((string)($_GET['filter'] ?? $_GET['status'] ?? 'all'));
$allowedInventoryFilters = ['all', 'available', 'deployed', 'maintenance', 'attention'];
if (!in_array($inventoryFilter, $allowedInventoryFilters, true)) {
    $inventoryFilter = 'all';
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
        COALESCE(unit_totals.maintenance_units, 0) AS maintenance_unit_instances,
        COALESCE(unit_totals.lost_units, 0) AS lost_unit_instances
     FROM inventory i
     INNER JOIN assets a ON a.id = i.asset_id
     LEFT JOIN (
        SELECT
            inventory_id,
            COUNT(*) AS total_units,
            SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) AS available_units,
            SUM(CASE WHEN status = 'deployed' THEN 1 ELSE 0 END) AS deployed_units,
            SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) AS maintenance_units,
            SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) AS lost_units
        FROM asset_units
        WHERE status <> 'archived'
        GROUP BY inventory_id
     ) unit_totals ON unit_totals.inventory_id = i.id
     WHERE a.deleted_at IS NULL
     ORDER BY a.asset_name ASC, i.id ASC";
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
$visibleInventoryItems = array_values(array_filter($inventoryItems, static function (array $item) use ($inventoryFilter): bool {
    if ($inventoryFilter === 'all') {
        return true;
    }

    $hasPhysicalUnits = (int)($item['total_unit_instances'] ?? 0) > 0;
    $available = $hasPhysicalUnits ? (int)$item['available_unit_instances'] : (int)$item['quantity'];
    $deployed = $hasPhysicalUnits ? (int)$item['deployed_unit_instances'] : 0;
    $maintenance = $hasPhysicalUnits ? (int)$item['maintenance_unit_instances'] : 0;
    $lost = $hasPhysicalUnits ? (int)$item['lost_unit_instances'] : 0;

    return match ($inventoryFilter) {
        'available' => $available > 0,
        'deployed' => $deployed > 0,
        'maintenance' => $maintenance > 0,
        'attention' => in_array($item['status'], ['low-stock', 'out-of-stock'], true) || $maintenance > 0 || $lost > 0,
        default => true,
    };
}));
inventory_clerk_render_page(
    'Inventory Management',
    function () use ($assetTypes, $totalUnits, $availableUnits, $deployedUnits, $maintenanceUnits, $visibleInventoryItems, $inventoryFilter, $assetCategories, $criticalityChoices, $csrfToken, $addAssetValues, $addAssetErrors, $openAddAssetModal, $assetFlash): void {
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
            <div class="inventory-page__section-head">
                <h1 class="section-title-inline">Inventory Items</h1>
                <button type="button" class="btn-primary inventory-page__add-asset" data-add-asset-open>+ Add Asset</button>
            </div>
            <div class="dashboard-actions">
                <a href="/codesamplecaps/INVENTORY_CLERK/dashboards/inventory.php?filter=all" class="action-chip<?php echo $inventoryFilter === 'all' ? ' active-chip' : ''; ?>">All</a>
                <a href="/codesamplecaps/INVENTORY_CLERK/dashboards/inventory.php?filter=available" class="action-chip<?php echo $inventoryFilter === 'available' ? ' active-chip' : ''; ?>">Available</a>
                <a href="/codesamplecaps/INVENTORY_CLERK/dashboards/inventory.php?filter=deployed" class="action-chip<?php echo $inventoryFilter === 'deployed' ? ' active-chip' : ''; ?>">Deployed / In Use</a>
                <a href="/codesamplecaps/INVENTORY_CLERK/dashboards/inventory.php?filter=maintenance" class="action-chip<?php echo $inventoryFilter === 'maintenance' ? ' active-chip' : ''; ?>">Maintenance</a>
                <a href="/codesamplecaps/INVENTORY_CLERK/dashboards/inventory.php?filter=attention" class="action-chip<?php echo $inventoryFilter === 'attention' ? ' active-chip' : ''; ?>">Attention</a>
            </div>

            <?php if ($assetFlash): ?>
                <div class="alert <?php echo $assetFlash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>">
                    <?php echo htmlspecialchars((string)$assetFlash['message']); ?>
                </div>
            <?php endif; ?>

            <?php if (empty($visibleInventoryItems)): ?>
                <div class="empty-state">No assets match this filter.</div>
            <?php else: ?>
                <div class="projects-grid">
                    <?php foreach ($visibleInventoryItems as $item): ?>
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

        <div class="inventory-add-asset-modal" data-add-asset-modal<?php echo $openAddAssetModal ? ' data-open="true"' : ''; ?><?php echo $openAddAssetModal ? '' : ' hidden'; ?> role="dialog" aria-modal="true" aria-labelledby="addAssetTitle">
            <div class="inventory-add-asset-modal__backdrop" data-add-asset-close></div>
            <section class="inventory-add-asset-modal__panel">
                <div class="inventory-add-asset-modal__header">
                    <h2 id="addAssetTitle">Add Asset</h2>
                    <button type="button" class="inventory-add-asset-modal__close" aria-label="Close Add Asset" data-add-asset-close>&times;</button>
                </div>

                <?php if (!empty($addAssetErrors['general'])): ?>
                    <div class="alert alert-error"><?php echo htmlspecialchars($addAssetErrors['general']); ?></div>
                <?php endif; ?>

                <form method="POST" data-add-asset-form>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="create_asset_master">
                    <div class="inventory-add-asset-modal__grid">
                        <div class="input-group">
                            <label for="add_asset_name">Asset Name *</label>
                            <input id="add_asset_name" name="asset_name" type="text" maxlength="255" required value="<?php echo htmlspecialchars((string)$addAssetValues['asset_name']); ?>">
                            <span class="inventory-add-asset-modal__error"><?php echo htmlspecialchars((string)($addAssetErrors['asset_name'] ?? '')); ?></span>
                        </div>
                        <div class="input-group">
                            <label for="add_asset_category">Category *</label>
                            <select id="add_asset_category" name="asset_category" required>
                                <option value="">Select category</option>
                                <?php foreach ($assetCategories as $category): ?>
                                    <option value="<?php echo htmlspecialchars((string)$category['category_key']); ?>"<?php echo $addAssetValues['asset_category'] === $category['category_key'] ? ' selected' : ''; ?>><?php echo htmlspecialchars((string)$category['category_label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="inventory-add-asset-modal__error"><?php echo htmlspecialchars((string)($addAssetErrors['asset_category'] ?? '')); ?></span>
                        </div>
                        <div class="input-group">
                            <label for="add_asset_criticality">Criticality *</label>
                            <select id="add_asset_criticality" name="criticality" required>
                                <option value="">Select criticality</option>
                                <?php foreach ($criticalityChoices as $criticalityValue => $criticalityLabel): ?>
                                    <option value="<?php echo htmlspecialchars($criticalityValue); ?>"<?php echo $addAssetValues['criticality'] === $criticalityValue ? ' selected' : ''; ?>><?php echo htmlspecialchars($criticalityLabel); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="inventory-add-asset-modal__error"><?php echo htmlspecialchars((string)($addAssetErrors['criticality'] ?? '')); ?></span>
                        </div>
                        <div class="input-group">
                            <label for="add_asset_min_stock">Minimum Available / Alert Level</label>
                            <input id="add_asset_min_stock" name="min_stock" type="number" min="0" step="1" inputmode="numeric" value="<?php echo $addAssetValues['min_stock'] === null ? '' : htmlspecialchars((string)$addAssetValues['min_stock']); ?>">
                            <span class="inventory-add-asset-modal__error"><?php echo htmlspecialchars((string)($addAssetErrors['min_stock'] ?? '')); ?></span>
                        </div>
                        <div class="input-group inventory-add-asset-modal__description">
                            <label for="add_asset_description">Description / Specification (Optional)</label>
                            <textarea id="add_asset_description" name="description" maxlength="255" rows="3" placeholder="Model, size, or important details"><?php echo htmlspecialchars((string)$addAssetValues['description']); ?></textarea>
                            <span class="inventory-add-asset-modal__error"><?php echo htmlspecialchars((string)($addAssetErrors['description'] ?? '')); ?></span>
                        </div>
                    </div>
                    <div class="inventory-add-asset-modal__actions">
                        <button type="button" class="btn-secondary" data-add-asset-close>Cancel</button>
                        <button type="submit" class="btn-primary" data-add-asset-submit>Add Asset</button>
                    </div>
                </form>
            </section>
        </div>
        </div>
<?php
    },
    ['/codesamplecaps/INVENTORY_CLERK/css/inventory.css'],
    'inventory-page',
    ['/codesamplecaps/INVENTORY_CLERK/js/inventory.js']
);
