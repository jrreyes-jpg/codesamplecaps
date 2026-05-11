<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

session_start();

require_once __DIR__ . '/../../vendor/autoload.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// CSRF check (if token is present in session/form)
if (isset($_SESSION['csrf_token'])) {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        http_response_code(400);
        exit('Invalid request token.');
    }
}

// Basic sanitization and validation
$name = trim((string) ($_POST['name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));

$name = strip_tags($name);
$message = strip_tags($message);

if ($name === '' || $email === '' || $message === '') {
    http_response_code(422);
    exit('Please complete all required fields.');
}

if (preg_match('/[\r\n]/', $name) || preg_match('/[\r\n]/', $email)) {
    http_response_code(400);
    exit('Invalid input detected.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    exit('Please provide a valid email address.');
}

if (mb_strlen($name) > 100 || mb_strlen($email) > 254 || mb_strlen($message) > 5000) {
    http_response_code(422);
    exit('Input is too long.');
}

// SMTP credentials from environment variables (recommended for security)
$smtpUser = getenv('GMAIL_SMTP_USER') ?: 'ejimenez.edge@gmail.com';
$smtpPass = getenv('GMAIL_SMTP_APP_PASSWORD') ?: '';

if ($smtpPass === '') {
    http_response_code(500);
    exit('Email service is not configured.');
}

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = $smtpUser;
    $mail->Password = $smtpPass; // Gmail App Password (NOT your normal password)
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    $mail->setFrom('ejimenez.edge@gmail.com', 'Edge Automation');
    $mail->addAddress('ejimenez.edge@gmail.com');
    $mail->addReplyTo($email, $name);

    $mail->isHTML(true);
    $mail->Subject = 'New Client Inquiry - Edge Automation';
    $mail->Body =
        '<h3>New Client Inquiry</h3>' .
        '<p><strong>Client Name:</strong> ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</p>' .
        '<p><strong>Client Email:</strong> ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</p>' .
        '<p><strong>Client Message:</strong><br>' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</p>';

    $mail->AltBody =
        "New Client Inquiry\n\n" .
        "Client Name: {$name}\n" .
        "Client Email: {$email}\n\n" .
        "Client Message:\n{$message}\n";

    $mail->send();

    http_response_code(200);
    echo 'Message sent successfully.';
} catch (Exception $e) {
    http_response_code(500);
    echo 'Unable to send message right now. Please try again later.';
}
