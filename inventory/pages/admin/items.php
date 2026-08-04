<?php
session_start();
require_once __DIR__ . '/../../inc/db.php';
require_once __DIR__ . '/../../inc/auth.php';
require_once __DIR__ . '/../../inc/functions.php';
require_once __DIR__ . '/../../inc/layout.php';

require_login();

$db  = db();
$msg = '';

// Save item edits
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_item'])) {
        $id         = (int)$_POST['id'];
        $name       = trim($_POST['name']);
        $land_cost  = (float)$_POST['land_cost_base'];
        $markup     = (float)$_POST['markup_multiplier'];
        $reorder    = (int)$_POST['reorder_threshold'];
        $is_active  = isset($_POST['is_active']) ? 1 : 0;

        $db->prepare('UPDATE items SET name=?, land_cost_base=?, markup_multiplier=?, reorder_threshold=?, is_active=? WHERE id=?')
           ->execute([$name, $land_cost, $markup, $reorder, $is_active, $id]);
        $msg = 'Item saved.';
    } elseif (isset($_POST['add_item'])) {
        $sku        = strtoupper(trim($_POST['sku']));
        $name       = trim($_POST['name']);
        $cat_id     = (int)$_POST['category_id'];
        $coo        = strtoupper(trim($_POST['coo']));
        $factory    = trim($_POST['factory_product_num']);
        $thick      = (float)$_POST['thickness_mm'];
        $log_w      = $_POST['log_width_inches'] !== '' ? (float)$_POST['log_width_inches'] : null;
        $roll_len   = (float)$_POST['roll_length_yards'];
        $land_cost  = (float)$_POST['land_cost_base'];
        $markup     = (float)$_POST['markup_multiplier'];
        $reorder    = (int)$_POST['reorder_threshold'];
        $fixed      = isset($_POST['is_fixed_width']) ? 1 : 0;
        $fixed_w    = $fixed ? (float)$_POST['fixed_width_inches'] : null;

        $db->prepare('INSERT INTO items (sku,name,category_id,coo,factory_product_num,thickness_mm,log_width_inches,roll_length_yards,land_cost_base,markup_multiplier,reorder_threshold,is_fixed_width,fixed_width_inches) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$sku,$name,$cat_id,$coo,$factory,$thick,$log_w,$roll_len,$land_cost,$markup,$reorder,$fixed,$fixed_w]);
        $msg = "Item {$sku} added.";
    }
}

$items = $db->query('SELECT i.*, c.name AS cat_name FROM items i JOIN categories c ON c.id=i.category_id ORDER BY i.is_active DESC, c.name, i.sku')->fetchAll();
$categories = $db->query('SELECT * FROM categories ORDER BY name')->fetchAll();

render_header('Admin — Items', 'admin');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Products / SKUs</h4>
    <div>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">+ Add SKU</button>
        <a href="/inventory/pages/admin/index.php" class="btn btn-sm btn-outline-secondary">Admin Menu</a>
    </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-success alert-dismissible fade show"><?= h($msg) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="card">
<div class="card-body p-0">
<table class="table table-hover mb-0 align-middle">
<thead><tr>
    <th>SKU</th><th>Name</th><th>Category</th>
    <th>1" Land Cost</th><th>Markup ×</th><th>Suggested 1" Sell</th>
    <th>Reorder At</th><th>Fixed Width</th><th>Active</th><th></th>
