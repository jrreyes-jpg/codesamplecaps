-- Site inspection schedules from verified inquiries to Engineer work.
CREATE TABLE IF NOT EXISTS site_inspections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inquiry_id INT NOT NULL,
    engineer_id INT NOT NULL,
    scheduled_at DATETIME NOT NULL,
    site_notes TEXT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'Scheduled',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_site_inspections_inquiry (inquiry_id),
    KEY idx_site_inspections_engineer (engineer_id),
    KEY idx_site_inspections_status (status),
    KEY idx_site_inspections_scheduled_at (scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
