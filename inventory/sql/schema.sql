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
DROP TABLE IF EXISTS products;
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
-- Products — one row per base_sku (base-level properties)
-- --------------------------------------------------------
CREATE TABLE products (
    base_sku            VARCHAR(20)   NOT NULL,
    name                VARCHAR(150)  NOT NULL,
    description         TEXT          NULL,
    datasheet_path      VARCHAR(255)  NULL,
    category_id         INT           NOT NULL,
    coo                 CHAR(2)       NOT NULL DEFAULT 'TW',
    factory_product_num VARCHAR(50)   NULL,
    thickness_mm        DECIMAL(6,3)  NULL,
    roll_length_yards   DECIMAL(8,2)  NOT NULL,
    is_log              TINYINT(1)    NOT NULL DEFAULT 0,
    is_fixed_width      TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = no slitting (1000X)',
    land_cost_base      DECIMAL(10,4) NOT NULL DEFAULT 0 COMMENT '1" land cost for this base_sku',
    markup_multiplier   DECIMAL(8,4)  NOT NULL DEFAULT 2.1900,
    PRIMARY KEY (base_sku),
    FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Items — one row per base_sku + width combination (sparse)
--
-- Rows are created on demand via find_or_create_item().
-- base_sku + width_inches must be unique.
-- --------------------------------------------------------
CREATE TABLE items (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    base_sku            VARCHAR(20)   NOT NULL,
    sku                 VARCHAR(40)   NOT NULL UNIQUE COMMENT 'e.g. 730D-1, 730D-0.125, 730D-L22.8',
    width_inches        DECIMAL(8,4)  NOT NULL COMMENT 'Slit width or full log width',
    quantity_on_hand    DECIMAL(10,2) NOT NULL DEFAULT 0,
    reorder_threshold   DECIMAL(10,2) NOT NULL DEFAULT 0,
    is_active           TINYINT(1)    NOT NULL DEFAULT 1,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_base_width (base_sku, width_inches),
    FOREIGN KEY (base_sku) REFERENCES products(base_sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed: products
INSERT INTO products (base_sku, name, category_id, coo, factory_product_num, thickness_mm, roll_length_yards, is_log, is_fixed_width, land_cost_base, markup_multiplier) VALUES
('730D',  'Glass Cloth Tape - 2 Layer w/Liner (Heavy)', 3,'TW','P730W-5-2L',0.600,18,0,0,19.4200,2.2000),
('730L',  'Glass Cloth Tape w/Liner',                  2,'TW','P700V-1',    0.190,36,0,0,14.0300,2.1100),
('730S',  'Glass Cloth Tape w/Liner (Heavy)',           2,'TW','P730W-5',    0.300,36,0,0,15.8900,2.1900),
('730SL', 'Glass Cloth Tape - 2 Layer w/Liner',        3,'TW','P730M-2',    0.490,18,0,0,17.3300,2.2200),
('520N',  'Glass Cloth Tape - No Liner',                1,'CN','XBQ-3120',   0.200,36,0,0, 9.9800,2.1900),
('526N',  'Glass Cloth Tape - No Liner (Heavy)',        1,'CN','XBQ-3123',   0.260,36,0,0,11.8600,2.1900),
('962S',  'Aluminum/Glass Cloth Tape - No Liner',       4,'CN','XBQ-028',    0.280,36,0,0,11.8500,2.1900),
('1000X', 'Silicone Rubber Blasting Tape',              5,'CN','XBQ-GT100',  1.250,10,0,1,23.7500,2.1800);

-- Seed: 730D widths
INSERT INTO items (base_sku, sku, width_inches, quantity_on_hand) VALUES
('730D','730D-0.125', 0.1250,34),
('730D','730D-0.25',  0.2500,30),
('730D','730D-0.375', 0.3750, 0),
('730D','730D-0.5',   0.5000, 7),
('730D','730D-0.75',  0.7500,59),
('730D','730D-1',     1.0000,139),
('730D','730D-1.25',  1.2500, 0),
('730D','730D-1.5',   1.5000, 0),
('730D','730D-2',     2.0000,125),
('730D','730D-3',     3.0000, 0),
('730D','730D-4',     4.0000, 0),
('730D','730D-5',     5.0000, 0),
('730D','730D-L22.8',22.8300, 0);

-- Seed: 730L widths
INSERT INTO items (base_sku, sku, width_inches, quantity_on_hand) VALUES
('730L','730L-1',   1.0000,341),
('730L','730L-2',   2.0000,160),
('730L','730L-3',   3.0000,  0),
('730L','730L-4',   4.0000,  9),
('730L','730L-5',   5.0000,  0),
('730L','730L-6',   6.0000, 66),
('730L','730L-L48',48.0300, 11);

-- Seed: 730S widths
INSERT INTO items (base_sku, sku, width_inches, quantity_on_hand) VALUES
('730S','730S-0.125', 0.1250,160),
('730S','730S-0.25',  0.2500, 92),
('730S','730S-0.375', 0.3750,  0),
('730S','730S-0.5',   0.5000,  6),
('730S','730S-0.75',  0.7500,  0),
('730S','730S-1',     1.0000, 75),
('730S','730S-1.25',  1.2500, 18),
('730S','730S-1.5',   1.5000, 28),
('730S','730S-2',     2.0000, 66),
('730S','730S-3',     3.0000, 12),
('730S','730S-4',     4.0000, 28),
('730S','730S-5',     5.0000,  0),
('730S','730S-L48',  48.0300,  9);

-- Seed: 730SL widths
INSERT INTO items (base_sku, sku, width_inches, quantity_on_hand) VALUES
('730SL','730SL-1',    1.0000,128),
('730SL','730SL-1.5',  1.5000, 56),
('730SL','730SL-2',    2.0000, 80),
('730SL','730SL-5',    5.0000,100),
('730SL','730SL-L42', 42.0000,  0);

-- Seed: 520N, 526N, 962S, 1000X template widths
INSERT INTO items (base_sku, sku, width_inches, quantity_on_hand) VALUES
('520N', '520N-1',   1.0000,0),
('526N', '526N-1',   1.0000,0),
('962S', '962S-1',   1.0000,0),
('1000X','1000X-2',  2.0000,0);

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
