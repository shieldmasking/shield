<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/layout.php';

require_login();

$db = db();

// ── Add PO to existing quote ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_po_quote_id'])) {
    $quote_id = (int)$_POST['add_po_quote_id'];
    $errors   = [];
    $po_path  = null;

    if (!$quote_id) {
        $errors[] = 'Invalid quote.';
    } elseif (empty($_FILES['add_po_pdf']['tmp_name'])) {
        $errors[] = 'Please select a PDF file.';
    } else {
        $ext = strtolower(pathinfo($_FILES['add_po_pdf']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            $errors[] = 'File must be a PDF.';
        } elseif ($_FILES['add_po_pdf']['size'] > 10 * 1024 * 1024) {
            $errors[] = 'File exceeds 10MB limit.';
        } else {
            $filename = 'uploads/po_' . $quote_id . '_' . time() . '.pdf';
            $dest     = __DIR__ . '/../' . $filename;
            if (!move_uploaded_file($_FILES['add_po_pdf']['tmp_name'], $dest)) {
                $errors[] = 'Upload failed. Check uploads/ directory permissions.';
            } else {
                $po_path = $filename;
            }
        }
    }

    if (empty($errors) && $po_path) {
        $parsed = parse_po_pdf(__DIR__ . '/../' . $po_path, $db);

        $qi_stmt = $db->prepare('
            SELECT qi.quantity, qi.unit_price, i.sku
            FROM quote_items qi JOIN items i ON i.id = qi.item_id
            WHERE qi.quote_id = ?
        ');
        $qi_stmt->execute([$quote_id]);
        $quote_items_for_compare = $qi_stmt->fetchAll();

        if ($parsed['parse_error']) {
            $disc_json = json_encode(['parse_error' => true]);
            $_SESSION['po_notice'] = 'PO uploaded but could not be parsed automatically — please verify quantities manually.';
        } else {
            $discrepancies = compare_po_to_quote($parsed['items'], $quote_items_for_compare);
            $disc_json = empty($discrepancies) ? null : json_encode(['parse_error' => false, 'discrepancies' => $discrepancies]);
        }

        $cur = $db->prepare('SELECT status FROM quotes WHERE id = ?');
        $cur->execute([$quote_id]);
        $current_status = $cur->fetchColumn();

        $db->prepare("UPDATE quotes SET po_pdf_path = ?, po_discrepancies = ?, status = 'ordered' WHERE id = ?")
           ->execute([$po_path, $disc_json, $quote_id]);

        if ($current_status !== 'ordered') {
            $existing = $db->prepare('SELECT id FROM orders WHERE quote_id = ?');
            $existing->execute([$quote_id]);
            if (!$existing->fetch()) {
                $notices = log_cut_recommendations($db, $quote_id);
                if ($notices) $_SESSION['cut_notices'] = $notices;
                create_order_from_quote($db, $quote_id, current_user_id());
            }
        }

        header('Location: /inventory/pages/dashboard.php?' . http_build_query(array_filter([
            'status' => $_POST['_filter_status'] ?? '',
            'q'      => $_POST['_filter_q'] ?? '',
        ])));
        exit;
    }

    $po_errors = $errors;
}

// ── Create quote from uploaded PO (no existing quote) ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_from_po'])) {
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $notes       = trim($_POST['notes'] ?? '');
    $errors      = [];

    if (!$customer_id) $errors[] = 'Please select a customer.';

    $po_path = null;
    if (!empty($_FILES['po_pdf']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['po_pdf']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            $errors[] = 'File must be a PDF.';
        } elseif ($_FILES['po_pdf']['size'] > 10 * 1024 * 1024) {
            $errors[] = 'File exceeds 10MB limit.';
        } else {
            $filename = 'uploads/po_cust' . $customer_id . '_' . time() . '.pdf';
            $dest     = __DIR__ . '/../' . $filename;
            if (!move_uploaded_file($_FILES['po_pdf']['tmp_name'], $dest)) {
                $errors[] = 'Upload failed. Check uploads/ directory permissions.';
            } else {
                $po_path = $filename;
            }
        }
    }

    if (empty($errors)) {
        $qnum   = next_quote_number($db);
        $parsed = $po_path ? parse_po_pdf(__DIR__ . '/../' . $po_path, $db) : ['parse_error' => true, 'items' => []];
        $use_po = !$parsed['parse_error'] && !empty($parsed['items']);

        if ($use_po) {
            // Create quote as PO Received with parsed items
            $db->prepare('INSERT INTO quotes (quote_number, customer_id, notes, po_pdf_path, status, created_by) VALUES (?,?,?,?,?,?)')
               ->execute([$qnum, $customer_id, $notes ?: null, $po_path, 'ordered', current_user_id()]);
            $quote_id = (int)$db->lastInsertId();

            $stmt = $db->prepare('INSERT INTO quote_items (quote_id, item_id, quantity, unit_price) VALUES (?,?,?,?)');
            foreach ($parsed['items'] as $pi) {
                if (!$pi['qty']) continue;
                $stmt->execute([$quote_id, $pi['item_id'], $pi['qty'], round((float)($pi['price'] ?? 0), 2)]);
            }

            $notices = log_cut_recommendations($db, $quote_id);
            if ($notices) $_SESSION['cut_notices'] = $notices;
            create_order_from_quote($db, $quote_id, current_user_id());

            header('Location: /inventory/pages/dashboard.php');
            exit;
        } else {
            // Parse failed — create draft and redirect to editor
            $db->prepare('INSERT INTO quotes (quote_number, customer_id, notes, po_pdf_path, created_by) VALUES (?,?,?,?,?)')
               ->execute([$qnum, $customer_id, $notes ?: null, $po_path, current_user_id()]);
            $quote_id = (int)$db->lastInsertId();

            $last = get_last_quote_prices($db, $customer_id);
            if ($last) {
                $stmt = $db->prepare('INSERT INTO quote_items (quote_id, item_id, quantity, unit_price) VALUES (?,?,?,?)');
                foreach ($last as $item_id => $row) {
                    $stmt->execute([$quote_id, $item_id, $row['quantity'], $row['unit_price']]);
                }
                $msg_param = $po_path
                    ? 'PO+could+not+be+parsed+automatically.+Prices+carried+from+last+quote+%E2%80%94+verify+quantities.'
                    : 'Quote+created+%E2%80%94+prices+carried+from+last+quote.+Review+and+save.';
            } else {
                $msg_param = $po_path
                    ? 'PO+could+not+be+parsed+automatically.+No+prior+quote+found+%E2%80%94+add+line+items+manually.'
                    : 'Quote+created.+No+prior+quote+found+%E2%80%94+add+line+items+at+list+price.';
            }
            header("Location: /inventory/pages/quote-edit.php?id={$quote_id}&msg={$msg_param}");
            exit;
        }
    }

    $po_errors = $errors;
}

