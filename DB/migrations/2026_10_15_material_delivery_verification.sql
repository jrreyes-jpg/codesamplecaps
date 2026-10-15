-- Run after a database backup.
-- Admin records the delivery. Inventory Clerk verifies the actual count.

CREATE TABLE IF NOT EXISTS material_delivery_verifications (
    id INT(11) NOT NULL AUTO_INCREMENT,
    material_id INT(11) NOT NULL,
    expected_quantity DECIMAL(12,2) NOT NULL,
    supplier_name VARCHAR(180) NULL,
    delivery_receipt_no VARCHAR(120) NOT NULL,
    admin_notes VARCHAR(500) NULL,
    status ENUM('for_verification', 'verified') NOT NULL DEFAULT 'for_verification',
    verified_quantity DECIMAL(12,2) NULL,
    clerk_notes VARCHAR(500) NULL,
    created_by INT(11) NOT NULL,
    verified_by INT(11) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verified_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_material_delivery_status_created (status, created_at),
    KEY idx_material_delivery_material (material_id),
    CONSTRAINT fk_material_delivery_material
        FOREIGN KEY (material_id) REFERENCES materials (id) ON DELETE RESTRICT,
    CONSTRAINT fk_material_delivery_created_by
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_material_delivery_verified_by
        FOREIGN KEY (verified_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
