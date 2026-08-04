<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/layout.php';

require_login();

$db = db();

// ── Create quote from uploaded PO ─────────────────────────────────────────────
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
        // Create draft quote
        $qnum = next_quote_number($db);
        $db->prepare('INSERT INTO quotes (quote_number, customer_id, notes, po_pdf_path, created_by) VALUES (?,?,?,?,?)')
           ->execute([$qnum, $customer_id, $notes ?: null, $po_path, current_user_id()]);
        $quote_id = (int)$db->lastInsertId();

        // Pre-fill from customer's last quote
        $last = get_last_quote_prices($db, $customer_id);
        if ($last) {
            $wm   = get_width_multipliers($db);
            $stmt = $db->prepare('INSERT INTO quote_items (quote_id, item_id, quantity, unit_price) VALUES (?,?,?,?)');
            foreach ($last as $item_id => $row) {
                $stmt->execute([$quote_id, $item_id, $row['quantity'], $row['unit_price']]);
            }
            $msg_param = 'Quote+created+from+PO+%E2%80%94+prices+carried+from+last+quote.+Review+and+save.';
        } else {
            // No prior quote — pre-fill with list prices is not possible without knowing
            // what was ordered; redirect to editor with a note to add items manually.
            $msg_param = 'Quote+created+from+PO.+No+prior+quote+found+%E2%80%94+add+line+items+at+list+price.';
        }

        header("Location: /inventory/pages/quote-edit.php?id={$quote_id}&msg={$msg_param}");
        exit;
    }

    // On error, fall through and show the page with $errors
    $po_errors = $errors;
}

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

$customers = $db->query('SELECT id, name, company FROM customers ORDER BY name')->fetchAll();

$status_badge = [
    'draft'    => 'secondary',
    'sent'     => 'primary',
    'approved' => 'success',
    'expired'  => 'warning',
    'rejected' => 'danger',
];

render_header('Quotes', 'quotes');
?>

<?php if (!empty($po_errors)): ?>
<?php foreach ($po_errors as $e): ?>
<div class="alert alert-danger"><?= h($e) ?></div>
<?php endforeach; ?>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Quotes</h4>
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

<!-- Upload PO / Create Quote Modal -->
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
        Line items will be pre-loaded from the customer's most recent quote.
        If no prior quote exists, you'll add items manually in the editor.
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

<?php render_footer(); ?>
