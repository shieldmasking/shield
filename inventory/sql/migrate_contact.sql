-- Migration: add phone to users, add company contact fields to settings
-- Run once on the live DB

ALTER TABLE users ADD COLUMN phone VARCHAR(30) NULL AFTER email;

INSERT INTO settings (`key`, value) VALUES
    ('company_address', ''),
    ('company_phone',   ''),
    ('company_email',   '')
ON DUPLICATE KEY UPDATE value = value;
