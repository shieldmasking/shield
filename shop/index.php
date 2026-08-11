<?php
require_once __DIR__ . '/inc/common.php';
require_once __DIR__ . '/inc/layout.php';

$db = db();
shop_ensure_tables($db);

// ── Filters ───────────────────────────────────────────────────────────────────
$filter_cats  = array_map('intval', (array)($_GET['cat'] ?? []));
$filter_q     = trim($_GET['q'] ?? '');
$filter_w_min = isset($_GET['w_min']) && $_GET['w_min'] !== '' ? (float)$_GET['w_min'] : null;
$filter_w_max = isset($_GET['w_max']) && $_GET['w_max'] !== '' ? (float)$_GET['w_max'] : null;

// ── Sidebar data ──────────────────────────────────────────────────────────────
$all_cats = $db->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();

$widths_raw = $db->query("
    SELECT DISTINCT i.width_inches
    FROM items i
    WHERE i.is_active = 1 AND i.quantity_on_hand > 0
      AND i.sku NOT LIKE CONCAT(i.base_sku, '-L%')
    ORDER BY i.width_inches ASC
")->fetchAll(PDO::FETCH_COLUMN);

// ── Product query ─────────────────────────────────────────────────────────────
$where  = ['i.is_active = 1', 'i.quantity_on_hand > 0', "i.sku NOT LIKE CONCAT(i.base_sku, '-L%')"];
$params = [];

if (!empty($filter_cats)) {
    $ph     = implode(',', array_fill(0, count($filter_cats), '?'));
    $where[]  = "c.id IN ($ph)";
    $params   = array_merge($params, $filter_cats);
}
if ($filter_q !== '') {
    $where[]  = '(p.name LIKE ? OR i.sku LIKE ? OR p.description LIKE ?)';
    $like     = '%' . $filter_q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($filter_w_min !== null) {
    $where[]  = 'i.width_inches >= ?';
    $params[] = $filter_w_min;
}
if ($filter_w_max !== null) {
    $where[]  = 'i.width_inches <= ?';
    $params[] = $filter_w_max;
}

$sql = "
    SELECT i.id, i.sku, i.width_inches, i.quantity_on_hand,
           p.name, p.description, p.land_cost_base, p.markup_multiplier,
           p.is_log, p.is_fixed_width, p.roll_length_yards,
           c.id AS cat_id, c.name AS category_name
    FROM items i
    JOIN products p ON p.base_sku = i.base_sku
    JOIN categories c ON c.id = p.category_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY c.name, p.name, i.width_inches
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$wm       = get_width_multipliers($db);
$products = [];
foreach ($rows as $row) {
    $row['price'] = calculate_sell_price($row, $wm);
    $products[]   = $row;
}

// ── Has active filters? ───────────────────────────────────────────────────────
$has_filters = !empty($filter_cats) || $filter_q !== '' || $filter_w_min !== null || $filter_w_max !== null;

shop_header('Shop', 'products');
?>

<div class="row g-4">
    <!-- Sidebar -->
    <div class="col-lg-3">
        <div class="card shadow-sm">
            <div class="card-body">
                <h6 class="fw-bold mb-3">Filters</h6>
                <form method="get" id="filterForm">
                    <?php if ($filter_q !== ''): ?>
                        <input type="hidden" name="q" value="<?= h($filter_q) ?>">
                    <?php endif; ?>

                    <!-- Categories -->
                    <div class="filter-section">
                        <div class="fw-semibold small text-uppercase text-muted mb-2">Category</div>
                        <?php foreach ($all_cats as $cat): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="cat[]"
                                       value="<?= $cat['id'] ?>"
                                       id="cat<?= $cat['id'] ?>"
                                       onchange="document.getElementById('filterForm').submit()"
                                    <?= in_array((int)$cat['id'], $filter_cats) ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="cat<?= $cat['id'] ?>">
                                    <?= h($cat['name']) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Width -->
                    <div class="filter-section">
                        <div class="fw-semibold small text-uppercase text-muted mb-2">Width</div>
                        <div class="row g-2">
                            <div class="col">
                                <label class="form-label small mb-1">Min</label>
                                <select class="form-select form-select-sm" name="w_min"
                                        onchange="document.getElementById('filterForm').submit()">
                                    <option value="">Any</option>
                                    <?php foreach ($widths_raw as $w): ?>
                                        <option value="<?= $w ?>" <?= (string)$filter_w_min === (string)$w ? 'selected' : '' ?>>
                                            <?= format_width((float)$w) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col">
                                <label class="form-label small mb-1">Max</label>
                                <select class="form-select form-select-sm" name="w_max"
                                        onchange="document.getElementById('filterForm').submit()">
                                    <option value="">Any</option>
                                    <?php foreach ($widths_raw as $w): ?>
                                        <option value="<?= $w ?>" <?= (string)$filter_w_max === (string)$w ? 'selected' : '' ?>>
                                            <?= format_width((float)$w) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <?php if ($has_filters): ?>
                        <a href="/shop/" class="btn btn-outline-secondary btn-sm w-100">Clear Filters</a>
                    <?php endif; ?>
                </form>

                <!-- Search -->
                <form method="get" class="mt-3">
                    <?php foreach ($filter_cats as $cid): ?>
                        <input type="hidden" name="cat[]" value="<?= $cid ?>">
                    <?php endforeach; ?>
                    <?php if ($filter_w_min !== null): ?>
                        <input type="hidden" name="w_min" value="<?= h((string)$filter_w_min) ?>">
                    <?php endif; ?>
                    <?php if ($filter_w_max !== null): ?>
                        <input type="hidden" name="w_max" value="<?= h((string)$filter_w_max) ?>">
                    <?php endif; ?>
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control" name="q"
                               placeholder="Search..." value="<?= h($filter_q) ?>">
                        <button class="btn btn-outline-secondary" type="submit">Go</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Product Grid -->
    <div class="col-lg-9">
        <?php if (empty($products)): ?>
            <div class="text-center py-5 text-muted">
                <p class="fs-5">No products found.</p>
                <?php if ($has_filters): ?>
                    <a href="/shop/" class="btn btn-outline-primary">Clear Filters</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-3 g-3">
                <?php foreach ($products as $p): ?>
                    <div class="col">
                        <div class="card h-100 product-card shadow-sm" style="transition: box-shadow .2s;">
                            <div class="card-body d-flex flex-column">
                                <div class="d-flex gap-1 mb-2 flex-wrap">
                                    <span class="badge text-bg-secondary"><?= h($p['category_name']) ?></span>
                                    <span class="badge text-bg-success">In Stock</span>
                                </div>
                                <div class="fw-bold"><?= h($p['name']) ?></div>
                                <div class="text-muted small mb-1"><?= h($p['sku']) ?></div>
                                <div class="small mb-1">
                                    Width: <?= h(format_width((float)$p['width_inches'])) ?>
                                    <?php if ($p['roll_length_yards']): ?>
                                        &nbsp;&middot;&nbsp; <?= h((string)$p['roll_length_yards']) ?> yd
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($p['description'])): ?>
                                    <p class="small text-muted mb-2"><?= h($p['description']) ?></p>
                                <?php endif; ?>
                                <div class="mt-auto">
                                    <div class="fs-5 fw-semibold text-success mb-2">
                                        $<?= number_format($p['price'], 2) ?> / roll
                                    </div>
                                    <div class="input-group input-group-sm">
                                        <input type="number" class="form-control"
                                               id="qty_<?= $p['id'] ?>"
                                               value="1" min="1"
                                               max="<?= (int)$p['quantity_on_hand'] ?>">
                                        <button class="btn btn-primary"
                                                onclick="addToCart(<?= (int)$p['id'] ?>, <?= (int)$p['id'] ?>)"
                                                style="background:#1e2d6b;border-color:#1e2d6b;">
                                            Add to Cart
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:9999">
    <div id="cartToast" class="toast align-items-center text-bg-success border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body" id="cartToastMsg">Added to cart.</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto"
                    data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script>
function addToCart(itemId, itemId2) {
    const qtyEl = document.getElementById('qty_' + itemId2);
    const qty   = qtyEl ? parseInt(qtyEl.value, 10) : 1;

    fetch('/shop/api/cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=add&item_id=' + itemId + '&qty=' + qty
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('cartToastMsg').textContent =
                'Added! Cart: ' + data.cart_count + ' item(s)';
            bootstrap.Toast.getOrCreateInstance(
                document.getElementById('cartToast')
            ).show();
        }
    });
}
</script>

<?php shop_footer(); ?>
