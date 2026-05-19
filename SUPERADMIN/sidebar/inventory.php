<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/audit_log.php';
require_once __DIR__ . '/../../config/asset_unit_helpers.php';

require_role('super_admin');
$csrfToken = auth_csrf_token('super_admin');

function determine_inventory_status_for_page(int $quantity, ?int $minStock): string {
    if ($quantity <= 0) {
        return 'out-of-stock';
    }

    if ($minStock !== null && $quantity <= $minStock) {
        return 'low-stock';
    }

    return 'available';
}

function redirect_inventory_page(): void {
    header('Location: /codesamplecaps/SUPERADMIN/sidebar/inventory.php');
    exit();
}

function set_inventory_flash(string $type, string $message): void {
    $_SESSION['inventory_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function getInventoryItemSnapshot(mysqli $conn, int $inventoryId): ?array {
    $stmt = $conn->prepare(
        'SELECT i.id, i.quantity, i.min_stock, i.status, a.asset_name
         FROM inventory i
         INNER JOIN assets a ON a.id = i.asset_id
         WHERE i.id = ?
         LIMIT 1'
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $inventoryId);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result ? $result->fetch_assoc() : null;
}

function inventory_table_exists(mysqli $conn, string $table): bool {
    $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    if ($safeTable === '') {
        return false;
    }

    $result = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

ensure_asset_unit_tracking_schema($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'super_admin')) {
        set_inventory_flash('error', 'Security check failed. Please try again.');
        redirect_inventory_page();
    }

    if ($action === 'create_inventory_item') {
        $assetId = (int)($_POST['asset_id'] ?? 0);
        $quantity = max(0, (int)($_POST['quantity'] ?? 0));
        $minStockRaw = trim((string)($_POST['min_stock'] ?? ''));
        $minStock = $minStockRaw === '' ? null : max(0, (int)$minStockRaw);

        if ($assetId <= 0) {
            set_inventory_flash('error', 'Select an asset first.');
            redirect_inventory_page();
        }

        $existingStmt = $conn->prepare('SELECT id FROM inventory WHERE asset_id = ? LIMIT 1');
        if (!$existingStmt) {
            set_inventory_flash('error', 'Failed to validate inventory item.');
            redirect_inventory_page();
        }

        $existingStmt->bind_param('i', $assetId);
        $existingStmt->execute();
        $existingResult = $existingStmt->get_result();
        if ($existingResult && $existingResult->fetch_assoc()) {
            set_inventory_flash('error', 'That asset already has an inventory record.');
            redirect_inventory_page();
        }

        $status = determine_inventory_status_for_page($quantity, $minStock);
        $insertStmt = $conn->prepare('INSERT INTO inventory (asset_id, quantity, min_stock, status) VALUES (?, ?, ?, ?)');

        try {
            $conn->begin_transaction();

            if (
                !$insertStmt ||
                !$insertStmt->bind_param('iiis', $assetId, $quantity, $minStock, $status) ||
                !$insertStmt->execute()
            ) {
                throw new RuntimeException('Failed to create inventory item.');
            }

            $createdInventoryId = (int)$insertStmt->insert_id;
            asset_units_sync_for_inventory($conn, $createdInventoryId, $quantity);

            $assetLabel = '';
            $assetStmt = $conn->prepare('SELECT asset_name FROM assets WHERE id = ? LIMIT 1');
            if ($assetStmt) {
                $assetStmt->bind_param('i', $assetId);
                $assetStmt->execute();
                $assetResult = $assetStmt->get_result();
                $assetRow = $assetResult ? $assetResult->fetch_assoc() : null;
                $assetLabel = (string)($assetRow['asset_name'] ?? '');
            }
            audit_log_event(
                $conn,
                (int)($_SESSION['user_id'] ?? 0),
                'create_inventory_item',
                'inventory',
                $createdInventoryId,
                null,
                [
                    'asset_id' => $assetId,
                    'asset_name' => $assetLabel,
                    'quantity' => $quantity,
                    'min_stock' => $minStock,
                    'status' => $status,
                ]
            );
            $conn->commit();
            set_inventory_flash('success', 'Inventory item created successfully. Unit QR records are ready.');
        } catch (Throwable $exception) {
            $conn->rollback();
            set_inventory_flash('error', $exception->getMessage());
        }

        redirect_inventory_page();
    }

    if ($action === 'update_inventory_item') {
        $inventoryId = (int)($_POST['inventory_id'] ?? 0);
        $quantity = max(0, (int)($_POST['quantity'] ?? 0));
        $minStockRaw = trim((string)($_POST['min_stock'] ?? ''));
        $minStock = $minStockRaw === '' ? null : max(0, (int)$minStockRaw);

        if ($inventoryId <= 0) {
            set_inventory_flash('error', 'Invalid inventory item.');
            redirect_inventory_page();
        }

        $beforeUpdate = getInventoryItemSnapshot($conn, $inventoryId);
        $status = determine_inventory_status_for_page($quantity, $minStock);
        $updateStmt = $conn->prepare(
            'UPDATE inventory
             SET quantity = ?, min_stock = ?, status = ?
             WHERE id = ?'
        );

        try {
            $conn->begin_transaction();

            if (
                !$updateStmt ||
                !$updateStmt->bind_param('iisi', $quantity, $minStock, $status, $inventoryId) ||
                !$updateStmt->execute()
            ) {
                throw new RuntimeException('Failed to update inventory item.');
            }

            asset_units_sync_for_inventory($conn, $inventoryId, $quantity);
            audit_log_event(
                $conn,
                (int)($_SESSION['user_id'] ?? 0),
                'update_inventory_item',
                'inventory',
                $inventoryId,
                $beforeUpdate ? [
                    'asset_name' => $beforeUpdate['asset_name'] ?? null,
                    'quantity' => (int)($beforeUpdate['quantity'] ?? 0),
                    'min_stock' => $beforeUpdate['min_stock'] !== null ? (int)$beforeUpdate['min_stock'] : null,
                    'status' => $beforeUpdate['status'] ?? null,
                ] : null,
                [
                    'asset_name' => $beforeUpdate['asset_name'] ?? null,
                    'quantity' => $quantity,
                    'min_stock' => $minStock,
                    'status' => $status,
                ]
            );
            $conn->commit();
            set_inventory_flash('success', 'Inventory item updated successfully. Unit instances were synced.');
        } catch (Throwable $exception) {
            $conn->rollback();
            set_inventory_flash('error', $exception->getMessage());
        }

        redirect_inventory_page();
    }
}

