<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/audit_log.php';
require_once __DIR__ . '/../../config/asset_master_service.php';
require_once __DIR__ . '/../../config/asset_unit_helpers.php';
require_once __DIR__ . '/../includes/asset_stock_in_service.php';
require_once __DIR__ . '/../includes/page_shell.php';

require_role('inventory_clerk');

ensure_asset_unit_tracking_schema($conn);
inventory_clerk_ensure_stock_movement_table($conn);
$csrfToken = auth_csrf_token('inventory_clerk_add_asset');
$stockInCsrfToken = auth_csrf_token('inventory_clerk_asset_stock_in');
$stockInRequestToken = inventory_clerk_stock_in_issue_once_token();
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
$stockInFlash = $_SESSION['inventory_asset_stock_in_flash'] ?? null;
unset($_SESSION['inventory_asset_stock_in_flash']);
$stockInValues = [
    'inventory_id' => '',
    'asset_name' => '',
    'current_units' => 0,
    'quantity' => '',
    'remarks' => '',
];
$stockInErrors = [];
$openStockInModal = false;

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'stock_in_asset_card') {
    $openStockInModal = true;
    $stockInValues = [
        'inventory_id' => (int)($_POST['inventory_id'] ?? 0),
        'asset_name' => trim((string)($_POST['asset_name'] ?? '')),
        'current_units' => max(0, (int)($_POST['current_units'] ?? 0)),
        'quantity' => trim((string)($_POST['quantity'] ?? '')),
        'remarks' => trim((string)($_POST['remarks'] ?? '')),
    ];

    if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'inventory_clerk_asset_stock_in')) {
        $stockInErrors['general'] = 'Security check failed. Please try again.';
    } elseif (!inventory_clerk_stock_in_consume_once_token($_POST['stock_in_request_token'] ?? null)) {
        $stockInErrors['general'] = 'This Stock In form was already sent. Please try again.';
    } else {
        $result = inventory_clerk_stock_in_asset(
            $conn,
            (int)$stockInValues['inventory_id'],
            $stockInValues['quantity'],
            $stockInValues['remarks'],
            (int)($_SESSION['user_id'] ?? 0)
        );

        if ($result['success']) {
            $_SESSION['inventory_asset_stock_in_flash'] = [
                'title' => 'Stock In complete',
                'message' => $result['quantity'] . ' ' . $result['asset_name'] . ' unit' . ($result['quantity'] === 1 ? '' : 's') . ' stocked in successfully.',
            ];
            header('Location: /codesamplecaps/INVENTORY_CLERK/dashboards/inventory.php');
            exit();
        }

        $stockInErrors[(string)($result['field'] ?? 'general')] = (string)$result['message'];
    }
}

$inventoryFilter = trim((string)($_GET['filter'] ?? $_GET['status'] ?? 'all'));
$allowedInventoryFilters = ['all', 'available', 'deployed', 'maintenance', 'attention'];
if (!in_array($inventoryFilter, $allowedInventoryFilters, true)) {
    $inventoryFilter = 'all';
}

$inventorySearch = trim((string)($_GET['search'] ?? ''));
$inventorySearch = substr($inventorySearch, 0, 100);
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$assetsPerPage = 8;
$unitTotalsJoin = "
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
     ) unit_totals ON unit_totals.inventory_id = i.id";
$availableSql = "(CASE WHEN COALESCE(unit_totals.total_units, 0) > 0 THEN COALESCE(unit_totals.available_units, 0) ELSE i.quantity END)";

$whereParts = ['a.deleted_at IS NULL'];
if ($inventorySearch !== '') {
    $whereParts[] = "(a.asset_name LIKE ? OR COALESCE(NULLIF(a.asset_category, ''), NULLIF(a.asset_type, ''), '') LIKE ?)";
}

