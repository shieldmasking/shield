<?php
header('Content-Type: application/json');

// Origin check — only accept submissions from our domain
$origin  = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
$allowed = ['https://www.shieldmasking.com', 'https://shieldmasking.com'];
$ok_origin = false;
foreach ($allowed as $a) {
    if (str_starts_with($origin, $a)) { $ok_origin = true; break; }
}
if (!$ok_origin) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden.']);
    exit;
}

// Honeypot — bots fill this, humans don't
if (!empty($_POST['website'])) {
    echo json_encode(['ok' => true]); // silently discard
    exit;
}

$name    = trim($_POST['name']    ?? '');
$email   = trim($_POST['email']   ?? '');
$product = trim($_POST['product'] ?? '');
$address = trim($_POST['address'] ?? '');
$message = trim($_POST['message'] ?? '');

if (!$name || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'error' => 'Please provide a valid name and email.']);
    exit;
}

require_once __DIR__ . '/mail_config.php';

$body = "Name: {$name}\r\nEmail: {$email}\r\n"
      . ($product ? "Product: {$product}\r\n" : '')
      . ($address ? "Ship To: {$address}\r\n" : '')
      . ($message ? "\r\nMessage:\r\n{$message}" : '');

$payload = json_encode([
    'from'     => 'Shield Masking <noreply@shieldmasking.com>',
    'to'       => ['rstrenger@shieldmasking.com'],
    'reply_to' => $email,
    'subject'  => 'Sample Roll Request' . ($product ? " — {$product}" : ''),
    'text'     => $body,
]);

$ch = curl_init('https://api.resend.com/emails');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . RESEND_KEY,
        'Content-Type: application/json',
    ],
]);
$response = curl_exec($ch);
$status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($status === 200 || $status === 201) {
    echo json_encode(['ok' => true]);
} else {
    echo json_encode(['ok' => false, 'error' => 'Failed to send. Please email us directly.']);
}
