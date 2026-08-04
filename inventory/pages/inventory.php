<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/layout.php';

require_login();

$db = db();

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

// Filters
$filter   = $_GET['filter'] ?? '';
$cat_id   = (int)($_GET['category'] ?? 0);
$where    = ['i.is_active = 1'];
$params   = [];

if ($filter === 'low_stock') {
    $where[] = 'i.reorder_threshold > 0 AND i.quantity_on_hand <= i.reorder_threshold';
}
if ($cat_id) {
    $where[] = 'i.category_id = ?';
    $params[] = $cat_id;
}

$sql = 'SELECT i.*, c.name AS category_name
        FROM items i
        JOIN categories c ON c.id = i.category_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY c.name, i.sku';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

$categories = $db->query('SELECT * FROM categories ORDER BY name')->fetchAll();

render_header('Inventory', 'inventory');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Inventory</h4>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#adjustModal">
        + Adjust Stock
    </button>
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
        <?php if ($filter || $cat_id): ?>
        <a href="?" class="btn btn-sm btn-outline-secondary">Clear</a>
        <?php endif; ?>
    </div>
</form>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead><tr>
                <th>SKU</th>
                <th>Name</th>
                <th>Category</th>
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
                <td><?= h($item['category_name']) ?></td>
                <td class="<?= $is_low ? 'low-stock' : '' ?>"><?= (int)$item['quantity_on_hand'] ?></td>
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
            <tr><td colspan="7" class="text-muted text-center py-3">No items found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Stock Adjustment Modal -->
<div class="modal fade" id="adjustModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Adjust Stock</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Item</label>
                        <select name="adjust_item_id" id="adjustItemId" class="form-select" required>
                            <option value="">Select item...</option>
                            <?php foreach ($items as $item): ?>
                            <option value="<?= $item['id'] ?>"><?= h($item['sku']) ?> — <?= h($item['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
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
                    <button type="submit" class="btn btn-primary">Save Adjustment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openAdjust(itemId) {
    document.getElementById('adjustItemId').value = itemId;
}
function updateQtyLabel() {
    const type = document.getElementById('adjustType').value;
    if (type === 'manual') {
        document.getElementById('qtyLabel').textContent = 'Quantity Change';
        document.getElementById('qtyHint').textContent = 'Use negative number to reduce stock (e.g. -5).';
    } else {
        document.getElementById('qtyLabel').textContent = 'Quantity to Add';
        document.getElementById('qtyHint').textContent = 'Enter a positive number to add stock.';
    }
}
</script>

<?php render_footer(); ?>