// ── Status change ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quote_id'], $_POST['status'])) {
    $new      = $_POST['status'];
    $quote_id = (int)$_POST['quote_id'];
    if (in_array($new, ['draft', 'sent', 'ordered'])) {
        $cur = $db->prepare('SELECT status FROM quotes WHERE id = ?');
        $cur->execute([$quote_id]);
        $current_status = $cur->fetchColumn();

        $db->prepare('UPDATE quotes SET status=? WHERE id=?')->execute([$new, $quote_id]);

        if ($new === 'ordered' && $current_status !== 'ordered') {
            $existing = $db->prepare('SELECT id FROM orders WHERE quote_id = ?');
            $existing->execute([$quote_id]);
            if (!$existing->fetch()) {
                $notices = log_cut_recommendations($db, $quote_id);
                if ($notices) $_SESSION['cut_notices'] = $notices;
                create_order_from_quote($db, $quote_id, $_SESSION['user_id']);
            }
        } elseif ($current_status === 'ordered' && $new !== 'ordered') {
            $ord = $db->prepare('SELECT id FROM orders WHERE quote_id = ?');
            $ord->execute([$quote_id]);
            $order = $ord->fetch();
            if ($order) {
                $order_id = (int)$order['id'];
                $items = $db->prepare('SELECT item_id, quantity FROM order_items WHERE order_id = ?');
                $items->execute([$order_id]);
                foreach ($items->fetchAll() as $oi) {
                    adjust_inventory($db, (int)$oi['item_id'], (float)$oi['quantity'],
                        'Order cancelled — Quote reverted to ' . $new, 'adjustment', null, $_SESSION['user_id']);
                }
                $db->prepare('DELETE FROM orders WHERE id = ?')->execute([$order_id]);
            }
        }
    }
    header('Location: /inventory/pages/dashboard.php?' . http_build_query(array_filter([
        'status' => $_POST['_filter_status'] ?? '',
        'q'      => $_POST['_filter_q'] ?? '',
    ])));
    exit;
}

