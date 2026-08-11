<?php
require_once __DIR__ . '/../../inc/common.php';

header('Content-Type: application/json');

$action  = $_POST['action']  ?? '';
$item_id = (int)($_POST['item_id'] ?? 0);
$qty     = (int)($_POST['qty']     ?? 1);

switch ($action) {
    case 'add':
        shop_cart_add($item_id, $qty);
        break;
    case 'update':
        shop_cart_update($item_id, $qty);
        break;
    case 'remove':
        unset($_SESSION['shop_cart'][$item_id]);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        exit;
}

echo json_encode(['success' => true, 'cart_count' => shop_cart_count()]);