switch ($inventoryFilter) {
    case 'available':
        $whereParts[] = $availableSql . ' > 0';
        break;
    case 'deployed':
        $whereParts[] = 'COALESCE(unit_totals.deployed_units, 0) > 0';
        break;
    case 'maintenance':
        $whereParts[] = 'COALESCE(unit_totals.maintenance_units, 0) > 0';
        break;
    case 'attention':
        $whereParts[] = "(
            (COALESCE(unit_totals.total_units, 0) = 0 AND i.quantity = 0)
            OR COALESCE(unit_totals.maintenance_units, 0) > 0
            OR COALESCE(unit_totals.lost_units, 0) > 0
            OR (COALESCE(unit_totals.total_units, 0) > 0
                AND COALESCE(unit_totals.available_units, 0) = 0
                AND COALESCE(unit_totals.deployed_units, 0) = 0
                AND COALESCE(unit_totals.maintenance_units, 0) = 0)
            OR ($availableSql > 0 AND i.min_stock IS NOT NULL AND $availableSql <= i.min_stock)
        )";
        break;
}

$whereSql = implode(' AND ', $whereParts);
$summaryQuery = "SELECT
        COUNT(DISTINCT a.id) AS asset_types,
        COALESCE(SUM(CASE WHEN COALESCE(unit_totals.total_units, 0) > 0 THEN unit_totals.total_units ELSE i.quantity END), 0) AS total_units,
        COALESCE(SUM($availableSql), 0) AS available_units,
        COALESCE(SUM(CASE WHEN COALESCE(unit_totals.total_units, 0) > 0 THEN unit_totals.deployed_units ELSE 0 END), 0) AS deployed_units,
        COALESCE(SUM(CASE WHEN COALESCE(unit_totals.total_units, 0) > 0 THEN unit_totals.maintenance_units ELSE 0 END), 0) AS maintenance_units
     FROM inventory i
     INNER JOIN assets a ON a.id = i.asset_id
     $unitTotalsJoin
     WHERE a.deleted_at IS NULL";
$summaryRow = $conn->query($summaryQuery)?->fetch_assoc() ?: [];
$assetTypeCount = (int)($summaryRow['asset_types'] ?? 0);
$totalUnits = (int)($summaryRow['total_units'] ?? 0);
$availableUnits = (int)($summaryRow['available_units'] ?? 0);
$deployedUnits = (int)($summaryRow['deployed_units'] ?? 0);
$maintenanceUnits = (int)($summaryRow['maintenance_units'] ?? 0);

$countQuery = "SELECT COUNT(*) AS total
     FROM inventory i
     INNER JOIN assets a ON a.id = i.asset_id
     $unitTotalsJoin
     WHERE $whereSql";
$countStatement = $conn->prepare($countQuery);
if ($inventorySearch !== '') {
    $searchLike = '%' . $inventorySearch . '%';
    $countStatement?->bind_param('ss', $searchLike, $searchLike);
}
$countStatement?->execute();
$countResult = $countStatement?->get_result();
$countRow = $countResult instanceof mysqli_result ? ($countResult->fetch_assoc() ?: []) : [];
$totalAssets = (int)($countRow['total'] ?? 0);
$countStatement?->close();
$totalPages = max(1, (int)ceil($totalAssets / $assetsPerPage));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $assetsPerPage;

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
     $unitTotalsJoin
     WHERE $whereSql
     ORDER BY a.asset_name ASC, i.id ASC
     LIMIT ? OFFSET ?";
$inventoryStatement = $conn->prepare($inventoryQuery);
if ($inventorySearch !== '') {
    $inventoryStatement?->bind_param('ssii', $searchLike, $searchLike, $assetsPerPage, $offset);
} else {
    $inventoryStatement?->bind_param('ii', $assetsPerPage, $offset);
}
$inventoryStatement?->execute();
$inventoryResult = $inventoryStatement?->get_result();
$inventoryItems = $inventoryResult instanceof mysqli_result ? $inventoryResult->fetch_all(MYSQLI_ASSOC) : [];
$inventoryStatement?->close();

