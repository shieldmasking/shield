<?php
header('Content-Type: application/json');

$name    = trim($_POST['name']    ?? '');
$email   = trim($_POST['email']   ?? '');
$product = trim($_POST['product'] ?? '');
$message = trim($_POST['message'] ?? '');

if (!$name || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'error' => 'Please provide a valid name and email.']);
    exit;
}

$to      = 'rstrenger@shieldmasking.com';
$subject = 'Sample Roll Request' . ($product ? " \xe2\x80\x94 {$product}" : '');
$body    = "Name: {$name}\r\nEmail: {$email}\r\n"
         . ($product ? "Product: {$product}\r\n" : '')
         . ($message ? "\r\nMessage:\r\n{$message}" : '');
$headers = implode("\r\n", [
    "From: noreply@shieldmasking.com",
    "Reply-To: {$email}",
    "Content-Type: text/plain; charset=UTF-8",
]);

if (mail($to, $subject, $body, $headers)) {
    echo json_encode(['ok' => true]);
} else {
    echo json_encode(['ok' => false, 'error' => 'Failed to send. Please email us directly.']);
}
