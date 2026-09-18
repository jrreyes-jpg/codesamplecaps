-- Link an optional Asset Master to initial quotation planning requirements.
-- This is planning-only. It does not reserve, issue, or select physical units.

ALTER TABLE inquiry_quotation_asset_requirements
    ADD COLUMN asset_id INT(11) NULL AFTER draft_id,
    ADD KEY idx_inquiry_quote_asset_requirements_asset (asset_id),
    ADD CONSTRAINT fk_inquiry_quote_asset_requirements_asset
        FOREIGN KEY (asset_id) REFERENCES assets(id)
        ON DELETE SET NULL;
