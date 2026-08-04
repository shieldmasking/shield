<?php
session_start();
require_once __DIR__ . '/../../inc/db.php';
require_once __DIR__ . '/../../inc/auth.php';
require_once __DIR__ . '/../../inc/layout.php';

require_login();

$db  = db();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['company_name', 'company_address', 'company_phone', 'company_email', 'low_stock_email'] as $key) {
        $val = trim($_POST[$key] ?? '');
        $db->prepare('INSERT INTO settings (`key`, value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=?')
           ->execute([$key, $val, $val]);
    }
    $msg = 'Settings saved.';
}

$settings = [];
foreach ($db->query('SELECT `key`, value FROM settings') as $row) {
    $settings[$row['key']] = $row['value'];
}

render_header('Admin — Settings', 'admin');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Settings</h4>
    <a href="/inventory/pages/admin/index.php" class="btn btn-sm btn-outline-secondary">Admin Menu</a>
</div>

<?php if ($msg): ?>
<div class="alert alert-success alert-dismissible fade show"><?= h($msg) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="card" style="max-width:600px">
<div class="card-body">
<form method="post">
    <div class="mb-3">
        <label class="form-label fw-semibold">Company Name</label>
        <input type="text" name="company_name" class="form-control" value="<?= h($settings['company_name'] ?? '') ?>">
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold">Company Address</label>
        <textarea name="company_address" class="form-control" rows="3" placeholder="Street, City, State ZIP"><?= h($settings['company_address'] ?? '') ?></textarea>
        <div class="form-text">Appears on quote PDFs.</div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Company Phone</label>
            <input type="text" name="company_phone" class="form-control" value="<?= h($settings['company_phone'] ?? '') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">Company Email</label>
            <input type="email" name="company_email" class="form-control" value="<?= h($settings['company_email'] ?? '') ?>">
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold">Low-Stock Alert Email</label>
        <input type="email" name="low_stock_email" class="form-control" value="<?= h($settings['low_stock_email'] ?? '') ?>">
        <div class="form-text">Low-stock alerts will be sent to this address.</div>
    </div>
    <hr>
    <div class="mb-3">
        <label class="form-label fw-semibold">QuickBooks Online</label>
        <div class="alert alert-warning py-2">
            QB credentials are stored in <code>config.php</code> on the server (not in the database).
            Update <code>$qb_client_id</code>, <code>$qb_client_secret</code>, <code>$qb_realm_id</code>, and tokens there.
        </div>
    </div>
    <button type="submit" class="btn btn-primary">Save Settings</button>
</form>
</div>
</div>

<?php render_footer(); ?>
