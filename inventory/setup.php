<?php
// ONE-TIME SETUP SCRIPT — creates the first admin user
// DELETE THIS FILE immediately after running it

require_once __DIR__ . '/inc/db.php';

$db  = db();
$cnt = $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($cnt > 0) {
    die('Setup already complete. Delete this file.');
}

$error = '';
$done  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pass  = $_POST['password'] ?? '';
    $pass2 = $_POST['password2'] ?? '';

    if (!$name || !$email || !$pass) {
        $error = 'All fields required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email.';
    } elseif (strlen($pass) < 10) {
        $error = 'Password must be at least 10 characters.';
    } elseif ($pass !== $pass2) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $db->prepare('INSERT INTO users (name, email, password_hash) VALUES (?,?,?)')
           ->execute([$name, $email, $hash]);
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html>
<head><title>Setup</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="min-height:100vh">
<div class="card shadow" style="width:400px">
<div class="card-body p-4">
<?php if ($done): ?>
    <div class="alert alert-success"><strong>User created!</strong><br>
    <strong class="text-danger">DELETE setup.php from the server immediately.</strong></div>
    <a href="/inventory/" class="btn btn-primary">Go to Login</a>
<?php else: ?>
    <h5>Create First Admin User</h5>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post">
        <div class="mb-2"><label class="form-label">Name</label>
        <input type="text" name="name" class="form-control" required></div>
        <div class="mb-2"><label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" required></div>
        <div class="mb-2"><label class="form-label">Password (min 10 chars)</label>
        <input type="password" name="password" class="form-control" required minlength="10"></div>
        <div class="mb-3"><label class="form-label">Confirm Password</label>
        <input type="password" name="password2" class="form-control" required></div>
        <button class="btn btn-primary w-100">Create User</button>
    </form>
<?php endif; ?>
</div></div>
</body></html>
