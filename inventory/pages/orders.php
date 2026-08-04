<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/layout.php';

require_login();

$db = db();

$search = trim($_GET['q'] ?? '');
$where  = $search ? 'AND (c.name LIKE ? OR q.quote_number LIKE ?)' : '';
$params = $search ? ["%{$search}%", "%{$search}%"] : [];

$sql = "SELECT o.*, q.quote_number, c.name AS customer_name,
               (SELECT SUM(oi.quantity * oi.unit_price) FROM order_items oi WHERE oi.order_id = o.id) AS total
        FROM orders o
        JOIN quotes q ON q.id = o.quote_id
        JOIN customers c ON c.id = o.customer_id
        WHERE 1=1 {$where}
        ORDER BY o.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

render_header('Orders', 'orders');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Orders</h4>
</div>

<form method="get" class="mb-3 d-flex gap-2">
    <input type="text" name="q" class="form-control form-control-sm" style="max-width:300px"
           placeholder="Search customer or quote #..." value="<?= h($search) ?>">
    <button class="btn btn-sm btn-outline-secondary">Search</button>
    <?php if ($search): ?><a href="?" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
</form>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead><tr>
                <th>Order #</th><th>Quote #</th><th>Customer</th><th>Total</th><th>QB Invoice</th><th>Date</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
            <tr>
                <td class="fw-semibold">#<?= (int)$o['id'] ?></td>
                <td><a href="/inventory/pages/quote-edit.php?id=<?= (int)$o['quote_id'] ?>">Q#<?= (int)$o['quote_number'] ?></a></td>
                <td><?= h($o['customer_name']) ?></td>
                <td><?= currency((float)($o['total'] ?? 0)) ?></td>
                <td><?= $o['qb_invoice_id'] ? h($o['qb_invoice_id']) : '<span class="text-muted">Pending</span>' ?></td>
                <td><?= date('M j, Y', strtotime($o['created_at'])) ?></td>
                <td><a href="/inventory/pages/order-view.php?id=<?= (int)$o['id'] ?>" class="btn btn-sm btn-outline-secondary">View</a></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($orders)): ?>
            <tr><td colspan="7" class="text-muted text-center py-3">No orders yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php render_footer(); ?>