// ── Delete SO ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_so_quote_id'])) {
    $quote_id = (int)$_POST['delete_so_quote_id'];
    $ord = $db->prepare('SELECT id FROM orders WHERE quote_id = ?');
    $ord->execute([$quote_id]);
    $order = $ord->fetch();
    if ($order) {
        $order_id = (int)$order['id'];
        $items = $db->prepare('SELECT item_id, quantity FROM order_items WHERE order_id = ?');
        $items->execute([$order_id]);
        foreach ($items->fetchAll() as $oi) {
            adjust_inventory($db, (int)$oi['item_id'], (float)$oi['quantity'],
                'SO deleted', 'adjustment', null, current_user_id());
        }
        $db->prepare('DELETE FROM orders WHERE id = ?')->execute([$order_id]);
        $db->prepare("UPDATE quotes SET status = 'sent' WHERE id = ?")->execute([$quote_id]);
    }
    header('Location: /inventory/pages/dashboard.php?' . http_build_query(array_filter([
        'status' => $_POST['_filter_status'] ?? '',
        'q'      => $_POST['_filter_q'] ?? '',
    ])));
    exit;
}

// ── Create SO for quote that has PO but no order ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_so_quote_id'])) {
    $quote_id = (int)$_POST['create_so_quote_id'];
    $existing = $db->prepare('SELECT id FROM orders WHERE quote_id = ?');
    $existing->execute([$quote_id]);
    if (!$existing->fetch()) {
        $notices = log_cut_recommendations($db, $quote_id);
        if ($notices) $_SESSION['cut_notices'] = $notices;
        create_order_from_quote($db, $quote_id, current_user_id());
        $db->prepare("UPDATE quotes SET status = 'ordered' WHERE id = ?")->execute([$quote_id]);
    }
    header('Location: /inventory/pages/dashboard.php?' . http_build_query(array_filter([
        'status' => $_POST['_filter_status'] ?? '',
        'q'      => $_POST['_filter_q'] ?? '',
    ])));
    exit;
}

// ── Stats ─────────────────────────────────────────────────────────────────────
$total_skus  = $db->query('SELECT COUNT(*) FROM items WHERE is_active = 1')->fetchColumn();
$low_stock   = $db->query('SELECT COUNT(*) FROM items WHERE is_active = 1 AND reorder_threshold > 0 AND quantity_on_hand <= reorder_threshold')->fetchColumn();
$open_quotes = $db->query("SELECT COUNT(*) FROM quotes WHERE status IN ('draft','sent')")->fetchColumn();
$open_orders = $db->query('SELECT COUNT(*) FROM orders WHERE qb_invoice_id IS NULL')->fetchColumn();

// ── Quote list ─────────────────────────────────────────────────────────────────
$status  = $_GET['status'] ?? '';
$search  = trim($_GET['q'] ?? '');
$where   = ['1=1'];
$params  = [];

