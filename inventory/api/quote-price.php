<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/functions.php';

if (empty($_SESSION['user_id'])) { http_response_code(401); exit; }

header('Content-Type: application/json');

$item_id = (int)($_GET['item_id'] ?? 0);
$width   = (float)($_GET['width'] ?? 0);

if (!$item_id || $width <= 0) {
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

$db   = db();
$stmt = $db->prepare('SELECT * FROM items WHERE id = ? AND is_active = 1');
$stmt->execute([$item_id]);
$item = $stmt->fetch();

if (!$item) {
    http_response_code(404);
    echo json_encode(['error' => 'Item not found']);
    exit;
}

$wm = get_width_multipliers($db);
echo json_encode([
    'sell_price' => calculate_sell_price($item, $width, $wm),
    'land_cost'  => calculate_land_cost($item, $width),
]);
