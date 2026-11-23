-- Run after a database backup.
-- Add the actual supplier delivery date to every delivery item.

ALTER TABLE material_delivery_verifications
    ADD COLUMN delivery_date DATE NULL AFTER delivery_receipt_no;

UPDATE material_delivery_verifications
SET delivery_date = DATE(created_at)
WHERE delivery_date IS NULL;

ALTER TABLE material_delivery_verifications
    MODIFY delivery_date DATE NOT NULL;

-- Rollback:
-- ALTER TABLE material_delivery_verifications DROP COLUMN delivery_date;
