<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/layout.php';

require_login();

$db       = db();
$order_id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare('
    SELECT o.*, q.quote_number, q.po_pdf_path, q.notes,
           c.name AS customer_name, c.company, c.email, c.phone, c.billing_address,
           u.name AS created_by_name
    FROM orders o
    JOIN quotes q ON q.id = o.quote_id
    JOIN customers c ON c.id = o.customer_id
    JOIN users u ON u.id = o.created_by
    WHERE o.id = ?
');
$stmt->execute([$order_id]);
$order = $stmt->fetch();
if (!$order) { header('Location: /inventory/pages/orders.php'); exit; }

$items_stmt = $db->prepare('
    SELECT oi.*, i.sku, i.width_inches,
           p.name AS item_name, p.roll_length_yards, p.description, p.is_log, p.is_fixed_width
    FROM order_items oi
    JOIN items i ON i.id = oi.item_id
    JOIN products p ON p.base_sku = i.base_sku
    WHERE oi.order_id = ?
    ORDER BY oi.id
');
$items_stmt->execute([$order_id]);
$line_items = $items_stmt->fetchAll();

$total = array_sum(array_map(fn($r) => $r['quantity'] * $r['unit_price'], $line_items));

render_header("Order #{$order_id}", 'orders');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0">Order #<?= $order_id ?></h4>
        <small class="text-muted">From Quote #<?= $order['quote_number'] ?> — <?= date('M j, Y', strtotime($order['created_at'])) ?></small>
    </div>
    <div>
        <a href="/inventory/pdf/quote.php?id=<?= (int)$order['quote_id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary">Print Quote PDF</a>
        <a href="/inventory/pages/orders.php" class="btn btn-sm btn-outline-secondary">Back</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header fw-semibold">Customer</div>
            <div class="card-body">
                <div class="fw-semibold"><?= h($order['customer_name']) ?></div>
                <?php if ($order['company']): ?><div class="text-muted"><?= h($order['company']) ?></div><?php endif; ?>
                <?php if ($order['email']): ?><div><?= h($order['email']) ?></div><?php endif; ?>
                <?php if ($order['phone']): ?><div><?= h($order['phone']) ?></div><?php endif; ?>
                <?php if ($order['billing_address']): ?><div class="mt-1 text-muted small"><?= nl2br(h($order['billing_address'])) ?></div><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header fw-semibold">Order Details</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><th>Order #</th><td>#<?= $order_id ?></td></tr>
                    <tr><th>Quote #</th><td>#<?= $order['quote_number'] ?></td></tr>
                    <tr><th>QB Invoice</th><td><?= $order['qb_invoice_id'] ? h($order['qb_invoice_id']) : '<span class="text-warning">Pending QB sync</span>' ?></td></tr>
                    <tr><th>Created by</th><td><?= h($order['created_by_name']) ?></td></tr>
                    <?php if ($order['po_pdf_path']): ?>
                    <tr><th>Customer PO</th><td><a href="/inventory/<?= h($order['po_pdf_path']) ?>" target="_blank">View PDF</a></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header fw-semibold">Line Items</div>
    <div class="card-body p-0">
        <table class="table mb-0">
            <thead><tr>
                <th>SKU</th><th>Item</th><th>Width</th><th>Length</th><th>Qty</th><th>Unit Price</th><th class="text-end">Total</th>
            </tr></thead>
            <tbody>
            <?php foreach ($line_items as $li): ?>
            <tr>
                <td class="fw-semibold"><?= h($li['sku']) ?></td>
                <td><?= h($li['item_name']) ?></td>
                <td><?= width_label($li) ?></td>
                <td><?= (int)$li['roll_length_yards'] ?>yds</td>
                <td><?= (int)$li['quantity'] ?></td>
                <td><?= currency((float)$li['unit_price']) ?></td>
                <td class="text-end"><?= currency($li['quantity'] * $li['unit_price']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr><td colspan="6" class="text-end fw-bold">Total</td>
                    <td class="text-end fw-bold"><?= currency($total) ?></td></tr>
            </tfoot>
        </table>
    </div>
</div>

<?php if ($order['notes']): ?>
<div class="card mt-3">
    <div class="card-header fw-semibold">Notes</div>
    <div class="card-body"><?= nl2br(h($order['notes'])) ?></div>
</div>
<?php endif; ?>

<?php render_footer(); ?>
