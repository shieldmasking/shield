<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/layout.php';

require_login();

$db = db();

// Handle convert POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['convert_groups'])) {
    $src_stmt = $db->prepare('SELECT i.*, p.is_log FROM items i JOIN products p ON p.base_sku = i.base_sku WHERE i.id = ?');

    foreach ($_POST['convert_groups'] as $group) {
        $src_id  = (int)($group['item_id'] ?? 0);
        $src_qty = (int)($group['qty'] ?? 0);
        $lines   = $group['lines'] ?? [];

        if (!$src_id || $src_qty <= 0 || empty($lines)) continue;

        $src_stmt->execute([$src_id]);
        $src_row = $src_stmt->fetch();
        if (!$src_row) continue;

        adjust_inventory($db, $src_id, -$src_qty, "Converted {$src_qty}x {$src_row['sku']} to rolls", 'convert', null, current_user_id());

        foreach ($lines as $line) {
            $width = (float)($line['width'] ?? 0);
            $qty   = (int)($line['qty'] ?? 0);
            if ($width > 0 && $qty > 0) {
                $tgt_id = find_or_create_item($db, $src_row['base_sku'], $width, false);
                if ($tgt_id) {
                    adjust_inventory($db, $tgt_id, $qty, "Converted from {$src_row['sku']}", 'convert', null, current_user_id());
                }
            }
        }
    }

    header('Location: /inventory/pages/inventory.php?saved=1');
    exit;
}

// Handle stock adjustment POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjust_item_id'])) {
    $item_id  = (int)$_POST['adjust_item_id'];
    $type     = $_POST['adjust_type'] ?? 'manual';
    $qty      = (float)$_POST['adjust_qty'];
    $notes    = trim($_POST['adjust_notes'] ?? '');

    if ($type === 'receive') {
        $qty    = abs($qty);
        $reason = 'Received stock' . ($notes ? ": {$notes}" : '');
        $ref    = 'receiving';
    } else {
        $reason = 'Manual adjustment' . ($notes ? ": {$notes}" : '');
        $ref    = 'manual';
    }

    if ($qty != 0) {
        adjust_inventory($db, $item_id, $qty, $reason, $ref, null, current_user_id());
    }

    header('Location: /inventory/pages/inventory.php?saved=1');
    exit;
}

// Handle receive-new-width POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['receive_base_sku'])) {
    $base_sku = strtoupper(trim($_POST['receive_base_sku']));
    $width    = (float)($_POST['receive_width'] ?? 0);
    $qty      = (float)($_POST['receive_qty'] ?? 0);
    $notes    = trim($_POST['receive_notes'] ?? '');

    if ($base_sku && $width > 0 && $qty > 0) {
        $item_id = find_or_create_item($db, $base_sku, $width);
        if ($item_id) {
            $reason = 'Received stock' . ($notes ? ": {$notes}" : '');
            adjust_inventory($db, $item_id, $qty, $reason, 'receiving', null, current_user_id());
        }
    }

    header('Location: /inventory/pages/inventory.php?saved=1');
    exit;
}

// Filters
$filter  = $_GET['filter'] ?? '';
$cat_id  = (int)($_GET['category'] ?? 0);
$show_all = isset($_GET['all']);

$where  = ['i.is_active = 1'];
$params = [];

if (!$show_all) {
    $where[] = 'i.quantity_on_hand > 0';
}
if ($filter === 'low_stock') {
    $where[] = 'i.reorder_threshold > 0 AND i.quantity_on_hand <= i.reorder_threshold';
}
if ($cat_id) {
    $where[] = 'p.category_id = ?';
    $params[] = $cat_id;
}

$sql = 'SELECT i.*, p.name, p.roll_length_yards, p.is_log, p.is_fixed_width, p.description, c.name AS category_name
        FROM items i
        JOIN products p ON p.base_sku = i.base_sku
        JOIN categories c ON c.id = p.category_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY i.base_sku, p.is_log, i.width_inches';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

// Base SKUs for the receive-new-width modal
$base_skus = $db->query('SELECT DISTINCT base_sku FROM products ORDER BY base_sku')->fetchAll(PDO::FETCH_COLUMN);

