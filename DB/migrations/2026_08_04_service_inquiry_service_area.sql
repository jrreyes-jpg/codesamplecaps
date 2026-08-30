ALTER TABLE service_inquiries
    ADD COLUMN IF NOT EXISTS province VARCHAR(80) NULL AFTER contact_no,
    ADD COLUMN IF NOT EXISTS city_municipality VARCHAR(120) NULL AFTER province,
    ADD COLUMN IF NOT EXISTS barangay VARCHAR(150) NULL AFTER city_municipality;
