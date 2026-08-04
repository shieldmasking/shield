<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/quickbooks.php';

require_login();

$db       = db();
$quote_id = (int)($_GET['id'] ?? 0);
$action   = $_POST['action'] ?? '';
$errors   = [];
$msg      = '';

// Load existing quote
$quote      = null;
$quote_items = [];
if ($quote_id) {
    $stmt = $db->prepare('SELECT q.*, c.name AS customer_name FROM quotes q JOIN customers c ON c.id=q.customer_id WHERE q.id=?');
    $stmt->execute([$quote_id]);
    $quote = $stmt->fetch();
    if (!$quote) { header('Location: /inventory/pages/quotes.php'); exit; }

    $stmt = $db->prepare('
        SELECT qi.*, i.base_sku, i.sku, i.width_inches,
               p.name AS item_name, p.roll_length_yards, p.description, p.is_log, p.is_fixed_width
        FROM quote_items qi
        JOIN items i ON i.id = qi.item_id
        JOIN products p ON p.base_sku = i.base_sku
        WHERE qi.quote_id = ?
        ORDER BY qi.id
    ');
    $stmt->execute([$quote_id]);
    $quote_items = $stmt->fetchAll();
}

$is_editable   = !$quote || in_array($quote['status'], ['draft', 'sent']);
$is_approvable = $quote && $quote['status'] === 'sent';
$is_readonly   = $quote && !$is_editable;

// ── Handle POST ───────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Change status only
    if ($action === 'status') {
        $new = $_POST['new_status'] ?? '';
        if ($quote_id && in_array($new, ['sent'])) {
            $db->prepare('UPDATE quotes SET status=? WHERE id=?')->execute([$new, $quote_id]);
        }
        header("Location: /inventory/pages/quote-edit.php?id={$quote_id}&msg=Status+updated");
        exit;
    }

    // Approve quote → create order
    if ($action === 'approve' && $is_approvable) {
        $po_path = null;
        if (!empty($_FILES['po_pdf']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['po_pdf']['name'], PATHINFO_EXTENSION));
            if ($ext !== 'pdf') {
                $errors[] = 'PO attachment must be a PDF file.';
            } elseif ($_FILES['po_pdf']['size'] > 10 * 1024 * 1024) {
                $errors[] = 'PO file exceeds 10MB limit.';
            } else {
                $filename = 'uploads/po_' . $quote_id . '_' . time() . '.pdf';
                $dest     = __DIR__ . '/../' . $filename;
                if (move_uploaded_file($_FILES['po_pdf']['tmp_name'], $dest)) {
                    $po_path = $filename;
                } else {
                    $errors[] = 'Failed to upload PO PDF. Check uploads/ directory permissions.';
                }
            }
        }

        if (empty($errors)) {
            $approval_items = $_POST['approval_items'] ?? [];
            foreach ($approval_items as $qi_id => $row) {
                $qty   = max(0, (int)$row['quantity']);
                $price = (float)$row['unit_price'];
                if ($qty > 0) {
                    $db->prepare('UPDATE quote_items SET quantity=?, unit_price=? WHERE id=? AND quote_id=?')
                       ->execute([$qty, $price, (int)$qi_id, $quote_id]);
                } else {
                    $db->prepare('DELETE FROM quote_items WHERE id=? AND quote_id=?')
                       ->execute([(int)$qi_id, $quote_id]);
                }
            }

            $db->prepare('UPDATE quotes SET status=\'ordered\', po_pdf_path=? WHERE id=?')
               ->execute([$po_path, $quote_id]);

            try {
                $order_id = create_order_from_quote($db, $quote_id, current_user_id());
                header("Location: /inventory/pages/order-view.php?id={$order_id}&msg=Order+created");
                exit;
            } catch (Throwable $e) {
                $errors[] = 'Order creation failed. Please try again.';
                $db->prepare('UPDATE quotes SET status=\'sent\', po_pdf_path=NULL WHERE id=?')->execute([$quote_id]);
            }
        }

        $stmt = $db->prepare('
            SELECT qi.*, i.base_sku, i.sku, i.width_inches,
                   p.name AS item_name, p.roll_length_yards, p.description, p.is_log, p.is_fixed_width
            FROM quote_items qi
            JOIN items i ON i.id = qi.item_id
            JOIN products p ON p.base_sku = i.base_sku
            WHERE qi.quote_id = ? ORDER BY qi.id
        ');
        $stmt->execute([$quote_id]);
        $quote_items = $stmt->fetchAll();
    }

    // Save quote (create or update)
    if ($action === 'save') {
        $customer_id = (int)($_POST['customer_id'] ?? 0);
        $notes       = trim($_POST['notes'] ?? '');
        $line_items  = $_POST['items'] ?? [];

        if (!$customer_id) $errors[] = 'Please select a customer.';
        if (empty($line_items)) $errors[] = 'Add at least one line item.';

        if (empty($errors)) {
            if ($quote_id) {
                $db->prepare('UPDATE quotes SET customer_id=?, notes=?, updated_at=NOW() WHERE id=?')
                   ->execute([$customer_id, $notes ?: null, $quote_id]);
                $db->prepare('DELETE FROM quote_items WHERE quote_id=?')->execute([$quote_id]);
            } else {
                $qnum = next_quote_number($db);
                $cterms = $db->prepare('SELECT terms FROM customers WHERE id=?');
                $cterms->execute([$customer_id]);
                $cterms = ($cterms->fetchColumn() ?: 'Net 30');
                $db->prepare('INSERT INTO quotes (quote_number, customer_id, notes, created_by) VALUES (?,?,?,?)')
                   ->execute([$qnum, $customer_id, $notes ?: $cterms, current_user_id()]);
                $quote_id = (int)$db->lastInsertId();
            }

            $stmt = $db->prepare('INSERT INTO quote_items (quote_id, item_id, quantity, unit_price) VALUES (?,?,?,?)');
            foreach ($line_items as $li) {
                $base_sku = strtoupper(trim($li['base_sku'] ?? ''));
                $width    = (float)($li['width_inches'] ?? 0);
                $qty      = max(1, (int)($li['quantity'] ?? 1));
                $price    = (float)($li['unit_price'] ?? 0);

                if (!$base_sku || $width <= 0 || $qty <= 0 || $price < 0) continue;

                $is_log  = !empty($li['is_log']);
                $item_id = find_or_create_item($db, $base_sku, $width, $is_log);
                if ($item_id) {
                    $stmt->execute([$quote_id, $item_id, $qty, $price]);
                }
            }

            header("Location: /inventory/pages/quote-edit.php?id={$quote_id}&msg=Saved");
            exit;
        }
    }
}

