<?php
function require_login(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: /inventory/index.php');
        exit;
    }
}

function attempt_login(PDO $db, string $email, string $password): string {
    $stmt = $db->prepare('SELECT id, password_hash, failed_attempts, locked_until FROM users WHERE email = ?');
    $stmt->execute([strtolower(trim($email))]);
    $user = $stmt->fetch();

    if (!$user) {
        return 'Invalid email or password.';
    }

    if ($user['locked_until'] && new DateTime() < new DateTime($user['locked_until'])) {
        return 'Account locked. Try again in 15 minutes.';
    }

    if (!password_verify($password, $user['password_hash'])) {
        $attempts = $user['failed_attempts'] + 1;
        $locked   = $attempts >= 5 ? date('Y-m-d H:i:s', strtotime('+15 minutes')) : null;
        $db->prepare('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?')
           ->execute([$attempts, $locked, $user['id']]);
        $remaining = max(0, 5 - $attempts);
        return $remaining > 0
            ? "Invalid email or password. {$remaining} attempt(s) remaining."
            : 'Account locked for 15 minutes due to too many failed attempts.';
    }

    $db->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?')
       ->execute([$user['id']]);

    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    session_write_close();
    return '';
}

function logout(): void {
    $_SESSION = [];
    session_destroy();
    header('Location: /inventory/index.php');
    exit;
}

function current_user_id(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}
