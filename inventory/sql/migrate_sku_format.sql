-- Migration: update SKU format to preserve decimal points
-- e.g. 730S-15 → 730S-1.5, 730D-0125 → 730D-0.125
-- Run once on the live DB

UPDATE items SET sku = '730D-0.125' WHERE sku = '730D-0125';
UPDATE items SET sku = '730D-0.25'  WHERE sku = '730D-025';
UPDATE items SET sku = '730D-0.375' WHERE sku = '730D-0375';
UPDATE items SET sku = '730D-0.5'   WHERE sku = '730D-05';
UPDATE items SET sku = '730D-0.75'  WHERE sku = '730D-075';
UPDATE items SET sku = '730D-1.25'  WHERE sku = '730D-125';
UPDATE items SET sku = '730D-1.5'   WHERE sku = '730D-15';

UPDATE items SET sku = '730S-0.125' WHERE sku = '730S-0125';
UPDATE items SET sku = '730S-0.25'  WHERE sku = '730S-025';
UPDATE items SET sku = '730S-0.375' WHERE sku = '730S-0375';
UPDATE items SET sku = '730S-0.5'   WHERE sku = '730S-05';
UPDATE items SET sku = '730S-0.75'  WHERE sku = '730S-075';
UPDATE items SET sku = '730S-1.25'  WHERE sku = '730S-125';
UPDATE items SET sku = '730S-1.5'   WHERE sku = '730S-15';

UPDATE items SET sku = '730SL-1.5'  WHERE sku = '730SL-15';
