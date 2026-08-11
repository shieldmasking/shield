<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Session first — before any output or requires that might emit whitespace
if (session_status() === PHP_SESSION_NONE) session_start();

// Do NOT require config.php here — db() loads it inside its own scope
require_once __DIR__ . '/../../inventory/inc/db.php';
require_once __DIR__ . '/../../inventory/inc/functions.php';

if (!isset($_SESSION['shop_cart'])) $_SESSION['shop_cart'] = [];

// ── Cart functions ────────────────────────────────────────────────────────────

function shop_cart_add(int $item_id, int $qty): void {
    $qty = max(1, $qty);
    $_SESSION['shop_cart'][$item_id] = ($_SESSION['shop_cart'][$item_id] ?? 0) + $qty;
}

function shop_cart_update(int $item_id, int $qty): void {
    if ($qty <= 0) {
        unset($_SESSION['shop_cart'][$item_id]);
    } else {
        $_SESSION['shop_cart'][$item_id] = $qty;
    }
}

function shop_cart_count(): int {
    return array_sum($_SESSION['shop_cart']);
}

function shop_cart_items(PDO $db): array {
    if (empty($_SESSION['shop_cart'])) return [];

    $ids          = array_keys($_SESSION['shop_cart']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $sql = "
        SELECT i.id, i.sku, i.width_inches, i.quantity_on_hand,
               p.name, p.description, p.land_cost_base, p.markup_multiplier,
               p.is_log, p.is_fixed_width, p.roll_length_yards,
               c.name AS category_name
        FROM items i
        JOIN products p ON p.base_sku = i.base_sku
        JOIN categories c ON c.id = p.category_id
        WHERE i.id IN ($placeholders)
          AND i.is_active = 1
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($ids);
    $rows = $stmt->fetchAll();

    $wm    = get_width_multipliers($db);
    $items = [];
    foreach ($rows as $row) {
        $qty               = (int)($_SESSION['shop_cart'][$row['id']] ?? 0);
        $unit_price        = calculate_sell_price($row, $wm);
        $row['qty']        = $qty;
        $row['unit_price'] = $unit_price;
        $row['line_total'] = round($unit_price * $qty, 2);
        $items[]           = $row;
    }
    return $items;
}

function shop_cart_total(PDO $db): float {
    $total = 0.0;
    foreach (shop_cart_items($db) as $item) {
        $total += $item['line_total'];
    }
    return round($total, 2);
}

// ── Table bootstrap ───────────────────────────────────────────────────────────

function shop_ensure_tables(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS shop_orders (
            id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_name         VARCHAR(150) NOT NULL,
            customer_email        VARCHAR(150) NOT NULL,
            customer_phone        VARCHAR(50)  DEFAULT NULL,
            shipping_address      TEXT         DEFAULT NULL,
            subtotal              DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            stripe_payment_intent VARCHAR(100) DEFAULT NULL,
            status                ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending',
            notes                 TEXT         DEFAULT NULL,
            created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS shop_order_items (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id    INT UNSIGNED NOT NULL,
            item_id     INT          NOT NULL,
            sku         VARCHAR(50)  NOT NULL,
            description VARCHAR(200) NOT NULL DEFAULT '',
            quantity    INT          NOT NULL,
            unit_price  DECIMAL(10,2) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}
