<?php
session_start();
require_once __DIR__ . '/../../inc/db.php';
require_once __DIR__ . '/../../inc/auth.php';
require_once __DIR__ . '/../../inc/functions.php';
require_once __DIR__ . '/../../inc/layout.php';

require_login();

$db  = db();
$msg = '';

// ── POST handlers ─────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['save_base'])) {
        $base_sku  = strtoupper(trim($_POST['base_sku']));
        $name      = trim($_POST['name']);
        $desc      = trim($_POST['description']);
        $land_cost = (float)$_POST['land_cost_base'];
        $markup    = (float)$_POST['markup_multiplier'];

        // Handle datasheet upload
        $datasheet_path = null;
        if (!empty($_FILES['datasheet']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['datasheet']['name'], PATHINFO_EXTENSION));
            if ($ext === 'pdf' && $_FILES['datasheet']['size'] <= 10 * 1024 * 1024) {
                $dir = __DIR__ . '/../../uploads/datasheets/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $dest = $dir . $base_sku . '.pdf';
                if (move_uploaded_file($_FILES['datasheet']['tmp_name'], $dest)) {
                    $datasheet_path = 'uploads/datasheets/' . $base_sku . '.pdf';
                }
            }
        }

        if ($datasheet_path !== null) {
            $db->prepare('UPDATE products SET name=?, description=?, land_cost_base=?, markup_multiplier=?, datasheet_path=? WHERE base_sku=?')
               ->execute([$name, $desc ?: null, $land_cost, $markup, $datasheet_path, $base_sku]);
        } else {
            $db->prepare('UPDATE products SET name=?, description=?, land_cost_base=?, markup_multiplier=? WHERE base_sku=?')
               ->execute([$name, $desc ?: null, $land_cost, $markup, $base_sku]);
        }
        $msg = "Pricing updated for {$base_sku}.";

    } elseif (isset($_POST['save_row'])) {
        $id        = (int)$_POST['id'];
        $reorder   = (int)$_POST['reorder_threshold'];
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        $db->prepare('UPDATE items SET reorder_threshold=?, is_active=? WHERE id=?')
           ->execute([$reorder, $is_active, $id]);
        $msg = 'Item updated.';

    } elseif (isset($_POST['add_base'])) {
        $base_sku   = strtoupper(trim($_POST['base_sku']));
        $name       = trim($_POST['name']);
        $desc       = trim($_POST['description']);
        $cat_id     = (int)$_POST['category_id'];
        $coo        = strtoupper(trim($_POST['coo']));
        $factory    = trim($_POST['factory_product_num']);
        $thick      = (float)$_POST['thickness_mm'];
        $roll_len   = (float)$_POST['roll_length_yards'];
        $land_cost  = (float)$_POST['land_cost_base'];
        $markup     = (float)$_POST['markup_multiplier'];
        $is_fixed   = isset($_POST['is_fixed_width']) ? 1 : 0;
        $fixed_w    = $is_fixed ? (float)$_POST['fixed_width_inches'] : 1.0;
        $sku        = make_item_sku($base_sku, $fixed_w, false);

        $db->prepare('INSERT INTO products
            (base_sku, name, description, category_id, coo, factory_product_num, thickness_mm,
             roll_length_yards, is_log, is_fixed_width, land_cost_base, markup_multiplier)
            VALUES (?,?,?,?,?,?,?,?,0,?,?,?)')
           ->execute([$base_sku, $name, $desc ?: null, $cat_id, $coo, $factory, $thick,
                      $roll_len, $is_fixed, $land_cost, $markup]);

        $db->prepare('INSERT INTO items (base_sku, sku, width_inches, is_active) VALUES (?,?,?,1)')
           ->execute([$base_sku, $sku, $fixed_w]);

        $msg = "Base SKU {$base_sku} added.";
    }
}

// ── Load data ─────────────────────────────────────────────────────────────────

$products   = $db->query('SELECT p.*, c.name AS cat_name FROM products p JOIN categories c ON c.id=p.category_id ORDER BY p.base_sku')->fetchAll();
$categories = $db->query('SELECT * FROM categories ORDER BY name')->fetchAll();
$wm         = get_width_multipliers($db);

// Load all items and group by base_sku
$items_raw = $db->query('SELECT * FROM items ORDER BY base_sku, width_inches')->fetchAll();
$item_rows = [];
foreach ($items_raw as $item) {
    $item_rows[$item['base_sku']][] = $item;
}

