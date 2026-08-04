-- Shield Masking — Migrate base-SKU fields from items into a new products table
-- Run once on the live DB. Safe to inspect beforehand; not idempotent.

SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------------------
-- 1. Create products table
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
    is_fixed_width      TINYINT(1)    NOT NULL DEFAULT 0,
    land_cost_base      DECIMAL(10,4) NOT NULL DEFAULT 0,
    markup_multiplier   DECIMAL(8,4)  NOT NULL DEFAULT 2.1900,
    PRIMARY KEY (base_sku),
    FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- 2. Populate products from existing items (one row per base_sku)
-- --------------------------------------------------------
INSERT INTO products
    (base_sku, name, category_id, coo, factory_product_num,
     thickness_mm, roll_length_yards, is_log, is_fixed_width,
     land_cost_base, markup_multiplier)
SELECT
    base_sku, name, category_id, coo, factory_product_num,
    thickness_mm, roll_length_yards,
    MAX(is_log),        -- if any row is a log, carry that
    MAX(is_fixed_width),
    MAX(land_cost_base),
    MAX(markup_multiplier)
FROM items
GROUP BY base_sku;

-- --------------------------------------------------------
-- 3. Add FK from items.base_sku → products.base_sku
-- --------------------------------------------------------
ALTER TABLE items
    ADD CONSTRAINT fk_items_base_sku
    FOREIGN KEY (base_sku) REFERENCES products(base_sku);

-- --------------------------------------------------------
-- 4. Drop now-redundant columns from items
-- --------------------------------------------------------
ALTER TABLE items
    DROP FOREIGN KEY fk_items_category,   -- drop old category FK if named (may error if unnamed; adjust as needed)
    DROP COLUMN name,
    DROP COLUMN category_id,
    DROP COLUMN coo,
    DROP COLUMN factory_product_num,
    DROP COLUMN thickness_mm,
    DROP COLUMN roll_length_yards,
    DROP COLUMN is_log,
    DROP COLUMN is_fixed_width,
    DROP COLUMN land_cost_base,
    DROP COLUMN markup_multiplier;

SET FOREIGN_KEY_CHECKS = 1;
