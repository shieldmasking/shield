<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/functions.php';

require_login();

$db       = db();
$quote_id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare('
    SELECT q.*, c.name AS customer_name, c.company, c.email, c.phone, c.billing_address,
           u.name AS created_by_name, u.email AS created_by_email, u.phone AS created_by_phone
    FROM quotes q
    JOIN customers c ON c.id = q.customer_id
    JOIN users u ON u.id = q.created_by
    WHERE q.id = ?
');
$stmt->execute([$quote_id]);
$quote = $stmt->fetch();
if (!$quote) { header('Location: /inventory/pages/quotes.php'); exit; }

$items_stmt = $db->prepare('
    SELECT qi.*, i.sku, i.name AS item_name, i.roll_length_yards,
           i.width_inches, i.is_log, i.is_fixed_width
    FROM quote_items qi
    JOIN items i ON i.id = qi.item_id
    WHERE qi.quote_id = ?
    ORDER BY qi.id
');
$items_stmt->execute([$quote_id]);
$line_items = $items_stmt->fetchAll();

$total = array_sum(array_map(fn($r) => $r['quantity'] * $r['unit_price'], $line_items));

$settings = [];
foreach ($db->query('SELECT `key`, value FROM settings') as $row) {
    $settings[$row['key']] = $row['value'];
}
$company_name    = $settings['company_name']    ?? 'Shield Masking Solutions';
$company_address = $settings['company_address'] ?? '';
$company_phone   = $settings['company_phone']   ?? '';
$company_email   = $settings['company_email']   ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Quote #<?= $quote['quote_number'] ?> — <?= h($company_name) ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: Arial, sans-serif; font-size: 11pt; color: #222; background: #fff; }
.page { max-width: 760px; margin: 0 auto; padding: 40px 40px 60px; }

/* Header */
.header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 32px; border-bottom: 2px solid #1e2d6b; padding-bottom: 16px; }
.company-name { font-size: 18pt; font-weight: bold; color: #1e2d6b; }
.company-sub { font-size: 9pt; color: #666; margin-top: 2px; }
.quote-meta { text-align: right; }
.quote-number { font-size: 16pt; font-weight: bold; color: #1e2d6b; }
.quote-date { font-size: 9pt; color: #666; margin-top: 4px; }
.quote-status { display: inline-block; margin-top: 6px; padding: 2px 10px; border-radius: 3px; font-size: 9pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; }
.status-draft    { background: #e9ecef; color: #555; }
.status-sent     { background: #cce5ff; color: #004085; }
.status-approved { background: #d4edda; color: #155724; }
.status-expired  { background: #fff3cd; color: #856404; }
.status-rejected { background: #f8d7da; color: #721c24; }

/* Addresses */
.addresses { display: flex; gap: 40px; margin-bottom: 28px; }
.address-block { flex: 1; }
.address-label { font-size: 8pt; text-transform: uppercase; letter-spacing: 0.08em; color: #888; margin-bottom: 4px; }
.address-block .name { font-weight: bold; font-size: 11pt; }

/* Table */
table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
thead th { background: #1e2d6b; color: #fff; padding: 8px 10px; font-size: 9pt; text-align: left; }
thead th.right { text-align: right; }
tbody td { padding: 8px 10px; border-bottom: 1px solid #e0e0e0; font-size: 10pt; vertical-align: top; }
tbody td.right { text-align: right; }
tbody tr:last-child td { border-bottom: none; }
tbody tr:nth-child(even) td { background: #f9f9f9; }

/* Totals */
.totals { margin-top: 8px; }
.totals table { width: 280px; margin-left: auto; }
.totals td { padding: 4px 10px; font-size: 10pt; }
.totals td:last-child { text-align: right; font-weight: bold; }
.total-row td { border-top: 2px solid #1e2d6b; font-size: 12pt; padding-top: 8px; }

/* Notes */
.notes-block { margin-top: 24px; padding: 12px; background: #f8f9fa; border-left: 3px solid #1e2d6b; font-size: 10pt; }
.notes-label { font-size: 8pt; text-transform: uppercase; letter-spacing: 0.08em; color: #888; margin-bottom: 4px; }

/* Footer */
.footer { margin-top: 40px; padding-top: 12px; border-top: 1px solid #ddd; font-size: 8pt; color: #888; display: flex; justify-content: space-between; }

/* Print button */
.print-btn { position: fixed; bottom: 24px; right: 24px; padding: 12px 24px; background: #1e2d6b; color: #fff; border: none; border-radius: 4px; font-size: 11pt; cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,0.2); }
.print-btn:hover { background: #2a3d8f; }

@media print {
    .print-btn { display: none; }
    body { font-size: 10pt; }
    .page { padding: 20px; }
}
</style>
</head>
<body>
<div class="page">

    <div class="header">
        <div>
            <div class="company-name"><?= h($company_name) ?></div>
<?php if ($company_address): ?><div class="company-sub" style="margin-top:4px"><?= nl2br(h($company_address)) ?></div><?php endif; ?>
            <?php if ($company_phone): ?><div class="company-sub"><?= h($company_phone) ?></div><?php endif; ?>
            <?php if ($company_email): ?><div class="company-sub"><?= h($company_email) ?></div><?php endif; ?>
        </div>
        <div class="quote-meta">
            <div class="quote-number">QUOTE #<?= (int)$quote['quote_number'] ?></div>
            <div class="quote-date">Date: <?= date('F j, Y', strtotime($quote['created_at'])) ?></div>
            <div class="quote-date">Expires: <?= date('F j, Y', strtotime($quote['created_at'] . ' +30 days')) ?></div>
            <div><span class="quote-status status-<?= h($quote['status']) ?>"><?= ucfirst($quote['status']) ?></span></div>
        </div>
    </div>

    <div class="addresses">
        <div class="address-block">
            <div class="address-label">Bill To</div>
            <div class="name"><?= h($quote['customer_name']) ?></div>
            <?php if ($quote['company']): ?><div><?= h($quote['company']) ?></div><?php endif; ?>
            <?php if ($quote['email']): ?><div><?= h($quote['email']) ?></div><?php endif; ?>
            <?php if ($quote['phone']): ?><div><?= h($quote['phone']) ?></div><?php endif; ?>
            <?php if ($quote['billing_address']): ?><div style="margin-top:4px;color:#555"><?= nl2br(h($quote['billing_address'])) ?></div><?php endif; ?>
        </div>
        <div class="address-block">
            <div class="address-label">Prepared By</div>
            <div class="name"><?= h($quote['created_by_name']) ?></div>
            <?php if ($quote['created_by_email']): ?><div><?= h($quote['created_by_email']) ?></div><?php endif; ?>
            <?php if ($quote['created_by_phone']): ?><div><?= h($quote['created_by_phone']) ?></div><?php endif; ?>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:80px">SKU</th>
                <th>Description</th>
                <th style="width:60px">Width</th>
                <th style="width:60px">Length</th>
                <th class="right" style="width:50px">Qty</th>
                <th class="right" style="width:90px">Unit Price</th>
                <th class="right" style="width:90px">Total</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($line_items as $li): ?>
        <tr>
            <td><strong><?= h($li['sku']) ?></strong></td>
            <td><?= h($li['item_name']) ?></td>
            <td><?= width_label($li) ?></td>
            <td><?= (int)$li['roll_length_yards'] ?>yds</td>
            <td class="right"><?= (int)$li['quantity'] ?></td>
            <td class="right"><?= currency((float)$li['unit_price']) ?></td>
            <td class="right"><?= currency($li['quantity'] * $li['unit_price']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totals">
        <table>
            <tr class="total-row">
                <td>Total</td>
                <td><?= currency($total) ?></td>
            </tr>
        </table>
        <div style="text-align:right;font-size:8pt;color:#888;margin-top:4px">Plus applicable taxes.</div>
    </div>

    <?php if ($quote['notes']): ?>
    <div class="notes-block">
        <div class="notes-label">Terms</div>
        <?= nl2br(h($quote['notes'])) ?>
    </div>
    <?php endif; ?>

    <div class="notes-block" style="margin-top:12px">
        <div class="notes-label">Shipping &amp; Terms</div>
        FOB Origin. Shipping included on orders over $5,000 (Continental US only).
    </div>

    <div class="footer">
        <div><?= h($company_name) ?> &mdash; shieldmasking.com</div>
        <div>Valid through <?= date('F j, Y', strtotime($quote['created_at'] . ' +30 days')) ?>.</div>
    </div>

</div>

<button class="print-btn" onclick="window.print()">Print / Save PDF</button>

</body>
</html>