render_header('Admin — Products', 'admin');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Products / SKUs</h4>
    <div>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addBaseModal">+ Add Product</button>
        <a href="/inventory/pages/admin/index.php" class="btn btn-sm btn-outline-secondary">Admin Menu</a>
    </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-success alert-dismissible fade show"><?= h($msg) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<?php foreach ($products as $prod):
    $rows = $item_rows[$prod['base_sku']] ?? [];
    $sell1 = currency(round($prod['land_cost_base'] * $prod['markup_multiplier'], 2));
?>
<div class="card mb-3">
<div class="card-header d-flex justify-content-between align-items-center">
    <div>
        <strong><?= h($prod['base_sku']) ?></strong>
        <span class="text-muted ms-2"><?= h($prod['name']) ?></span>
        <span class="badge bg-secondary ms-2"><?= h($prod['cat_name']) ?></span>
        <?php if ($prod['datasheet_path']): ?>
        <a href="/inventory/<?= h($prod['datasheet_path']) ?>" target="_blank" class="ms-2 small">Datasheet</a>
        <?php endif; ?>
    </div>
    <div class="d-flex align-items-center gap-3">
        <small class="text-muted">1" base: <?= currency((float)$prod['land_cost_base']) ?> &times; <?= number_format((float)$prod['markup_multiplier'], 4) ?> = <?= $sell1 ?>/roll</small>
        <button class="btn btn-sm btn-outline-primary"
            onclick="openBaseEdit(<?= htmlspecialchars(json_encode([
                'base_sku'          => $prod['base_sku'],
                'name'              => $prod['name'],
                'description'       => $prod['description'] ?? '',
                'land_cost_base'    => $prod['land_cost_base'],
                'markup_multiplier' => $prod['markup_multiplier'],
            ]), ENT_QUOTES) ?>)"
            data-bs-toggle="modal" data-bs-target="#editBaseModal">
            Edit Pricing
        </button>
    </div>
</div>
<div class="card-body p-0">
<table class="table table-sm mb-0 align-middle">
<thead class="table-light"><tr>
    <th>SKU</th><th>Width</th><th>Length</th><th>Sell Price</th><th>On Hand</th><th>Reorder At</th><th>Active</th><th></th>
</tr></thead>
<tbody>
<?php foreach ($rows as $item):
    // Merge product fields for pricing calculation
    $merged = array_merge($prod, $item);
    $sell = $prod['is_log']
        ? currency(round($prod['land_cost_base'] * $item['width_inches'] * $prod['markup_multiplier'], 2))
        : ($prod['is_fixed_width']
            ? currency(round($prod['land_cost_base'] * $prod['markup_multiplier'], 2))
            : currency(calculate_sell_price($merged, $wm)));
?>
<tr class="<?= !$item['is_active'] ? 'table-secondary text-muted' : '' ?>">
    <td class="fw-semibold"><?= h($item['sku']) ?></td>
    <td><?= width_label($merged) ?></td>
    <td><?= (int)$prod['roll_length_yards'] ?>yds</td>
    <td><?= $sell ?></td>
    <td><?= (int)$item['quantity_on_hand'] ?></td>
    <td><?= $item['reorder_threshold'] > 0 ? (int)$item['reorder_threshold'] : '—' ?></td>
    <td><?= $item['is_active'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
    <td>
        <button class="btn btn-sm btn-outline-secondary"
            onclick="openRowEdit(<?= htmlspecialchars(json_encode([
                'id'                => $item['id'],
                'sku'               => $item['sku'],
                'reorder_threshold' => $item['reorder_threshold'],
                'is_active'         => $item['is_active'],
            ]), ENT_QUOTES) ?>)"
            data-bs-toggle="modal" data-bs-target="#editRowModal">Edit</button>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($rows)): ?>
<tr><td colspan="8" class="text-muted text-center py-2">No width rows yet.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
<?php endforeach; ?>

<!-- Edit Base Pricing Modal -->
<div class="modal fade" id="editBaseModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="base_sku" id="editBaseSku">
<div class="modal-header"><h5 class="modal-title">Edit Pricing — <span id="editBaseSkuLabel"></span></h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
    <div class="alert alert-info py-2">Changes to land cost and markup apply to <strong>all widths</strong> of this SKU.</div>
    <div class="row g-3">
        <div class="col-12">
            <label class="form-label">Product Name</label>
            <input type="text" name="name" id="editBaseName" class="form-control" required>
        </div>
        <div class="col-12">
            <label class="form-label">Description</label>
            <textarea name="description" id="editBaseDescription" class="form-control" rows="2" placeholder="Optional product description shown on quotes"></textarea>
        </div>
        <div class="col-md-6">
            <label class="form-label">1" Land Cost ($)</label>
            <input type="number" name="land_cost_base" id="editBaseLandCost" class="form-control" step="0.0001" min="0" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Markup Multiplier</label>
            <input type="number" name="markup_multiplier" id="editBaseMarkup" class="form-control" step="0.0001" min="1" required>
        </div>
        <div class="col-12">
            <label class="form-label">Datasheet PDF</label>
            <input type="file" name="datasheet" class="form-control" accept=".pdf">
            <div class="form-text">Upload a new PDF to replace the existing datasheet. Max 10MB.</div>
        </div>
    </div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" name="save_base" value="1" class="btn btn-primary">Save</button>
