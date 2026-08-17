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

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/mail_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = 'smtp.office365.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_USER;
    $mail->Password   = MAIL_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom(MAIL_USER, 'Shield Masking Website');
    $mail->addAddress('rstrenger@shieldmasking.com', 'Ryan Strenger');
    $mail->addReplyTo($email, $name);

    $mail->Subject = 'Sample Roll Request' . ($product ? " — {$product}" : '');
    $mail->Body    = "Name: {$name}\r\nEmail: {$email}\r\n"
                   . ($product ? "Product: {$product}\r\n" : '')
                   . ($message ? "\r\nMessage:\r\n{$message}" : '');

    $mail->send();
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
