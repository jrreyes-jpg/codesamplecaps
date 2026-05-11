<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/audit_log.php';

require_role('super_admin');

function procurement_get_csrf_token(): string
{
    return auth_csrf_token('super_admin');
}

function procurement_is_valid_csrf_token(?string $token): bool
{
    return auth_is_valid_csrf($token, 'super_admin');
}

function procurement_redirect(): void
{
    header('Location: /codesamplecaps/SUPERADMIN/sidebar/procurement.php');
    exit();
}

function procurement_set_flash(string $type, string $message): void
{
    $_SESSION['procurement_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function procurement_normalize_text_or_null(?string $value): ?string
{
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function procurement_status_class(string $status): string
{
    return 'status-' . str_replace('_', '-', trim($status));
}

function procurement_ensure_purchase_requests_table(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS purchase_requests (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            request_no VARCHAR(80) NOT NULL UNIQUE,
            project_id INT(11) NOT NULL,
            requested_by INT(11) NOT NULL,
            engineer_reviewed_by INT(11) DEFAULT NULL,
            needed_date DATE DEFAULT NULL,
            request_type ENUM('material', 'tool', 'equipment', 'service') NOT NULL DEFAULT 'material',
            site_location VARCHAR(190) DEFAULT NULL,
            remarks TEXT DEFAULT NULL,
            engineer_review_notes TEXT DEFAULT NULL,
            status ENUM('draft', 'submitted', 'engineer_review', 'engineer_approved', 'engineer_rejected', 'approved', 'cancelled') NOT NULL DEFAULT 'submitted',
            engineer_reviewed_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

function procurement_ensure_suppliers_table(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS suppliers (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            supplier_code VARCHAR(80) NOT NULL UNIQUE,
            supplier_name VARCHAR(190) NOT NULL,
            contact_person VARCHAR(150) DEFAULT NULL,
            contact_number VARCHAR(50) DEFAULT NULL,
            email VARCHAR(190) DEFAULT NULL,
            address TEXT DEFAULT NULL,
            description TEXT DEFAULT NULL,
            status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

function procurement_ensure_purchase_orders_table(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS purchase_orders (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            po_no VARCHAR(80) NOT NULL UNIQUE,
            purchase_request_id INT(11) NOT NULL,
            project_id INT(11) NOT NULL,
            supplier_id INT(11) NOT NULL,
            order_date DATE NOT NULL,
            expected_delivery_date DATE DEFAULT NULL,
            remarks TEXT DEFAULT NULL,
            status ENUM('draft', 'issued', 'partially_delivered', 'delivered', 'cancelled', 'closed') NOT NULL DEFAULT 'issued',
            admin_approval_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
            admin_approved_by INT(11) DEFAULT NULL,
            admin_approved_at DATETIME DEFAULT NULL,
            admin_approval_notes TEXT DEFAULT NULL,
            created_by INT(11) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $columnCheck = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'admin_approval_status'");
    if (!$columnCheck || (int)$columnCheck->num_rows === 0) {
        $conn->query(
            "ALTER TABLE purchase_orders
             ADD COLUMN admin_approval_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending' AFTER status"
        );
        $conn->query("ALTER TABLE purchase_orders ADD COLUMN admin_approved_by INT(11) DEFAULT NULL AFTER admin_approval_status");
        $conn->query("ALTER TABLE purchase_orders ADD COLUMN admin_approved_at DATETIME DEFAULT NULL AFTER admin_approved_by");
        $conn->query("ALTER TABLE purchase_orders ADD COLUMN admin_approval_notes TEXT DEFAULT NULL AFTER admin_approved_at");
    }
}

procurement_ensure_purchase_requests_table($conn);
procurement_ensure_suppliers_table($conn);
procurement_ensure_purchase_orders_table($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!procurement_is_valid_csrf_token($_POST['csrf_token'] ?? null)) {
        procurement_set_flash('error', 'Security check failed.');
        procurement_redirect();
    }

    if ($action === 'review_purchase_order') {
        $purchaseOrderId = (int)($_POST['purchase_order_id'] ?? 0);
        $approvalAction = trim((string)($_POST['approval_action'] ?? ''));
        $approvalNotes = procurement_normalize_text_or_null($_POST['admin_approval_notes'] ?? null);
        $adminId = (int)($_SESSION['user_id'] ?? 0);

        if ($purchaseOrderId <= 0 || !in_array($approvalAction, ['approved', 'rejected'], true)) {
            procurement_set_flash('error', 'Invalid approval action.');
            procurement_redirect();
        }

        $snapshotStmt = $conn->prepare(
            "SELECT po.id, po.po_no, po.admin_approval_status, p.project_name
             FROM purchase_orders po
             INNER JOIN projects p ON p.id = po.project_id
             WHERE po.id = ?
             LIMIT 1"
        );

        if (!$snapshotStmt) {
            procurement_set_flash('error', 'Failed to load purchase order.');
            procurement_redirect();
        }

        $snapshotStmt->bind_param('i', $purchaseOrderId);
        $snapshotStmt->execute();
        $snapshot = $snapshotStmt->get_result()->fetch_assoc();

        if (!$snapshot) {
            procurement_set_flash('error', 'Purchase order not found.');
            procurement_redirect();
        }

        if ((string)($snapshot['admin_approval_status'] ?? 'pending') !== 'pending') {
            procurement_set_flash('error', 'This purchase order is already finalized.');
            procurement_redirect();
        }

        $updateStmt = $conn->prepare(
            'UPDATE purchase_orders
             SET admin_approval_status = ?, admin_approved_by = ?, admin_approved_at = NOW(), admin_approval_notes = ?
             WHERE id = ?'
        );

        if (
            $updateStmt &&
            $updateStmt->bind_param('sisi', $approvalAction, $adminId, $approvalNotes, $purchaseOrderId) &&
            $updateStmt->execute()
        ) {
            audit_log_event(
                $conn,
                $adminId,
                'super_admin_purchase_order_approval',
                'purchase_order',
                $purchaseOrderId,
                ['admin_approval_status' => $snapshot['admin_approval_status'] ?? 'pending'],
                [
                    'po_no' => $snapshot['po_no'] ?? null,
                    'project_name' => $snapshot['project_name'] ?? null,
                    'admin_approval_status' => $approvalAction,
                    'admin_approval_notes' => $approvalNotes,
                ]
            );
            procurement_set_flash('success', 'Purchase order approval saved.');
        } else {
            procurement_set_flash('error', 'Failed to save purchase order approval.');
        }

        procurement_redirect();
    }
}

$flash = $_SESSION['procurement_flash'] ?? null;
unset($_SESSION['procurement_flash']);
$csrfToken = procurement_get_csrf_token();

$pendingPurchaseOrders = [];
$pendingResult = $conn->query(
    "SELECT
        po.id,
        po.po_no,
        po.order_date,
        po.expected_delivery_date,
        po.remarks,
        po.status,
        po.admin_approval_status,
        p.project_name,
        pr.request_no,
        requester.full_name AS engineer_name,
        s.supplier_name
     FROM purchase_orders po
     INNER JOIN projects p ON p.id = po.project_id
     INNER JOIN purchase_requests pr ON pr.id = po.purchase_request_id
     INNER JOIN users requester ON requester.id = po.created_by
     INNER JOIN suppliers s ON s.id = po.supplier_id
     WHERE po.admin_approval_status = 'pending'
     ORDER BY po.created_at DESC"
);
if ($pendingResult) {
    $pendingPurchaseOrders = $pendingResult->fetch_all(MYSQLI_ASSOC);
}

$recentPurchaseOrders = [];
$recentResult = $conn->query(
    "SELECT
        po.id,
        po.po_no,
        po.order_date,
        po.expected_delivery_date,
        po.remarks,
        po.status,
        po.admin_approval_status,
        po.admin_approval_notes,
        po.admin_approved_at,
        p.project_name,
        pr.request_no,
        requester.full_name AS engineer_name,
        s.supplier_name
     FROM purchase_orders po
     INNER JOIN projects p ON p.id = po.project_id
     INNER JOIN purchase_requests pr ON pr.id = po.purchase_request_id
     INNER JOIN users requester ON requester.id = po.created_by
     INNER JOIN suppliers s ON s.id = po.supplier_id
     ORDER BY po.created_at DESC
     LIMIT 12"
);
if ($recentResult) {
    $recentPurchaseOrders = $recentResult->fetch_all(MYSQLI_ASSOC);
}

$approvalStats = [
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
];
$statsResult = $conn->query(
    "SELECT
        (SELECT COUNT(*) FROM purchase_orders WHERE admin_approval_status = 'pending') AS pending,
        (SELECT COUNT(*) FROM purchase_orders WHERE admin_approval_status = 'approved') AS approved,
        (SELECT COUNT(*) FROM purchase_orders WHERE admin_approval_status = 'rejected') AS rejected"
);
if ($statsResult) {
    $approvalStats = array_merge($approvalStats, $statsResult->fetch_assoc() ?: []);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procurement Approval - Super Admin</title>
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
                    <span>Pending Approval</span>
                    <strong><?php echo (int)($approvalStats['pending'] ?? 0); ?></strong>
                </div>
                <div class="metric-card">
                    <span>Approved PO</span>
                    <strong><?php echo (int)($approvalStats['approved'] ?? 0); ?></strong>
                </div>
                <div class="metric-card">
                    <span>Rejected PO</span>
                    <strong><?php echo (int)($approvalStats['rejected'] ?? 0); ?></strong>
                </div>
            </section>

            <?php if ($flash): ?>
                <div class="alert <?php echo ($flash['type'] ?? '') === 'success' ? 'alert-success' : 'alert-error'; ?>">
                    <?php echo htmlspecialchars((string)($flash['message'] ?? '')); ?>
                </div>
            <?php endif; ?>

            <section class="form-panel">
                <h1 class="section-title-inline">Procurement Approval</h1>
                <div class="lock-note">Engineers now manage suppliers and create purchase orders. Super Admin only reviews and approves the final procurement package here.</div>
            </section>

            <section class="page-stack">
                <h2 class="section-title-inline">Pending Purchase Orders</h2>
                <?php if (empty($pendingPurchaseOrders)): ?>
                    <div class="empty-state">No purchase orders are waiting for approval.</div>
                <?php else: ?>
                    <div class="projects-grid">
                        <?php foreach ($pendingPurchaseOrders as $purchaseOrder): ?>
                            <article class="project-card">
                                <div class="card-split">
                                    <div>
                                        <div class="project-card__eyebrow-row">
                                            <span class="project-card__eyebrow">PO No</span>
                                            <span class="project-card__reference"><?php echo htmlspecialchars((string)($purchaseOrder['po_no'] ?? '')); ?></span>
                                        </div>
                                        <h3><?php echo htmlspecialchars((string)($purchaseOrder['project_name'] ?? 'Project')); ?></h3>
                                        <div class="status-pill-wrap">
                                            <span class="status-pill <?php echo htmlspecialchars(procurement_status_class('pending')); ?>">
                                                Pending Approval
                                            </span>
                                        </div>
                                    </div>
                                    <div class="project-meta">
                                        <div><strong>Engineer:</strong> <?php echo htmlspecialchars((string)($purchaseOrder['engineer_name'] ?? 'N/A')); ?></div>
                                        <div><strong>Supplier:</strong> <?php echo htmlspecialchars((string)($purchaseOrder['supplier_name'] ?? 'N/A')); ?></div>
                                        <div><strong>Request:</strong> <?php echo htmlspecialchars((string)($purchaseOrder['request_no'] ?? 'N/A')); ?></div>
                                        <div><strong>Order Date:</strong> <?php echo htmlspecialchars((string)($purchaseOrder['order_date'] ?? '')); ?></div>
                                        <div><strong>Expected Delivery:</strong> <?php echo htmlspecialchars((string)($purchaseOrder['expected_delivery_date'] ?? 'Not set')); ?></div>
                                    </div>
                                </div>
                                <?php if (!empty($purchaseOrder['remarks'])): ?>
                                    <div class="lock-note"><strong>Engineer Note:</strong> <?php echo htmlspecialchars((string)$purchaseOrder['remarks']); ?></div>
                                <?php endif; ?>
                                <form method="POST" class="form-grid">
                                    <input type="hidden" name="action" value="review_purchase_order">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                    <input type="hidden" name="purchase_order_id" value="<?php echo (int)$purchaseOrder['id']; ?>">
                                    <div class="input-group input-group-wide">
                                        <label for="admin_approval_notes_<?php echo (int)$purchaseOrder['id']; ?>">Approval Note</label>
                                        <input type="text" id="admin_approval_notes_<?php echo (int)$purchaseOrder['id']; ?>" name="admin_approval_notes" placeholder="Approval or correction note">
                                    </div>
                                    <div class="form-actions">
                                        <button type="submit" name="approval_action" value="approved" class="btn-primary">Approve PO</button>
                                        <button type="submit" name="approval_action" value="rejected" class="btn-danger">Reject PO</button>
                                    </div>
                                </form>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="page-stack">
                <h2 class="section-title-inline">Recent Approval History</h2>
                <?php if (empty($recentPurchaseOrders)): ?>
                    <div class="empty-state">No purchase orders yet.</div>
                <?php else: ?>
                    <div class="projects-grid">
                        <?php foreach ($recentPurchaseOrders as $purchaseOrder): ?>
                            <article class="project-card">
                                <div class="card-split">
                                    <div>
                                        <div class="project-card__eyebrow-row">
                                            <span class="project-card__eyebrow">PO No</span>
                                            <span class="project-card__reference"><?php echo htmlspecialchars((string)($purchaseOrder['po_no'] ?? '')); ?></span>
                                        </div>
                                        <h3><?php echo htmlspecialchars((string)($purchaseOrder['project_name'] ?? 'Project')); ?></h3>
                                        <div class="status-pill-wrap">
                                            <span class="status-pill <?php echo htmlspecialchars(procurement_status_class((string)($purchaseOrder['admin_approval_status'] ?? 'pending'))); ?>">
                                                <?php echo htmlspecialchars('Admin ' . ucwords(str_replace('_', ' ', (string)($purchaseOrder['admin_approval_status'] ?? 'pending')))); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="project-meta">
                                        <div><strong>Engineer:</strong> <?php echo htmlspecialchars((string)($purchaseOrder['engineer_name'] ?? 'N/A')); ?></div>
                                        <div><strong>Supplier:</strong> <?php echo htmlspecialchars((string)($purchaseOrder['supplier_name'] ?? 'N/A')); ?></div>
                                        <div><strong>Request:</strong> <?php echo htmlspecialchars((string)($purchaseOrder['request_no'] ?? 'N/A')); ?></div>
                                        <div><strong>Order Date:</strong> <?php echo htmlspecialchars((string)($purchaseOrder['order_date'] ?? '')); ?></div>
                                        <div><strong>Expected Delivery:</strong> <?php echo htmlspecialchars((string)($purchaseOrder['expected_delivery_date'] ?? 'Not set')); ?></div>
                                    </div>
                                </div>
                                <?php if (!empty($purchaseOrder['remarks'])): ?>
                                    <div class="lock-note"><strong>Engineer Note:</strong> <?php echo htmlspecialchars((string)$purchaseOrder['remarks']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($purchaseOrder['admin_approval_notes'])): ?>
                                    <div class="lock-note"><strong>Admin Note:</strong> <?php echo htmlspecialchars((string)$purchaseOrder['admin_approval_notes']); ?></div>
                                <?php endif; ?>
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
