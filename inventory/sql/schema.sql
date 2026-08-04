-- Shield Masking — Inventory & Quoting Platform
-- v2: Sparse per-SKU+width inventory model
-- Safe to re-run: drops and recreates all tables

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS quote_items;
DROP TABLE IF EXISTS quotes;
DROP TABLE IF EXISTS inventory_log;
DROP TABLE IF EXISTS items;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS width_multipliers;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

-- --------------------------------------------------------
-- Users
-- --------------------------------------------------------
CREATE TABLE users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    email           VARCHAR(150) NOT NULL UNIQUE,
    phone           VARCHAR(30) NULL,
    password_hash   VARCHAR(255) NOT NULL,
    failed_attempts       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until          DATETIME NULL,
    force_password_change TINYINT(1) NOT NULL DEFAULT 0,
    is_admin              TINYINT(1) NOT NULL DEFAULT 0,
    created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Categories
-- --------------------------------------------------------
CREATE TABLE categories (
    id   INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO categories (name) VALUES
    ('Glass Cloth Tape - No Liner'),
    ('Glass Cloth Tape w/Liner'),
    ('Glass Cloth Tape - 2 Layer w/Liner'),
    ('Aluminum/Glass Cloth Tape - No Liner'),
    ('Silicone Rubber Blasting Tape');

-- --------------------------------------------------------
-- Items — one row per base_sku + width combination (sparse)
--
-- Rows are created on demand via find_or_create_item().
-- base_sku + width_inches must be unique.
-- land_cost_base and markup_multiplier are per base_sku
-- (denormalized for simplicity); editing them in admin
-- updates all rows sharing the same base_sku.
-- --------------------------------------------------------
CREATE TABLE items (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    base_sku            VARCHAR(20)  NOT NULL,
    sku                 VARCHAR(30)  NOT NULL UNIQUE COMMENT 'e.g. 730D-1, 730D-0125, 730D-L22.8',
    name                VARCHAR(150) NOT NULL,
    category_id         INT NOT NULL,
    coo                 CHAR(2) NOT NULL,
    factory_product_num VARCHAR(50) NULL,
    thickness_mm        DECIMAL(4,2) NULL,
    roll_length_yards   DECIMAL(6,2) NOT NULL,
    width_inches        DECIMAL(6,3) NOT NULL COMMENT 'Slit width or full log width',
    is_log              TINYINT(1)  NOT NULL DEFAULT 0,
    is_fixed_width      TINYINT(1)  NOT NULL DEFAULT 0 COMMENT '1 = no slitting (1000X)',
    land_cost_base      DECIMAL(10,2) NOT NULL COMMENT '1" land cost for this base_sku',
    markup_multiplier   DECIMAL(6,4)  NOT NULL DEFAULT 2.1900,
    quantity_on_hand    DECIMAL(10,2) NOT NULL DEFAULT 0,
    reorder_threshold   DECIMAL(10,2) NOT NULL DEFAULT 0,
    is_active           TINYINT(1)  NOT NULL DEFAULT 1,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_base_width (base_sku, width_inches),
    FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed: 730D — Glass Cloth Tape 2-Layer w/Liner (Heavy) | TW | 18 yds | $19.42 base | ×2.20
-- Widths from inventory PDF; only rows with NEW OH > 0 get stocked, rest pre-defined
INSERT INTO items (base_sku, sku, name, category_id, coo, factory_product_num, thickness_mm, roll_length_yards, width_inches, is_log, land_cost_base, markup_multiplier, quantity_on_hand) VALUES
('730D','730D-0.125','Glass Cloth Tape - 2 Layer w/Liner (Heavy)',3,'TW','P730W-5-2L',0.60,18,0.125,0,19.42,2.2000,34),
('730D','730D-0.25', 'Glass Cloth Tape - 2 Layer w/Liner (Heavy)',3,'TW','P730W-5-2L',0.60,18,0.250,0,19.42,2.2000,30),
('730D','730D-0.375','Glass Cloth Tape - 2 Layer w/Liner (Heavy)',3,'TW','P730W-5-2L',0.60,18,0.375,0,19.42,2.2000,0),
('730D','730D-0.5',  'Glass Cloth Tape - 2 Layer w/Liner (Heavy)',3,'TW','P730W-5-2L',0.60,18,0.500,0,19.42,2.2000,7),
('730D','730D-0.75', 'Glass Cloth Tape - 2 Layer w/Liner (Heavy)',3,'TW','P730W-5-2L',0.60,18,0.750,0,19.42,2.2000,59),
('730D','730D-1',   'Glass Cloth Tape - 2 Layer w/Liner (Heavy)',3,'TW','P730W-5-2L',0.60,18,1.000,0,19.42,2.2000,139),
('730D','730D-1.25', 'Glass Cloth Tape - 2 Layer w/Liner (Heavy)',3,'TW','P730W-5-2L',0.60,18,1.250,0,19.42,2.2000,0),
('730D','730D-1.5',  'Glass Cloth Tape - 2 Layer w/Liner (Heavy)',3,'TW','P730W-5-2L',0.60,18,1.500,0,19.42,2.2000,0),
('730D','730D-2',   'Glass Cloth Tape - 2 Layer w/Liner (Heavy)',3,'TW','P730W-5-2L',0.60,18,2.000,0,19.42,2.2000,125),
('730D','730D-3',   'Glass Cloth Tape - 2 Layer w/Liner (Heavy)',3,'TW','P730W-5-2L',0.60,18,3.000,0,19.42,2.2000,0),
('730D','730D-4',   'Glass Cloth Tape - 2 Layer w/Liner (Heavy)',3,'TW','P730W-5-2L',0.60,18,4.000,0,19.42,2.2000,0),
('730D','730D-5',   'Glass Cloth Tape - 2 Layer w/Liner (Heavy)',3,'TW','P730W-5-2L',0.60,18,5.000,0,19.42,2.2000,0),
('730D','730D-L22.8','Glass Cloth Tape - 2 Layer w/Liner (Heavy)',3,'TW','P730W-5-2L',0.60,18,22.830,1,19.42,2.2000,0);

-- Seed: 730L — Glass Cloth Tape w/Liner | TW | 36 yds | $14.03 base | ×2.11
INSERT INTO items (base_sku, sku, name, category_id, coo, factory_product_num, thickness_mm, roll_length_yards, width_inches, is_log, land_cost_base, markup_multiplier, quantity_on_hand) VALUES
('730L','730L-1',  'Glass Cloth Tape w/Liner',2,'TW','P700V-1',0.19,36,1.000,0,14.03,2.1100,341),
('730L','730L-2',  'Glass Cloth Tape w/Liner',2,'TW','P700V-1',0.19,36,2.000,0,14.03,2.1100,160),
('730L','730L-3',  'Glass Cloth Tape w/Liner',2,'TW','P700V-1',0.19,36,3.000,0,14.03,2.1100,0),
('730L','730L-4',  'Glass Cloth Tape w/Liner',2,'TW','P700V-1',0.19,36,4.000,0,14.03,2.1100,9),
('730L','730L-5',  'Glass Cloth Tape w/Liner',2,'TW','P700V-1',0.19,36,5.000,0,14.03,2.1100,0),
('730L','730L-6',  'Glass Cloth Tape w/Liner',2,'TW','P700V-1',0.19,36,6.000,0,14.03,2.1100,66),
('730L','730L-L48','Glass Cloth Tape w/Liner',2,'TW','P700V-1',0.19,36,48.030,1,14.03,2.1100,11);

-- Seed: 730S — Glass Cloth Tape w/Liner (Heavy) | TW | 36 yds | $15.89 base | ×2.19
INSERT INTO items (base_sku, sku, name, category_id, coo, factory_product_num, thickness_mm, roll_length_yards, width_inches, is_log, land_cost_base, markup_multiplier, quantity_on_hand) VALUES
('730S','730S-0.125','Glass Cloth Tape w/Liner (Heavy)',2,'TW','P730W-5',0.30,36,0.125,0,15.89,2.1900,160),
('730S','730S-0.25', 'Glass Cloth Tape w/Liner (Heavy)',2,'TW','P730W-5',0.30,36,0.250,0,15.89,2.1900,92),
('730S','730S-0.375','Glass Cloth Tape w/Liner (Heavy)',2,'TW','P730W-5',0.30,36,0.375,0,15.89,2.1900,0),
('730S','730S-0.5',  'Glass Cloth Tape w/Liner (Heavy)',2,'TW','P730W-5',0.30,36,0.500,0,15.89,2.1900,6),
('730S','730S-0.75', 'Glass Cloth Tape w/Liner (Heavy)',2,'TW','P730W-5',0.30,36,0.750,0,15.89,2.1900,0),
('730S','730S-1',   'Glass Cloth Tape w/Liner (Heavy)',2,'TW','P730W-5',0.30,36,1.000,0,15.89,2.1900,75),
('730S','730S-1.25', 'Glass Cloth Tape w/Liner (Heavy)',2,'TW','P730W-5',0.30,36,1.250,0,15.89,2.1900,18),
('730S','730S-1.5',  'Glass Cloth Tape w/Liner (Heavy)',2,'TW','P730W-5',0.30,36,1.500,0,15.89,2.1900,28),
('730S','730S-2',   'Glass Cloth Tape w/Liner (Heavy)',2,'TW','P730W-5',0.30,36,2.000,0,15.89,2.1900,66),
('730S','730S-3',   'Glass Cloth Tape w/Liner (Heavy)',2,'TW','P730W-5',0.30,36,3.000,0,15.89,2.1900,12),
('730S','730S-4',   'Glass Cloth Tape w/Liner (Heavy)',2,'TW','P730W-5',0.30,36,4.000,0,15.89,2.1900,28),
('730S','730S-5',   'Glass Cloth Tape w/Liner (Heavy)',2,'TW','P730W-5',0.30,36,5.000,0,15.89,2.1900,0),
('730S','730S-L48', 'Glass Cloth Tape w/Liner (Heavy)',2,'TW','P730W-5',0.30,36,48.030,1,15.89,2.1900,9);

-- Seed: 730SL — Glass Cloth Tape 2-Layer w/Liner | TW | 18 yds | $17.33 base | ×2.22
INSERT INTO items (base_sku, sku, name, category_id, coo, factory_product_num, thickness_mm, roll_length_yards, width_inches, is_log, land_cost_base, markup_multiplier, quantity_on_hand) VALUES
('730SL','730SL-1',   'Glass Cloth Tape - 2 Layer w/Liner',3,'TW','P730M-2',0.49,18,1.000,0,17.33,2.2200,128),
('730SL','730SL-1.5',  'Glass Cloth Tape - 2 Layer w/Liner',3,'TW','P730M-2',0.49,18,1.500,0,17.33,2.2200,56),
('730SL','730SL-2',   'Glass Cloth Tape - 2 Layer w/Liner',3,'TW','P730M-2',0.49,18,2.000,0,17.33,2.2200,80),
('730SL','730SL-5',   'Glass Cloth Tape - 2 Layer w/Liner',3,'TW','P730M-2',0.49,18,5.000,0,17.33,2.2200,100),
('730SL','730SL-L42', 'Glass Cloth Tape - 2 Layer w/Liner',3,'TW','P730M-2',0.49,18,42.000,1,17.33,2.2200,0);

-- Seed: 520N — Glass Cloth Tape No Liner | CN | 36 yds | $9.98 base | ×2.19 (template at 1")
INSERT INTO items (base_sku, sku, name, category_id, coo, factory_product_num, thickness_mm, roll_length_yards, width_inches, is_log, land_cost_base, markup_multiplier, quantity_on_hand) VALUES
('520N','520N-1','Glass Cloth Tape - No Liner',1,'CN','XBQ-3120',0.20,36,1.000,0,9.98,2.1900,0);

-- Seed: 526N — Glass Cloth Tape No Liner Heavy | CN | 36 yds | $11.86 base | ×2.19 (template at 1")
INSERT INTO items (base_sku, sku, name, category_id, coo, factory_product_num, thickness_mm, roll_length_yards, width_inches, is_log, land_cost_base, markup_multiplier, quantity_on_hand) VALUES
('526N','526N-1','Glass Cloth Tape - No Liner (Heavy)',1,'CN','XBQ-3123',0.26,36,1.000,0,11.86,2.1900,0);

-- Seed: 962S — Aluminum/Glass Cloth Tape No Liner | CN | 36 yds | $11.85 base | ×2.19 (template at 1")
INSERT INTO items (base_sku, sku, name, category_id, coo, factory_product_num, thickness_mm, roll_length_yards, width_inches, is_log, land_cost_base, markup_multiplier, quantity_on_hand) VALUES
('962S','962S-1','Aluminum/Glass Cloth Tape - No Liner',4,'CN','XBQ-028',0.28,36,1.000,0,11.85,2.1900,0);

-- Seed: 1000X — Silicone Rubber Blasting Tape | CN | 10 yds | fixed 2" | $23.75 | ×2.18
INSERT INTO items (base_sku, sku, name, category_id, coo, factory_product_num, thickness_mm, roll_length_yards, width_inches, is_log, is_fixed_width, land_cost_base, markup_multiplier, quantity_on_hand) VALUES
('1000X','1000X-2','Silicone Rubber Blasting Tape',5,'CN','XBQ-GT100',1.25,10,2.000,0,1,23.75,2.1800,0);

-- --------------------------------------------------------
-- Width Multipliers (pricing breakpoints; interpolated for intermediate widths)
-- --------------------------------------------------------
CREATE TABLE width_multipliers (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    width_inches DECIMAL(5,3) NOT NULL UNIQUE,
    multiplier   DECIMAL(6,4) NOT NULL,
    label        VARCHAR(20) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO width_multipliers (width_inches, multiplier, label) VALUES
    (0.125, 1.4600, '1/8"'),
    (0.250, 1.2000, '1/4"'),
    (0.375, 1.1000, '3/8"'),
    (0.500, 1.0500, '1/2"'),
    (0.750, 1.0200, '3/4"'),
    (1.000, 1.0000, '1"'),
    (2.000, 0.9800, '2"'),
    (3.000, 0.9700, '3"'),
    (4.000, 0.9600, '4"'),
    (5.000, 0.9500, '5"'),
    (6.000, 0.9400, '6"');

-- --------------------------------------------------------
-- Inventory Log
-- --------------------------------------------------------
CREATE TABLE inventory_log (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    item_id        INT NOT NULL,
    change_qty     DECIMAL(10,2) NOT NULL,
    reason         VARCHAR(150) NOT NULL,
    reference_type ENUM('order','receiving','adjustment','manual') NOT NULL,
    reference_id   INT NULL,
    created_by     INT NOT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id) REFERENCES items(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Customers
-- --------------------------------------------------------
CREATE TABLE customers (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    qb_customer_id  VARCHAR(50) NULL UNIQUE,
    name            VARCHAR(150) NOT NULL,
    company         VARCHAR(150) NULL,
    email           VARCHAR(150) NULL,
    phone           VARCHAR(30) NULL,
    billing_address TEXT NULL,
    terms           VARCHAR(100) NOT NULL DEFAULT 'Net 30',
    synced_at       DATETIME NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Quotes
-- --------------------------------------------------------
CREATE TABLE quotes (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    quote_number INT NOT NULL UNIQUE,
    customer_id  INT NOT NULL,
    status       ENUM('draft','sent','approved','expired','rejected') NOT NULL DEFAULT 'draft',
    notes        TEXT NULL,
    po_pdf_path  VARCHAR(255) NULL,
    created_by   INT NOT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Quote Line Items (width derived from items.width_inches)
-- --------------------------------------------------------
CREATE TABLE quote_items (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    quote_id   INT NOT NULL,
    item_id    INT NOT NULL,
    quantity   INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Orders
-- --------------------------------------------------------
CREATE TABLE orders (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    quote_id       INT NOT NULL UNIQUE,
    customer_id    INT NOT NULL,
    qb_invoice_id  VARCHAR(50) NULL,
    notes          TEXT NULL,
    created_by     INT NOT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (quote_id) REFERENCES quotes(id),
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Order Line Items (width derived from items.width_inches)
-- --------------------------------------------------------
CREATE TABLE order_items (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    order_id   INT NOT NULL,
    item_id    INT NOT NULL,
    quantity   INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Settings
-- --------------------------------------------------------
CREATE TABLE settings (
    `key`   VARCHAR(50) PRIMARY KEY,
    value   TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO settings (`key`, value) VALUES
    ('company_name',    'Shield Masking Solutions'),
    ('company_address', ''),
    ('company_phone',   ''),
    ('company_email',   ''),
    ('low_stock_email', 'admin@shieldmasking.com'),
    ('qb_sync_enabled', '0');
