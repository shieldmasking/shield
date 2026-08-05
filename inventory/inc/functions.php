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
    $code = rtrim(rtrim(number_format($width_inches, 3, '.', ''), '0'), '.');
    if ($is_log) {
        return $base_sku . '-L' . $code;
    }
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

    // Verify base_sku exists in products
    $tmpl = $db->prepare('SELECT base_sku FROM products WHERE base_sku = ?');
    $tmpl->execute([$base_sku]);
    if (!$tmpl->fetch()) return 0; // Unknown base_sku

    $sku = make_item_sku($base_sku, $width_inches, $is_log);

    $db->prepare('
        INSERT INTO items (base_sku, sku, width_inches, is_active)
        VALUES (?,?,?,1)
    ')->execute([$base_sku, $sku, $width_inches]);

    return (int)$db->lastInsertId();
}

// ── Quotes ───────────────────────────────────────────────────────────────────

function next_quote_number(PDO $db): int {
    $row = $db->query('SELECT COALESCE(MAX(quote_number), 0) + 1 AS next FROM quotes')->fetch();
    return max((int)$row['next'], 53821);
}

function get_last_quote_prices(PDO $db, int $customer_id): array {
    $sql = '
        SELECT qi.item_id, qi.unit_price, qi.quantity,
               i.base_sku, i.sku, p.name AS item_name, i.width_inches, p.is_log, p.is_fixed_width
        FROM quote_items qi
        JOIN quotes q ON q.id = qi.quote_id
        JOIN items i ON i.id = qi.item_id
        JOIN products p ON p.base_sku = i.base_sku
        WHERE q.customer_id = ?
          AND q.status IN (\'ordered\', \'sent\')
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

// ── PDF PO Parsing ────────────────────────────────────────────────────────────

/**
 * Extract line items from a PO PDF.
 * Matches known SKUs in the text; extracts qty and unit price from the same line.
 * Returns ['parse_error' => bool, 'items' => [['sku','item_id','qty','price'], ...]].
 */
function parse_po_pdf(string $file_path, PDO $db): array {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) {
        return ['parse_error' => true, 'items' => []];
    }
    require_once $autoload;

    try {
        $parser = new \Smalot\PdfParser\Parser();
        $text   = $parser->parseFile($file_path)->getText();
    } catch (\Throwable $e) {
        return ['parse_error' => true, 'items' => []];
    }

    if (empty(trim($text))) {
        return ['parse_error' => true, 'items' => []];
    }

    // Longest SKUs first to avoid partial matches (e.g. '730D-1.5' before '730D-1')
    $rows = $db->query(
        'SELECT sku, id FROM items WHERE is_active = 1 ORDER BY LENGTH(sku) DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
    $sku_map = [];
    foreach ($rows as $r) $sku_map[$r['sku']] = (int)$r['id'];

    $found = [];
    foreach (preg_split('/\r?\n/', $text) as $line) {
        $line = trim($line);
        if (!$line) continue;
        foreach (array_keys($sku_map) as $sku) {
            if (isset($found[$sku])) continue;
            if (!preg_match('/\b' . preg_quote($sku, '/') . '\b/i', $line)) continue;

            // Remove SKU so its digits aren't confused with qty/price
            $stripped = preg_replace('/\b' . preg_quote($sku, '/') . '\b/i', '', $line);

            preg_match_all('/\$?\s*(\d{1,6}\.\d{2})\b/', $stripped, $pm);
            preg_match_all('/\b(\d{1,4})\b/', $stripped, $qm);

            $price    = isset($pm[1][0]) ? (float)$pm[1][0] : null;
            $integers = array_values(array_filter($qm[1] ?? [], fn($n) => (int)$n > 0 && (int)$n < 9999));
            $qty      = !empty($integers) ? (int)$integers[0] : null;

            $found[$sku] = ['sku' => $sku, 'item_id' => $sku_map[$sku], 'qty' => $qty, 'price' => $price];
        }
    }

    if (empty($found)) {
        return ['parse_error' => true, 'items' => []];
    }

    return ['parse_error' => false, 'items' => array_values($found)];
}

/**
 * Compare PO items against quote items; return array of discrepancies.
 */
function compare_po_to_quote(array $po_items, array $quote_items): array {
    $discrepancies = [];
    $po_by_sku     = array_column($po_items, null, 'sku');

    foreach ($quote_items as $qi) {
        $sku = $qi['sku'];
        $po  = $po_by_sku[$sku] ?? null;
        if (!$po) {
            $discrepancies[] = ['sku' => $sku, 'missing_from_po' => true,
                                'qty_quote' => (int)$qi['quantity'], 'price_quote' => (float)$qi['unit_price']];
            continue;
        }
        $d = ['sku' => $sku]; $has = false;
        if ($po['qty'] !== null && (int)$po['qty'] !== (int)$qi['quantity']) {
            $d['qty_quote'] = (int)$qi['quantity']; $d['qty_po'] = (int)$po['qty']; $has = true;
        }
        if ($po['price'] !== null && abs((float)$po['price'] - (float)$qi['unit_price']) > 0.01) {
            $d['price_quote'] = (float)$qi['unit_price']; $d['price_po'] = (float)$po['price']; $has = true;
        }
        if ($has) $discrepancies[] = $d;
    }

    $quote_skus = array_column($quote_items, 'sku');
    foreach ($po_items as $pi) {
        if (!in_array($pi['sku'], $quote_skus)) {
            $discrepancies[] = ['sku' => $pi['sku'], 'missing_from_quote' => true,
                                'qty_po' => $pi['qty'], 'price_po' => $pi['price']];
        }
    }

    return $discrepancies;
}

// ── Inventory ─────────────────────────────────────────────────────────────────

/**
 * For each line item in a quote where quantity > on-hand, find the log item
 * for the same base_sku and calculate how many full logs to cut.
 * Logs needed covers the order deficit + reorder threshold replenishment.
 * Returns an array of recommendation arrays (empty if nothing needed).
 */
function log_cut_recommendations(PDO $db, int $quote_id): array {
    $stmt = $db->prepare('
        SELECT qi.quantity, i.id AS item_id, i.sku, i.width_inches,
               i.quantity_on_hand, i.reorder_threshold, i.base_sku
        FROM quote_items qi
        JOIN items i ON i.id = qi.item_id
        WHERE qi.quote_id = ?
    ');
    $stmt->execute([$quote_id]);
    $line_items = $stmt->fetchAll();

    $notices = [];
    foreach ($line_items as $li) {
        $qty_after = (float)$li['quantity_on_hand'] - (int)$li['quantity'];
        $target    = max(0, (float)$li['reorder_threshold']);
        $needed    = $target - $qty_after; // rolls needed from logs
        if ($needed <= 0) continue;

        $log_stmt = $db->prepare("
            SELECT id, sku, width_inches, quantity_on_hand
            FROM items
            WHERE base_sku = ? AND sku LIKE CONCAT(base_sku, '-L%')
            LIMIT 1
        ");
        $log_stmt->execute([$li['base_sku']]);
        $log = $log_stmt->fetch();
        if (!$log || $log['width_inches'] <= 0) continue;

        $rolls_per_log = (int)floor((float)$log['width_inches'] / (float)$li['width_inches']);
        if ($rolls_per_log < 1) continue;

        $logs_needed = (int)ceil($needed / $rolls_per_log);

        $notices[] = [
            'sku'            => $li['sku'],
            'cut_width'      => (float)$li['width_inches'],
            'log_sku'        => $log['sku'],
            'log_width'      => (float)$log['width_inches'],
            'rolls_per_log'  => $rolls_per_log,
            'logs_needed'    => $logs_needed,
            'logs_available' => (int)$log['quantity_on_hand'],
            'enough'         => (int)$log['quantity_on_hand'] >= $logs_needed,
        ];
    }
    return $notices;
}

function adjust_inventory(PDO $db, int $item_id, float $change_qty, string $reason, string $ref_type, ?int $ref_id, int $user_id): void {
    $db->prepare('UPDATE items SET quantity_on_hand = quantity_on_hand + ? WHERE id = ?')
       ->execute([$change_qty, $item_id]);

    $db->prepare('INSERT INTO inventory_log (item_id, change_qty, reason, reference_type, reference_id, created_by) VALUES (?,?,?,?,?,?)')
       ->execute([$item_id, $change_qty, $reason, $ref_type, $ref_id, $user_id]);

    check_low_stock($db, $item_id);
}

function check_low_stock(PDO $db, int $item_id): void {
    $item = $db->prepare('
        SELECT i.sku, p.name, i.quantity_on_hand, i.reorder_threshold
        FROM items i JOIN products p ON p.base_sku = i.base_sku
        WHERE i.id = ?
    ');
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
    if ($item['is_log'])        return format_width((float)$item['width_inches']);
    return format_width((float)$item['width_inches']);
}
