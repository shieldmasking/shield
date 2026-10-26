<?php
session_start();
require_once __DIR__ . '/../../inc/db.php';
require_once __DIR__ . '/../../inc/auth.php';
require_once __DIR__ . '/../../inc/layout.php';
require_once __DIR__ . '/../../../mail_config.php';
require_login();

// Migrate old table if columns are missing
foreach ([
    "ALTER TABLE leads ADD COLUMN token      VARCHAR(64)  NULL UNIQUE",
    "ALTER TABLE leads ADD COLUMN tape_sizes TEXT         NULL",
    "ALTER TABLE leads ADD COLUMN status     ENUM('invited','completed') NOT NULL DEFAULT 'invited'",
    "ALTER TABLE leads MODIFY spray_types    TEXT NULL",
    "ALTER TABLE leads MODIFY mask_products  TEXT NULL",
    "ALTER TABLE leads MODIFY tape_usage     VARCHAR(255) NULL",
    "ALTER TABLE leads MODIFY promo_code     VARCHAR(20)  NULL",
] as $sql) { try { db()->exec($sql); } catch (PDOException) {} }

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
$resent  = false;
$input   = ['company' => '', 'name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action           = $_POST['action'] ?? 'send';
    $input['company'] = trim($_POST['company'] ?? '');
    $input['name']    = trim($_POST['name']    ?? '');
    $input['email']   = trim($_POST['email']   ?? '');

    if ($action === 'delete') {
        $del_id = (int)($_POST['id'] ?? 0);
        if ($del_id) db()->prepare("DELETE FROM leads WHERE id = ?")->execute([$del_id]);
    } else {
        if ($input['company'] === '')                                $errors['company'] = 'Required.';
        if ($input['name']    === '')                                $errors['name']    = 'Required.';
        if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL))    $errors['email']   = 'Valid email required.';

        if (empty($errors)) {
            try {
                $existing = db()->prepare("SELECT id, status FROM leads WHERE email = ? LIMIT 1");
                $existing->execute([$input['email']]);
                $existing = $existing->fetch();

                $token = bin2hex(random_bytes(32));
                $link  = 'https://shieldmasking.com/prospect.php?t=' . $token;

                if ($existing) {
                    db()->prepare("UPDATE leads SET token=?, company=?, name=?, status='invited', created_at=NOW() WHERE id=?")
                        ->execute([$token, $input['company'], $input['name'], $existing['id']]);
                    $resent = true;
                } else {
                    db()->prepare("INSERT INTO leads (token, company, name, email) VALUES (?, ?, ?, ?)")
                        ->execute([$token, $input['company'], $input['name'], $input['email']]);
                    $sent = true;
                }

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

                $input = ['company' => '', 'name' => '', 'email' => ''];
            } catch (Throwable $e) {
                $errors['_'] = 'Error: ' . $e->getMessage();
            }
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
<?php elseif ($resent): ?>
    <div class="alert alert-success">Invite resent to <?= h($_POST['email'] ?? '') ?> with a new link.</div>
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
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($leads as $row): ?>
        <tr>
            <td>
                <a href="#" class="lead-detail-link"
                   data-company="<?= h($row['company']) ?>"
                   data-name="<?= h($row['name']) ?>"
                   data-email="<?= h($row['email']) ?>"
                   data-status="<?= h($row['status']) ?>"
                   data-spray="<?= h($row['spray_types'] ?? '') ?>"
                   data-mask="<?= h($row['mask_products'] ?? '') ?>"
                   data-sizes="<?= h($row['tape_sizes'] ?? '') ?>"
                   data-usage="<?= h($row['tape_usage'] ?? '') ?>"
                   data-promo="<?= h($row['promo_code'] ?? '') ?>"
                   data-bs-toggle="modal" data-bs-target="#leadModal">
                    <?= h($row['company']) ?>
                </a>
            </td>
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
            <td>
                <form method="post" onsubmit="return confirm('Delete this invite?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger">Delete</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<div class="modal fade" id="leadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="leadModalTitle"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <dl class="row mb-0" id="leadModalBody"></dl>
            </div>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.lead-detail-link').forEach(function(el) {
    el.addEventListener('click', function() {
        var d = this.dataset;
        document.getElementById('leadModalTitle').textContent = d.company;

        function parseList(json) {
            try { var a = JSON.parse(json); return a.length ? a.join(', ') : '—'; }
            catch(e) { return json || '—'; }
        }

        var rows = [
            ['Name',             d.name],
            ['Email',            d.email],
            ['Spray Processes',  parseList(d.spray)],
            ['Masking Products', parseList(d.mask)],
            ['Tape Sizes',       parseList(d.sizes)],
            ['Tape Usage',       d.usage || '—'],
            ['Promo Code',       d.promo || '—'],
        ];

        document.getElementById('leadModalBody').innerHTML = rows.map(function(r) {
            return '<dt class="col-sm-5">' + r[0] + '</dt><dd class="col-sm-7">' + r[1] + '</dd>';
        }).join('');
    });
});
</script>

<?php render_footer(); ?>
