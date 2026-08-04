-- Migration: rename quote status 'approved' to 'ordered'
UPDATE quotes SET status = 'ordered' WHERE status = 'approved';