if ($status && in_array($status, ['draft','sent','ordered'])) {
    $where[]  = 'q.status = ?';
    $params[] = $status;
}
if ($search) {
    $where[]  = '(c.name LIKE ? OR c.company LIKE ? OR q.quote_number LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

// Add po_discrepancies column if it doesn't exist yet
try {
    $db->query('SELECT po_discrepancies FROM quotes LIMIT 1');
} catch (PDOException $e) {
    $db->exec('ALTER TABLE quotes ADD COLUMN po_discrepancies TEXT NULL AFTER po_pdf_path');
}

$sql = 'SELECT q.id, q.quote_number, q.status, q.created_at, q.po_pdf_path, q.po_discrepancies,
               c.name AS customer_name, c.company,
               (SELECT SUM(qi.quantity * qi.unit_price) FROM quote_items qi WHERE qi.quote_id = q.id) AS total,
               o.id AS order_id
        FROM quotes q
        JOIN customers c ON c.id = q.customer_id
        LEFT JOIN orders o ON o.quote_id = q.id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY q.created_at DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$quotes = $stmt->fetchAll();

$customers = $db->query('SELECT id, name, company FROM customers ORDER BY name')->fetchAll();

$status_labels = [
    'draft'   => 'Draft',
    'sent'    => 'Sent',
    'ordered' => 'PO Received',
];
$status_badge = [
    'draft'   => 'secondary',
    'sent'    => 'primary',
    'ordered' => 'success',
];

render_header('Dashboard', 'dashboard');
?>

<?php if (!empty($po_errors)): ?>
<?php foreach ($po_errors as $e): ?>
<div class="alert alert-danger"><?= h($e) ?></div>
<?php endforeach; ?>
<?php endif; ?>
<?php if (!empty($_SESSION['po_notice'])): ?>
<div class="alert alert-warning"><?= h($_SESSION['po_notice']) ?></div>
<?php unset($_SESSION['po_notice']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['cut_notices'])): ?>
<?php $cut_notices = $_SESSION['cut_notices']; unset($_SESSION['cut_notices']); ?>
<div class="alert alert-warning">
    <strong>Log cutting required to fulfil order:</strong>
    <ul class="mb-0 mt-1">
    <?php foreach ($cut_notices as $n): ?>
        <li>
            Cut <strong><?= $n['logs_needed'] ?></strong> log<?= $n['logs_needed'] > 1 ? 's' : '' ?> of
            <strong><?= h($n['log_sku']) ?></strong> (<?= format_width($n['log_width']) ?>) →
            <?= $n['rolls_per_log'] ?> rolls/log of <?= format_width($n['cut_width']) ?>
            for <strong><?= h($n['sku']) ?></strong>
            <?php if (!$n['enough']): ?>
            <span class="text-danger fw-semibold">(only <?= $n['logs_available'] ?> available)</span>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

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

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Quotes</h5>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#poUploadModal">
            Upload PO
        </button>
        <a href="/inventory/pages/quote-edit.php" class="btn btn-primary btn-sm">+ New Quote</a>
    </div>
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
            <?= $status_labels[$s] ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead><tr>
                <th>Quote #</th><th>Customer</th><th>Status</th><th>PO</th><th>Sales Order</th><th>Total</th><th>Date</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($quotes as $q):
                $disc           = $q['po_discrepancies'] ? json_decode($q['po_discrepancies'], true) : null;
                $parse_error    = $disc && !empty($disc['parse_error']);
                $has_disc       = $disc && empty($disc['parse_error']) && !empty($disc['discrepancies']);
                $row_class      = ($parse_error || $has_disc) ? 'table-warning' : '';
            ?>
            <tr class="<?= $row_class ?>">
                <td class="fw-semibold">#<?= (int)$q['quote_number'] ?></td>
                <td>
                    <?= h($q['customer_name']) ?>
                    <?php if ($q['company']): ?><br><small class="text-muted"><?= h($q['company']) ?></small><?php endif; ?>
                </td>
                <td>
                    <select class="form-select form-select-sm" style="width:auto"
                            onchange="setStatus(<?= (int)$q['id'] ?>, this.value)">
                        <?php foreach ($status_labels as $val => $label): ?>
                        <option value="<?= $val ?>" <?= $q['status'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($parse_error): ?>
                    <span class="text-warning fw-semibold ms-1" title="PO could not be parsed — verify quantities manually">⚠ Verify quantities</span>
                    <?php elseif ($has_disc): ?>
                    <a href="#" class="text-warning fw-semibold ms-1 text-decoration-none"
                       data-bs-toggle="modal" data-bs-target="#discModal<?= (int)$q['id'] ?>">⚠ Discrepancies</a>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($q['po_pdf_path']): ?>
                    <a href="/inventory/<?= h($q['po_pdf_path']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">View PO</a>
                    <?php else: ?>
                    <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($q['order_id']): ?>
                    <a href="/inventory/pdf/so.php?id=<?= (int)$q['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary">View SO</a>
                    <form method="post" style="display:inline" onsubmit="return confirm('Delete this Sales Order? Inventory will be reversed.')">
                        <input type="hidden" name="delete_so_quote_id" value="<?= (int)$q['id'] ?>">
                        <input type="hidden" name="_filter_status" value="<?= h($status) ?>">
                        <input type="hidden" name="_filter_q" value="<?= h($search) ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete SO</button>
                    </form>
                    <?php elseif ($q['po_pdf_path']): ?>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="create_so_quote_id" value="<?= (int)$q['id'] ?>">
                        <input type="hidden" name="_filter_status" value="<?= h($status) ?>">
                        <input type="hidden" name="_filter_q" value="<?= h($search) ?>">
                        <button type="submit" class="btn btn-sm btn-primary">Create SO</button>
                    </form>
                    <?php else: ?>
                    <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td><?= $q['total'] !== null ? currency((float)$q['total']) : '—' ?></td>
                <td><?= date('M j, Y', strtotime($q['created_at'])) ?></td>
                <td class="text-end text-nowrap">
                    <a href="/inventory/pages/quote-edit.php?id=<?= (int)$q['id'] ?>" class="btn btn-sm btn-outline-secondary">
                        <?= in_array($q['status'], ['draft','sent']) ? 'Edit' : 'View' ?>
                    </a>
                    <a href="/inventory/pdf/quote.php?id=<?= (int)$q['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary">PDF</a>
                    <button class="btn btn-sm btn-outline-primary"
                            onclick="openAddPO(<?= (int)$q['id'] ?>)">
                        <?= $q['po_pdf_path'] ? 'Replace PO' : 'Add PO' ?>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($quotes)): ?>
            <tr><td colspan="8" class="text-muted text-center py-3">No quotes found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php // ── Per-row discrepancy modals ──────────────────────────────────────────
