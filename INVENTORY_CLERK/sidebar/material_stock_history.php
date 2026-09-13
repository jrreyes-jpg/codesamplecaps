<?php
require_once __DIR__ . '/../includes/stock_helpers.php';
require_once __DIR__ . '/../includes/page_shell.php';
require_once __DIR__ . '/../../config/material_stock.php';
$movements = [];
$result = $conn->query("SELECT sm.*, m.material_name, m.unit, u.full_name FROM material_stock_movements sm INNER JOIN materials m ON m.id = sm.material_id LEFT JOIN users u ON u.id = sm.created_by ORDER BY sm.created_at DESC, sm.id DESC LIMIT 200");
if ($result) { $movements = $result->fetch_all(MYSQLI_ASSOC); }
?>
<?php inventory_clerk_render_page('Material Stock History', function () use ($movements): void { ?>
<div class="page-stack"><section class="form-panel"><h1 class="section-title-inline">Material Stock History</h1><div class="table-responsive"><table class="data-table"><thead><tr><th>Date</th><th>Material</th><th>Type</th><th>Qty</th><th>Before</th><th>After</th><th>User</th><th>Remarks</th></tr></thead><tbody><?php if ($movements === []): ?><tr><td colspan="8">No material movement yet.</td></tr><?php endif; ?><?php foreach ($movements as $movement): ?><tr><td><?php echo htmlspecialchars((string)$movement['created_at']); ?></td><td><?php echo htmlspecialchars($movement['material_name']); ?></td><td><?php echo htmlspecialchars(str_replace('_', ' ', $movement['movement_type'])); ?></td><td><?php echo htmlspecialchars((string)$movement['quantity'] . ' ' . $movement['unit']); ?></td><td><?php echo htmlspecialchars((string)$movement['physical_before']); ?></td><td><?php echo htmlspecialchars((string)$movement['physical_after']); ?></td><td><?php echo htmlspecialchars((string)($movement['full_name'] ?? 'System')); ?></td><td><?php echo htmlspecialchars((string)($movement['remarks'] ?? '')); ?></td></tr><?php endforeach; ?></tbody></table></div></section></div>
<?php }); ?>
