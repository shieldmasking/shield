<?php
require_once __DIR__ . '/inc/common.php';
require_once __DIR__ . '/inc/layout.php';

$db    = db();
$items = shop_cart_items($db);
$total = shop_cart_total($db);

shop_header('Cart');
?>

<?php if (empty($items)): ?>
    <div class="row justify-content-center">
        <div class="col-md-6 text-center py-5">
            <div class="card shadow-sm p-5">
                <h4 class="mb-3">Your cart is empty</h4>
                <p class="text-muted">Browse our products and add something to get started.</p>
                <a href="/shop/" class="btn btn-primary" style="background:#1e2d6b;border-color:#1e2d6b;">
                    Browse Products
                </a>
            </div>
        </div>
    </div>
<?php else: ?>
    <h4 class="mb-3">Your Cart</h4>
    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Product</th>
                        <th>Width</th>
                        <th style="width:110px;">Qty</th>
                        <th class="text-end">Unit Price</th>
                        <th class="text-end">Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= h($item['name']) ?></div>
                                <div class="text-muted small"><?= h($item['sku']) ?></div>
                            </td>
                            <td><?= h(format_width((float)$item['width_inches'])) ?></td>
                            <td>
                                <input type="number"
                                       class="form-control form-control-sm"
                                       value="<?= (int)$item['qty'] ?>"
                                       min="1"
                                       max="<?= (int)$item['quantity_on_hand'] ?>"
                                       onchange="updateCart(<?= (int)$item['id'] ?>, this.value)">
                            </td>
                            <td class="text-end">$<?= number_format($item['unit_price'], 2) ?></td>
                            <td class="text-end">$<?= number_format($item['line_total'], 2) ?></td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-danger"
                                        onclick="removeItem(<?= (int)$item['id'] ?>)"
                                        title="Remove">&times;</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <td colspan="4" class="text-end fw-bold">Subtotal</td>
                        <td class="text-end fw-bold">$<?= number_format($total, 2) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="d-flex justify-content-between mt-4">
        <a href="/shop/" class="btn btn-outline-secondary">&larr; Continue Shopping</a>
        <a href="/shop/checkout.php" class="btn btn-primary" style="background:#1e2d6b;border-color:#1e2d6b;">
            Checkout &rarr;
        </a>
    </div>
<?php endif; ?>

<script>
function updateCart(itemId, qty) {
    fetch('/shop/api/cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=update&item_id=' + itemId + '&qty=' + parseInt(qty, 10)
    })
    .then(r => r.json())
    .then(data => { if (data.success) location.reload(); });
}

function removeItem(itemId) {
    fetch('/shop/api/cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=remove&item_id=' + itemId
    })
    .then(r => r.json())
    .then(data => { if (data.success) location.reload(); });
}
</script>

<?php shop_footer(); ?>
