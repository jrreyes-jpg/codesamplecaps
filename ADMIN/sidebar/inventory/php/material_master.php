<?php
require_once __DIR__ . '/../../../../config/auth_middleware.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/material_stock.php';
require_once __DIR__ . '/../../../../config/material_detail_suggestion_service.php';

require_role('admin');

// Admin lang ang puwedeng magbago ng material master data.
$materialCsrfToken = auth_csrf_token('admin_material_master');
$materialFlashKey = 'admin_material_master_flash';
$materialFilter = in_array($_GET['material_filter'] ?? 'active', ['active', 'inactive', 'all'], true)
    ? (string)($_GET['material_filter'] ?? 'active')
    : 'active';
$materialCategories = material_detail_suggestion_categories();
$materialUnits = material_detail_suggestion_units();
$wholeCountUnits = ['pcs', 'roll', 'box', 'pack', 'set', 'bundle', 'sheet', 'pair', 'tube'];

$materialRedirect = static function (string $filter = 'active'): void {
    header('Location: /codesamplecaps/ADMIN/sidebar/inventory/php/inventory.php?view=materials&material_filter=' . urlencode($filter));
    exit;
};

$materialNameExists = static function (mysqli $conn, string $name): bool {
    $normalized = strtolower(preg_replace('/\s+/', ' ', trim($name)) ?? '');
    $result = $conn->query('SELECT material_name FROM materials');
    if (!$result) {
        throw new RuntimeException('Unable to check existing materials.');
    }
    while ($row = $result->fetch_assoc()) {
        $saved = strtolower(preg_replace('/\s+/', ' ', trim((string)$row['material_name'])) ?? '');
        if ($saved === $normalized) {
            return true;
        }
    }
    return false;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['material_action'] ?? '');
    $redirectFilter = in_array($_POST['material_filter'] ?? '', ['active', 'inactive', 'all'], true)
        ? (string)$_POST['material_filter']
        : 'active';

    try {
        if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'admin_material_master')) {
            throw new RuntimeException('Security check failed. Please try again.');
        }

        if ($action === 'create') {
            $name = preg_replace('/\s+/', ' ', trim((string)($_POST['material_name'] ?? ''))) ?? '';
            $description = trim((string)($_POST['description'] ?? ''));
            $category = trim((string)($_POST['category'] ?? ''));
            $unit = trim((string)($_POST['unit'] ?? ''));
            $reorderRaw = trim((string)($_POST['reorder_level'] ?? ''));
            $reorder = is_numeric($reorderRaw) ? (float)$reorderRaw : 0.0;

            if ($name === '' || preg_match('/^[\p{L}\p{N}\s\-\/\.\(\)&]+$/u', $name) !== 1 || preg_match('/\p{L}/u', $name) !== 1) {
                throw new RuntimeException('Enter a valid material name.');
            }
            if ((function_exists('mb_strlen') ? mb_strlen($description) : strlen($description)) > 255) {
                throw new RuntimeException('Description must be 255 characters or less.');
            }
            if (!in_array($category, $materialCategories, true) || !in_array($unit, $materialUnits, true)) {
                throw new RuntimeException('Choose a valid category and unit.');
            }
            if ($reorderRaw === '' || !is_finite($reorder) || $reorder <= 0) {
                throw new RuntimeException('Low Stock Alert Level must be greater than 0.');
            }
            if (in_array($unit, $wholeCountUnits, true) && $reorder !== floor($reorder)) {
                throw new RuntimeException('Low Stock Alert Level must be a whole number for ' . $unit . '.');
            }
            if ($materialNameExists($conn, $name)) {
                throw new RuntimeException('Material already exists.');
            }

            $code = material_stock_create_material($conn, $name, $category, $unit, $reorder, $description);
            $_SESSION[$materialFlashKey] = ['type' => 'success', 'message' => 'Material ' . $code . ' created.'];
        } else {
            $materialId = (int)($_POST['material_id'] ?? 0);
            if ($action === 'archive') {
                material_stock_archive_material($conn, $materialId);
                $_SESSION[$materialFlashKey] = ['type' => 'success', 'message' => 'Material archived.'];
            } elseif ($action === 'restore') {
                material_stock_restore_material($conn, $materialId);
                $_SESSION[$materialFlashKey] = ['type' => 'success', 'message' => 'Material restored.'];
            } elseif ($action === 'permanent_delete') {
                material_stock_permanently_delete_material($conn, $materialId);
                $_SESSION[$materialFlashKey] = ['type' => 'success', 'message' => 'Material permanently deleted.'];
            } else {
                throw new RuntimeException('Invalid material action.');
            }
        }
    } catch (Throwable $exception) {
        $_SESSION[$materialFlashKey] = ['type' => 'error', 'message' => $exception->getMessage()];
    }
    $materialRedirect($redirectFilter);
}

$materialFlash = $_SESSION[$materialFlashKey] ?? null;
unset($_SESSION[$materialFlashKey]);
$materials = material_stock_fetch_materials($conn, $materialFilter);
$deletableMaterialIds = array_fill_keys(material_stock_fetch_deletable_material_ids($conn), true);

