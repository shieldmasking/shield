<?php
session_start();
require_once __DIR__ . '/../../inc/db.php';
require_once __DIR__ . '/../../inc/auth.php';
require_once __DIR__ . '/../../inc/layout.php';
require_once __DIR__ . '/../../../mail_config.php';
require_login();

db()->exec("CREATE TABLE IF NOT EXISTS leads (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token         VARCHAR(64)  NOT NULL UNIQUE,
    company       VARCHAR(255) NOT NULL,
    name          VARCHAR(255) NOT NULL,
    email         VARCHAR(255) NOT NULL,
    spray_types   TEXT         NULL,
    mask_products TEXT         NULL,
    tape_usage    ENUM('high','med','low') NULL,
    tape_sizes    TEXT         NULL,
    promo_code    VARCHAR(20)  NULL,
    status        ENUM('invited','completed') NOT NULL DEFAULT 'invited',
    created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function resend_invite(array $payload): bool {
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . RESEND_KEY,
            'Content-Type: application/json',
        ],
    ]);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $status === 200 || $status === 201;
}

$errors  = [];
$sent    = false;
$input   = ['company' => '', 'name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input['company'] = trim($_POST['company'] ?? '');
    $input['name']    = trim($_POST['name']    ?? '');
    $input['email']   = trim($_POST['email']   ?? '');

    if ($input['company'] === '')                                   $errors['company'] = 'Required.';
    if ($input['name']    === '')                                   $errors['name']    = 'Required.';
    if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL))        $errors['email']   = 'Valid email required.';

    if (empty($errors)) {
        try {
        $token = bin2hex(random_bytes(32));
        $link  = 'https://shieldmasking.com/prospect.php?t=' . $token;

        db()->prepare("
            INSERT INTO leads (token, company, name, email)
            VALUES (?, ?, ?, ?)
        ")->execute([$token, $input['company'], $input['name'], $input['email']]);

        resend_invite([
            'from'    => 'Shield Masking <noreply@shieldmasking.com>',
            'to'      => [$input['email']],
            'subject' => 'Get 10% Off Your First Shield Masking Order',
            'text'    => implode("\n", [
                "Hi {$input['name']},",
                "",
                "We'd love to learn more about your operation and get you set up with the right masking solutions.",
                "",
                "Fill out our short form to receive 10% off your first order:",
                "",
                "    {$link}",
                "",
                "Questions? Reply to this email.",
                "",
                "Shield Masking Team",
            ]),
        ]);

        $sent = true;
        $input = ['company' => '', 'name' => '', 'email' => ''];
        } catch (Throwable $e) {
            $errors['_'] = 'Error: ' . $e->getMessage();
        }
    }
}

// Load all leads
$leads = db()->query("SELECT * FROM leads ORDER BY created_at DESC")->fetchAll();

render_header('Send Prospect Form', 'admin');
?>

<div class="d-flex align-items-center mb-3">
    <a href="/inventory/pages/admin/" class="btn btn-sm btn-outline-secondary me-3">&larr; Admin</a>
    <h4 class="mb-0">Send Prospect Form</h4>
</div>

<?php if ($sent): ?>
    <div class="alert alert-success">Invite sent to <?= h($_POST['email'] ?? '') ?>.</div>
<?php endif; ?>
<?php if (isset($errors['_'])): ?>
    <div class="alert alert-danger"><?= h($errors['_']) ?></div>
<?php endif; ?>

<div class="card mb-5" style="max-width:500px">
    <div class="card-body">
        <form method="post" novalidate>
            <div class="mb-3">
                <label class="form-label fw-semibold">Company <span class="text-danger">*</span></label>
                <input type="text" name="company" class="form-control<?= isset($errors['company']) ? ' is-invalid' : '' ?>"
                       value="<?= h($input['company']) ?>">
                <?php if (isset($errors['company'])): ?><div class="invalid-feedback"><?= h($errors['company']) ?></div><?php endif; ?>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control<?= isset($errors['name']) ? ' is-invalid' : '' ?>"
                       value="<?= h($input['name']) ?>">
                <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?= h($errors['name']) ?></div><?php endif; ?>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                <input type="email" name="email" class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>"
                       value="<?= h($input['email']) ?>">
                <?php if (isset($errors['email'])): ?><div class="invalid-feedback"><?= h($errors['email']) ?></div><?php endif; ?>
            </div>
            <button type="submit" class="btn btn-primary">Send Invite</button>
        </form>
    </div>
</div>

<?php if ($leads): ?>
<h5 class="mb-3">Sent Invites</h5>
<table class="table table-sm table-bordered bg-white">
    <thead class="table-light">
        <tr>
            <th>Company</th>
            <th>Name</th>
            <th>Email</th>
            <th>Status</th>
            <th>Promo Code</th>
            <th>Sent</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($leads as $row): ?>
        <tr>
            <td><?= h($row['company']) ?></td>
            <td><?= h($row['name']) ?></td>
            <td><?= h($row['email']) ?></td>
            <td>
                <?php if ($row['status'] === 'completed'): ?>
                    <span class="badge bg-success">Completed</span>
                <?php else: ?>
                    <span class="badge bg-secondary">Invited</span>
                <?php endif; ?>
            </td>
            <td><?= $row['promo_code'] ? h($row['promo_code']) : '—' ?></td>
            <td class="text-muted small"><?= h($row['created_at']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php render_footer(); ?>
