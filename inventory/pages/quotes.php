<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/layout.php';

require_login();

$db = db();

$status   = $_GET['status'] ?? '';
$search   = trim($_GET['q'] ?? '');
$where    = ['1=1'];
$params   = [];

if ($status && in_array($status, ['draft','sent','approved','expired','rejected'])) {
    $where[]  = 'q.status = ?';
    $params[] = $status;
}
if ($search) {
    $where[]  = '(c.name LIKE ? OR q.quote_number LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$sql = 'SELECT q.*, c.name AS customer_name,
               (SELECT SUM(qi.quantity * qi.unit_price) FROM quote_items qi WHERE qi.quote_id = q.id) AS total
        FROM quotes q
        JOIN customers c ON c.id = q.customer_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY q.created_at DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$quotes = $stmt->fetchAll();

$status_badge = [
    'draft'    => 'secondary',
    'sent'     => 'primary',
    'approved' => 'success',
    'expired'  => 'warning',
    'rejected' => 'danger',
];

render_header('Quotes', 'quotes');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Quotes</h4>
    <a href="/inventory/pages/quote-edit.php" class="btn btn-primary btn-sm">+ New Quote</a>
</div>

<div class="d-flex gap-2 mb-3 flex-wrap">
    <form method="get" class="d-flex gap-2">
        <input type="text" name="q" class="form-control form-control-sm" style="max-width:250px"
               placeholder="Search customer or #..." value="<?= h($search) ?>">
        <?php if ($status): ?><input type="hidden" name="status" value="<?= h($status) ?>"><?php endif; ?>
        <button class="btn btn-sm btn-outline-secondary">Search</button>
    </form>
    <div class="btn-group btn-group-sm">
        <a href="?" class="btn btn-outline-secondary <?= !$status ? 'active' : '' ?>">All</a>
        <?php foreach ($status_badge as $s => $color): ?>
        <a href="?status=<?= $s ?><?= $search ? '&q='.urlencode($search) : '' ?>"
           class="btn btn-outline-<?= $color ?> <?= $status === $s ? 'active' : '' ?>">
            <?= ucfirst($s) ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead><tr>
                <th>Quote #</th><th>Customer</th><th>Status</th><th>Total</th><th>Date</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($quotes as $q): ?>
            <tr>
                <td class="fw-semibold">#<?= (int)$q['quote_number'] ?></td>
                <td><?= h($q['customer_name']) ?></td>
                <td><span class="badge bg-<?= $status_badge[$q['status']] ?? 'secondary' ?>"><?= ucfirst($q['status']) ?></span></td>
                <td><?= $q['total'] !== null ? currency((float)$q['total']) : '—' ?></td>
                <td><?= date('M j, Y', strtotime($q['created_at'])) ?></td>
                <td class="text-end">
                    <a href="/inventory/pages/quote-edit.php?id=<?= (int)$q['id'] ?>" class="btn btn-sm btn-outline-secondary">
                        <?= in_array($q['status'], ['draft','sent']) ? 'Edit' : 'View' ?>
                    </a>
                    <a href="/inventory/pdf/quote.php?id=<?= (int)$q['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary">PDF</a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($quotes)): ?>
            <tr><td colspan="6" class="text-muted text-center py-3">No quotes found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php render_footer(); ?>
