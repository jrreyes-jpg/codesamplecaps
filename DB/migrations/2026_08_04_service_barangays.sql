CREATE TABLE IF NOT EXISTS service_barangays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    province VARCHAR(80) NOT NULL,
    city_municipality VARCHAR(120) NOT NULL,
    barangay VARCHAR(150) NOT NULL,
    psgc_code VARCHAR(30) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_service_barangay (province, city_municipality, barangay),
    KEY idx_service_barangays_city (province, city_municipality)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Import official PSA/PSGC barangays here.
-- Example:
-- INSERT INTO service_barangays (province, city_municipality, barangay, psgc_code)
-- VALUES ('Laguna', 'Calamba', 'Barangay 1', '0000000000');
