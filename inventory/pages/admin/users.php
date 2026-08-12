<?php
session_start();
require_once __DIR__ . '/../../inc/db.php';
require_once __DIR__ . '/../../inc/auth.php';
require_once __DIR__ . '/../../inc/functions.php';
require_once __DIR__ . '/../../inc/layout.php';

require_admin();

$db  = db();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_user'])) {
        $name  = trim($_POST['name']);
        $email = strtolower(trim($_POST['email']));
        $phone = trim($_POST['phone'] ?? '');
        $pass  = $_POST['password'];

        $role  = isset($_POST['role']) && $_POST['role'] === 'admin' ? 1 : 0;
        if (!$name || !$email || !$pass) {
            $msg = 'All fields required.';
        } elseif (strlen($pass) < 10) {
            $msg = 'Password must be at least 10 characters.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = 'Invalid email.';
        } else {
            try {
                $db->prepare('INSERT INTO users (name, email, phone, password_hash, force_password_change, is_admin) VALUES (?,?,?,?,1,?)')
                   ->execute([$name, $email, $phone ?: null, password_hash($pass, PASSWORD_DEFAULT), $role]);
                $msg = "User {$name} added.";
            } catch (PDOException $e) {
                $msg = 'Email already exists.';
            }
        }
    } elseif (isset($_POST['edit_user'])) {
        $user_id = (int)$_POST['user_id'];
        $name    = trim($_POST['name']);
        $email   = strtolower(trim($_POST['email']));
        $phone   = trim($_POST['phone'] ?? '');
        $role    = isset($_POST['role']) && $_POST['role'] === 'admin' ? 1 : 0;
        if (!$name || !$email) {
            $msg = 'Name and email required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = 'Invalid email.';
        } else {
            try {
                $db->prepare('UPDATE users SET name=?, email=?, phone=?, is_admin=? WHERE id=?')
                   ->execute([$name, $email, $phone ?: null, $role, $user_id]);
                $msg = 'User updated.';
            } catch (PDOException $e) {
                $msg = 'Email already in use.';
            }
        }
    } elseif (isset($_POST['reset_password'])) {
        $user_id = (int)$_POST['user_id'];
        $pass    = $_POST['new_password'];
        if (strlen($pass) < 10) {
            $msg = 'Password must be at least 10 characters.';
        } else {
            $db->prepare('UPDATE users SET password_hash=?, failed_attempts=0, locked_until=NULL, force_password_change=1 WHERE id=?')
               ->execute([password_hash($pass, PASSWORD_DEFAULT), $user_id]);
            $msg = 'Password reset.';
        }
    } elseif (isset($_POST['delete_user'])) {
        $user_id = (int)$_POST['user_id'];
        if ($user_id === (int)($_SESSION['user_id'] ?? 0)) {
            $msg = 'Cannot delete your own account.';
        } else {
            $db->prepare('DELETE FROM users WHERE id=?')->execute([$user_id]);
            $msg = 'User deleted.';
        }
    } elseif (isset($_POST['toggle_lock'])) {
        $user_id = (int)$_POST['user_id'];
        $lock    = (int)$_POST['lock'];
        $locked  = $lock ? date('Y-m-d H:i:s', strtotime('+100 years')) : null;
        $db->prepare('UPDATE users SET locked_until=?, failed_attempts=0 WHERE id=?')
           ->execute([$locked, $user_id]);
        $msg = $lock ? 'User locked.' : 'User unlocked.';
    }
}

$users = $db->query('SELECT * FROM users ORDER BY name')->fetchAll();

render_header('Admin — Users', 'admin');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Users</h4>
    <div>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">+ Add User</button>
        <a href="/inventory/pages/admin/index.php" class="btn btn-sm btn-outline-secondary">Admin Menu</a>
    </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= str_contains($msg, 'required') || str_contains($msg, 'Invalid') || str_contains($msg, 'exists') || str_contains($msg, 'least') ? 'danger' : 'success' ?> alert-dismissible fade show">
    <?= h($msg) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card">
<div class="card-body p-0">
<table class="table table-hover mb-0 align-middle">
<thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Status</th><th>Failed Logins</th><th></th></tr></thead>
<tbody>
<?php foreach ($users as $u):
    $is_locked = $u['locked_until'] && new DateTime() < new DateTime($u['locked_until']);
