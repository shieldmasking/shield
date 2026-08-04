<?php

// ── Pricing ──────────────────────────────────────────────────────────────────

function get_width_multipliers(PDO $db): array {
    return $db->query('SELECT width_inches, multiplier FROM width_multipliers ORDER BY width_inches ASC')
              ->fetchAll(PDO::FETCH_KEY_PAIR);
}

function get_width_multiplier(float $width, array $multipliers): float {
    // Exact match
    foreach ($multipliers as $w => $m) {
        if (abs((float)$w - $width) < 0.0001) return (float)$m;
    }

    // Interpolate between two nearest values
    $widths = array_map('floatval', array_keys($multipliers));
    $mvals  = array_map('floatval', array_values($multipliers));
    sort($widths);

    for ($i = 0, $n = count($widths) - 1; $i < $n; $i++) {
        if ($width >= $widths[$i] && $width <= $widths[$i + 1]) {
            $t = ($width - $widths[$i]) / ($widths[$i + 1] - $widths[$i]);
            return $mvals[$i] + $t * ($mvals[$i + 1] - $mvals[$i]);
        }
    }

    // Out of range: clamp to nearest endpoint
    return $width < $widths[0] ? $mvals[0] : $mvals[count($mvals) - 1];
}

/**
 * Calculate sell price for an item row.
 * - Fixed-width (1000X): land_cost_base × markup (no width scaling)
 * - Log: land_cost_base × width_inches × markup (no width multiplier premium)
 * - Slit roll: land_cost_base × markup × width × width_multiplier
 */
function calculate_sell_price(array $item, array $width_multipliers): float {
    $land   = (float)$item['land_cost_base'];
    $markup = (float)$item['markup_multiplier'];
    $width  = (float)$item['width_inches'];

    if ($item['is_fixed_width']) {
        return round($land * $markup, 2);
    }
    if ($item['is_log']) {
        return round($land * $width * $markup, 2);
    }
    $wm = get_width_multiplier($width, $width_multipliers);
    return round($land * $markup * $width * $wm, 2);
}

function calculate_land_cost(array $item): float {
    if ($item['is_fixed_width']) {
        return round((float)$item['land_cost_base'], 2);
    }
    return round((float)$item['land_cost_base'] * (float)$item['width_inches'], 2);
}

/**
 * Generate the standard-width dropdown values: 0.125" to 6.0" in 0.125" increments.
 * Returns an array of floats.
 */
function standard_widths(): array {
    $widths = [];
    for ($i = 1; $i <= 48; $i++) {
        $widths[] = round($i * 0.125, 3);
    }
    return $widths;
}

/**
 * Build a display SKU from base_sku + width.
 * Examples: 730D + 1.0 → 730D-1, 730D + 0.125 → 730D-0125, 730D + 22.83 (log) → 730D-L22.8
 */
function make_item_sku(string $base_sku, float $width_inches, bool $is_log): string {
    if ($is_log) {
        $code = rtrim(rtrim(number_format($width_inches, 1, '.', ''), '0'), '.');
        return $base_sku . '-L' . $code;
    }
    // Remove decimal point, then strip trailing zeros
    $code = rtrim(str_replace('.', '', number_format($width_inches, 3, '.', '')), '0');
    return $base_sku . '-' . $code;
}

/**
 * Find or create an item row for a given base_sku + width_inches.
 * If the row doesn't exist, it is created by copying properties from any
 * existing row for the same base_sku.
 * Returns the item id, or 0 if base_sku is not found.
 */
function find_or_create_item(PDO $db, string $base_sku, float $width_inches, bool $is_log = false): int {
    // Try to find existing row
    $stmt = $db->prepare('SELECT id FROM items WHERE base_sku = ? AND width_inches = ?');
    $stmt->execute([$base_sku, $width_inches]);
    $row = $stmt->fetch();
    if ($row) return (int)$row['id'];

    // Copy base properties from any existing row for this base_sku
    $tmpl = $db->prepare('SELECT * FROM items WHERE base_sku = ? LIMIT 1');
    $tmpl->execute([$base_sku]);
    $tmpl = $tmpl->fetch();
    if (!$tmpl) return 0; // Unknown base_sku

    $sku = make_item_sku($base_sku, $width_inches, $is_log);

    $db->prepare('
        INSERT INTO items
            (base_sku, sku, name, category_id, coo, factory_product_num,
             thickness_mm, roll_length_yards, width_inches, is_log, is_fixed_width,
             land_cost_base, markup_multiplier, quantity_on_hand, reorder_threshold, is_active)
        VALUES (?,?,?,?,?,?,?,?,?,?,0,?,?,0,0,1)
    ')->execute([
        $base_sku, $sku, $tmpl['name'], $tmpl['category_id'], $tmpl['coo'],
        $tmpl['factory_product_num'], $tmpl['thickness_mm'], $tmpl['roll_length_yards'],
        $width_inches, $is_log ? 1 : 0,
        $tmpl['land_cost_base'], $tmpl['markup_multiplier'],
    ]);

    return (int)$db->lastInsertId();
}

