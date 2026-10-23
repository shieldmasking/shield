<?php
function require_login(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: /inventory/index.php');
        exit;
    }
    if (!empty($_SESSION['force_password_change']) &&
        !str_ends_with($_SERVER['SCRIPT_NAME'], 'change-password.php')) {
        header('Location: /inventory/pages/change-password.php');
        exit;
    }
}

function check_ip_rate_limit(): bool {
    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $file = sys_get_temp_dir() . '/shield_ip_' . md5($ip) . '.json';
    $now  = time();
    $window = 3600; // 1 hour
    $max    = 20;   // max attempts per IP per hour

    $data = ['attempts' => [], 'blocked_until' => 0];
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true) ?: $data;
    }

    if ($data['blocked_until'] > $now) return false;

    // Purge attempts older than window
    $data['attempts'] = array_filter($data['attempts'], fn($t) => $t > $now - $window);
    $data['attempts'][] = $now;

    if (count($data['attempts']) > $max) {
        $data['blocked_until'] = $now + $window;
        $data['attempts'] = [];
        file_put_contents($file, json_encode($data), LOCK_EX);
        return false;
    }

    file_put_contents($file, json_encode($data), LOCK_EX);
    return true;
}

function attempt_login(PDO $db, string $email, string $password): string {
    if (!check_ip_rate_limit()) {
        return 'Too many login attempts from your IP. Try again in 1 hour.';
    }

    $stmt = $db->prepare('SELECT id, password_hash, failed_attempts, locked_until, force_password_change, is_admin FROM users WHERE email = ?');
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
    $_SESSION['user_id']               = $user['id'];
    $_SESSION['force_password_change'] = (bool)$user['force_password_change'];
    $_SESSION['is_admin']              = (bool)$user['is_admin'];
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

function is_admin(): bool {
    return !empty($_SESSION['is_admin']);
}

function require_admin(): void {
    require_login();
    if (!is_admin()) {
        header('Location: /inventory/pages/dashboard.php');
        exit;
    }
}
