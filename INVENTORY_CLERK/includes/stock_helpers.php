<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/audit_log.php';
require_once __DIR__ . '/../../config/asset_unit_helpers.php';

require_role('inventory_clerk');

function inventory_clerk_table_exists(mysqli $conn, string $table): bool {
    $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    if ($safeTable === '') {
        return false;
    }

    $result = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function inventory_clerk_status(int $quantity, ?int $minStock): string {
    if ($quantity <= 0) {
        return 'out-of-stock';
    }

    if ($minStock !== null && $quantity <= $minStock) {
        return 'low-stock';
    }

    return 'available';
}

function inventory_clerk_ensure_stock_movement_table(mysqli $conn): void {
    // Ito ang history ng bawat stock in at stock out.
    $conn->query(
        "CREATE TABLE IF NOT EXISTS inventory_stock_movements (
            id INT(11) NOT NULL AUTO_INCREMENT,
            inventory_id INT(11) NOT NULL,
            movement_type ENUM('stock_in','stock_out') NOT NULL,
            quantity INT(11) NOT NULL,
            previous_quantity INT(11) NOT NULL,
            new_quantity INT(11) NOT NULL,
            remarks TEXT DEFAULT NULL,
            created_by INT(11) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_inventory_stock_movements_inventory (inventory_id),
            KEY idx_inventory_stock_movements_type (movement_type),
            KEY idx_inventory_stock_movements_created_at (created_at),
            KEY idx_inventory_stock_movements_user (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

function inventory_clerk_redirect(string $path): void {
    header('Location: ' . $path);
    exit();
}

function inventory_clerk_set_flash(string $type, string $message): void {
    $_SESSION['inventory_clerk_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function inventory_clerk_consume_flash(): ?array {
    $flash = $_SESSION['inventory_clerk_flash'] ?? null;
    unset($_SESSION['inventory_clerk_flash']);
    return is_array($flash) ? $flash : null;
}

function inventory_clerk_fetch_items(mysqli $conn): array {
    $result = $conn->query(
        "SELECT i.id, i.quantity, i.min_stock, i.status, a.asset_name, a.asset_type, a.serial_number
         FROM inventory i
         INNER JOIN assets a ON a.id = i.asset_id
         ORDER BY a.asset_name ASC, i.id ASC"
    );

    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}
