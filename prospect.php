<?php
require_once __DIR__ . '/inventory/inc/db.php';
require_once __DIR__ . '/mail_config.php';

function leads_ensure_table(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS leads (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company       VARCHAR(255) NOT NULL,
        name          VARCHAR(255) NOT NULL,
        email         VARCHAR(255) NOT NULL,
        spray_types   JSON         NOT NULL,
        mask_products JSON         NOT NULL,
        tape_usage    ENUM('high','med','low') NOT NULL,
        promo_code    VARCHAR(20)  NOT NULL,
        created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function generate_promo(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code  = '';
    for ($i = 0; $i < 6; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return 'SHIELD10-' . $code;
}

function resend(array $payload): void {
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
    curl_close($ch);
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

leads_ensure_table();

$spray_options = ['HVOF', 'Plasma', 'Arc Wire', 'Flame Spray', 'Cold Spray', 'HVAF'];
$mask_options  = ['Tape', 'MachBloc', '2-Part Putty'];
$usage_options = [
    'high' => 'High — over 100 rolls/mo',
    'med'  => 'Medium — 20–100 rolls/mo',
    'low'  => 'Low — under 20 rolls/mo',
];

$errors  = [];
$success = false;
$promo   = '';
$input   = [
    'company'       => '',
    'name'          => '',
    'email'         => '',
    'spray_types'   => [],
    'mask_products' => [],
    'tape_usage'    => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['website'])) {
        $success = true; // honeypot — silently discard
    } else {
        $input['company']       = trim($_POST['company']    ?? '');
        $input['name']          = trim($_POST['name']       ?? '');
        $input['email']         = trim($_POST['email']      ?? '');
        $input['spray_types']   = array_values(array_intersect($_POST['spray_types']   ?? [], $spray_options));
        $input['mask_products'] = array_values(array_intersect($_POST['mask_products'] ?? [], $mask_options));
        $input['tape_usage']    = $_POST['tape_usage'] ?? '';

        if ($input['company'] === '')                                      $errors['company']       = 'Required.';
        if ($input['name']    === '')                                      $errors['name']          = 'Required.';
        if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL))           $errors['email']         = 'Valid email required.';
        if (empty($input['spray_types']))                                  $errors['spray_types']   = 'Select at least one.';
        if (empty($input['mask_products']))                                $errors['mask_products'] = 'Select at least one.';
        if (!array_key_exists($input['tape_usage'], $usage_options))      $errors['tape_usage']    = 'Select one.';

        if (empty($errors)) {
            $promo = generate_promo();

            db()->prepare("
                INSERT INTO leads (company, name, email, spray_types, mask_products, tape_usage, promo_code)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $input['company'],
                $input['name'],
                $input['email'],
                json_encode($input['spray_types']),
                json_encode($input['mask_products']),
                $input['tape_usage'],
                $promo,
            ]);

            $spray_list  = implode(', ', $input['spray_types']);
            $mask_list   = implode(', ', $input['mask_products']);
            $usage_label = $usage_options[$input['tape_usage']];

            resend([
                'from'    => 'Shield Masking <noreply@shieldmasking.com>',
                'to'      => ['rstrenger@shieldmasking.com'],
                'subject' => "New Prospect: {$input['company']}",
                'text'    => implode("\n", [
                    "Company:          {$input['company']}",
                    "Name:             {$input['name']}",
                    "Email:            {$input['email']}",
                    "",
                    "Spray Processes:  {$spray_list}",
                    "Masking Products: {$mask_list}",
                    "Tape Usage:       {$usage_label}",
                    "",
                    "Promo Code:       {$promo}",
                ]),
            ]);

            resend([
                'from'    => 'Shield Masking <noreply@shieldmasking.com>',
                'to'      => [$input['email']],
                'subject' => 'Your 10% Discount Code — Shield Masking',
                'text'    => implode("\n", [
                    "Hi {$input['name']},",
                    "",
                    "Thank you for your interest in Shield Masking! Here is your 10% discount code for your first order:",
                    "",
                    "    {$promo}",
                    "",
                    "Apply it at checkout at shieldmasking.com/shop/ or mention it when placing an order with our team.",
                    "",
                    "Questions? Reply to this email or reach us at rstrenger@shieldmasking.com.",
                    "",
                    "Shield Masking Team",
                ]),
            ]);

            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Get 10% Off Your First Order — Shield Masking</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <style>
        :root { --navy: #1e2d6b; }
        body { background: #f8f9fa; }
    </style>
</head>
<body>

<nav class="navbar mb-5" style="background:#1e2d6b;">
    <div class="container">
        <a class="navbar-brand fw-bold text-white" href="/">Shield Masking</a>
    </div>
</nav>

<div class="container pb-5" style="max-width:600px;">

<?php if ($success): ?>

    <div class="text-center py-4">
        <h2 class="fw-bold mb-3">You're all set!</h2>
        <p class="lead mb-1">Your discount code has been sent to</p>
        <p class="fw-semibold mb-4"><?= h($input['email']) ?></p>
        <?php if ($promo): ?>
        <div class="card border-success mb-4">
            <div class="card-body py-4">
                <div class="text-muted small mb-1">YOUR 10% DISCOUNT CODE</div>
                <div class="fs-2 fw-bold text-success"><?= h($promo) ?></div>
            </div>
        </div>
        <p class="text-muted">Use at checkout or mention it when placing your order.</p>
        <?php endif; ?>
        <a href="/" class="btn btn-lg mt-3 text-white" style="background:#1e2d6b;">Back to Site</a>
    </div>

<?php else: ?>

    <h1 class="fw-bold mb-1">Get 10% Off Your First Order</h1>
    <p class="text-muted mb-4">Tell us about your operation and we'll send your discount code right away.</p>

    <form method="post" novalidate>
        <input type="text" name="website" style="display:none" tabindex="-1" autocomplete="off">

        <div class="mb-3">
            <label class="form-label fw-semibold">Company <span class="text-danger">*</span></label>
            <input type="text" name="company" class="form-control<?= isset($errors['company']) ? ' is-invalid' : '' ?>"
                   value="<?= h($input['company']) ?>">
            <?php if (isset($errors['company'])): ?>
                <div class="invalid-feedback"><?= h($errors['company']) ?></div>
            <?php endif; ?>
        </div>

        <div class="mb-3">
            <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control<?= isset($errors['name']) ? ' is-invalid' : '' ?>"
                   value="<?= h($input['name']) ?>">
            <?php if (isset($errors['name'])): ?>
                <div class="invalid-feedback"><?= h($errors['name']) ?></div>
            <?php endif; ?>
        </div>

        <div class="mb-3">
            <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
            <input type="email" name="email" class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>"
                   value="<?= h($input['email']) ?>">
            <?php if (isset($errors['email'])): ?>
                <div class="invalid-feedback"><?= h($errors['email']) ?></div>
            <?php endif; ?>
        </div>

        <div class="mb-3">
            <label class="form-label fw-semibold">Spray Processes <span class="text-danger">*</span></label>
            <?php foreach ($spray_options as $opt): ?>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="spray_types[]"
                       value="<?= h($opt) ?>" id="spray_<?= h(str_replace(' ', '_', $opt)) ?>"
                       <?= in_array($opt, $input['spray_types']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="spray_<?= h(str_replace(' ', '_', $opt)) ?>"><?= h($opt) ?></label>
            </div>
            <?php endforeach; ?>
            <?php if (isset($errors['spray_types'])): ?>
                <div class="text-danger small mt-1"><?= h($errors['spray_types']) ?></div>
            <?php endif; ?>
        </div>

        <div class="mb-3">
            <label class="form-label fw-semibold">Masking Products Used <span class="text-danger">*</span></label>
            <?php foreach ($mask_options as $opt): ?>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="mask_products[]"
                       value="<?= h($opt) ?>" id="mask_<?= h(str_replace(' ', '_', $opt)) ?>"
                       <?= in_array($opt, $input['mask_products']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="mask_<?= h(str_replace(' ', '_', $opt)) ?>"><?= h($opt) ?></label>
            </div>
            <?php endforeach; ?>
            <?php if (isset($errors['mask_products'])): ?>
                <div class="text-danger small mt-1"><?= h($errors['mask_products']) ?></div>
            <?php endif; ?>
        </div>

        <div class="mb-4">
            <label class="form-label fw-semibold">Tape Usage <span class="text-danger">*</span></label>
            <?php foreach ($usage_options as $val => $label): ?>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="tape_usage"
                       value="<?= h($val) ?>" id="usage_<?= h($val) ?>"
                       <?= $input['tape_usage'] === $val ? 'checked' : '' ?>>
                <label class="form-check-label" for="usage_<?= h($val) ?>"><?= h($label) ?></label>
            </div>
            <?php endforeach; ?>
            <?php if (isset($errors['tape_usage'])): ?>
                <div class="text-danger small mt-1"><?= h($errors['tape_usage']) ?></div>
            <?php endif; ?>
        </div>

        <button type="submit" class="btn btn-lg w-100 text-white" style="background:#1e2d6b;">
            Get My 10% Discount
        </button>
    </form>

<?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