$flash = $_SESSION['inventory_flash'] ?? null;
unset($_SESSION['inventory_flash']);
$statusFilter = trim((string)($_GET['status'] ?? ''));
$allowedInventoryFilters = ['available', 'low-stock', 'out-of-stock', 'attention'];
if (!in_array($statusFilter, $allowedInventoryFilters, true)) {
    $statusFilter = '';
}

$assetsWithoutInventory = [];
$assetsWithoutInventoryResult = $conn->query(
    "SELECT a.id, a.asset_name, a.asset_type, a.serial_number
     FROM assets a
     LEFT JOIN inventory i ON i.asset_id = a.id
     WHERE i.id IS NULL
     ORDER BY a.asset_name ASC, a.id ASC"
);
if ($assetsWithoutInventoryResult) {
    $assetsWithoutInventory = $assetsWithoutInventoryResult->fetch_all(MYSQLI_ASSOC);
}

$inventoryItems = [];
$hasDeploymentTables = inventory_table_exists($conn, 'project_inventory_deployments')
    && inventory_table_exists($conn, 'project_inventory_return_logs')
    && inventory_table_exists($conn, 'projects');
$hasUsageLogsTable = inventory_table_exists($conn, 'asset_usage_logs');
$hasScanHistoryTable = inventory_table_exists($conn, 'asset_scan_history');

