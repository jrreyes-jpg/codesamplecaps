<?php
require_once __DIR__ . '/../includes/stock_helpers.php';
require_once __DIR__ . '/../includes/page_shell.php';

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

inventory_clerk_render_page('Stock History', function () use ($movements): void {
?>
    <div class="page-stack stock-history-page">
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
<?php
}, [
    '/codesamplecaps/INVENTORY_CLERK/css/stock_history.css',
], '', [
    '/codesamplecaps/INVENTORY_CLERK/js/stock_history.js',
]);
