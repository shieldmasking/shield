<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/layout.php';

require_login();

$db       = db();
$my_admin = is_admin();
$msg      = '';

// ── POST: manage assignments (admin only) ─────────────────────────────────────
if ($my_admin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_assignment'])) {
        $cid = (int)$_POST['customer_id'];
        $uid = (int)$_POST['user_id'];
        $pct = round((float)$_POST['commission_pct'], 2);
        if ($cid && $uid && $pct > 0 && $pct <= 100) {
            try {
                $db->prepare('INSERT INTO customer_sales (customer_id, user_id, commission_pct) VALUES (?,?,?)
                              ON DUPLICATE KEY UPDATE commission_pct=?')
                   ->execute([$cid, $uid, $pct, $pct]);
                $msg = 'Assignment saved.';
            } catch (PDOException) {
                $msg = 'Error saving assignment.';
            }
        } else {
            $msg = 'Invalid input.';
        }
    } elseif (isset($_POST['delete_assignment'])) {
        $db->prepare('DELETE FROM customer_sales WHERE id=?')->execute([(int)$_POST['assignment_id']]);
        $msg = 'Assignment removed.';
    }
    header('Location: /inventory/pages/commissions.php?tab=assignments&msg=' . urlencode($msg));
    exit;
}

if (isset($_GET['msg'])) $msg = $_GET['msg'];
$active_tab = $_GET['tab'] ?? 'estimates';

// ── Date filter ───────────────────────────────────────────────────────────────
$date_from = $_GET['from'] ?? date('Y-m-01');
$date_to   = $_GET['to']   ?? date('Y-m-t');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-t');

// ── Commission estimates query ────────────────────────────────────────────────
$params     = [$date_from, $date_to];
$uid_filter = '';
if (!$my_admin) {
    $uid_filter = 'AND cs.user_id = ?';
    $params[]   = current_user_id();
}