$adminPageTitle = 'Consumable Material Master - Admin';
$adminCssFiles = [
    '/codesamplecaps/ADMIN/common/css/admin-common.css',
    '/codesamplecaps/ADMIN/sidebar/inventory/css/inventory.css',
];
$adminJsFiles = [
    '/codesamplecaps/ADMIN/common/js/admin-common.js',
];
include __DIR__ . '/../../../layout/header.php';
include __DIR__ . '/../../../admin_sidebar.php';
?>
<main class="main-content">
    <div class="page-stack material-master-page">
        <nav class="resource-page-tabs" aria-label="Inventory sections">
            <a class="is-active" href="/codesamplecaps/ADMIN/sidebar/inventory/php/inventory.php">Consumables</a>
            <a href="/codesamplecaps/ADMIN/sidebar/inventory/php/inventory.php?view=history">Stock History</a>
        </nav>

        <?php if ($materialFlash): ?>
            <div class="alert <?php echo $materialFlash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>">
                <?php echo htmlspecialchars((string)$materialFlash['message']); ?>
            </div>
        <?php endif; ?>

        <section class="form-panel">
            <h1 class="section-title-inline">Add Consumable Material</h1>
            <p>Creates the material definition only. Inventory Clerk records the actual receipt.</p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($materialCsrfToken); ?>">
                <input type="hidden" name="material_action" value="create">
                <div class="form-grid material-master-form-grid">
                    <div class="input-group"><label for="material_name">Material Name</label><input id="material_name" name="material_name" maxlength="180" required></div>
                    <div class="input-group"><label for="category">Category</label><select id="category" name="category" required><option value="">Select category</option><?php foreach ($materialCategories as $category): ?><option value="<?php echo htmlspecialchars($category); ?>"><?php echo htmlspecialchars($category); ?></option><?php endforeach; ?></select></div>
                    <div class="input-group"><label for="unit">Unit</label><select id="unit" name="unit" required><option value="">Select unit</option><?php foreach ($materialUnits as $unit): ?><option value="<?php echo htmlspecialchars($unit); ?>"><?php echo htmlspecialchars($unit); ?></option><?php endforeach; ?></select></div>
                    <div class="input-group"><label for="reorder_level">Low Stock Alert Level</label><input id="reorder_level" name="reorder_level" type="number" min="0.01" step="0.01" required></div>
                    <div class="input-group material-master-description"><label for="description">Description (Optional)</label><textarea id="description" name="description" maxlength="255" rows="2"></textarea></div>
                </div>
                <div class="form-actions"><button type="submit" class="btn-primary">Add Material</button></div>
            </form>
        </section>

        <section class="form-panel">
            <div class="material-master-heading">
                <h2 class="section-title-inline">Material Master List</h2>
                <nav class="dashboard-actions" aria-label="Material filter">
                    <?php foreach (['active' => 'Active', 'inactive' => 'Archived', 'all' => 'All'] as $value => $label): ?>
                        <a class="action-chip<?php echo $materialFilter === $value ? ' active-chip' : ''; ?>" href="?view=materials&amp;material_filter=<?php echo $value; ?>"><?php echo $label; ?></a>
                    <?php endforeach; ?>
                </nav>
            </div>
            <div class="table-responsive">
                <table class="data-table material-master-table">
                    <thead><tr><th>Material</th><th>Category</th><th>Unit</th><th>Alert Level</th><th>Physical</th><th>Reserved</th><th>Available</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php if ($materials === []): ?><tr><td colspan="9" class="empty-state">No materials in this filter.</td></tr><?php endif; ?>
                    <?php foreach ($materials as $material): ?>
                        <?php $isArchived = (string)$material['status'] === 'inactive'; ?>
                        <tr>
                            <td data-label="Material"><strong><?php echo htmlspecialchars((string)$material['material_name']); ?></strong><small><?php echo htmlspecialchars((string)$material['material_code']); ?></small></td>
                            <td data-label="Category"><?php echo htmlspecialchars((string)($material['category'] ?: '—')); ?></td>
                            <td data-label="Unit"><?php echo htmlspecialchars((string)$material['unit']); ?></td>
                            <td data-label="Alert Level"><?php echo $material['reorder_level'] === null ? '—' : htmlspecialchars((string)$material['reorder_level']); ?></td>
                            <td data-label="Physical"><?php echo htmlspecialchars((string)$material['physical_quantity']); ?></td>
                            <td data-label="Reserved"><?php echo htmlspecialchars((string)$material['reserved_quantity']); ?></td>
                            <td data-label="Available"><?php echo htmlspecialchars((string)$material['available_quantity']); ?></td>
                            <td data-label="Status"><span class="status-pill <?php echo $isArchived ? 'status-inactive' : 'status-available'; ?>"><?php echo $isArchived ? 'Archived' : 'Active'; ?></span></td>
                            <td data-label="Actions">
                                <div class="material-master-actions">
                                    <form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($materialCsrfToken); ?>"><input type="hidden" name="material_id" value="<?php echo (int)$material['id']; ?>"><input type="hidden" name="material_filter" value="<?php echo htmlspecialchars($materialFilter); ?>"><input type="hidden" name="material_action" value="<?php echo $isArchived ? 'restore' : 'archive'; ?>"><button type="submit" class="btn-secondary"><?php echo $isArchived ? 'Restore' : 'Archive'; ?></button></form>
                                    <?php if (isset($deletableMaterialIds[(int)$material['id']])): ?><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($materialCsrfToken); ?>"><input type="hidden" name="material_id" value="<?php echo (int)$material['id']; ?>"><input type="hidden" name="material_filter" value="<?php echo htmlspecialchars($materialFilter); ?>"><input type="hidden" name="material_action" value="permanent_delete"><button type="submit" class="btn-danger">Delete</button></form><?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</main>
<?php include __DIR__ . '/../../../layout/footer.php'; ?>
