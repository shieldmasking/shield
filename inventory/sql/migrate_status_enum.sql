-- Migration: update quotes status ENUM to match new statuses
ALTER TABLE quotes MODIFY COLUMN status ENUM('draft','sent','ordered') NOT NULL DEFAULT 'draft';