$stmt = $db->prepare("
    SELECT
        cs.user_id,
        u.name  AS user_name,
        c.name  AS customer_name,
        q.quote_number,
        o.id    AS order_id,
        DATE(o.created_at) AS order_date,
        cs.commission_pct,
        SUM(oi.quantity * oi.unit_price) AS revenue,
        SUM(oi.quantity * IF(p.is_fixed_width, p.land_cost_base, p.land_cost_base * i.width_inches)) AS cost
    FROM customer_sales cs
    JOIN users u         ON u.id  = cs.user_id
    JOIN customers c     ON c.id  = cs.customer_id
    JOIN orders o        ON o.customer_id = cs.customer_id
    JOIN quotes q        ON q.id  = o.quote_id
    JOIN order_items oi  ON oi.order_id = o.id
    JOIN items i         ON i.id  = oi.item_id
    JOIN products p      ON p.base_sku = i.base_sku
    WHERE DATE(o.created_at) BETWEEN ? AND ?
      {$uid_filter}
    GROUP BY cs.user_id, u.name, c.name, q.quote_number, o.id, o.created_at, cs.commission_pct
    ORDER BY u.name, o.created_at DESC
");
$stmt->execute($params);

// Group results by user
$by_user = [];
foreach ($stmt->fetchAll() as $r) {
    $uid = $r['user_id'];
    if (!isset($by_user[$uid])) {
        $by_user[$uid] = ['name' => $r['user_name'], 'orders' => [], 'total_gm' => 0, 'total_commission' => 0];
    }
    $gm         = (float)$r['revenue'] - (float)$r['cost'];
    $commission = $gm * (float)$r['commission_pct'] / 100;
    $by_user[$uid]['orders'][]        = $r + ['gm' => $gm, 'commission' => $commission];
    $by_user[$uid]['total_gm']        += $gm;
    $by_user[$uid]['total_commission'] += $commission;
}

// ── Admin: load assignment data ───────────────────────────────────────────────
$assignments   = [];
$all_customers = [];
$sales_users   = [];
if ($my_admin) {
    $assignments = $db->query('
        SELECT cs.id, cs.commission_pct, cs.customer_id, cs.user_id,
               c.name AS customer_name, u.name AS user_name
        FROM customer_sales cs
        JOIN customers c ON c.id = cs.customer_id
        JOIN users u     ON u.id = cs.user_id
        ORDER BY c.name, u.name
    ')->fetchAll();

    $all_customers = $db->query('SELECT id, name FROM customers ORDER BY name')->fetchAll();
    $sales_users   = $db->query('SELECT id, name FROM users WHERE is_admin = 0 ORDER BY name')->fetchAll();
}

render_header('Commissions', 'commissions');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Commissions</h4>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= str_contains($msg, 'Error') || str_contains($msg, 'Invalid') ? 'danger' : 'success' ?> alert-dismissible fade show">
    <?= h($msg) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($my_admin): ?>
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'estimates' ? 'active' : '' ?>"
           href="?tab=estimates&from=<?= h($date_from) ?>&to=<?= h($date_to) ?>">Estimates</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'assignments' ? 'active' : '' ?>"
           href="?tab=assignments">Assignments</a>
    </li>
</ul>
<?php endif; ?>

<?php if ($active_tab === 'estimates' || !$my_admin): ?>

<!-- Date filter -->
<form method="get" class="d-flex gap-2 align-items-end mb-4">
    <input type="hidden" name="tab" value="estimates">
    <div>
        <label class="form-label mb-1 small fw-semibold">From</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= h($date_from) ?>">
    </div>
    <div>
        <label class="form-label mb-1 small fw-semibold">To</label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?= h($date_to) ?>">
    </div>
    <button class="btn btn-sm btn-outline-secondary">Filter</button>
    <a href="?tab=estimates&from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-t') ?>" class="btn btn-sm btn-outline-secondary">This Month</a>
    <a href="?tab=estimates&from=<?= date('Y-01-01') ?>&to=<?= date('Y-12-31') ?>" class="btn btn-sm btn-outline-secondary">This Year</a>
</form>

<?php if (empty($by_user)): ?>
<div class="text-muted">No commission data for this period.</div>
<?php else: ?>

<?php foreach ($by_user as $uid => $ud): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><?= h($ud['name']) ?></span>
        <span>
            GM: <strong><?= currency($ud['total_gm']) ?></strong>
            &nbsp;&nbsp;
            Est. Commission: <strong class="text-success"><?= currency($ud['total_commission']) ?></strong>
        </span>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Order Date</th>
                    <th>Customer</th>
                    <th>Quote #</th>
                    <th class="text-end">Revenue</th>
                    <th class="text-end">Cost</th>
                    <th class="text-end">GM</th>
                    <th class="text-end">Rate</th>
                    <th class="text-end">Commission</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($ud['orders'] as $o): ?>
            <tr>
                <td><?= date('M j, Y', strtotime($o['order_date'])) ?></td>
                <td><?= h($o['customer_name']) ?></td>
                <td><a href="/inventory/pages/order-view.php?id=<?= (int)$o['order_id'] ?>">#<?= (int)$o['quote_number'] ?></a></td>
                <td class="text-end"><?= currency((float)$o['revenue']) ?></td>
                <td class="text-end"><?= currency((float)$o['cost']) ?></td>
                <td class="text-end"><?= currency($o['gm']) ?></td>
                <td class="text-end"><?= number_format((float)$o['commission_pct'], 2) ?>%</td>
                <td class="text-end fw-semibold text-success"><?= currency($o['commission']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light">
                <tr>
                    <td colspan="7" class="text-end fw-bold">Total</td>
                    <td class="text-end fw-bold text-success"><?= currency($ud['total_commission']) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php elseif ($active_tab === 'assignments' && $my_admin): ?>

<div class="row g-4">
    <!-- Add assignment -->
    <div class="col-md-4">
        <div class="card">
            <div class="card-header fw-semibold">Add Assignment</div>
            <div class="card-body">
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">Customer</label>
                        <select name="customer_id" class="form-select" required>
                            <option value="">Select...</option>
                            <?php foreach ($all_customers as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sales Rep</label>
                        <select name="user_id" class="form-select" required>
                            <option value="">Select...</option>
                            <?php foreach ($sales_users as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= h($u['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Commission %</label>
                        <div class="input-group">
                            <input type="number" name="commission_pct" class="form-control"
                                   step="0.01" min="0.01" max="100" required placeholder="e.g. 5">
                            <span class="input-group-text">%</span>
                        </div>
                        <div class="form-text">% of gross margin (GM).</div>
                    </div>
                    <button type="submit" name="add_assignment" value="1" class="btn btn-primary w-100">Save</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Existing assignments -->
    <div class="col-md-8">
        <div class="card">
            <div class="card-header fw-semibold">Current Assignments</div>
            <div class="card-body p-0">
                <?php if (empty($assignments)): ?>
                <p class="text-muted p-3 mb-0">No assignments yet.</p>
                <?php else: ?>
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr><th>Customer</th><th>Sales Rep</th><th class="text-end">Rate</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($assignments as $a): ?>
                    <tr>
                        <td><?= h($a['customer_name']) ?></td>
                        <td><?= h($a['user_name']) ?></td>
                        <td class="text-end"><?= number_format((float)$a['commission_pct'], 2) ?>%</td>
                        <td class="text-end">
                            <form method="post" class="d-inline"
                                  onsubmit="return confirm('Remove <?= h($a['user_name']) ?> from <?= h($a['customer_name']) ?>?')">
                                <input type="hidden" name="assignment_id" value="<?= $a['id'] ?>">
                                <button type="submit" name="delete_assignment" value="1"
                                        class="btn btn-sm btn-outline-danger">Remove</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<?php render_footer(); ?>
