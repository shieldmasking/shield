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
            <svg class="text-success mb-3" width="72" height="72" viewBox="0 0 16 16" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
                <path d="M10.97 4.97a.235.235 0 0 0-.02.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-1.071-1.05z"/>
            </svg>
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
