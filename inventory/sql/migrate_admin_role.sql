-- Migration: add is_admin flag to users
ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER force_password_change;

-- Set rstrenger as admin (safe if email doesn't exist yet)
UPDATE users SET is_admin = 1 WHERE email = 'rstrenger@shieldmasking.com';
