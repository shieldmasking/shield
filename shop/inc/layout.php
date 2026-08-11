<?php

function shop_header(string $title, string $page = ''): void {
    $count = shop_cart_count();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> — Shield Masking</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <style>
        :root { --navy: #1e2d6b; --navy-lt: #2a3d8f; }
        .product-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,.1); }
        .filter-section { border-bottom: 1px solid #e9ecef; padding-bottom: 1rem; margin-bottom: 1rem; }
        .filter-section:last-child { border-bottom: none; }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-dark navbar-expand-lg mb-4" style="background:#1e2d6b;">
    <div class="container">
        <a class="navbar-brand fw-bold" href="/shop/">Shield Masking</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#shopNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="shopNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link<?= $page === 'products' ? ' active' : '' ?>" href="/shop/">Products</a>
                </li>
            </ul>
            <div class="d-flex align-items-center gap-3">
                <a href="/shop/cart.php" class="btn btn-outline-light btn-sm position-relative">
                    Cart
                    <?php if ($count > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                            <?= $count ?>
                        </span>
                    <?php endif; ?>
                </a>
                <a href="/" class="nav-link text-white-50 small">&larr; Back to Site</a>
            </div>
        </div>
    </div>
</nav>

<div class="container pb-5">
    <?php
}

function shop_footer(): void {
    ?>
</div><!-- /container -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
    <?php
}
