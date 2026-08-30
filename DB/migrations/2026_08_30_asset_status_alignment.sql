-- Keep the legacy status column compatible while asset_status stays canonical.
-- Manual migration ito para sa existing databases. Huwag patakbuhin sa PHP request.

ALTER TABLE assets
    MODIFY COLUMN asset_status ENUM('available', 'maintenance', 'damaged', 'in_use', 'lost') NOT NULL DEFAULT 'available',
    MODIFY COLUMN status ENUM('available', 'maintenance', 'damaged', 'in_use', 'lost') NULL DEFAULT 'available';

UPDATE assets
SET status = asset_status
WHERE status IS NULL OR status <> asset_status;
