<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/quickbooks.php';

require_login();

$db  = db();
$msg = '';

// Edit customer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_customer'])) {
    $id      = (int)$_POST['customer_id'];
    $name    = trim($_POST['name'] ?? '');
    $company = trim($_POST['company'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $addr    = trim($_POST['billing_address'] ?? '');
    $terms   = trim($_POST['terms'] ?? '') ?: 'Net 30';

    if ($name && $id) {
        $db->prepare('UPDATE customers SET name=?, company=?, email=?, phone=?, billing_address=?, terms=? WHERE id=?')
           ->execute([$name, $company ?: null, $email ?: null, $phone ?: null, $addr ?: null, $terms, $id]);
        $msg = 'Customer updated.';
    }
}

// Manual add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_customer'])) {
    $name    = trim($_POST['name'] ?? '');
    $company = trim($_POST['company'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $addr    = trim($_POST['billing_address'] ?? '');
    $terms   = trim($_POST['terms'] ?? '') ?: 'Net 30';

    if ($name) {
        $db->prepare('INSERT INTO customers (name, company, email, phone, billing_address, terms) VALUES (?,?,?,?,?,?)')
           ->execute([$name, $company ?: null, $email ?: null, $phone ?: null, $addr ?: null, $terms]);
        $msg = 'Customer added.';
    }
}

// QB sync stub
if (isset($_GET['qb_sync'])) {
    $synced = qb_sync_customers($db);
    $msg = qb_is_configured()
        ? "QB sync complete. {$synced} customers updated."
        : 'QuickBooks integration not yet configured. Add credentials to config.php.';
    header("Location: /inventory/pages/customers.php?msg=" . urlencode($msg));
    exit;
}

if (isset($_GET['msg'])) $msg = $_GET['msg'];

$search   = trim($_GET['q'] ?? '');
$where    = $search ? 'WHERE name LIKE ? OR company LIKE ? OR email LIKE ?' : '';
$params   = $search ? ["%{$search}%", "%{$search}%", "%{$search}%"] : [];

$stmt = $db->prepare("SELECT * FROM customers {$where} ORDER BY name LIMIT 200");
$stmt->execute($params);
$customers = $stmt->fetchAll();

render_header('Customers', 'customers');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Customers</h4>
    <div>
        <a href="?qb_sync=1" class="btn btn-sm btn-outline-secondary">QB Sync Now</a>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">+ Add Customer</button>
    </div>
</div>

<?php if ($msg): ?>
    <div class="alert alert-info alert-dismissible fade show"><?= h($msg) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<form method="get" class="mb-3 d-flex gap-2">
    <input type="text" name="q" class="form-control form-control-sm" style="max-width:300px" placeholder="Search by name, company, email..." value="<?= h($search) ?>">
    <button class="btn btn-sm btn-outline-secondary">Search</button>
    <?php if ($search): ?><a href="?" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
</form>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead><tr>
                <th>Name</th><th>Company</th><th>Email</th><th>Phone</th><th>Terms</th><th>QB Sync</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($customers as $c): ?>
            <tr>
                <td><?= h($c['name']) ?></td>
                <td><?= h($c['company'] ?? '—') ?></td>
                <td><?= h($c['email'] ?? '—') ?></td>
                <td><?= h($c['phone'] ?? '—') ?></td>
                <td><?= h($c['terms'] ?? 'Net 30') ?></td>
                <td><?= $c['synced_at'] ? date('M j, Y', strtotime($c['synced_at'])) : '<span class="text-muted">Manual</span>' ?></td>
                <td class="text-end"><button class="btn btn-sm btn-outline-primary"
                    onclick="openEdit(<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>)"
                    data-bs-toggle="modal" data-bs-target="#editModal">Edit</button></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($customers)): ?>
            <tr><td colspan="5" class="text-muted text-center py-3">No customers found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Edit Customer Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="customer_id" id="editId">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Customer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2"><label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="editName" class="form-control" required></div>
                    <div class="mb-2"><label class="form-label">Company</label>
                        <input type="text" name="company" id="editCompany" class="form-control"></div>
                    <div class="mb-2"><label class="form-label">Email</label>
                        <input type="email" name="email" id="editEmail" class="form-control"></div>
                    <div class="mb-2"><label class="form-label">Phone</label>
                        <input type="text" name="phone" id="editPhone" class="form-control"></div>
                    <div class="mb-2"><label class="form-label">Billing Address</label>
                        <textarea name="billing_address" id="editAddr" class="form-control" rows="2"></textarea></div>
                    <div class="mb-2"><label class="form-label">Terms</label>
                        <input type="text" name="terms" id="editTerms" class="form-control" value="Net 30"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_customer" value="1" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Customer Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Add Customer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2"><label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required></div>
                    <div class="mb-2"><label class="form-label">Company</label>
                        <input type="text" name="company" class="form-control"></div>
                    <div class="mb-2"><label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control"></div>
                    <div class="mb-2"><label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control"></div>
                    <div class="mb-2"><label class="form-label">Billing Address</label>
                        <textarea name="billing_address" class="form-control" rows="2"></textarea></div>
                    <div class="mb-2"><label class="form-label">Terms</label>
                        <input type="text" name="terms" class="form-control" value="Net 30"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_customer" value="1" class="btn btn-primary">Add Customer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openEdit(c) {
    document.getElementById('editId').value      = c.id;
    document.getElementById('editName').value    = c.name || '';
    document.getElementById('editCompany').value = c.company || '';
    document.getElementById('editEmail').value   = c.email || '';
    document.getElementById('editPhone').value   = c.phone || '';
    document.getElementById('editAddr').value    = c.billing_address || '';
    document.getElementById('editTerms').value   = c.terms || 'Net 30';
}
</script>

<?php render_footer(); ?>
