<?php
require_once __DIR__ . '/inc/common.php';
require_once __DIR__ . '/inc/layout.php';

$order_id = $_SESSION['shop_order_id'] ?? null;
unset($_SESSION['shop_order_id']);

shop_header('Order Confirmed');
?>

<div class="row justify-content-center">
    <div class="col-md-6 text-center py-5">
        <div class="card shadow-sm p-5">
            <div class="display-1 text-success mb-3">&#10003;</div>
            <h3 class="fw-bold mb-2">Order Received!</h3>
            <p class="text-muted mb-4">
                Thank you for your order.
                <?php if ($order_id): ?>
                    Your order number is <strong>#<?= (int)$order_id ?></strong>.
                <?php endif; ?>
                We will be in touch shortly to confirm and arrange payment.
            </p>
            <a href="/shop/" class="btn btn-primary"
               style="background:#1e2d6b;border-color:#1e2d6b;">
                Continue Shopping
            </a>
        </div>
    </div>
</div>

<?php shop_footer(); ?>
