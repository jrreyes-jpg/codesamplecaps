-- Kumpletuhin muna ang Inquiry schema bago gamitin ang updated pages.
-- Manual migration ito. Huwag patakbuhin mula sa PHP page.

ALTER TABLE service_inquiries
    ADD COLUMN IF NOT EXISTS admin_notes TEXT NULL AFTER status,
    ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMP NULL AFTER admin_notes,
    ADD COLUMN IF NOT EXISTS viewed_at TIMESTAMP NULL AFTER reviewed_at,
    ADD COLUMN IF NOT EXISTS archived_at TIMESTAMP NULL AFTER viewed_at,
    ADD COLUMN IF NOT EXISTS archived_by INT NULL AFTER archived_at,
    ADD COLUMN IF NOT EXISTS archive_reason TEXT NULL AFTER archived_by,
    ADD COLUMN IF NOT EXISTS province VARCHAR(80) NULL AFTER contact_no,
    ADD COLUMN IF NOT EXISTS city_municipality VARCHAR(120) NULL AFTER province,
    ADD COLUMN IF NOT EXISTS barangay VARCHAR(150) NULL AFTER city_municipality;

ALTER TABLE site_inspections
    ADD COLUMN IF NOT EXISTS engineer_findings TEXT NULL AFTER site_notes,
    ADD COLUMN IF NOT EXISTS risk_notes TEXT NULL AFTER engineer_findings,
    ADD COLUMN IF NOT EXISTS client_requests TEXT NULL AFTER risk_notes;

ALTER TABLE site_inspection_cost_items
    ADD COLUMN IF NOT EXISTS unit VARCHAR(30) NOT NULL DEFAULT 'unit' AFTER quantity;

ALTER TABLE inquiry_quotation_drafts
    ADD COLUMN IF NOT EXISTS project_id INT NULL AFTER inspection_id,
    ADD COLUMN IF NOT EXISTS sent_by INT NULL AFTER approved_at,
    ADD COLUMN IF NOT EXISTS sent_to_client_id INT NULL AFTER sent_by,
    ADD COLUMN IF NOT EXISTS sent_to_name VARCHAR(190) NULL AFTER sent_to_client_id,
    ADD COLUMN IF NOT EXISTS sent_to_email VARCHAR(190) NULL AFTER sent_to_name,
    ADD COLUMN IF NOT EXISTS sent_to_contact VARCHAR(40) NULL AFTER sent_to_email,
    ADD COLUMN IF NOT EXISTS recipient_source VARCHAR(40) NULL AFTER sent_to_contact,
    ADD COLUMN IF NOT EXISTS public_access_token_hash VARCHAR(255) NULL AFTER recipient_source,
    ADD COLUMN IF NOT EXISTS public_token_expires_at DATETIME NULL AFTER public_access_token_hash,
    ADD COLUMN IF NOT EXISTS client_decision_note TEXT NULL AFTER sent_at,
    ADD COLUMN IF NOT EXISTS client_decision_at DATETIME NULL AFTER client_decision_note;

CREATE TABLE IF NOT EXISTS inquiry_quotation_status_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    draft_id INT NOT NULL,
    from_status VARCHAR(40) NULL,
    to_status VARCHAR(40) NOT NULL,
    note TEXT NULL,
    actor_id INT NOT NULL,
    actor_role VARCHAR(40) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_inquiry_quote_history_draft (draft_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE projects
    ADD COLUMN IF NOT EXISTS contact_person VARCHAR(190) NULL AFTER client_id,
    ADD COLUMN IF NOT EXISTS contact_number VARCHAR(40) NULL AFTER contact_person,
    ADD COLUMN IF NOT EXISTS project_site VARCHAR(190) NULL AFTER client_id,
    ADD COLUMN IF NOT EXISTS project_address TEXT NULL AFTER client_id,
    ADD COLUMN IF NOT EXISTS project_email VARCHAR(190) NULL AFTER project_address,
    ADD COLUMN IF NOT EXISTS project_code VARCHAR(80) NULL AFTER project_email,
    ADD COLUMN IF NOT EXISTS project_source VARCHAR(40) NOT NULL DEFAULT 'walk_in' AFTER project_code,
    ADD COLUMN IF NOT EXISTS project_start_date DATE NULL AFTER start_date,
    ADD COLUMN IF NOT EXISTS estimated_completion_date DATE NULL AFTER project_start_date;

CREATE TABLE IF NOT EXISTS project_budget_profiles (
    project_id INT NOT NULL PRIMARY KEY,
    budget_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    budget_notes TEXT NULL,
    created_by INT NOT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Linisin ang lumang status names nang isang beses lang.
UPDATE service_inquiries
SET status = 'Verified Lead'
WHERE status = 'Verified';

UPDATE service_inquiries
SET status = 'Not Qualified'
WHERE status = 'Rejected';
