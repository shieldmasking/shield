-- Migration: add internal sku field to products
ALTER TABLE products ADD COLUMN sku VARCHAR(50) NULL AFTER base_sku;
