<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/layout.php';

require_login();

$db    = db();
$error = '';
$done  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass  = $_POST['password']  ?? '';
    $pass2 = $_POST['password2'] ?? '';

    if (strlen($pass) < 10) {
        $error = 'Password must be at least 10 characters.';
    } elseif ($pass !== $pass2) {
        $error = 'Passwords do not match.';
    } else {
        $db->prepare('UPDATE users SET password_hash=?, force_password_change=0 WHERE id=?')
           ->execute([password_hash($pass, PASSWORD_DEFAULT), current_user_id()]);
        $_SESSION['force_password_change'] = false;
        header('Location: /inventory/pages/dashboard.php');
        exit;
    }
}

render_header('Set New Password', '');
?>

<div class="row justify-content-center mt-5">
    <div class="col-md-4">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h5 class="mb-1">Set a New Password</h5>
                <p class="text-muted small mb-3">Your account requires a new password before continuing.</p>
                <?php if ($error): ?>
                    <div class="alert alert-danger py-2"><?= h($error) ?></div>
                <?php endif; ?>
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">New Password <span class="text-muted small">(min 10 chars)</span></label>
                        <input type="password" name="password" class="form-control" required minlength="10" autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm Password</label>
                        <input type="password" name="password2" class="form-control" required minlength="10">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Set Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php render_footer(); ?>
