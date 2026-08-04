ALTER TABLE service_inquiries
    ADD COLUMN province VARCHAR(80) NULL AFTER contact_no,
    ADD COLUMN city_municipality VARCHAR(120) NULL AFTER province,
    ADD COLUMN barangay VARCHAR(150) NULL AFTER city_municipality;