$inventoryQuery = "SELECT
        i.id,
        i.asset_id,
        i.quantity,
        i.min_stock,
        i.status,
        i.updated_at,
        a.asset_name,
        a.asset_type,
        a.serial_number";

if ($hasDeploymentTables) {
    $inventoryQuery .= ",
        active_deployment.project_name AS assigned_project_name,
        active_deployment.remaining_quantity AS assigned_project_quantity,
        active_deployment.deployed_at AS assigned_project_deployed_at,
        active_deployment.deployed_by_name AS assigned_project_deployed_by";
} else {
    $inventoryQuery .= ",
        NULL AS assigned_project_name,
        NULL AS assigned_project_quantity,
        NULL AS assigned_project_deployed_at,
        NULL AS assigned_project_deployed_by";
}

if ($hasUsageLogsTable) {
    $inventoryQuery .= ",
        latest_usage.worker_name AS latest_worker_name,
        latest_usage.used_at AS latest_used_at,
        latest_usage.notes AS latest_usage_notes,
        latest_usage.foreman_name AS latest_usage_foreman_name,
        latest_usage.unit_code AS latest_usage_unit_code";
} else {
    $inventoryQuery .= ",
        NULL AS latest_worker_name,
        NULL AS latest_used_at,
        NULL AS latest_usage_notes,
        NULL AS latest_usage_foreman_name,
        NULL AS latest_usage_unit_code";
}

if ($hasScanHistoryTable) {
    $inventoryQuery .= ",
        latest_scan.scan_time AS latest_scan_time,
        latest_scan.scan_device AS latest_scan_device,
        latest_scan.scanned_by_name AS latest_scan_by_name,
        latest_scan.unit_code AS latest_scan_unit_code";
} else {
    $inventoryQuery .= ",
        NULL AS latest_scan_time,
        NULL AS latest_scan_device,
        NULL AS latest_scan_by_name,
        NULL AS latest_scan_unit_code";
}

$inventoryQuery .= ",
        COALESCE(unit_totals.total_units, 0) AS total_unit_instances,
        COALESCE(unit_totals.available_units, 0) AS available_unit_instances,
        COALESCE(unit_totals.deployed_units, 0) AS deployed_unit_instances";

$inventoryQuery .= "
     FROM inventory i
     INNER JOIN assets a ON a.id = i.asset_id";

if ($hasDeploymentTables) {
    $inventoryQuery .= "
     LEFT JOIN (
        SELECT
            pid.inventory_id,
            p.project_name,
            (pid.quantity - COALESCE(returns.returned_quantity, 0)) AS remaining_quantity,
            pid.deployed_at,
            deployer.full_name AS deployed_by_name
        FROM project_inventory_deployments pid
        INNER JOIN projects p ON p.id = pid.project_id
        LEFT JOIN users deployer ON deployer.id = pid.deployed_by
        LEFT JOIN (
            SELECT deployment_id, SUM(quantity) AS returned_quantity
            FROM project_inventory_return_logs
            GROUP BY deployment_id
        ) returns ON returns.deployment_id = pid.id
        INNER JOIN (
            SELECT
                pid_inner.inventory_id,
                MAX(pid_inner.id) AS latest_deployment_id
            FROM project_inventory_deployments pid_inner
            LEFT JOIN (
                SELECT deployment_id, SUM(quantity) AS returned_quantity
                FROM project_inventory_return_logs
                GROUP BY deployment_id
            ) inner_returns ON inner_returns.deployment_id = pid_inner.id
            WHERE (pid_inner.quantity - COALESCE(inner_returns.returned_quantity, 0)) > 0
            GROUP BY pid_inner.inventory_id
        ) latest_active_deployment ON latest_active_deployment.latest_deployment_id = pid.id
        WHERE (pid.quantity - COALESCE(returns.returned_quantity, 0)) > 0
     ) active_deployment ON active_deployment.inventory_id = i.id";
}

