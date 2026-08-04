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

function calculate_sell_price(array $item, float $width, array $width_multipliers): float {
    if ($item['is_fixed_width']) {
        return round((float)$item['land_cost_base'] * (float)$item['markup_multiplier'], 2);
    }
    $wm = get_width_multiplier($width, $width_multipliers);
    return round((float)$item['land_cost_base'] * (float)$item['markup_multiplier'] * $width * $wm, 2);
}

function calculate_land_cost(array $item, float $width): float {
    if ($item['is_fixed_width']) {
        return round((float)$item['land_cost_base'], 2);
    }
    return round((float)$item['land_cost_base'] * $width, 2);
}

// ── Quotes ───────────────────────────────────────────────────────────────────

function next_quote_number(PDO $db): int {
    $row = $db->query('SELECT COALESCE(MAX(quote_number), 0) + 1 AS next FROM quotes')->fetch();
    return (int)$row['next'];
}

function get_last_quote_prices(PDO $db, int $customer_id): array {
    // Returns [item_id => ['width_inches', 'unit_price', 'quantity']] from most recent approved/sent quote
    $sql = '
        SELECT qi.item_id, qi.width_inches, qi.unit_price, qi.quantity,
               i.sku, i.name AS item_name
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
                'width_inches' => (float)$row['width_inches'],
                'unit_price'   => (float)$row['unit_price'],
                'quantity'     => (int)$row['quantity'],
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

        // Create order record
        $db->prepare('INSERT INTO orders (quote_id, customer_id, created_by) VALUES (?,?,?)')
           ->execute([$quote_id, $quote['customer_id'], $user_id]);
        $order_id = (int)$db->lastInsertId();

        // Create order line items and deduct inventory
        foreach ($line_items as $li) {
            $db->prepare('INSERT INTO order_items (order_id, item_id, width_inches, quantity, unit_price) VALUES (?,?,?,?,?)')
               ->execute([$order_id, $li['item_id'], $li['width_inches'], $li['quantity'], $li['unit_price']]);

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