// ── Quotes ───────────────────────────────────────────────────────────────────

function next_quote_number(PDO $db): int {
    $row = $db->query('SELECT COALESCE(MAX(quote_number), 0) + 1 AS next FROM quotes')->fetch();
    return (int)$row['next'];
}

function get_last_quote_prices(PDO $db, int $customer_id): array {
    $sql = '
        SELECT qi.item_id, qi.unit_price, qi.quantity,
               i.base_sku, i.sku, i.name AS item_name, i.width_inches, i.is_log, i.is_fixed_width
        FROM quote_items qi
        JOIN quotes q ON q.id = qi.quote_id
        JOIN items i ON i.id = qi.item_id
        WHERE q.customer_id = ?
          AND q.status IN (\'approved\', \'sent\')
        ORDER BY q.created_at DESC
    ';
    $stmt = $db->prepare($sql);
    $stmt->execute([$customer_id]);
    $rows = $stmt->fetchAll();

    $prices = [];
    foreach ($rows as $row) {
        if (!isset($prices[$row['item_id']])) {
            $prices[$row['item_id']] = [
                'unit_price'   => (float)$row['unit_price'],
                'quantity'     => (int)$row['quantity'],
                'base_sku'     => $row['base_sku'],
                'width_inches' => (float)$row['width_inches'],
                'is_log'       => (int)$row['is_log'],
                'is_fixed_width' => (int)$row['is_fixed_width'],
                'sku'          => $row['sku'],
                'item_name'    => $row['item_name'],
            ];
        }
    }
    return $prices;
}

// ── Inventory ─────────────────────────────────────────────────────────────────

function adjust_inventory(PDO $db, int $item_id, float $change_qty, string $reason, string $ref_type, ?int $ref_id, int $user_id): void {
    $db->prepare('UPDATE items SET quantity_on_hand = quantity_on_hand + ? WHERE id = ?')
       ->execute([$change_qty, $item_id]);

    $db->prepare('INSERT INTO inventory_log (item_id, change_qty, reason, reference_type, reference_id, created_by) VALUES (?,?,?,?,?,?)')
       ->execute([$item_id, $change_qty, $reason, $ref_type, $ref_id, $user_id]);

    check_low_stock($db, $item_id);
}

function check_low_stock(PDO $db, int $item_id): void {
    $item = $db->prepare('SELECT sku, name, quantity_on_hand, reorder_threshold FROM items WHERE id = ?');
    $item->execute([$item_id]);
    $item = $item->fetch();

    if ($item && $item['reorder_threshold'] > 0 && $item['quantity_on_hand'] <= $item['reorder_threshold']) {
        send_low_stock_alert($item);
    }
}

function send_low_stock_alert(array $item): void {
    global $mail_from, $mail_to;
    if (empty($mail_to)) return;

    $subject = "Low Stock Alert: {$item['sku']} — {$item['name']}";
    $body    = "Stock level for {$item['sku']} ({$item['name']}) has dropped to {$item['quantity_on_hand']} units, at or below the reorder threshold of {$item['reorder_threshold']}.\n\nLog in to place a reorder.";

    mail($mail_to, $subject, $body, "From: {$mail_from}");
}

// ── Orders ────────────────────────────────────────────────────────────────────

function create_order_from_quote(PDO $db, int $quote_id, int $user_id): int {
    $db->beginTransaction();
    try {
        $quote = $db->prepare('SELECT * FROM quotes WHERE id = ?');
        $quote->execute([$quote_id]);
        $quote = $quote->fetch();

        $items_stmt = $db->prepare('SELECT * FROM quote_items WHERE quote_id = ?');
        $items_stmt->execute([$quote_id]);
        $line_items = $items_stmt->fetchAll();

        $db->prepare('INSERT INTO orders (quote_id, customer_id, created_by) VALUES (?,?,?)')
           ->execute([$quote_id, $quote['customer_id'], $user_id]);
        $order_id = (int)$db->lastInsertId();

        foreach ($line_items as $li) {
            $db->prepare('INSERT INTO order_items (order_id, item_id, quantity, unit_price) VALUES (?,?,?,?)')
               ->execute([$order_id, $li['item_id'], $li['quantity'], $li['unit_price']]);

            adjust_inventory(
                $db,
                (int)$li['item_id'],
                -(int)$li['quantity'],
                "Order from Quote #{$quote['quote_number']}",
                'order',
                $order_id,
                $user_id
            );
        }

        $db->commit();
        return $order_id;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

// ── Formatting ────────────────────────────────────────────────────────────────

function currency(float $amount): string {
    return '$' . number_format($amount, 2);
}

function h(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function format_width(float $w): string {
    return rtrim(rtrim(number_format($w, 3), '0'), '.') . '"';
}

/**
 * Human-readable width label for display in tables/PDFs.
 * is_log items show the log width; fixed-width shows "2" (fixed)".
 */
function width_label(array $item): string {
    if ($item['is_fixed_width']) return '2" (fixed)';
    if ($item['is_log'])        return 'Log ' . format_width((float)$item['width_inches']);
    return format_width((float)$item['width_inches']);
}