</tr></thead>
<tbody>
<?php foreach ($items as $item): ?>
<tr class="<?= !$item['is_active'] ? 'table-secondary text-muted' : '' ?>">
    <td class="fw-semibold"><?= h($item['sku']) ?></td>
    <td><?= h($item['name']) ?></td>
    <td><?= h($item['cat_name']) ?></td>
    <td><?= currency((float)$item['land_cost_base']) ?></td>
    <td><?= number_format((float)$item['markup_multiplier'], 4) ?>×</td>
    <td><?= currency(round($item['land_cost_base'] * $item['markup_multiplier'], 2)) ?></td>
    <td><?= $item['reorder_threshold'] > 0 ? (int)$item['reorder_threshold'] : '—' ?></td>
    <td><?= $item['is_fixed_width'] ? format_width((float)$item['fixed_width_inches']) : '—' ?></td>
    <td><?= $item['is_active'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
    <td>
        <button class="btn btn-sm btn-outline-primary"
            onclick="openEdit(<?= htmlspecialchars(json_encode($item), ENT_QUOTES) ?>)"
            data-bs-toggle="modal" data-bs-target="#editModal">Edit</button>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<form method="post">
<input type="hidden" name="id" id="editId">
<div class="modal-header"><h5 class="modal-title">Edit Item</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Name</label>
            <input type="text" name="name" id="editName" class="form-control" required>
        </div>
        <div class="col-md-3">
            <label class="form-label">1" Land Cost ($)</label>
            <input type="number" name="land_cost_base" id="editLandCost" class="form-control" step="0.01" min="0" required>
        </div>
        <div class="col-md-3">
            <label class="form-label">Markup Multiplier</label>
            <input type="number" name="markup_multiplier" id="editMarkup" class="form-control" step="0.0001" min="1" required>
        </div>
        <div class="col-md-3">
            <label class="form-label">Reorder Threshold (rolls)</label>
            <input type="number" name="reorder_threshold" id="editReorder" class="form-control" min="0" value="0">
        </div>
        <div class="col-md-3 d-flex align-items-end">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_active" id="editActive" value="1">
                <label class="form-check-label" for="editActive">Active</label>
            </div>
        </div>
    </div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" name="save_item" value="1" class="btn btn-primary">Save</button>
</div>
</form>
</div></div></div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<form method="post">
<div class="modal-header"><h5 class="modal-title">Add SKU</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
    <div class="row g-3">
        <div class="col-md-2"><label class="form-label">SKU</label>
            <input type="text" name="sku" class="form-control" required></div>
        <div class="col-md-5"><label class="form-label">Name</label>
            <input type="text" name="name" class="form-control" required></div>
        <div class="col-md-5"><label class="form-label">Category</label>
            <select name="category_id" class="form-select" required>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>"><?= h($cat['name']) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="col-md-2"><label class="form-label">COO</label>
            <input type="text" name="coo" class="form-control" maxlength="2" placeholder="CN"></div>
        <div class="col-md-4"><label class="form-label">Factory Product #</label>
            <input type="text" name="factory_product_num" class="form-control"></div>
        <div class="col-md-2"><label class="form-label">Thickness (mm)</label>
            <input type="number" name="thickness_mm" class="form-control" step="0.01"></div>
        <div class="col-md-2"><label class="form-label">Log Width (in)</label>
            <input type="number" name="log_width_inches" class="form-control" step="0.01"></div>
        <div class="col-md-2"><label class="form-label">Roll Length (yds)</label>
            <input type="number" name="roll_length_yards" class="form-control" step="0.01" required></div>
        <div class="col-md-3"><label class="form-label">1" Land Cost ($)</label>
            <input type="number" name="land_cost_base" class="form-control" step="0.01" min="0" required></div>
        <div class="col-md-3"><label class="form-label">Markup Multiplier</label>
            <input type="number" name="markup_multiplier" class="form-control" step="0.0001" value="2.1900" required></div>
        <div class="col-md-3"><label class="form-label">Reorder Threshold</label>
            <input type="number" name="reorder_threshold" class="form-control" min="0" value="0"></div>
        <div class="col-md-3 d-flex align-items-end gap-2">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_fixed_width" id="addFixed" onchange="toggleFixedWidth(this)">
                <label class="form-check-label" for="addFixed">Fixed Width</label>
            </div>
        </div>
        <div class="col-md-3" id="fixedWidthField" style="display:none">
            <label class="form-label">Fixed Width (in)</label>
            <input type="number" name="fixed_width_inches" class="form-control" step="0.01">
        </div>
    </div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" name="add_item" value="1" class="btn btn-primary">Add SKU</button>
</div>
</form>
</div></div></div>

<script>
function openEdit(item) {
    document.getElementById('editId').value       = item.id;
    document.getElementById('editName').value     = item.name;
    document.getElementById('editLandCost').value = item.land_cost_base;
    document.getElementById('editMarkup').value   = item.markup_multiplier;
    document.getElementById('editReorder').value  = item.reorder_threshold;
    document.getElementById('editActive').checked = item.is_active == 1;
}
function toggleFixedWidth(cb) {
    document.getElementById('fixedWidthField').style.display = cb.checked ? '' : 'none';
}
</script>

<?php render_footer(); ?>
