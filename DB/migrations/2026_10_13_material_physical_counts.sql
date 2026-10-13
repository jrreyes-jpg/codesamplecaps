-- Blind audit submissions. Run after taking a database backup.

CREATE TABLE IF NOT EXISTS material_physical_counts (
    id INT(11) NOT NULL AUTO_INCREMENT,
    material_id INT(11) NOT NULL,
    counted_quantity DECIMAL(12,2) NOT NULL,
    total_quantity_snapshot DECIMAL(12,2) NOT NULL,
    reserved_quantity_snapshot DECIMAL(12,2) NOT NULL,
    expected_quantity_snapshot DECIMAL(12,2) NOT NULL,
    counted_by INT(11) NOT NULL,
    counted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_material_physical_counts_material (material_id, id),
    KEY idx_material_physical_counts_user (counted_by),
    CONSTRAINT fk_material_physical_counts_material
        FOREIGN KEY (material_id) REFERENCES materials (id) ON DELETE RESTRICT,
    CONSTRAINT fk_material_physical_counts_user
        FOREIGN KEY (counted_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