</div>
</form>
</div></div></div>

<!-- Edit Row Modal (reorder threshold + active) -->
<div class="modal fade" id="editRowModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
<form method="post">
<input type="hidden" name="id" id="editRowId">
<div class="modal-header"><h5 class="modal-title">Edit — <span id="editRowSku"></span></h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Reorder Threshold (rolls)</label>
            <input type="number" name="reorder_threshold" id="editRowReorder" class="form-control" min="0" value="0">
        </div>
        <div class="col-md-6 d-flex align-items-end">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_active" id="editRowActive" value="1">
                <label class="form-check-label" for="editRowActive">Active</label>
            </div>
        </div>
    </div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" name="save_row" value="1" class="btn btn-primary">Save</button>
</div>
</form>
</div></div></div>

<!-- Add New Base SKU Modal -->
<div class="modal fade" id="addBaseModal" tabindex="-1">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<form method="post">
<div class="modal-header"><h5 class="modal-title">Add Product</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
    <div class="row g-3">
        <div class="col-md-2"><label class="form-label">Base SKU</label>
            <input type="text" name="base_sku" class="form-control" placeholder="730D" required></div>
        <div class="col-md-5"><label class="form-label">Name</label>
            <input type="text" name="name" class="form-control" required></div>
        <div class="col-md-5"><label class="form-label">Category</label>
            <select name="category_id" class="form-select" required>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>"><?= h($cat['name']) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="col-12"><label class="form-label">Description (optional)</label>
            <textarea name="description" class="form-control" rows="2"></textarea></div>
        <div class="col-md-2"><label class="form-label">COO</label>
            <input type="text" name="coo" class="form-control" maxlength="2" placeholder="TW" required></div>
        <div class="col-md-4"><label class="form-label">Factory Product #</label>
            <input type="text" name="factory_product_num" class="form-control"></div>
        <div class="col-md-2"><label class="form-label">Thickness (mm)</label>
            <input type="number" name="thickness_mm" class="form-control" step="0.001"></div>
        <div class="col-md-2"><label class="form-label">Roll Length (yds)</label>
            <input type="number" name="roll_length_yards" class="form-control" step="0.01" required></div>
        <div class="col-md-3"><label class="form-label">1" Land Cost ($)</label>
            <input type="number" name="land_cost_base" class="form-control" step="0.0001" min="0" required></div>
        <div class="col-md-3"><label class="form-label">Markup Multiplier</label>
            <input type="number" name="markup_multiplier" class="form-control" step="0.0001" value="2.1900" required></div>
        <div class="col-md-4 d-flex align-items-end gap-2">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_fixed_width" id="addFixed" onchange="toggleFixedWidth(this)">
                <label class="form-check-label" for="addFixed">Fixed Width (e.g. 1000X)</label>
            </div>
        </div>
        <div class="col-md-3" id="fixedWidthField" style="display:none">
            <label class="form-label">Fixed Width (in)</label>
            <input type="number" name="fixed_width_inches" class="form-control" step="0.01" value="2">
        </div>
    </div>
    <div class="form-text mt-2">A template row at 1" (or fixed width) will be created. Receive stock to add additional widths.</div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" name="add_base" value="1" class="btn btn-primary">Add Product</button>
</div>
</form>
</div></div></div>

<script>
function openBaseEdit(data) {
    document.getElementById('editBaseSku').value            = data.base_sku;
    document.getElementById('editBaseSkuLabel').textContent = data.base_sku;
    document.getElementById('editBaseName').value           = data.name;
    document.getElementById('editBaseDescription').value    = data.description || '';
    document.getElementById('editBaseLandCost').value       = data.land_cost_base;
    document.getElementById('editBaseMarkup').value         = data.markup_multiplier;
}
function openRowEdit(data) {
    document.getElementById('editRowId').value        = data.id;
    document.getElementById('editRowSku').textContent = data.sku;
    document.getElementById('editRowReorder').value   = data.reorder_threshold;
    document.getElementById('editRowActive').checked  = data.is_active == 1;
}
function toggleFixedWidth(cb) {
    document.getElementById('fixedWidthField').style.display = cb.checked ? '' : 'none';
}
</script>

<?php render_footer(); ?>
