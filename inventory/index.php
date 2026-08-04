<?php
session_start();

if (!empty($_SESSION['user_id'])) {
    header('Location: /inventory/pages/dashboard.php');
    exit;
}

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/auth.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $error    = attempt_login(db(), $email, $password);
    if ($error === '') {
        header('Location: /inventory/pages/dashboard.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Staff Login — Shield Masking</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #0e1014; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-card { width: 360px; }
        .login-logo { font-size: 1.4rem; font-weight: 700; color: #fff; letter-spacing: 0.03em; }
        .login-sub  { font-size: 0.8rem; color: #8892a0; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="text-center mb-4">
            <div class="login-logo">Shield Masking Solutions</div>
            <div class="login-sub">Staff Portal</div>
        </div>
        <div class="card shadow-lg">
            <div class="card-body p-4">
                <?php if ($error): ?>
                    <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <form method="post" autocomplete="on">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Email</label>
                        <input type="email" name="email" class="form-control" required autofocus
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Log In</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
