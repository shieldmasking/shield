<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/layout.php';

require_login();

$db = db();

// Stats
$total_skus    = $db->query('SELECT COUNT(*) FROM items WHERE is_active = 1')->fetchColumn();
$low_stock     = $db->query('SELECT COUNT(*) FROM items WHERE is_active = 1 AND reorder_threshold > 0 AND quantity_on_hand <= reorder_threshold')->fetchColumn();
$open_quotes   = $db->query("SELECT COUNT(*) FROM quotes WHERE status IN ('draft','sent')")->fetchColumn();
$open_orders   = $db->query('SELECT COUNT(*) FROM orders WHERE qb_invoice_id IS NULL')->fetchColumn();

// Recent quotes
$recent_quotes = $db->query('
    SELECT q.id, q.quote_number, q.status, q.created_at, c.name AS customer_name
    FROM quotes q
    JOIN customers c ON c.id = q.customer_id
    ORDER BY q.created_at DESC
    LIMIT 5
')->fetchAll();

render_header('Dashboard', 'dashboard');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">Dashboard</h4>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="stat-value"><?= (int)$total_skus ?></div>
                <div class="stat-label">Active SKUs</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card <?= $low_stock > 0 ? 'border-danger' : '' ?>">
            <div class="card-body">
                <div class="stat-value <?= $low_stock > 0 ? 'text-danger' : '' ?>"><?= (int)$low_stock ?></div>
                <div class="stat-label">Low Stock Items</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="stat-value"><?= (int)$open_quotes ?></div>
                <div class="stat-label">Open Quotes</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="stat-value"><?= (int)$open_orders ?></div>
                <div class="stat-label">Uninvoiced Orders</div>
            </div>
        </div>
    </div>
</div>

<?php if ($low_stock > 0): ?>
<div class="alert alert-danger">
    <strong><?= (int)$low_stock ?> item(s) are at or below reorder threshold.</strong>
    <a href="/inventory/pages/inventory.php?filter=low_stock" class="alert-link ms-2">View →</a>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Recent Quotes</span>
        <a href="/inventory/pages/quotes.php" class="btn btn-sm btn-outline-primary">All Quotes</a>
    </div>
    <div class="card-body p-0">
        <?php if (empty($recent_quotes)): ?>
            <p class="text-muted p-3 mb-0">No quotes yet.</p>
        <?php else: ?>
        <table class="table table-hover mb-0">
            <thead><tr>
                <th>Quote #</th><th>Customer</th><th>Status</th><th>Date</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($recent_quotes as $q): ?>
            <tr>
                <td>#<?= (int)$q['quote_number'] ?></td>
                <td><?= h($q['customer_name']) ?></td>
                <td><span class="badge badge-<?= h($q['status']) ?>"><?= ucfirst(h($q['status'])) ?></span></td>
                <td><?= date('M j, Y', strtotime($q['created_at'])) ?></td>
                <td><a href="/inventory/pages/quote-edit.php?id=<?= (int)$q['id'] ?>" class="btn btn-sm btn-outline-secondary">View</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php render_footer(); ?>
