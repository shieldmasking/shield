<?php
session_start();
require_once __DIR__ . '/../../inc/db.php';
require_once __DIR__ . '/../../inc/auth.php';
require_once __DIR__ . '/../../inc/functions.php';
require_once __DIR__ . '/../../inc/layout.php';

require_login();

$db  = db();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_all'])) {
        foreach ($_POST['multiplier'] as $id => $val) {
            $label = trim($_POST['label'][$id] ?? '');
            $db->prepare('UPDATE width_multipliers SET multiplier=?, label=? WHERE id=?')
               ->execute([(float)$val, $label ?: null, (int)$id]);
        }
        $msg = 'Width multipliers saved.';
    } elseif (isset($_POST['add_width'])) {
        $width = (float)$_POST['width_inches'];
        $mult  = (float)$_POST['multiplier'];
        $label = trim($_POST['label'] ?? '');
        $db->prepare('INSERT INTO width_multipliers (width_inches, multiplier, label) VALUES (?,?,?)')
           ->execute([$width, $mult, $label ?: null]);
        $msg = "Width {$width}\" added.";
    } elseif (isset($_POST['delete_id'])) {
        $db->prepare('DELETE FROM width_multipliers WHERE id=?')->execute([(int)$_POST['delete_id']]);
        $msg = 'Width removed.';
    }
}

$rows = $db->query('SELECT * FROM width_multipliers ORDER BY width_inches')->fetchAll();

render_header('Admin — Width Multipliers', 'admin');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0">Width Multipliers</h4>
        <small class="text-muted">Sell price = 1" base price × width × multiplier. Values above 1.00 add a premium; below 1.00 apply a discount.</small>
    </div>
    <a href="/inventory/pages/admin/index.php" class="btn btn-sm btn-outline-secondary">Admin Menu</a>
</div>

<?php if ($msg): ?>
<div class="alert alert-success alert-dismissible fade show"><?= h($msg) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<form method="post">
<div class="card mb-3">
<div class="card-body p-0">
<table class="table mb-0 align-middle">
<thead><tr>
    <th>Width</th><th>Label</th><th style="width:180px">Multiplier</th>
    <th>Example: 520N 1" base $21.85</th><th></th>
</tr></thead>
<tbody>
<?php foreach ($rows as $row):
    $example = round(21.85 * (float)$row['width_inches'] * (float)$row['multiplier'], 2);
?>
<tr>
    <td class="fw-semibold"><?= number_format((float)$row['width_inches'], 3) ?>"</td>
    <td><input type="text" name="label[<?= $row['id'] ?>]" value="<?= h($row['label'] ?? '') ?>" class="form-control form-control-sm" style="width:80px"></td>
    <td><input type="number" name="multiplier[<?= $row['id'] ?>]" value="<?= number_format((float)$row['multiplier'], 4) ?>" step="0.0001" min="0.1" max="5" class="form-control form-control-sm"></td>
    <td class="text-muted">$<?= number_format($example, 2) ?> per roll</td>
    <td>
        <button type="submit" name="delete_id" value="<?= $row['id'] ?>"
                class="btn btn-sm btn-outline-danger"
                onclick="return confirm('Remove this width?')">×</button>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<button type="submit" name="save_all" value="1" class="btn btn-primary">Save Changes</button>
</form>

<hr>
<h5>Add Width</h5>
<form method="post" class="row g-2 align-items-end">
    <div class="col-auto"><label class="form-label">Width (inches)</label>
        <input type="number" name="width_inches" class="form-control" step="0.001" min="0.1" max="12" placeholder="e.g. 1.5" required style="width:130px"></div>
    <div class="col-auto"><label class="form-label">Label</label>
        <input type="text" name="label" class="form-control" placeholder='e.g. 1-1/2"' style="width:100px"></div>
    <div class="col-auto"><label class="form-label">Multiplier</label>
        <input type="number" name="multiplier" class="form-control" step="0.0001" min="0.1" value="1.0000" required style="width:120px"></div>
    <div class="col-auto"><button type="submit" name="add_width" value="1" class="btn btn-outline-primary">Add</button></div>
</form>

<?php render_footer(); ?>
