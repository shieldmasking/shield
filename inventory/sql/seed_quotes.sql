-- Historical customers and quotes seeded from Excel quote files
-- Run AFTER schema.sql
-- Requires at least one user in the users table (created via setup.php)

-- ── Customers ─────────────────────────────────────────────────────────────────
INSERT INTO customers (name, company) VALUES
    ('Kevin Dobbins',   'Trinity Coatings'),
    ('Jeff Esparza',    'MD&A'),
    ('Francis Dinh',    'APG'),
    ('Scott Hughes',    'Techmetals'),
    ('Neil Thibodeaux', 'Arrow Aviation'),
    ('John Dowdy',      'ProEnergy'),
    ('Jennie Wilson',   'ProEnergy Houston');

SET @c_tc  = (SELECT id FROM customers WHERE name = 'Kevin Dobbins');
SET @c_mda = (SELECT id FROM customers WHERE name = 'Jeff Esparza');
SET @c_apg = (SELECT id FROM customers WHERE name = 'Francis Dinh');
SET @c_tm  = (SELECT id FROM customers WHERE name = 'Scott Hughes');
SET @c_aa  = (SELECT id FROM customers WHERE name = 'Neil Thibodeaux');
SET @c_pe1 = (SELECT id FROM customers WHERE name = 'John Dowdy');
SET @c_pe2 = (SELECT id FROM customers WHERE name = 'Jennie Wilson');

-- ── Quote 1: Trinity Coatings — 2026-02-11 ───────────────────────────────────
INSERT INTO quotes (quote_number, customer_id, status, notes, created_by, created_at, updated_at)
VALUES (1, @c_tc, 'approved', 'Terms: 1% 15, Net 30', 1, '2026-02-11', '2026-02-11');
SET @q1 = LAST_INSERT_ID();

INSERT INTO quote_items (quote_id, item_id, quantity, unit_price) VALUES
(@q1, (SELECT id FROM items WHERE base_sku='730S'  AND width_inches=1.000), 40, 25.04),
(@q1, (SELECT id FROM items WHERE base_sku='730S'  AND width_inches=2.000), 20, 49.48),
(@q1, (SELECT id FROM items WHERE base_sku='730SL' AND width_inches=1.000), 40, 24.90),
(@q1, (SELECT id FROM items WHERE base_sku='730SL' AND width_inches=2.000), 20, 49.20),
(@q1, (SELECT id FROM items WHERE base_sku='730D'  AND width_inches=1.000), 40, 29.63),
(@q1, (SELECT id FROM items WHERE base_sku='730D'  AND width_inches=2.000), 20, 58.55);

-- ── Quote 2: MD&A — 2026-03-31 ───────────────────────────────────────────────
INSERT INTO quotes (quote_number, customer_id, status, notes, created_by, created_at, updated_at)
VALUES (2, @c_mda, 'approved', 'Terms: Net 30', 1, '2026-03-31', '2026-03-31');
SET @q2 = LAST_INSERT_ID();

INSERT INTO quote_items (quote_id, item_id, quantity, unit_price) VALUES
(@q2, (SELECT id FROM items WHERE base_sku='730S' AND width_inches=4.000), 24, 129.84),
(@q2, (SELECT id FROM items WHERE base_sku='730S' AND width_inches=1.000), 30,  32.99);

-- ── Quote 3: APG — 2026-05-14 ────────────────────────────────────────────────
INSERT INTO quotes (quote_number, customer_id, status, notes, created_by, created_at, updated_at)
VALUES (3, @c_apg, 'approved', 'Terms: Net 30', 1, '2026-05-14', '2026-05-14');
SET @q3 = LAST_INSERT_ID();

