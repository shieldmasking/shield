<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/layout.php';

require_login();

$db      = db();
$item_id = (int)($_GET['item_id'] ?? 0);

$where  = [];
$params = [];
if ($item_id) {
    $where[]  = 'l.item_id = ?';
    $params[] = $item_id;
}

$sql = 'SELECT l.*, i.sku, i.name AS item_name, u.name AS user_name
        FROM inventory_log l
        JOIN items i ON i.id = l.item_id
        JOIN users u ON u.id = l.created_by'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . ' ORDER BY l.created_at DESC LIMIT 500';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$item_name = '';
if ($item_id) {
    $row = $db->prepare('SELECT sku, name FROM items WHERE id = ?');
    $row->execute([$item_id]);
    $row = $row->fetch();
    if ($row) $item_name = $row['sku'] . ' — ' . $row['name'];
}

$type_colors = [
    'order'      => 'danger',
    'receiving'  => 'success',
    'adjustment' => 'warning',
    'manual'     => 'secondary',
];

render_header('Inventory Log', 'inventory');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0">Inventory Log<?= $item_name ? ': ' . h($item_name) : '' ?></h4>
    </div>
    <div>
        <?php if ($item_id): ?>
        <a href="/inventory/pages/inventory-log.php" class="btn btn-sm btn-outline-secondary">All Items</a>
        <?php endif; ?>
        <a href="/inventory/pages/inventory.php" class="btn btn-sm btn-outline-secondary">Back to Inventory</a>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead><tr>
                <th>Date</th>
                <th>Item</th>
                <th>Change</th>
                <th>Type</th>
                <th>Reason</th>
                <th>By</th>
            </tr></thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
            <tr>
                <td class="text-nowrap"><?= date('M j, Y g:ia', strtotime($log['created_at'])) ?></td>
                <td><?= h($log['sku']) ?> <span class="text-muted">— <?= h($log['item_name']) ?></span></td>
                <td class="<?= $log['change_qty'] > 0 ? 'text-success' : 'text-danger' ?> fw-semibold">
                    <?= $log['change_qty'] > 0 ? '+' : '' ?><?= (int)$log['change_qty'] ?>
                </td>
                <td><span class="badge bg-<?= $type_colors[$log['reference_type']] ?? 'secondary' ?>">
                    <?= ucfirst(h($log['reference_type'])) ?>
                </span></td>
                <td><?= h($log['reason']) ?></td>
                <td><?= h($log['user_name']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($logs)): ?>
            <tr><td colspan="6" class="text-muted text-center py-3">No log entries.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php render_footer(); ?>
