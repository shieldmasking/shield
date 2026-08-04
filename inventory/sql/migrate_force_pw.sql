-- Migration: add force_password_change flag to users
ALTER TABLE users ADD COLUMN force_password_change TINYINT(1) NOT NULL DEFAULT 0 AFTER locked_until;
