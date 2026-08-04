-- Migration: add terms field to customers, default Net 30
ALTER TABLE customers ADD COLUMN terms VARCHAR(100) NOT NULL DEFAULT 'Net 30' AFTER billing_address;