foreach ($quotes as $q):
    $disc = $q['po_discrepancies'] ? json_decode($q['po_discrepancies'], true) : null;
    if (!$disc || !empty($disc['parse_error']) || empty($disc['discrepancies'])) continue;
?>
<div class="modal fade" id="discModal<?= (int)$q['id'] ?>" tabindex="-1">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<div class="modal-header">
    <h5 class="modal-title">PO Discrepancies — Quote #<?= (int)$q['quote_number'] ?></h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body p-0">
<table class="table mb-0">
<thead><tr><th>SKU</th><th>Issue</th><th>Quote</th><th>PO</th></tr></thead>
<tbody>
<?php foreach ($disc['discrepancies'] as $d): ?>
<tr>
    <td class="fw-semibold"><?= h($d['sku']) ?></td>
    <td>
        <?php if (!empty($d['missing_from_po'])): ?>
            <span class="badge bg-danger">Not on PO</span>
        <?php elseif (!empty($d['missing_from_quote'])): ?>
            <span class="badge bg-warning text-dark">Not on Quote</span>
        <?php else: ?>
            <?php if (isset($d['qty_quote'])): ?><span class="badge bg-secondary">Qty mismatch</span> <?php endif; ?>
            <?php if (isset($d['price_quote'])): ?><span class="badge bg-secondary">Price mismatch</span><?php endif; ?>
        <?php endif; ?>
    </td>
    <td>
        <?php if (!empty($d['missing_from_po'])): ?>
            Qty: <?= $d['qty_quote'] ?>, <?= currency($d['price_quote']) ?>
        <?php elseif (!empty($d['missing_from_quote'])): ?>
            —
        <?php else: ?>
            <?php if (isset($d['qty_quote'])): ?>Qty: <?= $d['qty_quote'] ?><br><?php endif; ?>
            <?php if (isset($d['price_quote'])): ?><?= currency($d['price_quote']) ?><?php endif; ?>
        <?php endif; ?>
    </td>
    <td>
        <?php if (!empty($d['missing_from_po'])): ?>
            —
        <?php elseif (!empty($d['missing_from_quote'])): ?>
            Qty: <?= $d['qty_po'] ?? '?' ?>, <?= $d['price_po'] ? currency($d['price_po']) : '?' ?>
        <?php else: ?>
            <?php if (isset($d['qty_po'])): ?>Qty: <?= $d['qty_po'] ?><br><?php endif; ?>
            <?php if (isset($d['price_po'])): ?><?= currency($d['price_po']) ?><?php endif; ?>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
    <a href="/inventory/pages/quote-edit.php?id=<?= (int)$q['id'] ?>" class="btn btn-primary">Edit Quote</a>
