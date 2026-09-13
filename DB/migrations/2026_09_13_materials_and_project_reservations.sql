-- Consumable Materials only. Reusable Assets and QR units stay unchanged.
-- Run after taking a database backup.

CREATE TABLE IF NOT EXISTS materials (
    id INT(11) NOT NULL AUTO_INCREMENT,
    material_code VARCHAR(50) NOT NULL,
    material_name VARCHAR(180) NOT NULL,
    category VARCHAR(80) DEFAULT NULL,
    unit VARCHAR(30) NOT NULL DEFAULT 'unit',
    physical_quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    reserved_quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    reorder_level DECIMAL(12,2) DEFAULT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_materials_code (material_code),
    KEY idx_materials_status_name (status, material_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS project_material_reservations (
    id INT(11) NOT NULL AUTO_INCREMENT,
    project_id INT(11) NOT NULL,
    material_id INT(11) NOT NULL,
    required_quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    reserved_quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    issued_quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('active', 'fulfilled', 'cancelled') NOT NULL DEFAULT 'active',
    created_by INT(11) NOT NULL,
    cancelled_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_project_material_reservation (project_id, material_id),
    KEY idx_project_material_reservations_material_status (material_id, status),
    CONSTRAINT fk_project_material_reservations_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT fk_project_material_reservations_material FOREIGN KEY (material_id) REFERENCES materials (id) ON DELETE RESTRICT,
    CONSTRAINT fk_project_material_reservations_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS material_stock_movements (
    id INT(11) NOT NULL AUTO_INCREMENT,
    material_id INT(11) NOT NULL,
    reservation_id INT(11) DEFAULT NULL,
    movement_type ENUM('stock_in', 'project_issue', 'manual_stock_out', 'adjustment_in', 'adjustment_out') NOT NULL,
    quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    physical_before DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    physical_after DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    remarks TEXT DEFAULT NULL,
    created_by INT(11) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_material_stock_movements_material_created (material_id, created_at),
    KEY idx_material_stock_movements_reservation (reservation_id),
    CONSTRAINT fk_material_stock_movements_material FOREIGN KEY (material_id) REFERENCES materials (id) ON DELETE RESTRICT,
    CONSTRAINT fk_material_stock_movements_reservation FOREIGN KEY (reservation_id) REFERENCES project_material_reservations (id) ON DELETE SET NULL,
    CONSTRAINT fk_material_stock_movements_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE inquiry_quotation_items
    ADD COLUMN IF NOT EXISTS material_id INT(11) DEFAULT NULL AFTER item_type,
    ADD KEY IF NOT EXISTS idx_inquiry_quote_items_material (material_id);

ALTER TABLE site_inspection_cost_items
    ADD COLUMN IF NOT EXISTS material_id INT(11) DEFAULT NULL AFTER inventory_id,
    ADD KEY IF NOT EXISTS idx_site_inspection_cost_items_material (material_id);

-- Add foreign keys once only so this migration is safe to re-run.
SET @add_quote_material_fk = IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inquiry_quotation_items' AND CONSTRAINT_NAME = 'fk_inquiry_quotation_items_material'),
    'SELECT 1',
    'ALTER TABLE inquiry_quotation_items ADD CONSTRAINT fk_inquiry_quotation_items_material FOREIGN KEY (material_id) REFERENCES materials (id) ON DELETE SET NULL'
);
PREPARE add_quote_material_fk FROM @add_quote_material_fk;
EXECUTE add_quote_material_fk;
DEALLOCATE PREPARE add_quote_material_fk;

SET @add_inspection_material_fk = IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'site_inspection_cost_items' AND CONSTRAINT_NAME = 'fk_site_inspection_cost_items_material'),
    'SELECT 1',
    'ALTER TABLE site_inspection_cost_items ADD CONSTRAINT fk_site_inspection_cost_items_material FOREIGN KEY (material_id) REFERENCES materials (id) ON DELETE SET NULL'
);
PREPARE add_inspection_material_fk FROM @add_inspection_material_fk;
EXECUTE add_inspection_material_fk;
DEALLOCATE PREPARE add_inspection_material_fk;
