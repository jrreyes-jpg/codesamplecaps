-- Service inquiries table for landing page quotation requests.
CREATE TABLE IF NOT EXISTS service_inquiries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_name VARCHAR(150) NOT NULL,
    company_name VARCHAR(150) NULL,
    email VARCHAR(150) NOT NULL,
    contact_no VARCHAR(30) NOT NULL,
    site_address TEXT NOT NULL,
    service_category VARCHAR(80) NOT NULL,
    description TEXT NOT NULL,
    preferred_inspection_date DATE NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'Pending Review',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
