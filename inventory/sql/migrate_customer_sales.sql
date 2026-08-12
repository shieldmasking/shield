-- Migration: commission assignments per customer per sales user
CREATE TABLE IF NOT EXISTS customer_sales (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    customer_id    INT NOT NULL,
    user_id        INT NOT NULL,
    commission_pct DECIMAL(5,2) NOT NULL DEFAULT 5.00,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_customer_user (customer_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
