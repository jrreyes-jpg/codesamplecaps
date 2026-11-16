-- Backup the database before running this migration.
-- Adds safe history types for delivery differences and project reservations.

ALTER TABLE material_delivery_verifications
    MODIFY status ENUM('for_verification', 'verified', 'discrepancy')
    NOT NULL DEFAULT 'for_verification';

ALTER TABLE material_stock_movements
    MODIFY movement_type ENUM(
        'stock_in',
        'reserved',
        'discrepancy',
        'project_issue',
        'manual_stock_out',
        'adjustment_in',
        'adjustment_out'
    ) NOT NULL;

-- Rollback plan (run only if no row uses reserved or discrepancy):
-- ALTER TABLE material_delivery_verifications
--     MODIFY status ENUM('for_verification', 'verified') NOT NULL DEFAULT 'for_verification';
-- ALTER TABLE material_stock_movements
--     MODIFY movement_type ENUM('stock_in', 'project_issue', 'manual_stock_out', 'adjustment_in', 'adjustment_out') NOT NULL;
