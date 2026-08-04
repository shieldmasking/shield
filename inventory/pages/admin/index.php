<?php
session_start();
require_once __DIR__ . '/../../inc/db.php';
require_once __DIR__ . '/../../inc/auth.php';
require_once __DIR__ . '/../../inc/layout.php';
require_login();
render_header('Admin', 'admin');
?>
<h4>Admin</h4>
<div class="list-group mt-3" style="max-width:400px">
    <a href="/inventory/pages/admin/items.php" class="list-group-item list-group-item-action">Products / SKUs</a>
    <a href="/inventory/pages/admin/width-multipliers.php" class="list-group-item list-group-item-action">Width Multipliers</a>
    <a href="/inventory/pages/admin/users.php" class="list-group-item list-group-item-action">Users</a>
    <a href="/inventory/pages/admin/settings.php" class="list-group-item list-group-item-action">Settings</a>
</div>
<?php render_footer(); ?>
