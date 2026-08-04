<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/functions.php';

if (empty($_SESSION['user_id'])) { http_response_code(401); exit; }

header('Content-Type: application/json');

$customer_id = (int)($_GET['customer_id'] ?? 0);
if (!$customer_id) {
    echo json_encode([]);
    exit;
}

$db = db();
echo json_encode(get_last_quote_prices($db, $customer_id));
