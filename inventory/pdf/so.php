<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/functions.php';

require_login();

$db       = db();
$quote_id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare('
    SELECT q.*, c.name AS customer_name, c.company, c.email, c.phone, c.billing_address, c.terms,
           u.name AS rep_name,
           o.id AS order_id
    FROM quotes q
    JOIN customers c ON c.id = q.customer_id
    JOIN users u ON u.id = q.created_by
    LEFT JOIN orders o ON o.quote_id = q.id
    WHERE q.id = ?
');
$stmt->execute([$quote_id]);
$quote = $stmt->fetch();
if (!$quote) { header('Location: /inventory/pages/dashboard.php'); exit; }

// Try to build line items from PO data; fall back to quote_items
$line_items = [];
if ($quote['po_pdf_path']) {
    $po_file = __DIR__ . '/../' . $quote['po_pdf_path'];
    $parsed  = file_exists($po_file) ? parse_po_pdf($po_file, $db) : ['parse_error' => true, 'items' => []];
    if (!$parsed['parse_error'] && !empty($parsed['items'])) {
        $detail = $db->prepare('
            SELECT i.id, i.sku, i.width_inches,
                   p.name AS item_name, p.roll_length_yards, p.description, p.is_log, p.is_fixed_width
            FROM items i JOIN products p ON p.base_sku = i.base_sku
            WHERE i.id = ?
        ');
        foreach ($parsed['items'] as $pi) {
            if (!$pi['qty']) continue;
            $detail->execute([$pi['item_id']]);
            $row = $detail->fetch();
            if (!$row) continue;
            $row['quantity']   = $pi['qty'];
            $row['unit_price'] = $pi['price'] ?? 0;
            $line_items[]      = $row;
        }
    }
}
if (empty($line_items)) {
    $items_stmt = $db->prepare('
        SELECT qi.quantity, qi.unit_price, i.sku, i.width_inches,
               p.name AS item_name, p.roll_length_yards, p.description, p.is_log, p.is_fixed_width
        FROM quote_items qi
        JOIN items i ON i.id = qi.item_id
        JOIN products p ON p.base_sku = i.base_sku
        WHERE qi.quote_id = ?
        ORDER BY qi.id
    ');
    $items_stmt->execute([$quote_id]);
    $line_items = $items_stmt->fetchAll();
}

$total = array_sum(array_map(fn($r) => $r['quantity'] * $r['unit_price'], $line_items));

$settings = [];
foreach ($db->query('SELECT `key`, value FROM settings') as $row) {
    $settings[$row['key']] = $row['value'];
}
$company_name    = $settings['company_name']    ?? 'Shield Masking Solutions';
$company_address = $settings['company_address'] ?? '';
$company_sub     = $settings['company_sub']     ?? 'SMS is a division of Builder Surfaces Technology LLC';

// S.O. No. format: YY-quote_number
$so_date   = $quote['created_at'] ? date('n/j/Y', strtotime($quote['created_at'])) : date('n/j/Y');
$so_year   = $quote['created_at'] ? date('y', strtotime($quote['created_at'])) : date('y');
$so_number = $so_year . '-' . $quote['quote_number'];

// Rep initials from name
$rep_initials = implode('', array_map(fn($w) => strtoupper($w[0]), array_filter(explode(' ', $quote['rep_name']))));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>SO-<?= h($quote['customer_name']) ?> <?= h($so_number) ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: Arial, sans-serif; font-size: 10.5pt; color: #222; background: #fff; }
.page { max-width: 780px; margin: 0 auto; padding: 32px 40px 60px; }

.top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; }
.company-block { font-size: 9pt; color: #333; }
.company-block .co-name { font-size: 12pt; font-weight: bold; color: #1e2d6b; }
.so-title { font-size: 28pt; font-weight: bold; color: #1e2d6b; letter-spacing: 0.02em; }
.so-sub { font-size: 8.5pt; font-style: italic; color: #666; text-align: right; margin-top: 4px; }

.addr-row { display: flex; gap: 20px; margin-bottom: 16px; }
.addr-box { flex: 1; border: 1px solid #bbb; padding: 10px 12px; min-height: 90px; font-size: 9.5pt; }
.addr-box .label { font-size: 8.5pt; color: #888; margin-bottom: 4px; }

.meta-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 9.5pt; }
.meta-table th, .meta-table td { border: 1px solid #bbb; padding: 5px 10px; text-align: center; }
.meta-table th { background: #f5f5f5; font-weight: normal; color: #888; font-size: 8.5pt; }
.meta-table td { font-weight: bold; }

table.items { width: 100%; border-collapse: collapse; font-size: 9.5pt; }
table.items thead th { background: #f5f5f5; border: 1px solid #bbb; padding: 6px 10px; font-weight: bold; text-align: left; }
table.items thead th.right { text-align: right; }
table.items thead th.center { text-align: center; }
table.items tbody td { border: 1px solid #ddd; padding: 7px 10px; vertical-align: top; }
table.items tbody td.center { text-align: center; }
table.items tbody td.right { text-align: right; }
table.items tbody tr:last-child td { border-bottom: 1px solid #bbb; }

.total-row { display: flex; justify-content: flex-end; margin-top: 0; }
.total-box { border: 1px solid #bbb; border-top: none; width: 280px; display: flex; justify-content: space-between; padding: 8px 14px; font-size: 13pt; font-weight: bold; color: #555; }

.print-btn { position: fixed; bottom: 24px; right: 24px; padding: 12px 24px; background: #1e2d6b; color: #fff; border: none; border-radius: 4px; font-size: 11pt; cursor: pointer; }
@media print { .print-btn { display: none; } .page { padding: 20px; } }
</style>
</head>
<body>
<div class="page">

    <div class="top">
        <div class="company-block">
            <div class="co-name"><?= h($company_name) ?></div>
            <?php if ($company_address): ?>
            <div style="margin-top:4px; white-space:pre-line"><?= h($company_address) ?></div>
            <?php endif; ?>
        </div>
        <div style="text-align:right">
            <div class="so-title">SALES ORDER</div>
            <?php if ($company_sub): ?><div class="so-sub"><?= h($company_sub) ?></div><?php endif; ?>
        </div>
    </div>

    <div class="addr-row">
        <div class="addr-box">
            <div class="label">Name / Address</div>
            <strong><?= h($quote['customer_name']) ?></strong>
            <?php if ($quote['company']): ?><br><?= h($quote['company']) ?><?php endif; ?>
            <?php if ($quote['billing_address']): ?><br><?= nl2br(h($quote['billing_address'])) ?><?php endif; ?>
        </div>
        <div class="addr-box">
            <div class="label">Ship To</div>
        </div>
    </div>

    <table class="meta-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>S.O. No.</th>
                <th>Terms</th>
                <th>Ship Date</th>
                <th>Rep</th>
                <th>P.O. No.</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?= h($so_date) ?></td>
                <td><?= h($so_number) ?></td>
                <td><?= h($quote['terms'] ?: 'Net 30') ?></td>
                <td></td>
                <td><?= h($rep_initials) ?></td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width:130px">Item</th>
                <th>Description</th>
                <th class="center" style="width:80px">Ordered</th>
                <th class="right" style="width:90px">Rate</th>
                <th class="right" style="width:90px">Amount</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($line_items as $li):
            $desc = h($li['item_name']);
            $dims = format_width((float)$li['width_inches']) . 'x' . (int)$li['roll_length_yards'] . 'yd Roll';
            if ($li['description']) $dims .= ', ' . $li['description'];
        ?>
        <tr>
            <td><strong><?= h($li['sku']) ?></strong></td>
            <td><?= $desc ?><br><span style="font-size:9pt;color:#555"><?= h($dims) ?></span></td>
            <td class="center"><?= (int)$li['quantity'] ?></td>
            <td class="right"><?= currency((float)$li['unit_price']) ?></td>
            <td class="right"><?= currency($li['quantity'] * $li['unit_price']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php
        // Fill empty rows to match sample style (at least 10 rows total)
        $empty = max(0, 10 - count($line_items));
        for ($i = 0; $i < $empty; $i++): ?>
        <tr><td style="height:28px">&nbsp;</td><td></td><td></td><td></td><td></td></tr>
        <?php endfor; ?>
        </tbody>
    </table>

    <div class="total-row">
        <div class="total-box">
            <span>Total</span>
            <span><?= currency($total) ?></span>
        </div>
    </div>

</div>
<button class="print-btn" onclick="window.print()">Print / Save PDF</button>
</body>
</html>
