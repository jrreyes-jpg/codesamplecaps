ALTER TABLE service_inquiries
    ADD COLUMN inquiry_source VARCHAR(30) NOT NULL DEFAULT 'online' AFTER client_id,
    ADD COLUMN contact_person VARCHAR(150) DEFAULT NULL AFTER client_name,
    ADD COLUMN preferred_inspection_time TIME DEFAULT NULL AFTER preferred_inspection_date,
    ADD INDEX idx_service_inquiries_source_status (inquiry_source, status, archived_at);