</div>
</div></div></div>
<?php endforeach; ?>

<!-- Add PO to existing quote modal -->
<div class="modal fade" id="addPoModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="add_po_quote_id" id="addPoQuoteId">
<input type="hidden" name="_filter_status" value="<?= h($status) ?>">
<input type="hidden" name="_filter_q" value="<?= h($search) ?>">
<div class="modal-header">
    <h5 class="modal-title">Upload Customer PO</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
    <div class="alert alert-info py-2 small">
        SKUs will be matched automatically against the quote. If the PDF cannot be parsed,
        you will be prompted to verify quantities manually.
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold">Customer PO (PDF) <span class="text-danger">*</span></label>
        <input type="file" name="add_po_pdf" class="form-control" accept=".pdf" required>
        <div class="form-text">Max 10MB.</div>
    </div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn btn-primary">Upload &amp; Compare</button>
</div>
</form>
</div></div></div>

<!-- Upload PO / Create Quote modal (no existing quote) -->
<div class="modal fade" id="poUploadModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
<form method="post" enctype="multipart/form-data">
<div class="modal-header">
    <h5 class="modal-title">Create Quote from Customer PO</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
    <div class="alert alert-info py-2 small">
        SKUs and quantities will be parsed from the PDF automatically. If parsing fails,
        a draft quote will be created for manual entry.
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold">Customer <span class="text-danger">*</span></label>
        <select name="customer_id" class="form-select" required>
            <option value="">Select customer...</option>
            <?php foreach ($customers as $c): ?>
            <option value="<?= $c['id'] ?>">
                <?= h($c['name']) ?><?= $c['company'] ? ' — ' . h($c['company']) : '' ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold">Customer PO (PDF) <span class="text-danger">*</span></label>
        <input type="file" name="po_pdf" class="form-control" accept=".pdf" required>
        <div class="form-text">Max 10MB.</div>
    </div>
    <div class="mb-3">
        <label class="form-label">Notes (optional)</label>
        <input type="text" name="notes" class="form-control" placeholder="e.g. Rush order, special instructions">
    </div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" name="create_from_po" value="1" class="btn btn-primary">Create Quote</button>
</div>
</form>
</div></div></div>

<form method="post" id="statusForm" action="/inventory/pages/dashboard.php">
    <input type="hidden" name="quote_id" id="statusQuoteId">
    <input type="hidden" name="status"   id="statusValue">
    <input type="hidden" name="_filter_status" value="<?= h($status) ?>">
    <input type="hidden" name="_filter_q"      value="<?= h($search) ?>">
</form>
<script>
function setStatus(quoteId, status) {
    document.getElementById('statusQuoteId').value = quoteId;
    document.getElementById('statusValue').value   = status;
    document.getElementById('statusForm').submit();
}
function openAddPO(quoteId) {
    document.getElementById('addPoQuoteId').value = quoteId;
    new bootstrap.Modal(document.getElementById('addPoModal')).show();
}
</script>
<?php render_footer(); ?>
