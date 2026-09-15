ALTER TABLE items ADD COLUMN price_override DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'Manual roll price override; NULL = use calculated price';
