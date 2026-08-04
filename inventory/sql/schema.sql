-- Shield Masking — Inventory & Quoting Platform
-- Run once to initialize the database

SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------------------
-- Users
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    email           VARCHAR(150) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until    DATETIME NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Categories
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
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
-- Items (SKUs)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS items (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    sku                 VARCHAR(20) NOT NULL UNIQUE,
    name                VARCHAR(150) NOT NULL,
    category_id         INT NOT NULL,
    coo                 CHAR(2) NOT NULL COMMENT 'Country of origin',
    factory_product_num VARCHAR(50) NULL,
    thickness_mm        DECIMAL(4,2) NULL,
    log_width_inches    DECIMAL(6,2) NULL COMMENT 'NULL for fixed-width items',
    roll_length_yards   DECIMAL(6,2) NOT NULL,
    land_cost_base      DECIMAL(10,2) NOT NULL COMMENT '1in land cost; 2in for fixed-width items',
    markup_multiplier   DECIMAL(6,4) NOT NULL DEFAULT 2.1900,
    is_fixed_width      TINYINT(1) NOT NULL DEFAULT 0,
    fixed_width_inches  DECIMAL(4,2) NULL,
    quantity_on_hand    DECIMAL(10,2) NOT NULL DEFAULT 0,
    reorder_threshold   DECIMAL(10,2) NOT NULL DEFAULT 0,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO items (sku, name, category_id, coo, factory_product_num, thickness_mm, log_width_inches, roll_length_yards, land_cost_base, markup_multiplier, is_fixed_width, fixed_width_inches) VALUES
    ('520N',  'Glass Cloth Tape - No Liner',           1, 'CN', 'XBQ-3120',    0.20, 49.00, 36, 9.98,  2.1900, 0, NULL),
    ('526N',  'Glass Cloth Tape - No Liner (Heavy)',   1, 'CN', 'XBQ-3123',    0.26, 49.00, 36, 11.86, 2.1900, 0, NULL),
    ('730L',  'Glass Cloth Tape w/Liner',              2, 'TW', 'P700V-1',     0.19, 48.03, 36, 14.03, 2.1100, 0, NULL),
    ('730S',  'Glass Cloth Tape w/Liner (Heavy)',      2, 'TW', 'P730W-5',     0.30, 48.03, 36, 15.89, 2.1900, 0, NULL),
    ('730SL', 'Glass Cloth Tape - 2 Layer w/Liner',   3, 'TW', 'P730M-2',     0.49, 19.89, 18, 17.33, 2.2200, 0, NULL),
    ('730D',  'Glass Cloth Tape - 2 Layer w/Liner (Heavy)', 3, 'TW', 'P730W-5-2L', 0.60, 22.83, 18, 19.42, 2.2000, 0, NULL),
    ('962S',  'Aluminum/Glass Cloth Tape - No Liner',  4, 'CN', 'XBQ-028',     0.28, 48.03, 36, 11.85, 2.1900, 0, NULL),
    ('1000X', 'Silicone Rubber Blasting Tape',         5, 'CN', 'XBQ-GT100',   1.25, NULL,  10, 23.75, 2.1800, 1, 2.00);

-- --------------------------------------------------------
-- Width Multipliers
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS width_multipliers (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    width_inches DECIMAL(5,3) NOT NULL UNIQUE,
    multiplier   DECIMAL(6,4) NOT NULL,
    label        VARCHAR(20) NULL COMMENT 'Display label'
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
CREATE TABLE IF NOT EXISTS inventory_log (
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
-- Customers (synced from QuickBooks Online)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS customers (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    qb_customer_id  VARCHAR(50) NULL UNIQUE,
    name            VARCHAR(150) NOT NULL,
    company         VARCHAR(150) NULL,
    email           VARCHAR(150) NULL,
    phone           VARCHAR(30) NULL,
    billing_address TEXT NULL,
    synced_at       DATETIME NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Quotes
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS quotes (
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
-- Quote Line Items
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS quote_items (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    quote_id     INT NOT NULL,
    item_id      INT NOT NULL,
    width_inches DECIMAL(5,3) NOT NULL,
    quantity     INT NOT NULL,
    unit_price   DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Orders
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
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
-- Order Line Items
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS order_items (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    order_id     INT NOT NULL,
    item_id      INT NOT NULL,
    width_inches DECIMAL(5,3) NOT NULL,
    quantity     INT NOT NULL,
    unit_price   DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Settings
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    `key`   VARCHAR(50) PRIMARY KEY,
    value   TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO settings (`key`, value) VALUES
    ('company_name', 'Shield Masking Solutions'),
    ('low_stock_email', 'admin@shieldmasking.com'),
    ('qb_sync_enabled', '0');

SET FOREIGN_KEY_CHECKS = 1;