?>
<tr>
    <td><?= h($u['name']) ?></td>
    <td><?= h($u['email']) ?></td>
    <td><?= h($u['phone'] ?? '—') ?></td>
    <td><?= $u['is_admin'] ? '<span class="badge bg-primary">Admin</span>' : '<span class="badge bg-secondary">Sales</span>' ?></td>
    <td><?= $is_locked ? '<span class="badge bg-danger">Locked</span>' : '<span class="badge bg-success">Active</span>' ?></td>
    <td><?= (int)$u['failed_attempts'] ?></td>
    <td class="text-end">
        <button class="btn btn-sm btn-outline-primary"
            onclick="openEdit(<?= htmlspecialchars(json_encode($u), ENT_QUOTES) ?>)"
            data-bs-toggle="modal" data-bs-target="#editModal">Edit</button>
        <button class="btn btn-sm btn-outline-secondary"
            onclick="openReset(<?= $u['id'] ?>, '<?= h($u['name']) ?>')"
            data-bs-toggle="modal" data-bs-target="#resetModal">Reset PW</button>
        <form method="post" class="d-inline">
            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
            <input type="hidden" name="lock" value="<?= $is_locked ? 0 : 1 ?>">
            <button type="submit" name="toggle_lock" value="1"
                class="btn btn-sm <?= $is_locked ? 'btn-outline-success' : 'btn-outline-danger' ?>">
                <?= $is_locked ? 'Unlock' : 'Lock' ?>
            </button>
        </form>
        <?php if ($u['id'] !== (int)($_SESSION['user_id'] ?? 0)): ?>
        <form method="post" class="d-inline" onsubmit="return confirm('Delete <?= h($u['name']) ?>? This cannot be undone.')">
            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
            <button type="submit" name="delete_user" value="1" class="btn btn-sm btn-outline-danger">Delete</button>
        </form>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
<form method="post">
<input type="hidden" name="user_id" id="editUserId">
<div class="modal-header"><h5 class="modal-title">Edit User</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
    <div class="mb-2"><label class="form-label">Name</label>
        <input type="text" name="name" id="editName" class="form-control" required></div>
    <div class="mb-2"><label class="form-label">Email</label>
        <input type="email" name="email" id="editEmail" class="form-control" required></div>
    <div class="mb-2"><label class="form-label">Phone</label>
        <input type="text" name="phone" id="editPhone" class="form-control"></div>
    <div class="mb-2"><label class="form-label">Role</label>
        <select name="role" id="editRole" class="form-select">
            <option value="sales">Sales</option>
            <option value="admin">Admin</option>
        </select></div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" name="edit_user" value="1" class="btn btn-primary">Save</button>
</div>
</form>
</div></div></div>

<!-- Add User Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
<form method="post">
<div class="modal-header"><h5 class="modal-title">Add User</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
    <div class="mb-2"><label class="form-label">Name</label>
        <input type="text" name="name" class="form-control" required></div>
    <div class="mb-2"><label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" required></div>
    <div class="mb-2"><label class="form-label">Phone</label>
        <input type="text" name="phone" class="form-control" placeholder="e.g. 555-555-5555"></div>
    <div class="mb-2"><label class="form-label">Role</label>
        <select name="role" class="form-select">
            <option value="sales">Sales</option>
            <option value="admin">Admin</option>
        </select></div>
    <div class="mb-2"><label class="form-label">Password (min 10 chars)</label>
        <input type="password" name="password" class="form-control" required minlength="10"></div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" name="add_user" value="1" class="btn btn-primary">Add User</button>
</div>
</form>
</div></div></div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
<form method="post">
<input type="hidden" name="user_id" id="resetUserId">
<div class="modal-header"><h5 class="modal-title">Reset Password — <span id="resetUserName"></span></h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
    <div class="mb-2"><label class="form-label">New Password (min 10 chars)</label>
        <input type="password" name="new_password" class="form-control" required minlength="10"></div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" name="reset_password" value="1" class="btn btn-primary">Reset</button>
</div>
</form>
</div></div></div>

<script>
function openEdit(u) {
    document.getElementById('editUserId').value = u.id;
    document.getElementById('editName').value   = u.name;
    document.getElementById('editEmail').value  = u.email;
    document.getElementById('editPhone').value  = u.phone || '';
    document.getElementById('editRole').value   = u.is_admin == 1 ? 'admin' : 'sales';
}
function openReset(id, name) {
    document.getElementById('resetUserId').value = id;
    document.getElementById('resetUserName').textContent = name;
}
</script>

<?php render_footer(); ?>