$categories = $db->query('SELECT * FROM categories ORDER BY name')->fetchAll();
$standard_widths = standard_widths();

render_header('Inventory', 'inventory');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Inventory</h4>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#receiveNewModal">
            + Receive New Width
        </button>
        <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#convertModal">
            Convert
        </button>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#adjustModal">
            Adjust Stock
        </button>
    </div>
</div>

<?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success alert-dismissible fade show">Stock updated. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<!-- Filters -->
<form method="get" class="row g-2 mb-3">
    <div class="col-auto">
        <select name="category" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['id'] ?>" <?= $cat_id == $cat['id'] ? 'selected' : '' ?>>
                <?= h($cat['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <a href="?filter=low_stock" class="btn btn-sm <?= $filter === 'low_stock' ? 'btn-danger' : 'btn-outline-danger' ?>">
            Low Stock Only
        </a>
        <a href="?<?= $show_all ? '' : 'all=1' ?>" class="btn btn-sm <?= $show_all ? 'btn-secondary' : 'btn-outline-secondary' ?>">
            <?= $show_all ? 'Hide Zero Stock' : 'Show All' ?>
        </a>
        <?php if ($filter || $cat_id): ?>
        <a href="?<?= $show_all ? 'all=1' : '' ?>" class="btn btn-sm btn-outline-secondary">Clear Filters</a>
        <?php endif; ?>
    </div>
</form>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead><tr>
                <th>SKU</th>
                <th>Name</th>
                <th>Width</th>
                <th>Length</th>
                <th>On Hand</th>
                <th>Reorder At</th>
                <th>Status</th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($items as $item):
                $is_low = $item['reorder_threshold'] > 0 && $item['quantity_on_hand'] <= $item['reorder_threshold'];
            ?>
            <tr>
                <td class="fw-semibold"><?= h($item['sku']) ?></td>
                <td><?= h($item['name']) ?></td>
                <td><?= width_label($item) ?></td>
                <td><?= (int)$item['roll_length_yards'] ?>yds</td>
                <td class="<?= $is_low ? 'text-danger fw-semibold' : '' ?>"><?= (int)$item['quantity_on_hand'] ?></td>
                <td><?= $item['reorder_threshold'] > 0 ? (int)$item['reorder_threshold'] : '—' ?></td>
                <td>
                    <?php if ($is_low): ?>
                        <span class="badge bg-danger">Low Stock</span>
                    <?php else: ?>
                        <span class="badge bg-success">OK</span>
                    <?php endif; ?>
                </td>
                <td class="text-end">
                    <button class="btn btn-sm btn-outline-primary"
                        onclick="openAdjust(<?= $item['id'] ?>, '<?= h($item['sku']) ?>')"
                        data-bs-toggle="modal" data-bs-target="#adjustModal">
                        Adjust
                    </button>
                    <a href="/inventory/pages/inventory-log.php?item_id=<?= $item['id'] ?>" class="btn btn-sm btn-outline-secondary">Log</a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($items)): ?>
            <tr><td colspan="8" class="text-muted text-center py-3">
                No items <?= $show_all ? 'found' : 'with stock' ?>. <?= !$show_all ? '<a href="?all=1">Show all</a>' : '' ?>
            </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Receive New Width Modal -->
<div class="modal fade" id="receiveNewModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Receive New Width</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Base SKU</label>
                        <select name="receive_base_sku" class="form-select" required>
                            <option value="">Select SKU...</option>
                            <?php foreach ($base_skus as $bsku): ?>
                            <option value="<?= h($bsku) ?>"><?= h($bsku) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Width</label>
                        <select name="receive_width" class="form-select" required>
                            <?php foreach ($standard_widths as $w): ?>
                            <option value="<?= $w ?>" <?= abs($w - 1.0) < 0.001 ? 'selected' : '' ?>><?= format_width($w) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Quantity Received</label>
                        <input type="number" name="receive_qty" class="form-control" min="1" step="1" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes (optional)</label>
                        <input type="text" name="receive_notes" class="form-control" placeholder="e.g. PO #1234">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Receive</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Stock Adjustment Modal (existing items) -->
<div class="modal fade" id="adjustModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Adjust Stock — <span id="adjustSkuLabel"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="adjust_item_id" id="adjustItemId">
                    <div class="mb-3">
                        <label class="form-label">Type</label>
                        <select name="adjust_type" id="adjustType" class="form-select" onchange="updateQtyLabel()">
                            <option value="receive">Receive Stock (add)</option>
                            <option value="manual">Manual Correction (+ or −)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" id="qtyLabel">Quantity to Add</label>
                        <input type="number" name="adjust_qty" class="form-control" step="1" required>
                        <div class="form-text" id="qtyHint">Enter a positive number to add stock.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes (optional)</label>
                        <input type="text" name="adjust_notes" class="form-control" placeholder="e.g. PO #1234, counted inventory">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Convert Modal -->
<div class="modal fade" id="convertModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Convert Roll / Log</h5>
                <button type="button" class="btn btn-outline-secondary btn-sm ms-auto me-2" onclick="addSourceGroup()">+ Add Item</button>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Step 1: inputs -->
                <div id="convertStep1">
                    <div id="convertGroups">
                        <div class="convert-group mb-2">
                            <div class="d-flex gap-2 align-items-center">
                                <select class="form-select form-select-sm cg-item" style="max-width:240px">
                                    <option value="">Source item...</option>
                                    <?php foreach ($items as $it): ?>
                                    <option value="<?= $it['id'] ?>" data-width="<?= (float)$it['width_inches'] ?>" data-sku="<?= h($it['sku']) ?>" data-on-hand="<?= (int)$it['quantity_on_hand'] ?>">
                                        <?= h($it['sku']) ?> (<?= width_label($it) ?>, on hand: <?= (int)$it['quantity_on_hand'] ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="number" class="form-control form-control-sm cg-qty" style="width:70px" min="1" step="1" value="1" title="Qty">
                                <select class="form-select form-select-sm cg-first-width" style="width:90px">
                                    <?php foreach ($standard_widths as $w): ?>
                                    <option value="<?= $w ?>" <?= abs($w - 3.0) < 0.001 ? 'selected' : '' ?>><?= format_width($w) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-danger btn-sm cg-remove" onclick="removeSourceGroup(this)" disabled>✕</button>
                            </div>
                        </div>
                    </div>
                    <div class="mt-2">
                        <button type="button" class="btn btn-primary btn-sm" onclick="calcConvert()">Preview</button>
                    </div>
                    <div id="convertError" class="alert alert-danger d-none mt-2"></div>
                </div>

                <!-- Step 2: line items preview -->
                <div id="convertStep2" class="d-none">
                    <p class="text-muted small mb-2" id="convertSummaryText"></p>
                    <table class="table table-sm table-bordered mb-3">
                        <thead><tr>
                            <th>Source</th>
                            <th>Width</th>
                            <th style="width:110px">Qty</th>
                            <th style="width:44px"></th>
                        </tr></thead>
                        <tbody id="convertLinesBody"></tbody>
                    </table>
                    <div class="d-flex gap-2 align-items-center flex-wrap">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="resetConvert()">← Back</button>
                        <div class="d-flex gap-1 ms-auto align-items-center">
                            <select id="addLineGroup" class="form-select form-select-sm" style="width:auto"></select>
                            <select id="addLineWidth" class="form-select form-select-sm" style="width:auto">
                                <?php foreach ($standard_widths as $w): ?>
                                <option value="<?= $w ?>"><?= format_width($w) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addOutputLine()">+ Add Line</button>
                        </div>
                        <button type="button" class="btn btn-success btn-sm" onclick="saveConvert()">Save</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Hidden convert save form -->
<form id="convertSaveForm" method="post" style="display:none">
    <div id="cLinesContainer"></div>
</form>

<script>
function openAdjust(itemId, sku) {
    document.getElementById('adjustItemId').value  = itemId;
    document.getElementById('adjustSkuLabel').textContent = sku;
}
function updateQtyLabel() {
    const type = document.getElementById('adjustType').value;
    if (type === 'manual') {
        document.getElementById('qtyLabel').textContent = 'Quantity Change';
        document.getElementById('qtyHint').textContent  = 'Use negative number to reduce stock (e.g. -5).';
    } else {
        document.getElementById('qtyLabel').textContent = 'Quantity to Add';
        document.getElementById('qtyHint').textContent  = 'Enter a positive number to add stock.';
    }
}

const convertWidthOptions = <?= json_encode(array_map(fn($w) => ['v' => $w, 'l' => format_width($w)], $standard_widths)) ?>;
const convertItemOptionsHTML = <?= json_encode('<option value="">Source item...</option>' . implode('', array_map(fn($it) =>
    '<option value="' . $it['id'] . '" data-width="' . (float)$it['width_inches'] . '" data-sku="' . h($it['sku']) . '" data-on-hand="' . (int)$it['quantity_on_hand'] . '">' .
    h($it['sku']) . ' (' . width_label($it) . ', on hand: ' . (int)$it['quantity_on_hand'] . ')</option>',
    $items))) ?>;
const convertWidthOptionsHTML = convertWidthOptions.map(o => `<option value="${o.v}">${o.l}</option>`).join('');

function addSourceGroup() {
    const container = document.getElementById('convertGroups');
    const div = document.createElement('div');
    div.className = 'convert-group mb-2';
    div.innerHTML = `<div class="d-flex gap-2 align-items-center">
        <select class="form-select form-select-sm cg-item" style="max-width:240px">${convertItemOptionsHTML}</select>
        <input type="number" class="form-control form-control-sm cg-qty" style="width:70px" min="1" step="1" value="1" title="Qty">
        <select class="form-select form-select-sm cg-first-width" style="width:90px">${convertWidthOptionsHTML}</select>
        <button type="button" class="btn btn-outline-danger btn-sm cg-remove" onclick="removeSourceGroup(this)">✕</button>
    </div>`;
    container.appendChild(div);
    updateGroupRemoveButtons();
}

function removeSourceGroup(btn) {
    btn.closest('.convert-group').remove();
    updateGroupRemoveButtons();
}

function updateGroupRemoveButtons() {
    const btns = document.querySelectorAll('.convert-group .cg-remove');
    btns.forEach(b => b.disabled = btns.length === 1);
}


function calcConvert() {
    const errEl = document.getElementById('convertError');
    errEl.classList.add('d-none');

    const groups = document.querySelectorAll('.convert-group');
    const tbody  = document.getElementById('convertLinesBody');
    tbody.innerHTML = '';
    const summaries = [];
    let hasError = false;

    groups.forEach((group, gi) => {
        const itemSel = group.querySelector('.cg-item');
        const srcQty  = parseInt(group.querySelector('.cg-qty').value) || 0;

        if (!itemSel.value || srcQty < 1) {
            errEl.textContent = 'All rows must have a source item and quantity.';
            errEl.classList.remove('d-none');
            hasError = true; return;
        }

        const opt      = itemSel.options[itemSel.selectedIndex];
        const srcWidth = parseFloat(opt.dataset.width);
        const srcSku   = opt.dataset.sku;
        const onHand   = parseInt(opt.dataset.onHand);
        const itemId   = itemSel.value;

        if (srcQty > onHand) {
            errEl.textContent = `Only ${onHand} on hand for ${srcSku}.`;
            errEl.classList.remove('d-none');
            hasError = true; return;
        }

        const tgtWidths = [parseFloat(group.querySelector('.cg-first-width').value)];

        if (tgtWidths.some(w => w >= srcWidth)) {
            errEl.textContent = `Target widths must be smaller than source (${srcWidth}") for ${srcSku}.`;
            errEl.classList.remove('d-none');
            hasError = true; return;
        }

        let remaining = srcWidth;
        const parts = [];

        tgtWidths.forEach(tgtW => {
            if (remaining < tgtW) return;
            const rollsPer = Math.floor(remaining / tgtW);
            remaining -= rollsPer * tgtW;
            appendOutputLine(srcSku, itemId, gi, tgtW, rollsPer * srcQty);
            parts.push(`${rollsPer}×${tgtW}"`);
        });

        const leftover = Math.floor(remaining / 0.5) * 0.5;
        if (leftover >= 0.5) {
            appendOutputLine(srcSku, itemId, gi, leftover, srcQty);
            parts.push(`${leftover}" leftover`);
        }

        summaries.push(`${srcQty}×${srcSku}: ${parts.join(', ') || 'nothing fits'}`);
    });

    if (hasError) return;

    document.getElementById('convertSummaryText').textContent = summaries.join(' | ');

    // Populate group selector for Add Line
    const groupSel = document.getElementById('addLineGroup');
    groupSel.innerHTML = '';
    groups.forEach((g, i) => {
        const opt = g.querySelector('.cg-item').options[g.querySelector('.cg-item').selectedIndex];
        groupSel.innerHTML += `<option value="${i}">${opt?.dataset?.sku || 'Group '+(i+1)}</option>`;
    });

    document.getElementById('convertStep2').classList.remove('d-none');
}

function appendOutputLine(srcSku, itemId, groupIdx, width, qty) {
    const tbody = document.getElementById('convertLinesBody');
    const tr = document.createElement('tr');
    tr.dataset.width    = width;
    tr.dataset.qty      = qty;
    tr.dataset.groupIdx = groupIdx;
    tr.dataset.itemId   = itemId;
    tr.innerHTML = `
        <td class="text-muted small">${srcSku}</td>
        <td>${width}"</td>
        <td><input type="number" class="form-control form-control-sm" value="${qty}" min="0" step="1" onchange="updateLineQty(this)"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeLine(this)">✕</button></td>`;
    tbody.appendChild(tr);
}

function addOutputLine() {
    const gi      = parseInt(document.getElementById('addLineGroup').value);
    const groups  = document.querySelectorAll('.convert-group');
    const group   = groups[gi];
    const itemSel = group.querySelector('.cg-item');
    const w       = parseFloat(document.getElementById('addLineWidth').value);
    appendOutputLine(itemSel.options[itemSel.selectedIndex].dataset.sku, itemSel.value, gi, w, 0);
}

function updateLineQty(input) {
    input.closest('tr').dataset.qty = input.value;
}

function removeLine(btn) {
    btn.closest('tr').remove();
}

function resetConvert() {
    document.getElementById('convertStep2').classList.add('d-none');
    document.getElementById('convertError').classList.add('d-none');
    document.querySelectorAll('.convert-group:not(:first-child)').forEach(g => g.remove());
    updateGroupRemoveButtons();
}

function saveConvert() {
    const rows    = document.querySelectorAll('#convertLinesBody tr');
    const groupEls = document.querySelectorAll('.convert-group');
    const container = document.getElementById('cLinesContainer');
    container.innerHTML = '';

    // Build per-group buckets
    const groups = {};
    groupEls.forEach((g, gi) => {
        const itemSel = g.querySelector('.cg-item');
        groups[gi] = { itemId: itemSel.value, qty: g.querySelector('.cg-qty').value, lines: [] };
    });

    rows.forEach(tr => {
        const qty = parseInt(tr.dataset.qty) || 0;
        if (qty <= 0) return;
        const gi = parseInt(tr.dataset.groupIdx);
        if (groups[gi]) groups[gi].lines.push({ width: tr.dataset.width, qty });
    });

    let gi = 0, hasLines = false;
    Object.values(groups).forEach(g => {
        if (!g.lines.length) return;
        container.innerHTML += `<input type="hidden" name="convert_groups[${gi}][item_id]" value="${g.itemId}">`;
        container.innerHTML += `<input type="hidden" name="convert_groups[${gi}][qty]" value="${g.qty}">`;
        g.lines.forEach((ln, li) => {
            container.innerHTML += `<input type="hidden" name="convert_groups[${gi}][lines][${li}][width]" value="${ln.width}">`;
            container.innerHTML += `<input type="hidden" name="convert_groups[${gi}][lines][${li}][qty]" value="${ln.qty}">`;
        });
        gi++; hasLines = true;
    });

    if (!hasLines) { alert('No lines with quantity > 0.'); return; }
    document.getElementById('convertSaveForm').submit();
}
</script>

<?php render_footer(); ?>