if ($hasUsageLogsTable) {
    $inventoryQuery .= "
     LEFT JOIN (
        SELECT
            aul.asset_id,
            aul.worker_name,
            aul.notes,
            aul.used_at,
            u.full_name AS foreman_name,
            au.unit_code
        FROM asset_usage_logs aul
        LEFT JOIN users u ON u.id = aul.foreman_id
        LEFT JOIN asset_units au ON au.id = aul.asset_unit_id
        INNER JOIN (
            SELECT asset_id, MAX(id) AS latest_usage_id
            FROM asset_usage_logs
            GROUP BY asset_id
        ) latest_usage ON latest_usage.latest_usage_id = aul.id
     ) latest_usage ON latest_usage.asset_id = a.id";
}

if ($hasScanHistoryTable) {
    $inventoryQuery .= "
     LEFT JOIN (
        SELECT
            ash.asset_id,
            ash.scan_time,
            ash.scan_device,
            u.full_name AS scanned_by_name,
            au.unit_code
        FROM asset_scan_history ash
        LEFT JOIN users u ON u.id = ash.foreman_id
        LEFT JOIN asset_units au ON au.id = ash.asset_unit_id
        INNER JOIN (
            SELECT asset_id, MAX(id) AS latest_scan_id
            FROM asset_scan_history
            GROUP BY asset_id
        ) latest_scan ON latest_scan.latest_scan_id = ash.id
     ) latest_scan ON latest_scan.asset_id = a.id";
}

$inventoryQuery .= "
     LEFT JOIN (
        SELECT
            inventory_id,
            COUNT(*) AS total_units,
            SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) AS available_units,
            SUM(CASE WHEN status = 'deployed' THEN 1 ELSE 0 END) AS deployed_units
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

$totalInventoryItems = count($inventoryItems);
$totalUnits = 0;
$lowStockItems = 0;
$outOfStockItems = 0;

