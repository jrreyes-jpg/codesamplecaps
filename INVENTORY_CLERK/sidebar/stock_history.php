<?php
require_once __DIR__ . '/../includes/stock_helpers.php';

inventory_clerk_ensure_stock_movement_table($conn);

$movements = [];
$result = $conn->query(
    "SELECT m.*, a.asset_name, u.full_name
     FROM inventory_stock_movements m
     INNER JOIN inventory i ON i.id = m.inventory_id
     INNER JOIN assets a ON a.id = i.asset_id
     LEFT JOIN users u ON u.id = m.created_by
     ORDER BY m.created_at DESC, m.id DESC
     LIMIT 200"
);
if ($result) {
    $movements = $result->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock History</title>
    <link rel="stylesheet" href="/codesamplecaps/SHARED/admin_ui/css/base.css">
    <link rel="stylesheet" href="../css/inventory_clerk_dashboard.css">
    <link rel="stylesheet" href="/codesamplecaps/SHARED/admin_ui/css/sidebar.css">
    <link rel="stylesheet" href="/codesamplecaps/SHARED/admin_ui/css/header.css">
    <link rel="stylesheet" href="/codesamplecaps/SHARED/admin_ui/css/notifications.css">
    <link rel="stylesheet" href="/codesamplecaps/SHARED/admin_ui/css/layout.css">
</head>
<body>
<div class="container">
    <?php include __DIR__ . '/inventory_clerk_sidebar.php'; ?>
    <main class="main-content">
        <div class="page-stack">
            <section class="form-panel">
                <h1 class="section-title-inline">Stock History</h1>
                <?php if (empty($movements)): ?>
                    <div class="empty-state">No stock movement yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Item</th>
                                    <th>Type</th>
                                    <th>Qty</th>
                                    <th>Before</th>
                                    <th>After</th>
                                    <th>User</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($movements as $movement): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)$movement['created_at']); ?></td>
                                        <td><?php echo htmlspecialchars((string)$movement['asset_name']); ?></td>
                                        <td><?php echo htmlspecialchars(str_replace('_', ' ', (string)$movement['movement_type'])); ?></td>
                                        <td><?php echo (int)$movement['quantity']; ?></td>
                                        <td><?php echo (int)$movement['previous_quantity']; ?></td>
                                        <td><?php echo (int)$movement['new_quantity']; ?></td>
                                        <td><?php echo htmlspecialchars((string)($movement['full_name'] ?? 'System')); ?></td>
                                        <td><?php echo htmlspecialchars((string)($movement['remarks'] ?? '')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>
<script src="../js/inventory_clerk_dashboard.js"></script>
</body>
</html>
