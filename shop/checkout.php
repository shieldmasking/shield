<?php
require_once __DIR__ . '/inc/common.php';
require_once __DIR__ . '/inc/layout.php';

$db = db();
shop_ensure_tables($db);

$items = shop_cart_items($db);
$total = shop_cart_total($db);

if (empty($items)) {
    header('Location: /shop/');
    exit;
}

$errors = [];
$input  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input['name']    = trim($_POST['name']    ?? '');
    $input['email']   = trim($_POST['email']   ?? '');
    $input['phone']   = trim($_POST['phone']   ?? '');
    $input['address'] = trim($_POST['address'] ?? '');
    $input['notes']   = trim($_POST['notes']   ?? '');

    if ($input['name'] === '') {
        $errors['name'] = 'Name is required.';
    }
    if ($input['email'] === '' || !filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'A valid email address is required.';
    }

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            $db->prepare("
                INSERT INTO shop_orders
                    (customer_name, customer_email, customer_phone, shipping_address, subtotal, notes)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([
                $input['name'],
                $input['email'],
                $input['phone'],
                $input['address'],
                $total,
                $input['notes'],
            ]);
            $order_id = (int)$db->lastInsertId();

            $ins = $db->prepare("
                INSERT INTO shop_order_items
                    (order_id, item_id, sku, description, quantity, unit_price)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($items as $item) {
                $ins->execute([
                    $order_id,
                    (int)$item['id'],
                    $item['sku'],
                    $item['name'] . ' ' . format_width((float)$item['width_inches']),
                    (int)$item['qty'],
                    $item['unit_price'],
                ]);
            }

            $db->commit();
            $_SESSION['shop_cart']    = [];
            $_SESSION['shop_order_id'] = $order_id;
            header('Location: /shop/order-confirm.php');
            exit;
        } catch (Throwable $e) {
            $db->rollBack();
            $errors['_'] = 'An error occurred saving your order. Please try again.';
        }
    }
} else {
    $input = ['name' => '', 'email' => '', 'phone' => '', 'address' => '', 'notes' => ''];
}

shop_header('Checkout');
?>

<h4 class="mb-4">Checkout</h4>

<?php if (!empty($errors['_'])): ?>
    <div class="alert alert-danger"><?= h($errors['_']) ?></div>
<?php endif; ?>

<div class="row g-4">
    <!-- Contact & Payment -->
    <div class="col-md-7">
        <form method="post" novalidate>
            <div class="card shadow-sm mb-4">
                <div class="card-header fw-semibold">Contact Information</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                               value="<?= h($input['name']) ?>">
                        <?php if (isset($errors['name'])): ?>
                            <div class="invalid-feedback"><?= h($errors['name']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                               value="<?= h($input['email']) ?>">
                        <?php if (isset($errors['email'])): ?>
                            <div class="invalid-feedback"><?= h($errors['email']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <input type="tel" name="phone" class="form-control"
                               value="<?= h($input['phone']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Shipping Address</label>
                        <textarea name="address" class="form-control" rows="3"><?= h($input['address']) ?></textarea>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"><?= h($input['notes']) ?></textarea>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mb-4">
                <div class="card-header fw-semibold">Payment</div>
                <div class="card-body">
                    <div class="alert alert-info mb-3">
                        Stripe integration pending &mdash; payment processing will be activated once Stripe keys are configured.
                    </div>
                    <div id="payment-element"></div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-lg w-100"
                    style="background:#1e2d6b;border-color:#1e2d6b;">
                Place Order &mdash; $<?= number_format($total, 2) ?>
            </button>
        </form>
    </div>

    <!-- Order Summary -->
    <div class="col-md-5">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Order Summary</div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php foreach ($items as $item): ?>
                        <li class="list-group-item">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <div class="fw-semibold small"><?= h($item['name']) ?></div>
                                    <div class="text-muted small">
                                        <?= h(format_width((float)$item['width_inches'])) ?>
                                        &nbsp;&middot;&nbsp; qty <?= (int)$item['qty'] ?>
                                        &times; $<?= number_format($item['unit_price'], 2) ?>
                                    </div>
                                </div>
                                <div class="fw-semibold">$<?= number_format($item['line_total'], 2) ?></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                    <li class="list-group-item d-flex justify-content-between fw-bold">
                        <span>Total</span>
                        <span>$<?= number_format($total, 2) ?></span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php shop_footer(); ?>
