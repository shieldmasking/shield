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
    if (isset($_POST['add_user'])) {
        $name  = trim($_POST['name']);
        $email = strtolower(trim($_POST['email']));
        $pass  = $_POST['password'];

        if (!$name || !$email || !$pass) {
            $msg = 'All fields required.';
        } elseif (strlen($pass) < 10) {
            $msg = 'Password must be at least 10 characters.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = 'Invalid email.';
        } else {
            try {
                $db->prepare('INSERT INTO users (name, email, password_hash) VALUES (?,?,?)')
                   ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
                $msg = "User {$name} added.";
            } catch (PDOException $e) {
                $msg = 'Email already exists.';
            }
        }
    } elseif (isset($_POST['reset_password'])) {
        $user_id = (int)$_POST['user_id'];
        $pass    = $_POST['new_password'];
        if (strlen($pass) < 10) {
            $msg = 'Password must be at least 10 characters.';
        } else {
            $db->prepare('UPDATE users SET password_hash=?, failed_attempts=0, locked_until=NULL WHERE id=?')
               ->execute([password_hash($pass, PASSWORD_DEFAULT), $user_id]);
            $msg = 'Password reset.';
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
<thead><tr><th>Name</th><th>Email</th><th>Status</th><th>Failed Logins</th><th></th></tr></thead>
<tbody>
<?php foreach ($users as $u):
    $is_locked = $u['locked_until'] && new DateTime() < new DateTime($u['locked_until']);
?>
<tr>
    <td><?= h($u['name']) ?></td>
    <td><?= h($u['email']) ?></td>
    <td><?= $is_locked ? '<span class="badge bg-danger">Locked</span>' : '<span class="badge bg-success">Active</span>' ?></td>
    <td><?= (int)$u['failed_attempts'] ?></td>
    <td class="text-end">
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
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>

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
function openReset(id, name) {
    document.getElementById('resetUserId').value = id;
    document.getElementById('resetUserName').textContent = name;
}
</script>

<?php render_footer(); ?>
