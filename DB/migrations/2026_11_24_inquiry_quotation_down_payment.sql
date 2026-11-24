-- Simple quotation payment term for the capstone demo.
-- Run this once in the online database before uploading the updated PHP files.
ALTER TABLE inquiry_quotation_drafts
    ADD COLUMN IF NOT EXISTS down_payment_percent DECIMAL(5,2) NOT NULL DEFAULT 50.00
    AFTER grand_total;
