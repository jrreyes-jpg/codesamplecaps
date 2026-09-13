<?php
require_once __DIR__ . '/../includes/stock_helpers.php';
require_once __DIR__ . '/../includes/page_shell.php';
require_once __DIR__ . '/../../config/material_stock.php';

$csrfToken = auth_csrf_token('inventory_clerk_materials');
$flashKey = 'inventory_clerk_materials_flash';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'inventory_clerk_materials')) {
            throw new RuntimeException('Security check failed. Please try again.');
        }
        $reorder = trim((string)($_POST['reorder_level'] ?? ''));
        $code = material_stock_create_material(
            $conn,
            (string)($_POST['material_name'] ?? ''),
            (string)($_POST['category'] ?? ''),
            (string)($_POST['unit'] ?? ''),
            $reorder === '' ? null : (float)$reorder
        );
        $_SESSION[$flashKey] = ['type' => 'success', 'message' => 'Material ' . $code . ' created. Add Stock In when stock arrives.'];
    } catch (Throwable $exception) {
        $_SESSION[$flashKey] = ['type' => 'error', 'message' => $exception->getMessage()];
    }
    header('Location: /codesamplecaps/INVENTORY_CLERK/sidebar/materials.php');
    exit;
}

$flash = $_SESSION[$flashKey] ?? null;
unset($_SESSION[$flashKey]);
$materials = material_stock_fetch_active_materials($conn);
$shortages = [];
$shortageResult = $conn->query(
    "SELECT p.project_name, m.material_name, m.unit, r.required_quantity, r.reserved_quantity, r.issued_quantity,
            GREATEST(r.required_quantity - r.reserved_quantity - r.issued_quantity, 0) AS shortage_quantity
     FROM project_material_reservations r
     INNER JOIN projects p ON p.id = r.project_id
     INNER JOIN materials m ON m.id = r.material_id
     WHERE r.status = 'active'
     ORDER BY p.created_at ASC, r.id ASC"
);
if ($shortageResult) {
    $shortages = $shortageResult->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Materials</title>
    <script src="/codesamplecaps/SHARED/sidebar/js/sidebar-state.js"></script>
    <script src="/codesamplecaps/SHARED/sidebar/js/sidebar.js" defer></script>
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/codesamplecaps/SHARED/admin_ui/css/base.css">
    <link rel="stylesheet" href="/codesamplecaps/INVENTORY_CLERK/css/inventory-clerk-content.css">
    <link rel="stylesheet" href="/codesamplecaps/SHARED/header/core/header.css">
    <link rel="stylesheet" href="/codesamplecaps/SHARED/sidebar/css/sidebar.css">
    <link rel="stylesheet" href="/codesamplecaps/INVENTORY_CLERK/css/materials.css">
</head>
<body>
<div class="container">
    <?php include __DIR__ . '/inventory_clerk_sidebar.php'; ?>
    <main class="main-content inventory-clerk-content-shell">
        <?php inventory_clerk_render_header($conn); ?>
        <div class="page-stack materials-page">
        <section class="form-panel">
            <p class="materials-page__eyebrow">Consumable stock</p>
            <h1 class="section-title-inline">Materials</h1>
            <p>Wire, cable, conduit, bolts, and similar items. Reusable tools stay in Asset / QR Inventory.</p>
            <?php if ($flash): ?><div class="alert <?php echo $flash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>"><?php echo htmlspecialchars($flash['message']); ?></div><?php endif; ?>
            <form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <div class="form-grid">
                    <div class="input-group"><label>Material Code</label><output class="material-code-preview">Made by system after save</output></div>
                    <div class="input-group"><label for="material_name">Material Name</label><input id="material_name" name="material_name" maxlength="180" required></div>
                    <div class="input-group"><label for="category">Category</label><input id="category" name="category" maxlength="80"></div>
                    <div class="input-group"><label for="unit">Unit</label><input id="unit" name="unit" value="pcs" maxlength="30" required></div>
                    <div class="input-group"><label for="reorder_level">Reorder Level</label><input id="reorder_level" name="reorder_level" type="number" min="0" step="0.01"></div>
                </div>
                <div class="form-actions"><button type="submit" class="btn-primary">Add Material</button></div>
            </form>
        </section>
        <section class="form-panel">
            <h2 class="section-title-inline">Material Master List</h2>
            <p class="materials-table-note">Available = Physical minus Reserved.</p>
            <div class="table-responsive"><table class="data-table materials-table"><thead><tr><th>Material Code</th><th>Material Name</th><th>Category</th><th>Unit</th><th>Physical</th><th>Reserved</th><th>Available</th><th>Reorder Level</th><th>Status</th></tr></thead><tbody>
            <?php if ($materials === []): ?><tr><td colspan="9" class="materials-empty">No materials yet.</td></tr><?php endif; ?>
            <?php foreach ($materials as $material): ?>
                <?php
                $physical = (float)$material['physical_quantity'];
                $available = (float)$material['available_quantity'];
                $reorder = $material['reorder_level'] === null ? null : (float)$material['reorder_level'];
                $stockState = $physical <= 0 ? 'out' : ($reorder !== null && $available <= $reorder ? 'low' : 'available');
                $stockLabel = $stockState === 'out' ? 'Out of Stock' : ($stockState === 'low' ? 'Low Stock' : 'Available');
                ?>
                <tr><td><strong><?php echo htmlspecialchars($material['material_code']); ?></strong></td><td><?php echo htmlspecialchars($material['material_name']); ?></td><td><?php echo htmlspecialchars((string)($material['category'] ?: '—')); ?></td><td><?php echo htmlspecialchars($material['unit']); ?></td><td><?php echo htmlspecialchars((string)$material['physical_quantity']); ?></td><td><?php echo htmlspecialchars((string)$material['reserved_quantity']); ?></td><td><?php echo htmlspecialchars((string)$material['available_quantity']); ?></td><td><?php echo $reorder === null ? '—' : htmlspecialchars((string)$material['reorder_level']); ?></td><td><span class="material-status material-status--<?php echo $stockState; ?>"><?php echo $stockLabel; ?></span></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </section>
        <section class="form-panel">
            <h2 class="section-title-inline">Project Material Needs</h2>
            <div class="table-responsive"><table class="data-table"><thead><tr><th>Project</th><th>Material</th><th>Required</th><th>Reserved</th><th>Issued</th><th>Shortage</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($shortages as $shortage): ?>
                <?php $needsProcurement = (float)$shortage['shortage_quantity'] > 0; ?>
                <tr><td><?php echo htmlspecialchars($shortage['project_name']); ?></td><td><?php echo htmlspecialchars($shortage['material_name']); ?></td><td><?php echo htmlspecialchars((string)$shortage['required_quantity']); ?></td><td><?php echo htmlspecialchars((string)$shortage['reserved_quantity']); ?></td><td><?php echo htmlspecialchars((string)$shortage['issued_quantity']); ?></td><td><?php echo htmlspecialchars((string)$shortage['shortage_quantity'] . ' ' . $shortage['unit']); ?></td><td><span class="material-status material-status--<?php echo $needsProcurement ? 'needs' : 'covered'; ?>"><?php echo $needsProcurement ? 'Needs Procurement' : 'Covered'; ?></span></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </section>
    </div></main>
</div>
<script src="/codesamplecaps/assets/js/app-window-guard.js"></script>
<script src="/codesamplecaps/SHARED/header/core/operations-header.js"></script>
</body>
</html>
