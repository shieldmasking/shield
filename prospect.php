<?php
require_once __DIR__ . '/inventory/inc/db.php';
require_once __DIR__ . '/mail_config.php';

function leads_ensure_table(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS leads (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        token         VARCHAR(64)  NOT NULL UNIQUE,
        company       VARCHAR(255) NOT NULL,
        name          VARCHAR(255) NOT NULL,
        email         VARCHAR(255) NOT NULL,
        spray_types   TEXT         NULL,
        mask_products TEXT         NULL,
        tape_usage    VARCHAR(255) NULL,
        tape_sizes    TEXT         NULL,
        promo_code    VARCHAR(20)  NULL,
        status        ENUM('invited','completed') NOT NULL DEFAULT 'invited',
        created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Migrate existing table if needed
    try {
        db()->exec("ALTER TABLE leads
            ADD COLUMN token   VARCHAR(64) NULL UNIQUE AFTER id,
            ADD COLUMN status  ENUM('invited','completed') NOT NULL DEFAULT 'invited' AFTER promo_code,
            MODIFY spray_types   TEXT NULL,
            MODIFY mask_products TEXT NULL,
            MODIFY tape_usage    ENUM('high','med','low') NULL,
            MODIFY promo_code    VARCHAR(20) NULL");
    } catch (PDOException) {}
    try { db()->exec("ALTER TABLE leads ADD COLUMN tape_sizes TEXT NULL AFTER tape_usage"); } catch (PDOException) {}
    try { db()->exec("ALTER TABLE leads MODIFY tape_usage VARCHAR(255) NULL"); } catch (PDOException) {}
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

$spray_options     = ['HVOF', 'Plasma', 'Arc Wire', 'Flame Spray', 'Cold Spray', 'HVAF'];
$mask_options      = ['Single-Sided Tape', 'Double-Sided Tape', 'MachBloc', '2-Part Putty'];
$tape_size_options = ['0.5"', '1"', '1.5"', '2"', '2.5"', '3"', '3.5"', '4"', '4.5"', '5"', '5.5"', '6"'];
$usage_options = [
    'high' => 'High — over 100 rolls/mo',
    'med'  => 'Medium — 20–100 rolls/mo',
    'low'  => 'Low — under 20 rolls/mo',
];

// Load lead by token
$token = trim($_GET['t'] ?? '');
$lead  = null;

if ($token !== '') {
    $stmt = db()->prepare("SELECT * FROM leads WHERE token = ?");
    $stmt->execute([$token]);
    $lead = $stmt->fetch() ?: null;
}

$errors  = [];
$success = false;
$promo   = '';
$input   = ['spray_types' => [], 'mask_products' => [], 'tape_sizes' => [], 'tape_usage' => ''];

if ($lead && $lead['status'] === 'invited' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['website'])) {
        $success = true;
    } else {
        $input['spray_types']   = array_values(array_intersect($_POST['spray_types']   ?? [], $spray_options));
        $input['mask_products'] = array_values(array_intersect($_POST['mask_products'] ?? [], $mask_options));
        $input['tape_sizes']    = array_values(array_intersect($_POST['tape_sizes']    ?? [], $tape_size_options));

        foreach ([
            ['spray_types',   'spray_types_other'],
            ['mask_products', 'mask_products_other'],
            ['tape_sizes',    'tape_sizes_other'],
        ] as [$field, $other_key]) {
            $other_text = trim($_POST[$other_key] ?? '');
            if (!empty($other_text)) $input[$field][] = "Other: {$other_text}";
        }
        $input['tape_usage'] = $_POST['tape_usage'] ?? '';
        if ($input['tape_usage'] === 'other') {
            $other_text = trim($_POST['tape_usage_other'] ?? '');
            $input['tape_usage'] = $other_text !== '' ? "Other: {$other_text}" : '';
        }

        if (empty($input['spray_types']))                                                          $errors['spray_types']   = 'Select at least one.';
        if (empty($input['mask_products']))                                                        $errors['mask_products'] = 'Select at least one.';
        if (empty($input['tape_sizes']))                                                           $errors['tape_sizes']    = 'Select at least one.';
        if (!array_key_exists($input['tape_usage'], $usage_options) && !str_starts_with($input['tape_usage'], 'Other:')) $errors['tape_usage'] = 'Select one.';

        if (empty($errors)) {
            db()->prepare("
                UPDATE leads SET spray_types=?, mask_products=?, tape_sizes=?, tape_usage=?, status='completed'
                WHERE token=?
            ")->execute([
                json_encode($input['spray_types']),
                json_encode($input['mask_products']),
                json_encode($input['tape_sizes']),
                $input['tape_usage'],
                $token,
            ]);

            $spray_list  = implode(', ', $input['spray_types']);
            $mask_list   = implode(', ', $input['mask_products']);
            $sizes_list  = implode(', ', $input['tape_sizes']);
            $usage_label = $usage_options[$input['tape_usage']];

            resend([
                'from'    => 'Shield Masking <noreply@shieldmasking.com>',
                'to'      => ['rstrenger@shieldmasking.com'],
                'subject' => "Prospect Form Completed: {$lead['company']}",
                'text'    => implode("\n", [
                    "Company:          {$lead['company']}",
                    "Name:             {$lead['name']}",
                    "Email:            {$lead['email']}",
                    "",
                    "Spray Processes:  {$spray_list}",
                    "Masking Products: {$mask_list}",
                    "Tape Sizes:       {$sizes_list}",
                    "Tape Usage:       {$usage_label}",
                    "",
                    "NOTE: Apply a 10% discount to this customer's first order.",
                ]),
            ]);

            resend([
                'from'    => 'Shield Masking <noreply@shieldmasking.com>',
                'to'      => [$lead['email']],
                'subject' => 'Your 10% Discount — Shield Masking',
                'text'    => implode("\n", [
                    "Hi {$lead['name']},",
                    "",
                    "Thank you for completing our form! Your 10% discount will automatically be applied to your first order.",
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

<?php if (!$lead): ?>

    <div class="text-center py-5">
        <h4 class="text-muted">Invalid or expired link.</h4>
        <p>Please contact us at <a href="mailto:rstrenger@shieldmasking.com">rstrenger@shieldmasking.com</a> for a new link.</p>
    </div>

<?php elseif ($lead['status'] === 'completed' && !$success): ?>

    <div class="text-center py-5">
        <h4>Already submitted</h4>
        <p class="text-muted">This form has already been completed. Your 10% discount will be applied to your first order.</p>
    </div>

<?php elseif ($success): ?>

    <div class="text-center py-4">
        <h2 class="fw-bold mb-3">You're all set!</h2>
        <p class="lead mb-2">Your 10% discount will automatically be applied to your first order.</p>
        <p class="text-muted">A confirmation has been sent to <strong><?= h($lead['email']) ?></strong>.</p>
        <a href="/" class="btn btn-lg mt-3 text-white" style="background:#1e2d6b;">Back to Site</a>
    </div>

<?php else: ?>

    <h1 class="fw-bold mb-1">Get 10% Off Your First Order</h1>
    <p class="text-muted mb-4">Tell us about your operation and we'll send your discount code right away.</p>

    <form method="post" action="?t=<?= h($token) ?>" novalidate>
        <input type="text" name="website" style="display:none" tabindex="-1" autocomplete="off">

        <div class="mb-3">
            <label class="form-label fw-semibold">Company</label>
            <input type="text" class="form-control bg-light" value="<?= h($lead['company']) ?>" disabled>
        </div>

        <div class="mb-3">
            <label class="form-label fw-semibold">Name</label>
            <input type="text" class="form-control bg-light" value="<?= h($lead['name']) ?>" disabled>
        </div>

        <div class="mb-3">
            <label class="form-label fw-semibold">Email</label>
            <input type="email" class="form-control bg-light" value="<?= h($lead['email']) ?>" disabled>
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
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="spray_Other"
                       onchange="document.getElementById('spray_other_text').style.display=this.checked?'block':'none'"
                       <?= !empty($_POST['spray_types_other'] ?? '') ? 'checked' : '' ?>>
                <label class="form-check-label" for="spray_Other">Other</label>
                <input type="text" name="spray_types_other" id="spray_other_text" class="form-control form-control-sm mt-1"
                       placeholder="Specify process(es)"
                       style="display:<?= !empty($_POST['spray_types_other'] ?? '') ? 'block' : 'none' ?>"
                       value="<?= h($_POST['spray_types_other'] ?? '') ?>">
            </div>
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
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="mask_Other"
                       onchange="document.getElementById('mask_other_text').style.display=this.checked?'block':'none'"
                       <?= !empty($_POST['mask_products_other'] ?? '') ? 'checked' : '' ?>>
                <label class="form-check-label" for="mask_Other">Other</label>
                <input type="text" name="mask_products_other" id="mask_other_text" class="form-control form-control-sm mt-1"
                       placeholder="Specify product(s)"
                       style="display:<?= !empty($_POST['mask_products_other'] ?? '') ? 'block' : 'none' ?>"
                       value="<?= h($_POST['mask_products_other'] ?? '') ?>">
            </div>
            <?php if (isset($errors['mask_products'])): ?>
                <div class="text-danger small mt-1"><?= h($errors['mask_products']) ?></div>
            <?php endif; ?>
        </div>

        <div class="mb-3">
            <label class="form-label fw-semibold">Tape Sizes Most Often Used <span class="text-danger">*</span></label>
            <div class="row row-cols-3 g-1">
            <?php foreach ($tape_size_options as $opt): ?>
                <div class="col">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="tape_sizes[]"
                               value="<?= h($opt) ?>" id="tsize_<?= h(str_replace(['.', '"'], ['_', ''], $opt)) ?>"
                               <?= in_array($opt, $input['tape_sizes']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="tsize_<?= h(str_replace(['.', '"'], ['_', ''], $opt)) ?>"><?= h($opt) ?></label>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
            <div class="form-check mt-1">
                <input class="form-check-input" type="checkbox" name="tape_sizes[]" value="Other" id="tsize_Other"
                       <?= in_array('Other', $input['tape_sizes']) ? 'checked' : '' ?>
                       onchange="document.getElementById('tsize_other_text').style.display=this.checked?'block':'none'">
                <label class="form-check-label" for="tsize_Other">Other</label>
                <input type="text" name="tape_sizes_other" id="tsize_other_text" class="form-control form-control-sm mt-1"
                       placeholder="Specify size(s)"
                       style="display:<?= in_array('Other', $input['tape_sizes']) ? 'block' : 'none' ?>"
                       value="<?= h($_POST['tape_sizes_other'] ?? '') ?>">
            </div>
            <?php if (isset($errors['tape_sizes'])): ?>
                <div class="text-danger small mt-1"><?= h($errors['tape_sizes']) ?></div>
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
            <div class="form-check">
                <input class="form-check-input" type="radio" name="tape_usage" value="other" id="usage_other"
                       <?= $input['tape_usage'] === 'other' || str_starts_with($input['tape_usage'], 'Other:') ? 'checked' : '' ?>
                       onchange="document.getElementById('usage_other_text').style.display=this.checked?'block':'none'">
                <label class="form-check-label" for="usage_other">Other</label>
                <input type="text" name="tape_usage_other" id="usage_other_text" class="form-control form-control-sm mt-1"
                       placeholder="Describe usage"
                       style="display:<?= str_starts_with($input['tape_usage'], 'Other:') ? 'block' : 'none' ?>"
                       value="<?= h(str_starts_with($input['tape_usage'], 'Other:') ? substr($input['tape_usage'], 7) : ($_POST['tape_usage_other'] ?? '')) ?>">
            </div>
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
