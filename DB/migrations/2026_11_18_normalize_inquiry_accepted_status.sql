-- Accepted ay para lang sa quotation. Ang lumang inquiry status ay For Inspection.
START TRANSACTION;

UPDATE service_inquiries
SET status = 'For Inspection'
WHERE status = 'Accepted';

COMMIT;
