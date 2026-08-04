<?php
function render_header(string $title, string $active = ''): void {
    $nav = [
        'dashboard'  => ['Dashboard',  '/inventory/pages/dashboard.php'],
        'inventory'  => ['Inventory',  '/inventory/pages/inventory.php'],
        'quotes'     => ['Quotes',     '/inventory/pages/quotes.php'],
        'orders'     => ['Orders',     '/inventory/pages/orders.php'],
        'customers'  => ['Customers',  '/inventory/pages/customers.php'],
        'admin'      => ['Admin',      '/inventory/pages/admin/index.php'],
    ];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> — Shield Masking</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/inventory/assets/css/app.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="/inventory/pages/dashboard.php">Shield Masking</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="nav">
            <ul class="navbar-nav me-auto">
                <?php foreach ($nav as $key => [$label, $url]): ?>
                <li class="nav-item">
                    <a class="nav-link<?= $active === $key ? ' active' : '' ?>" href="<?= $url ?>"><?= $label ?></a>
                </li>
                <?php endforeach; ?>
            </ul>
            <a class="btn btn-sm btn-outline-light" href="/inventory/logout.php">Logout</a>
        </div>
    </div>
</nav>
<div class="container-fluid py-4 px-4">
    <?php
}

function render_footer(): void {
    ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
    <?php
}

function alert(string $type, string $message): void {
    echo '<div class="alert alert-' . h($type) . ' alert-dismissible fade show" role="alert">'
        . h($message)
        . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}