foreach ($inventoryItems as $item) {
    $totalUnits += (int)$item['quantity'];
    if (($item['status'] ?? '') === 'low-stock') {
        $lowStockItems++;
    }
    if (($item['status'] ?? '') === 'out-of-stock') {
        $outOfStockItems++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management - Super Admin</title>
    <link rel="stylesheet" href="../css/super_admin_dashboard.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
</head>
<body>
<div class="container">
    <?php include __DIR__ . '/../super_admin_sidebar.php'; ?>

    <main class="main-content">
        <div class="page-stack">
            <section class="metrics-grid">
                <div class="metric-card">
                    <span>Inventory Items</span>
                    <strong><?php echo $totalInventoryItems; ?></strong>
                </div>
                <div class="metric-card">
                    <span>Total Units</span>
                    <strong><?php echo $totalUnits; ?></strong>
                </div>
                <div class="metric-card">
                    <span>Low Stock</span>
                    <strong><?php echo $lowStockItems; ?></strong>
                </div>
                <div class="metric-card">
                    <span>Out of Stock</span>
                    <strong><?php echo $outOfStockItems; ?></strong>
                </div>
            </section>

            <?php if ($flash): ?>
                <div class="alert <?php echo $flash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>">
                    <?php echo htmlspecialchars($flash['message']); ?>
                </div>
            <?php endif; ?>

            <section class="form-panel">
                <h2 class="section-title-inline">Add Inventory Record</h2>

                <?php if (empty($assetsWithoutInventory)): ?>
                    <div class="empty-state">All assets already have inventory records.</div>
                <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="create_inventory_item">
                        <div class="form-grid">
                            <div class="input-group">
                                <label for="asset_id">Asset</label>
                                <select id="asset_id" name="asset_id" required>
                                    <option value="">Select asset</option>
                                    <?php foreach ($assetsWithoutInventory as $asset): ?>
                                        <option value="<?php echo (int)$asset['id']; ?>">
                                            <?php
                                            $label = $asset['asset_name'];
                                            if (!empty($asset['asset_type'])) {
                                                $label .= ' - ' . $asset['asset_type'];
                                            }
                                            if (!empty($asset['serial_number'])) {
                                                $label .= ' (SN: ' . $asset['serial_number'] . ')';
                                            }
                                            echo htmlspecialchars($label);
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="input-group">
                                <label for="quantity">Quantity</label>
                                <input type="number" id="quantity" name="quantity" min="0" step="1" value="0" required>
                            </div>

                            <div class="input-group">
                                <label for="min_stock">Min Stock</label>
                                <input type="number" id="min_stock" name="min_stock" min="0" step="1" placeholder="Optional">
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn-primary">Create Inventory Record</button>
                        </div>
                    </form>
                <?php endif; ?>
            </section>

            <section class="page-stack">
                <h2 class="section-title-inline">Inventory Items</h2>
                <div class="dashboard-actions">
                    <a href="/codesamplecaps/SUPERADMIN/sidebar/inventory.php" class="action-chip<?php echo $statusFilter === '' ? ' active-chip' : ''; ?>">All</a>
                    <a href="/codesamplecaps/SUPERADMIN/sidebar/inventory.php?status=attention" class="action-chip<?php echo $statusFilter === 'attention' ? ' active-chip' : ''; ?>">Attention</a>
                    <a href="/codesamplecaps/SUPERADMIN/sidebar/inventory.php?status=low-stock" class="action-chip<?php echo $statusFilter === 'low-stock' ? ' active-chip' : ''; ?>">Low Stock</a>
                    <a href="/codesamplecaps/SUPERADMIN/sidebar/inventory.php?status=out-of-stock" class="action-chip<?php echo $statusFilter === 'out-of-stock' ? ' active-chip' : ''; ?>">Out of Stock</a>
                    <a href="/codesamplecaps/SUPERADMIN/sidebar/inventory.php?status=available" class="action-chip<?php echo $statusFilter === 'available' ? ' active-chip' : ''; ?>">Available</a>
                </div>

                <?php if (empty($inventoryItems)): ?>
                    <div class="empty-state">No inventory records yet.</div>
                <?php else: ?>
                    <div class="projects-grid">
                        <?php foreach ($inventoryItems as $item): ?>
                            <article class="project-card">
                                <div class="card-split">
                                    <div>
                                        <h3><?php echo htmlspecialchars($item['asset_name']); ?></h3>
                                        <div class="status-pill-wrap">
                                            <span class="status-pill status-<?php echo htmlspecialchars($item['status']); ?>">
                                                <?php echo htmlspecialchars(ucfirst($item['status'])); ?>
                                            </span>
                                        </div>
                                    </div>

                                    <div class="project-meta">
                                        <div><strong>Type:</strong> <?php echo htmlspecialchars($item['asset_type'] ?: 'N/A'); ?></div>
                                        <div><strong>Serial:</strong> <?php echo htmlspecialchars($item['serial_number'] ?: 'N/A'); ?></div>
                                        <div><strong>Available Qty:</strong> <?php echo (int)$item['quantity']; ?></div>
                                        <div><strong>Tracked Units:</strong> <?php echo (int)($item['total_unit_instances'] ?? 0); ?></div>
                                        <div><strong>Available Units:</strong> <?php echo (int)($item['available_unit_instances'] ?? 0); ?></div>
                                        <div><strong>Deployed Units:</strong> <?php echo (int)($item['deployed_unit_instances'] ?? 0); ?></div>
                                        <div><strong>Min Stock:</strong> <?php echo $item['min_stock'] !== null ? (int)$item['min_stock'] : 'Not set'; ?></div>
                                        <div><strong>Updated:</strong> <?php echo htmlspecialchars($item['updated_at']); ?></div>
                                        <div><strong>Last QR Scan By:</strong> <?php echo htmlspecialchars((string)($item['latest_scan_by_name'] ?? 'No scan yet')); ?></div>
                                        <div><strong>Last Scanned Unit:</strong> <?php echo htmlspecialchars((string)($item['latest_scan_unit_code'] ?? 'No unit scan yet')); ?></div>
                                        <div><strong>Last Scan Time:</strong> <?php echo htmlspecialchars((string)($item['latest_scan_time'] ?? 'No scan yet')); ?></div>
                                        <div><strong>Scan Device:</strong> <?php echo htmlspecialchars((string)($item['latest_scan_device'] ?? 'Not recorded')); ?></div>
                                        <div>
                                            <strong>Assigned To:</strong>
                                            <?php
                                            if (!empty($item['assigned_project_name'])) {
                                                echo htmlspecialchars((string)$item['assigned_project_name'] . ' | Remaining deployed qty: ' . (int)($item['assigned_project_quantity'] ?? 0));
                                            } elseif (!empty($item['latest_worker_name'])) {
                                                echo htmlspecialchars((string)$item['latest_worker_name']);
                                            } else {
                                                echo 'Available in stock';
                                            }
                                            ?>
                                        </div>
                                        <div>
                                            <strong>Handled By:</strong>
                                            <?php
                                            if (!empty($item['assigned_project_name'])) {
                                                echo htmlspecialchars((string)($item['assigned_project_deployed_by'] ?? 'Unknown'));
                                            } elseif (!empty($item['latest_usage_foreman_name'])) {
                                                echo htmlspecialchars((string)$item['latest_usage_foreman_name']);
                                            } else {
                                                echo 'N/A';
                                            }
                                            ?>
                                        </div>
                                        <div>
                                            <strong>Assignment Time:</strong>
                                            <?php
                                            if (!empty($item['assigned_project_name'])) {
                                                echo htmlspecialchars((string)($item['assigned_project_deployed_at'] ?? 'Not recorded'));
                                            } elseif (!empty($item['latest_used_at'])) {
                                                echo htmlspecialchars((string)$item['latest_used_at']);
                                            } else {
                                                echo 'N/A';
                                            }
                                            ?>
                                        </div>
                                        <div><strong>Latest Used Unit:</strong> <?php echo htmlspecialchars((string)($item['latest_usage_unit_code'] ?? 'No usage log yet')); ?></div>
                                    </div>
                                </div>

                                <?php if (!empty($item['latest_usage_notes'])): ?>
                                    <div class="lock-note"><strong>Latest Usage Note:</strong> <?php echo htmlspecialchars((string)$item['latest_usage_notes']); ?></div>
                                <?php endif; ?>

                                <form method="POST" class="mini-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                    <input type="hidden" name="action" value="update_inventory_item">
                                    <input type="hidden" name="inventory_id" value="<?php echo (int)$item['id']; ?>">

                                    <h4 class="subheading">Update Stock</h4>
                                    <div class="form-grid">
                                        <div class="input-group">
                                            <label for="quantity-<?php echo (int)$item['id']; ?>">Quantity</label>
                                            <input type="number" id="quantity-<?php echo (int)$item['id']; ?>" name="quantity" min="0" step="1" value="<?php echo (int)$item['quantity']; ?>" required>
                                        </div>

                                        <div class="input-group">
                                            <label for="min_stock-<?php echo (int)$item['id']; ?>">Min Stock</label>
                                            <input type="number" id="min_stock-<?php echo (int)$item['id']; ?>" name="min_stock" min="0" step="1" value="<?php echo $item['min_stock'] !== null ? (int)$item['min_stock'] : ''; ?>">
                                        </div>
                                    </div>

                                    <div class="form-actions">
                                        <button type="submit" class="btn-primary">Save Stock</button>
                                    </div>
                                </form>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>
<script src="../js/super_admin_dashboard.js"></script>
</body>
</html>
