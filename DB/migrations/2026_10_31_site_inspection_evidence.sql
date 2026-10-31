CREATE TABLE IF NOT EXISTS site_inspection_evidence (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    inspection_id INT NOT NULL,
    evidence_type ENUM('photo', 'google_drive') NOT NULL,
    file_path VARCHAR(255) NULL,
    original_name VARCHAR(255) NULL,
    mime_type VARCHAR(50) NULL,
    file_size INT UNSIGNED NULL,
    external_url VARCHAR(2048) NULL,
    uploaded_by INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_inspection_evidence_inspection (inspection_id),
    KEY idx_inspection_evidence_type (evidence_type),
    CONSTRAINT fk_inspection_evidence_inspection
        FOREIGN KEY (inspection_id) REFERENCES site_inspections (id) ON DELETE CASCADE,
    CONSTRAINT fk_inspection_evidence_user
        FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
