-- Planning-only reusable asset needs from the Engineer site inspection.
-- Walang QR, reservation, o pagbabago ng asset status dito.

CREATE TABLE IF NOT EXISTS site_inspection_asset_requirements (
    id INT(11) NOT NULL AUTO_INCREMENT,
    inspection_id INT(11) NOT NULL,
    asset_id INT(11) NOT NULL,
    quantity_required INT(11) NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_site_inspection_asset_requirement (inspection_id, asset_id),
    KEY idx_site_inspection_asset_requirements_asset (asset_id),
    CONSTRAINT fk_site_inspection_asset_requirements_inspection
        FOREIGN KEY (inspection_id) REFERENCES site_inspections (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_site_inspection_asset_requirements_asset
        FOREIGN KEY (asset_id) REFERENCES assets (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
