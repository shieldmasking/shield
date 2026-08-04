<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/functions.php';

if (empty($_SESSION['user_id'])) { http_response_code(401); exit; }

header('Content-Type: application/json');

$base_sku = trim($_GET['base_sku'] ?? '');
$width    = (float)($_GET['width'] ?? 0);

if (!$base_sku || $width <= 0) {
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

$db = db();

// Get base properties from any existing row for this base_sku
$stmt = $db->prepare('SELECT * FROM items WHERE base_sku = ? AND is_active = 1 LIMIT 1');
$stmt->execute([$base_sku]);
$base = $stmt->fetch();

if (!$base) {
    http_response_code(404);
    echo json_encode(['error' => 'Base SKU not found']);
    exit;
}

// Build a synthetic item row using the requested width for pricing
$item = $base;
$item['width_inches']   = $width;
$item['is_log']         = 0;
$item['is_fixed_width'] = $base['is_fixed_width'];

$wm = get_width_multipliers($db);
echo json_encode([
    'sell_price' => calculate_sell_price($item, $wm),
    'land_cost'  => calculate_land_cost($item),
]);
