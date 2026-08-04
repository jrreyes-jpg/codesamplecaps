CREATE TABLE IF NOT EXISTS site_inspection_cost_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inspection_id INT NOT NULL,
    item_type VARCHAR(30) NOT NULL DEFAULT 'material',
    inventory_id INT NULL,
    item_name VARCHAR(180) NOT NULL,
    quantity DECIMAL(12,2) NOT NULL DEFAULT 1.00,
    unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    line_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_site_inspection_cost_items_inspection (inspection_id),
    KEY idx_site_inspection_cost_items_inventory (inventory_id),
    KEY idx_site_inspection_cost_items_type (item_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