if (isset($_GET['msg'])) $msg = $_GET['msg'];

// ── Load form data ────────────────────────────────────────────────────────────

$customers = $db->query('SELECT id, name, company FROM customers ORDER BY name')->fetchAll();

// Distinct base SKUs (one row per base_sku for metadata)
$base_skus_raw = $db->query('
    SELECT base_sku, name, description, roll_length_yards, is_fixed_width
    FROM products
    WHERE base_sku IN (SELECT DISTINCT base_sku FROM items WHERE is_active = 1)
    ORDER BY base_sku
')->fetchAll();
$base_skus = [];
foreach ($base_skus_raw as $b) {
    $base_skus[$b['base_sku']] = $b;
}

$wm             = get_width_multipliers($db);
$standard_widths = standard_widths(); // 0.125 to 6.0 in 0.125" steps

$total = array_sum(array_map(fn($r) => $r['quantity'] * $r['unit_price'], $quote_items));

$page_title   = $quote_id ? "Quote #" . $quote['quote_number'] : "New Quote";
$status_badge = ['draft'=>'secondary','sent'=>'primary','ordered'=>'success'];

render_header($page_title, 'quotes');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0"><?= h($page_title) ?></h4>
        <?php if ($quote): ?>
        <small class="text-muted"><?= h($quote['customer_name']) ?> &mdash;
            <span class="badge bg-<?= $status_badge[$quote['status']] ?>"><?= ucfirst($quote['status']) ?></span>
        </small>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2">
        <?php if ($quote_id): ?>
        <a href="/inventory/pdf/quote.php?id=<?= $quote_id ?>" target="_blank" class="btn btn-sm btn-outline-secondary">Print PDF</a>
        <?php endif; ?>
        <a href="/inventory/pages/quotes.php" class="btn btn-sm btn-outline-secondary">Back to Quotes</a>
    </div>
</div>

<?php foreach ($errors as $e): ?>
<div class="alert alert-danger"><?= h($e) ?></div>
<?php endforeach; ?>

<?php if ($msg): ?>
<div class="alert alert-success alert-dismissible fade show"><?= h($msg) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<?php if ($is_readonly): ?>
<!-- Read-only view -->
<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="card h-100"><div class="card-header fw-semibold">Customer</div>
        <div class="card-body"><?= h($quote['customer_name']) ?></div></div>
    </div>
    <div class="col-md-6">
        <div class="card h-100"><div class="card-header fw-semibold">Details</div>
        <div class="card-body">
            <div>Status: <span class="badge bg-<?= $status_badge[$quote['status']] ?>"><?= ucfirst($quote['status']) ?></span></div>
            <div class="mt-1">Date: <?= date('M j, Y', strtotime($quote['created_at'])) ?></div>
            <?php if ($quote['po_pdf_path']): ?>
            <div class="mt-1"><a href="/inventory/<?= h($quote['po_pdf_path']) ?>" target="_blank">View Customer PO</a></div>
            <?php endif; ?>
        </div></div>
    </div>
</div>

<div class="card mb-3">
<div class="card-header fw-semibold">Line Items</div>
<div class="card-body p-0">
<table class="table mb-0">
<thead><tr><th>Product</th><th>Width</th><th>Length</th><th>Qty</th><th>Unit Price</th><th class="text-end">Total</th></tr></thead>
<tbody>
<?php foreach ($quote_items as $li): ?>
<tr>
    <td><?= h($li['item_name']) ?></td>
    <td><?= width_label($li) ?></td>
    <td><?= (int)$li['roll_length_yards'] ?>yds</td>
    <td><?= (int)$li['quantity'] ?></td>
    <td><?= currency((float)$li['unit_price']) ?></td>
    <td class="text-end"><?= currency($li['quantity'] * $li['unit_price']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot><tr>
    <td colspan="5" class="text-end fw-bold">Total</td>
    <td class="text-end fw-bold"><?= currency($total) ?></td>
</tr></tfoot>
</table>
</div></div>

<?php if ($quote['notes']): ?>
<div class="card"><div class="card-header fw-semibold">Notes</div>
<div class="card-body"><?= nl2br(h($quote['notes'])) ?></div></div>
<?php endif; ?>

<?php else: // Editable form ?>

<form method="post" id="quoteForm">
<input type="hidden" name="action" value="save">

<div class="row g-3 mb-3">
    <div class="col-md-5">
        <label class="form-label fw-semibold">Customer <span class="text-danger">*</span></label>
        <select name="customer_id" id="customerSelect" class="form-select" required>
            <option value="">Select customer...</option>
            <?php foreach ($customers as $c): ?>
            <option value="<?= $c['id'] ?>" <?= ($quote['customer_id'] ?? 0) == $c['id'] ? 'selected' : '' ?>>
                <?= h($c['name']) ?><?= $c['company'] ? ' — '.h($c['company']) : '' ?>
            </option>
            <?php endforeach; ?>
        </select>
        <div class="form-text" id="lastQuoteNote" style="display:none">
            <span class="text-info">Last quote prices pre-loaded. Review and adjust as needed.</span>
        </div>
    </div>
    <div class="col-md-7">
        <label class="form-label fw-semibold">Terms</label>
        <input type="text" name="notes" class="form-control" value="<?= h($quote['notes'] ?? 'Net 30') ?>" placeholder="e.g. Net 30">
        <?php if (!empty($quote['po_pdf_path'])): ?>
        <div class="form-text">
            <a href="/inventory/<?= h($quote['po_pdf_path']) ?>" target="_blank">View attached PO PDF</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Line Items -->
<div class="card mb-3">
<div class="card-header d-flex justify-content-between align-items-center">
    <span class="fw-semibold">Line Items</span>
    <button type="button" class="btn btn-sm btn-outline-primary" onclick="addRow()">+ Add Item</button>
</div>
<div class="card-body p-0">
<div class="table-responsive">
<table class="table mb-0 align-middle" id="lineItemsTable">
<thead><tr>
    <th style="min-width:140px">Product</th>
    <th style="min-width:140px">Width</th>
    <th style="width:80px">Length</th>
    <th style="width:80px">Qty</th>
    <th style="width:120px">Unit Price</th>
    <th style="width:110px" class="text-end">Line Total</th>
    <th style="width:40px"></th>
</tr></thead>
<tbody id="lineItems">
<?php foreach ($quote_items as $li): ?>
<tr data-row="existing-<?= $li['id'] ?>">
    <td>
        <select name="items[<?= $li['id'] ?>][base_sku]" class="form-select form-select-sm sku-select" onchange="onSkuChange(this)">
            <?php foreach ($base_skus as $bsku => $bdata): ?>
            <option value="<?= h($bsku) ?>"
                data-fixed="<?= $bdata['is_fixed_width'] ?>"
                data-len="<?= $bdata['roll_length_yards'] ?>"
                <?= $bsku === $li['base_sku'] ? 'selected' : '' ?>>
                <?= h($bdata['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <input type="hidden" name="items[<?= $li['id'] ?>][is_log]" value="<?= $li['is_log'] ? '1' : '0' ?>">
    </td>
    <td>
        <?php if ($li['is_fixed_width']): ?>
        <input type="hidden" name="items[<?= $li['id'] ?>][width_inches]" value="2">
        <span class="text-muted">2" (fixed)</span>
        <?php else: ?>
        <select name="items[<?= $li['id'] ?>][width_inches]" class="form-select form-select-sm width-select" onchange="fetchPrice(this.closest('tr'))">
            <?php foreach ($standard_widths as $w): ?>
            <option value="<?= $w ?>" <?= abs($w - (float)$li['width_inches']) < 0.001 ? 'selected' : '' ?>><?= format_width($w) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
    </td>
    <td class="text-muted roll-length"><?= (int)$li['roll_length_yards'] ?>yds</td>
    <td><input type="number" name="items[<?= $li['id'] ?>][quantity]" class="form-control form-control-sm qty-input" min="1" value="<?= (int)$li['quantity'] ?>" required oninput="updateTotals()"></td>
    <td><input type="number" name="items[<?= $li['id'] ?>][unit_price]" class="form-control form-control-sm price-input" step="0.01" min="0" value="<?= number_format((float)$li['unit_price'], 2) ?>" required oninput="updateTotals()"></td>
    <td class="text-end line-total fw-semibold"><?= currency($li['quantity'] * $li['unit_price']) ?></td>
    <td><button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="removeRow(this)">×</button></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot>
<tr class="table-light">
    <td colspan="5" class="text-end fw-bold">Total</td>
    <td class="text-end fw-bold" id="grandTotal"><?= currency($total) ?></td>
    <td></td>
</tr>
</tfoot>
</table>
</div>
</div>
</div>

<div class="d-flex gap-2 flex-wrap">
    <button type="submit" class="btn btn-primary">Save Quote</button>

    <?php if ($quote_id && $quote['status'] === 'draft'): ?>
    <button type="submit" form="statusForm" name="new_status" value="sent" class="btn btn-outline-primary">Mark as Sent</button>
    <?php endif; ?>

    <?php if ($quote_id && $quote['status'] === 'sent'): ?>
    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#approveModal">Create Order</button>
    <?php endif; ?>
</div>
</form>

<form method="post" id="statusForm">
    <input type="hidden" name="action" value="status">
    <input type="hidden" name="new_status" value="">
</form>

<?php endif; // end editable ?>

<?php if ($is_approvable): ?>
<div class="modal fade" id="approveModal" tabindex="-1">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="action" value="approve">
<div class="modal-header">
    <h5 class="modal-title">Approve Quote #<?= $quote['quote_number'] ?></h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
    <div class="alert alert-info py-2">Review quantities to match customer's PO. Upload PO PDF as reference.</div>
    <div class="mb-3">
        <label class="form-label fw-semibold">Customer PO PDF</label>
        <input type="file" name="po_pdf" class="form-control" accept=".pdf">
        <div class="form-text">Optional but recommended. Max 10MB.</div>
    </div>
    <table class="table table-sm">
    <thead><tr><th>SKU</th><th>Item</th><th>Width</th><th>Quoted Qty</th><th>Approved Qty</th><th>Unit Price</th></tr></thead>
    <tbody>
    <?php foreach ($quote_items as $li): ?>
    <tr>
        <td><?= h($li['sku']) ?></td>
        <td><?= h($li['item_name']) ?></td>
        <td><?= width_label($li) ?></td>
        <td class="text-muted"><?= (int)$li['quantity'] ?></td>
        <td style="width:100px">
            <input type="number" name="approval_items[<?= $li['id'] ?>][quantity]"
                   class="form-control form-control-sm" min="0" value="<?= (int)$li['quantity'] ?>" required>
        </td>
        <td style="width:120px">
            <input type="number" name="approval_items[<?= $li['id'] ?>][unit_price]"
                   class="form-control form-control-sm" step="0.01" min="0" value="<?= number_format((float)$li['unit_price'], 2) ?>" required>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
    <div class="form-text">Set quantity to 0 to remove an item from the order.</div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn btn-success">Confirm Approval &amp; Create Order</button>
</div>
</form>
</div></div></div>
<?php endif; ?>

<script>
const BASE_SKUS = <?= json_encode(array_values($base_skus)) ?>;
const WIDTHS    = <?= json_encode($standard_widths) ?>;
const IS_NEW    = <?= $quote_id ? 'false' : 'true' ?>;
let rowCounter  = 1000;

function skuOpts(selectedSku) {
    return BASE_SKUS.map(b =>
        `<option value="${b.base_sku}" data-fixed="${b.is_fixed_width}" data-len="${b.roll_length_yards}"${b.base_sku === selectedSku ? ' selected' : ''}>${b.name}</option>`
    ).join('');
}

function widthOpts(selectedWidth) {
    return WIDTHS.map(w => {
        const label = parseFloat(w).toString().replace(/\.?0+$/, '') + '"';
        const sel   = Math.abs(w - selectedWidth) < 0.001 ? ' selected' : '';
        return `<option value="${w}"${sel}>${label}</option>`;
    }).join('');
}

function addRow(data = {}) {
    rowCounter++;
    const n        = rowCounter;
    const baseSku  = data.base_sku  || BASE_SKUS[0]?.base_sku || '';
    const width    = data.width     || 1;
    const qty      = data.qty       || 1;
    const price    = data.price     || 0;
    const isLog    = data.is_log    || 0;
    const base     = BASE_SKUS.find(b => b.base_sku === baseSku) || BASE_SKUS[0];
    const isFixed  = base?.is_fixed_width == 1;
    const len      = base?.roll_length_yards || '';

    const widthCell = isFixed
        ? `<input type="hidden" name="items[${n}][width_inches]" value="2"><span class="text-muted">2" (fixed)</span>`
        : `<select name="items[${n}][width_inches]" class="form-select form-select-sm width-select" onchange="fetchPrice(this.closest('tr'))">${widthOpts(width)}</select>`;

    const tr = document.createElement('tr');
    tr.dataset.row = n;
    tr.innerHTML = `
        <td>
            <select name="items[${n}][base_sku]" class="form-select form-select-sm sku-select" onchange="onSkuChange(this)">${skuOpts(baseSku)}</select>
            <input type="hidden" name="items[${n}][is_log]" value="${isLog}">
        </td>
        <td class="width-cell">${widthCell}</td>
        <td class="text-muted roll-length">${len}yds</td>
        <td><input type="number" name="items[${n}][quantity]" class="form-control form-control-sm qty-input" min="1" value="${qty}" required oninput="updateTotals()"></td>
        <td><input type="number" name="items[${n}][unit_price]" class="form-control form-control-sm price-input" step="0.01" min="0" value="${price > 0 ? price.toFixed(2) : ''}" required oninput="updateTotals()"></td>
        <td class="text-end line-total fw-semibold">${price > 0 ? '$' + (qty * price).toFixed(2) : '—'}</td>
        <td><button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="removeRow(this)">×</button></td>
    `;
    document.getElementById('lineItems').appendChild(tr);

    if (!data.price || data.price === 0) fetchPrice(tr);
    updateTotals();
}

function onSkuChange(sel) {
    const tr      = sel.closest('tr');
    const opt     = sel.selectedOptions[0];
    const isFixed = opt.dataset.fixed === '1';
    const len     = opt.dataset.len;
    const n       = tr.dataset.row;

    tr.querySelector('.roll-length').textContent = len + 'yds';

    const widthCell = tr.querySelector('.width-cell');
    if (isFixed) {
        widthCell.innerHTML = `<input type="hidden" name="items[${n}][width_inches]" value="2"><span class="text-muted">2" (fixed)</span>`;
    } else {
        widthCell.innerHTML = `<select name="items[${n}][width_inches]" class="form-select form-select-sm width-select" onchange="fetchPrice(this.closest('tr'))">${widthOpts(1)}</select>`;
    }
    fetchPrice(tr);
}

function removeRow(btn) {
    btn.closest('tr').remove();
    updateTotals();
}

async function fetchPrice(tr) {
    const skuSel  = tr.querySelector('.sku-select');
    const widthSel = tr.querySelector('.width-select');
    const priceIn  = tr.querySelector('.price-input');

    if (!skuSel || !widthSel || !priceIn) return;
    const baseSku = skuSel.value;
    const width   = widthSel.value;
    if (!baseSku || !width || parseFloat(width) <= 0) return;

    try {
        const res  = await fetch(`/inventory/api/quote-price.php?base_sku=${encodeURIComponent(baseSku)}&width=${width}`);
        const data = await res.json();
        if (data.sell_price !== undefined) {
            priceIn.value = data.sell_price.toFixed(2);
            updateTotals();
        }
    } catch(e) { /* ignore */ }
}

function updateTotals() {
    let total = 0;
    document.querySelectorAll('#lineItems tr').forEach(tr => {
        const qty   = parseFloat(tr.querySelector('.qty-input')?.value) || 0;
        const price = parseFloat(tr.querySelector('.price-input')?.value) || 0;
        const lt    = qty * price;
        const ltCell = tr.querySelector('.line-total');
        if (ltCell) ltCell.textContent = lt > 0 ? '$' + lt.toFixed(2) : '—';
        total += lt;
    });
    document.getElementById('grandTotal').textContent = '$' + total.toFixed(2);
}

// Customer selection → pre-fill from last quote
document.getElementById('customerSelect')?.addEventListener('change', async function() {
    if (!IS_NEW) return;
    const cid = this.value;
    if (!cid) return;
    try {
        const res  = await fetch(`/inventory/api/customer-last-quote.php?customer_id=${cid}`);
        const data = await res.json();
        const keys = Object.keys(data);
        if (keys.length === 0) return;

        document.getElementById('lineItems').innerHTML = '';
        for (const [, row] of Object.entries(data)) {
            addRow({
                base_sku: row.base_sku,
                width:    parseFloat(row.width_inches),
                qty:      row.quantity,
                price:    parseFloat(row.unit_price),
                is_log:   row.is_log,
            });
        }
        document.getElementById('lastQuoteNote').style.display = '';
        updateTotals();
    } catch(e) { /* ignore */ }
});

updateTotals();
</script>

<?php render_footer(); ?>