$unitsByInventory = [];
$visibleInventoryIds = array_values(array_filter(array_map(static fn(array $item): int => (int)$item['id'], $inventoryItems)));
if ($visibleInventoryIds !== []) {
    $unitIdList = implode(',', $visibleInventoryIds);
    $unitResult = $conn->query(
        "SELECT inventory_id, unit_code, qr_code_value, status
         FROM asset_units
         WHERE inventory_id IN ($unitIdList)
         ORDER BY inventory_id ASC, unit_number ASC"
    );
    foreach ($unitResult?->fetch_all(MYSQLI_ASSOC) ?: [] as $unit) {
        $unitsByInventory[(int)$unit['inventory_id']][] = $unit;
    }
}

$qrLibraryReady = false;
$qrAutoloadPath = __DIR__ . '/../../vendor/autoload.php';
if (is_file($qrAutoloadPath)) {
    require_once $qrAutoloadPath;
    $qrLibraryReady = class_exists('chillerlan\\QRCode\\QRCode') && class_exists('chillerlan\\QRCode\\QROptions');
}
$renderUnitQrImage = static function (string $value) use ($qrLibraryReady): string {
    if (!$qrLibraryReady || $value === '') {
        return '';
    }

    $optionsClass = 'chillerlan\\QRCode\\QROptions';
    $qrClass = 'chillerlan\\QRCode\\QRCode';
    return (new $qrClass(new $optionsClass(['outputType' => 'png', 'scale' => 6])))->render($value);
};
foreach ($unitsByInventory as &$assetUnits) {
    foreach ($assetUnits as &$unit) {
        $unit['qr_image'] = $renderUnitQrImage((string)($unit['qr_code_value'] ?? ''));
    }
    unset($unit);
}
unset($assetUnits);
$visibleInventoryItems = $inventoryItems;
$showingStart = $totalAssets === 0 ? 0 : $offset + 1;
$showingEnd = min($offset + count($visibleInventoryItems), $totalAssets);
$paginationNumbers = array_values(array_unique(array_filter([
    1,
    2,
    $currentPage - 1,
    $currentPage,
    $currentPage + 1,
    $totalPages - 1,
    $totalPages,
], static fn(int $pageNumber): bool => $pageNumber >= 1 && $pageNumber <= $totalPages)));
sort($paginationNumbers);
$inventoryPageUrl = static function (array $overrides = []) use ($inventoryFilter, $inventorySearch): string {
    $params = ['filter' => $inventoryFilter];
    if ($inventorySearch !== '') {
        $params['search'] = $inventorySearch;
    }
    $params = array_merge($params, $overrides);
    unset($params['page']);
    if (array_key_exists('page', $overrides)) {
        $params['page'] = max(1, (int)$overrides['page']);
    }

    return '/codesamplecaps/INVENTORY_CLERK/dashboards/inventory.php?' . http_build_query($params);
};
inventory_clerk_render_page(
    'Inventory Management',
    function () use ($assetTypeCount, $totalUnits, $availableUnits, $deployedUnits, $maintenanceUnits, $visibleInventoryItems, $unitsByInventory, $inventoryFilter, $inventorySearch, $currentPage, $totalPages, $totalAssets, $showingStart, $showingEnd, $paginationNumbers, $inventoryPageUrl, $assetCategories, $criticalityChoices, $csrfToken, $stockInCsrfToken, $stockInRequestToken, $addAssetValues, $addAssetErrors, $openAddAssetModal, $assetFlash, $stockInFlash, $stockInValues, $stockInErrors, $openStockInModal): void {
?>
        <div class="page-stack">
        <section class="form-panel">
            <h1 class="section-title-inline">Inventory Management</h1>
            <section class="metrics-grid">
                <div class="metric-card inventory-metric-card inventory-metric-card--asset-types">
                    <span>Asset Types</span>
                    <strong><?php echo $assetTypeCount; ?></strong>
                </div>
                <div class="metric-card inventory-metric-card inventory-metric-card--total-units">
                    <span>Total Units</span>
                    <strong><?php echo $totalUnits; ?></strong>
                </div>
                <div class="metric-card inventory-metric-card inventory-metric-card--available">
                    <span>Available</span>
                    <strong><?php echo $availableUnits; ?></strong>
                </div>
                <div class="metric-card inventory-metric-card inventory-metric-card--deployed">
                    <span>Deployed / In Use</span>
                    <strong><?php echo $deployedUnits; ?></strong>
                </div>
                <div class="metric-card inventory-metric-card inventory-metric-card--maintenance">
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
                <a href="<?php echo htmlspecialchars($inventoryPageUrl(['filter' => 'all'])); ?>" class="action-chip inventory-filter inventory-filter--all<?php echo $inventoryFilter === 'all' ? ' active-chip' : ''; ?>">All</a>
                <a href="<?php echo htmlspecialchars($inventoryPageUrl(['filter' => 'available'])); ?>" class="action-chip inventory-filter inventory-filter--available<?php echo $inventoryFilter === 'available' ? ' active-chip' : ''; ?>">Available</a>
                <a href="<?php echo htmlspecialchars($inventoryPageUrl(['filter' => 'deployed'])); ?>" class="action-chip inventory-filter inventory-filter--deployed<?php echo $inventoryFilter === 'deployed' ? ' active-chip' : ''; ?>">Deployed / In Use</a>
                <a href="<?php echo htmlspecialchars($inventoryPageUrl(['filter' => 'maintenance'])); ?>" class="action-chip inventory-filter inventory-filter--maintenance<?php echo $inventoryFilter === 'maintenance' ? ' active-chip' : ''; ?>">Maintenance</a>
                <a href="<?php echo htmlspecialchars($inventoryPageUrl(['filter' => 'attention'])); ?>" class="action-chip inventory-filter inventory-filter--attention<?php echo $inventoryFilter === 'attention' ? ' active-chip' : ''; ?>">Attention</a>
            </div>

            <form method="GET" class="inventory-page__search" role="search">
                <input type="hidden" name="filter" value="<?php echo htmlspecialchars($inventoryFilter); ?>">
                <label class="sr-only" for="inventory_asset_search">Search assets</label>
                <input id="inventory_asset_search" type="search" name="search" maxlength="100" value="<?php echo htmlspecialchars($inventorySearch); ?>" placeholder="Search assets...">
                <button type="submit" class="btn-secondary">Search</button>
            </form>

            <?php if ($assetFlash): ?>
                <div class="alert <?php echo $assetFlash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>">
                    <?php echo htmlspecialchars((string)$assetFlash['message']); ?>
                </div>
            <?php endif; ?>

            <?php if ($stockInFlash): ?>
                <div class="inventory-stock-in-toast" data-inventory-stock-in-toast role="status">
                    <div>
                        <strong><?php echo htmlspecialchars((string)$stockInFlash['title']); ?></strong>
                        <span><?php echo htmlspecialchars((string)$stockInFlash['message']); ?></span>
                    </div>
                    <button type="button" aria-label="Close Stock In message" data-inventory-stock-in-toast-close>&times;</button>
                </div>
            <?php endif; ?>

            <?php if (empty($visibleInventoryItems)): ?>
                <div class="empty-state">No assets match this filter.</div>
            <?php else: ?>
                <div class="projects-grid">
                    <?php foreach ($visibleInventoryItems as $item): ?>
                            <?php
                            $counts = inventory_clerk_asset_display_data($item);
                            $totalUnitCount = $counts['total'];
                            $availableUnitCount = $counts['available'];
                            $deployedUnitCount = $counts['deployed'];
                            $maintenanceUnitCount = $counts['maintenance'];
                            $categoryLabel = ucwords(strtolower((string)$item['asset_category']));
                            $categoryLabel = preg_replace_callback(
                                '/\b(it|qr)\b/i',
                                static fn (array $match): string => strtoupper($match[0]),
                                $categoryLabel
                            ) ?? $categoryLabel;
                            $criticalityValue = strtolower(trim((string)($item['criticality'] ?? '')));
                            $criticalityLabel = $criticalityValue !== ''
                                ? ucwords(str_replace('-', ' ', $criticalityValue))
                                : 'Not set';

                            $displayStatusData = inventory_clerk_asset_display_status($item, $counts);
                            $displayStatus = $displayStatusData['key'];
                            $statusLabel = $displayStatusData['label'];
                            $assetUnits = $unitsByInventory[(int)$item['id']] ?? [];
                            $assetUnitsJson = json_encode($assetUnits, JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_TAG) ?: '[]';
                            ?>
                            <article class="project-card asset-summary-card">
                                <div class="asset-summary-card__header">
                                    <div>
                                        <h3><?php echo htmlspecialchars($item['asset_name']); ?></h3>
                                        <p class="asset-summary-card__category"><?php echo htmlspecialchars($categoryLabel); ?></p>
                                    </div>
                                    <span class="asset-summary-card__status asset-summary-card__status--<?php echo htmlspecialchars($displayStatus); ?>">
                                        <?php echo htmlspecialchars($statusLabel); ?>
                                    </span>
                                </div>

                                <dl class="asset-summary-card__details">
                                    <div><dt>Criticality</dt><dd><span class="asset-summary-card__criticality asset-summary-card__criticality--<?php echo htmlspecialchars($criticalityValue ?: 'not-set'); ?>"><?php echo htmlspecialchars($criticalityLabel); ?></span></dd></div>
                                    <div><dt>Total Units</dt><dd><?php echo $totalUnitCount; ?></dd></div>
                                    <div><dt>Available</dt><dd><?php echo $availableUnitCount; ?></dd></div>
                                    <div><dt>Deployed / In Use</dt><dd><?php echo $deployedUnitCount; ?></dd></div>
                                    <div><dt>Maintenance</dt><dd><?php echo $maintenanceUnitCount; ?></dd></div>
                                    <div><dt>Minimum Available</dt><dd><?php echo $item['min_stock'] !== null ? (int)$item['min_stock'] : 'Not set'; ?></dd></div>
                                </dl>

                                <div class="asset-summary-card__actions" aria-label="Asset actions">
                                    <button
                                        type="button"
                                        class="btn-secondary"
                                        data-view-asset-units
                                        data-asset-name="<?php echo htmlspecialchars((string)$item['asset_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-total-units="<?php echo $totalUnitCount; ?>"
                                        data-available-units="<?php echo $availableUnitCount; ?>"
                                        data-deployed-units="<?php echo $deployedUnitCount; ?>"
                                        data-maintenance-units="<?php echo $maintenanceUnitCount; ?>"
                                        data-asset-units="<?php echo htmlspecialchars($assetUnitsJson, ENT_QUOTES, 'UTF-8'); ?>"
                                    >View Units</button>
                                    <button
                                        type="button"
                                        class="btn-primary"
                                        data-asset-stock-in-open
                                        data-inventory-id="<?php echo (int)$item['id']; ?>"
                                        data-asset-name="<?php echo htmlspecialchars((string)$item['asset_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-current-units="<?php echo $totalUnitCount; ?>"
                                    >Stock In</button>
                                    <?php if ($availableUnitCount > 0): ?>
                                        <a href="/codesamplecaps/INVENTORY_CLERK/dashboards/stock_out.php" class="btn-secondary">Stock Out</a>
                                    <?php else: ?>
                                        <span class="btn-secondary asset-summary-card__action-disabled" aria-disabled="true" title="No available units to stock out.">Stock Out</span>
                                    <?php endif; ?>
                                </div>
                            </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <nav class="inventory-pagination" aria-label="Asset pages">
                <span class="inventory-pagination__summary">Showing <?php echo $showingStart; ?>&ndash;<?php echo $showingEnd; ?> of <?php echo $totalAssets; ?> assets</span>
                <?php if ($totalPages > 1): ?>
                    <div class="inventory-pagination__links">
                        <?php if ($currentPage > 1): ?>
                            <a class="btn-secondary" href="<?php echo htmlspecialchars($inventoryPageUrl(['page' => $currentPage - 1])); ?>">Previous</a>
                        <?php endif; ?>
                        <?php $previousPageNumber = 0; ?>
                        <?php foreach ($paginationNumbers as $pageNumber): ?>
                            <?php if ($previousPageNumber > 0 && $pageNumber > $previousPageNumber + 1): ?>
                                <span class="inventory-pagination__ellipsis" aria-hidden="true">&hellip;</span>
                            <?php endif; ?>
                            <?php if ($pageNumber === $currentPage): ?>
                                <span class="inventory-pagination__current" aria-current="page"><?php echo $pageNumber; ?></span>
                            <?php else: ?>
                                <a class="inventory-pagination__page" href="<?php echo htmlspecialchars($inventoryPageUrl(['page' => $pageNumber])); ?>"><?php echo $pageNumber; ?></a>
                            <?php endif; ?>
                            <?php $previousPageNumber = $pageNumber; ?>
                        <?php endforeach; ?>
                        <?php if ($currentPage < $totalPages): ?>
                            <a class="btn-secondary" href="<?php echo htmlspecialchars($inventoryPageUrl(['page' => $currentPage + 1])); ?>">Next</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </nav>
        </section>

        <div class="inventory-units-modal" data-view-asset-units-modal hidden role="dialog" aria-modal="true" aria-labelledby="assetUnitsTitle">
            <div class="inventory-units-modal__backdrop" data-view-asset-units-close></div>
            <section class="inventory-units-modal__panel">
                <div class="inventory-units-modal__header">
                    <div>
                        <h2 id="assetUnitsTitle">Physical Units &mdash; <span data-view-asset-units-title></span></h2>
                        <p data-view-asset-units-name></p>
                    </div>
                    <button type="button" class="inventory-units-modal__close" aria-label="Close Physical Units" data-view-asset-units-close>&times;</button>
                </div>
                <div class="inventory-units-modal__summary">
                    <div><span>Total Units</span><strong data-view-asset-units-total>0</strong></div>
                    <div><span>Available</span><strong data-view-asset-units-available>0</strong></div>
                    <div><span>Deployed / In Use</span><strong data-view-asset-units-deployed>0</strong></div>
                    <div><span>Maintenance</span><strong data-view-asset-units-maintenance>0</strong></div>
                </div>
                <div class="inventory-units-modal__table-wrap">
                    <table class="inventory-units-modal__table">
                        <thead>
                            <tr><th>Unit Code</th><th>Status</th><th>QR</th><th>Action</th></tr>
                        </thead>
                        <tbody data-view-asset-units-body></tbody>
                    </table>
                    <div class="inventory-units-modal__empty" data-view-asset-units-empty hidden>No physical units have been stocked in yet.</div>
                </div>
            </section>
        </div>

        <div class="inventory-unit-qr-modal" data-view-unit-qr-modal hidden role="dialog" aria-modal="true" aria-labelledby="assetUnitQrTitle">
            <div class="inventory-unit-qr-modal__backdrop" data-view-unit-qr-close></div>
            <section class="inventory-unit-qr-modal__panel">
                <div class="inventory-unit-qr-modal__header">
                    <h2 id="assetUnitQrTitle">QR Code &mdash; <span data-view-unit-qr-title></span></h2>
                    <button type="button" class="inventory-unit-qr-modal__close" aria-label="Close unit QR code" data-view-unit-qr-close>&times;</button>
                </div>
                <img class="inventory-unit-qr-modal__image" data-view-unit-qr-image alt="Asset unit QR code" hidden>
                <p class="inventory-unit-qr-modal__unavailable" data-view-unit-qr-unavailable hidden>QR image is not available for this unit.</p>
            </section>
        </div>

        <div class="inventory-stock-in-modal" data-asset-stock-in-modal<?php echo $openStockInModal ? ' data-open="true"' : ''; ?><?php echo $openStockInModal ? '' : ' hidden'; ?> role="dialog" aria-modal="true" aria-labelledby="assetStockInTitle">
            <div class="inventory-stock-in-modal__backdrop" data-asset-stock-in-close></div>
            <section class="inventory-stock-in-modal__panel">
                <div class="inventory-stock-in-modal__header">
                    <h2 id="assetStockInTitle">Stock In Asset</h2>
                    <button type="button" class="inventory-stock-in-modal__close" aria-label="Close Stock In Asset" data-asset-stock-in-close>&times;</button>
                </div>

                <?php if (!empty($stockInErrors['general'])): ?>
                    <div class="alert alert-error"><?php echo htmlspecialchars((string)$stockInErrors['general']); ?></div>
                <?php endif; ?>

                <form method="POST" data-asset-stock-in-form>
                    <input type="hidden" name="action" value="stock_in_asset_card">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($stockInCsrfToken); ?>">
                    <input type="hidden" name="stock_in_request_token" value="<?php echo htmlspecialchars($stockInRequestToken); ?>">
                    <input type="hidden" name="inventory_id" value="<?php echo (int)$stockInValues['inventory_id']; ?>" data-asset-stock-in-inventory-id>
                    <input type="hidden" name="asset_name" value="<?php echo htmlspecialchars((string)$stockInValues['asset_name']); ?>" data-asset-stock-in-name>
                    <input type="hidden" name="current_units" value="<?php echo (int)$stockInValues['current_units']; ?>" data-asset-stock-in-current-hidden>

                    <div class="inventory-stock-in-modal__grid">
                        <div class="input-group inventory-stock-in-modal__full">
                            <label for="asset_stock_in_name">Asset</label>
                            <input id="asset_stock_in_name" type="text" readonly value="<?php echo htmlspecialchars((string)$stockInValues['asset_name']); ?>" data-asset-stock-in-name-display>
                        </div>
                        <div class="inventory-stock-in-modal__readout">
                            <span>Current Units</span>
                            <strong data-asset-stock-in-current><?php echo (int)$stockInValues['current_units']; ?></strong>
                        </div>
                        <div class="input-group">
                            <label for="asset_stock_in_quantity">Quantity In *</label>
                            <input id="asset_stock_in_quantity" name="quantity" type="text" inputmode="numeric" autocomplete="off" value="<?php echo htmlspecialchars((string)$stockInValues['quantity']); ?>" data-asset-stock-in-quantity aria-describedby="assetStockInQuantityError">
                            <span id="assetStockInQuantityError" class="inventory-stock-in-modal__error" data-asset-stock-in-error><?php echo htmlspecialchars((string)($stockInErrors['quantity'] ?? '')); ?></span>
                        </div>
                        <div class="inventory-stock-in-modal__readout">
                            <span>New Total</span>
                            <strong data-asset-stock-in-new-total><?php echo (int)$stockInValues['current_units']; ?></strong>
                        </div>
                        <div class="input-group inventory-stock-in-modal__full">
                            <label for="asset_stock_in_remarks">Remarks (Optional)</label>
                            <input id="asset_stock_in_remarks" name="remarks" type="text" maxlength="500" value="<?php echo htmlspecialchars((string)$stockInValues['remarks']); ?>" placeholder="Supplier, delivery, or note">
                        </div>
                    </div>
                    <div class="inventory-stock-in-modal__actions">
                        <button type="button" class="btn-secondary" data-asset-stock-in-close>Cancel</button>
                        <button type="submit" class="btn-primary" data-asset-stock-in-submit>Confirm Stock In</button>
                    </div>
                </form>
            </section>
        </div>

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
                            <div class="inventory-add-asset-modal__suggestion">
                                <button type="button" class="btn-secondary inventory-add-asset-modal__suggest-button" data-category-suggest>Suggest Category</button>
                                <span class="inventory-add-asset-modal__suggest-feedback" data-category-suggest-feedback aria-live="polite"></span>
                            </div>
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