INSERT INTO quote_items (quote_id, item_id, quantity, unit_price) VALUES
(@q3, (SELECT id FROM items WHERE base_sku='730S' AND width_inches=0.500), 24, 12.77),
(@q3, (SELECT id FROM items WHERE base_sku='730S' AND width_inches=1.000), 40, 25.04),
(@q3, (SELECT id FROM items WHERE base_sku='730S' AND width_inches=2.000), 20, 49.48);

-- ── Quote 4: Techmetals — 2026-05-22 ─────────────────────────────────────────
INSERT INTO quotes (quote_number, customer_id, status, notes, created_by, created_at, updated_at)
VALUES (4, @c_tm, 'approved', 'Terms: Net 30', 1, '2026-05-22', '2026-05-22');
SET @q4 = LAST_INSERT_ID();

INSERT INTO quote_items (quote_id, item_id, quantity, unit_price) VALUES
(@q4, (SELECT id FROM items WHERE base_sku='730S' AND width_inches=2.000), 10, 49.48);

-- ── Quote 5: Arrow Aviation — 2026-06-25 ─────────────────────────────────────
INSERT INTO quotes (quote_number, customer_id, status, notes, created_by, created_at, updated_at)
VALUES (5, @c_aa, 'approved', 'Terms: Net 30; Delivery: FedEx Ground', 1, '2026-06-25', '2026-06-25');
SET @q5 = LAST_INSERT_ID();

INSERT INTO quote_items (quote_id, item_id, quantity, unit_price) VALUES
(@q5, (SELECT id FROM items WHERE base_sku='730L' AND width_inches=2.000), 10, 38.99),
(@q5, (SELECT id FROM items WHERE base_sku='730L' AND width_inches=4.000),  5, 77.66);

-- ── Quote 6: ProEnergy Houston (Jennie Wilson) — 2026-07-15 ──────────────────
-- Note: 730D-.0125 in source file corrected to 0.125"
INSERT INTO quotes (quote_number, customer_id, status, notes, created_by, created_at, updated_at)
VALUES (6, @c_pe2, 'approved', 'Terms: Net 30', 1, '2026-07-15', '2026-07-15');
SET @q6 = LAST_INSERT_ID();

INSERT INTO quote_items (quote_id, item_id, quantity, unit_price) VALUES
(@q6, (SELECT id FROM items WHERE base_sku='730S' AND width_inches=0.250), 36,  7.29),
(@q6, (SELECT id FROM items WHERE base_sku='730S' AND width_inches=0.500), 60, 14.17),
(@q6, (SELECT id FROM items WHERE base_sku='730D' AND width_inches=0.250), 36,  7.46),
(@q6, (SELECT id FROM items WHERE base_sku='730D' AND width_inches=0.500), 60, 14.49),
(@q6, (SELECT id FROM items WHERE base_sku='730D' AND width_inches=0.125), 72,  4.15);

-- ── Quote 7: ProEnergy (John Dowdy) — 2026-07-17 ─────────────────────────────
INSERT INTO quotes (quote_number, customer_id, status, notes, created_by, created_at, updated_at)
VALUES (7, @c_pe1, 'approved', 'Terms: 1% 15, Net 30', 1, '2026-07-17', '2026-07-17');
SET @q7 = LAST_INSERT_ID();

INSERT INTO quote_items (quote_id, item_id, quantity, unit_price) VALUES
(@q7, (SELECT id FROM items WHERE base_sku='730S' AND width_inches=2.000), 40, 54.89),
(@q7, (SELECT id FROM items WHERE base_sku='730S' AND width_inches=1.000), 80, 27.78),
(@q7, (SELECT id FROM items WHERE base_sku='730D' AND width_inches=3.000), 48, 84.04),
(@q7, (SELECT id FROM items WHERE base_sku='730D' AND width_inches=2.000), 60, 56.14),
(@q7, (SELECT id FROM items WHERE base_sku='730D' AND width_inches=1.500), 84, 42.19),
(@q7, (SELECT id FROM items WHERE base_sku='730D' AND width_inches=1.000), 80, 28.41);
