<?php
session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/inquiry_otp.php';

inquiry_otp_ensure_table($conn);

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$message = '';
$error = '';

function verify_inquiry_redirect_home(string $status): void
{
    header('Location: /codesamplecaps/LOGIN/php/index.php?inquiry=' . rawurlencode($status) . '#contact');
    exit();
}

function verify_inquiry_ensure_columns(mysqli $conn): void
{
    $conn->query(
        'CREATE TABLE IF NOT EXISTS service_inquiries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_name VARCHAR(150) NOT NULL,
            company_name VARCHAR(150) DEFAULT NULL,
            email VARCHAR(150) NOT NULL,
            contact_no VARCHAR(30) NOT NULL,
            province VARCHAR(80) DEFAULT NULL,
            city_municipality VARCHAR(120) DEFAULT NULL,
            barangay VARCHAR(150) DEFAULT NULL,
            site_address TEXT NOT NULL,
            service_category VARCHAR(80) NOT NULL,
            description TEXT NOT NULL,
            preferred_inspection_date DATE DEFAULT NULL,
            status VARCHAR(50) NOT NULL DEFAULT "Pending Review",
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );

    $columns = [
        'province' => 'ALTER TABLE service_inquiries ADD COLUMN province VARCHAR(80) NULL AFTER contact_no',
        'city_municipality' => 'ALTER TABLE service_inquiries ADD COLUMN city_municipality VARCHAR(120) NULL AFTER province',
        'barangay' => 'ALTER TABLE service_inquiries ADD COLUMN barangay VARCHAR(150) NULL AFTER city_municipality',
    ];

    foreach ($columns as $column => $sql) {
        $stmt = $conn->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = "service_inquiries"
             AND COLUMN_NAME = ?
             LIMIT 1'
        );
        if (!$stmt) {
            continue;
        }

        $stmt->bind_param('s', $column);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            $conn->query($sql);
        }
    }
}

if ($token === '') {
    verify_inquiry_redirect_home('invalid');
}

$stmt = $conn->prepare(
    'SELECT * FROM pending_service_inquiries
     WHERE token = ? AND verified_at IS NULL
     LIMIT 1'
);
$stmt->bind_param('s', $token);
$stmt->execute();
$pending = $stmt->get_result()->fetch_assoc();

if (!$pending) {
    verify_inquiry_redirect_home('invalid');
}

if (strtotime((string)$pending['expires_at']) < time()) {
    verify_inquiry_redirect_home('expired');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp = trim((string)($_POST['otp'] ?? ''));

    if (!preg_match('/^\d{6}$/', $otp)) {
        $error = 'Enter the 6-digit code.';
    } elseif ((int)$pending['attempts'] >= 5) {
        $error = 'Too many tries. Please submit the inquiry again.';
    } elseif (!password_verify($otp, (string)$pending['otp_hash'])) {
        $update = $conn->prepare('UPDATE pending_service_inquiries SET attempts = attempts + 1 WHERE id = ?');
        $pendingId = (int)$pending['id'];
        $update->bind_param('i', $pendingId);
        $update->execute();
        $error = 'Wrong code. Please try again.';
    } else {
        $payload = json_decode((string)$pending['payload_json'], true);
        if (!is_array($payload)) {
            verify_inquiry_redirect_home('invalid');
        }

        verify_inquiry_ensure_columns($conn);
        $insert = $conn->prepare(
            'INSERT INTO service_inquiries (
                client_name, company_name, email, contact_no, province,
                city_municipality, barangay, site_address, service_category,
                description, preferred_inspection_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $clientName = (string)($payload['client_name'] ?? '');
        $companyName = $payload['company_name'] ?? null;
        $email = (string)($payload['email'] ?? '');
        $contactNo = (string)($payload['contact_no'] ?? '');
        $province = (string)($payload['province'] ?? '');
        $cityMunicipality = (string)($payload['city_municipality'] ?? '');
        $barangay = (string)($payload['barangay'] ?? '');
        $siteAddress = (string)($payload['site_address'] ?? '');
        $serviceCategory = (string)($payload['service_category'] ?? '');
        $description = (string)($payload['description'] ?? '');
        $preferredInspectionDate = $payload['preferred_inspection_date'] ?? null;

        $insert->bind_param(
            'sssssssssss',
            $clientName,
            $companyName,
            $email,
            $contactNo,
            $province,
            $cityMunicipality,
            $barangay,
            $siteAddress,
            $serviceCategory,
            $description,
            $preferredInspectionDate
        );

        if ($insert->execute()) {
            $pendingId = (int)$pending['id'];
            $done = $conn->prepare('UPDATE pending_service_inquiries SET verified_at = NOW() WHERE id = ?');
            $done->bind_param('i', $pendingId);
            $done->execute();
            verify_inquiry_redirect_home('success');
        }

        $error = 'Could not save inquiry. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Inquiry - Edge Automation</title>
    <link rel="stylesheet" href="../css/auth-shared.css">
</head>
<body>
    <div class="container">
        <div class="right-panel">
            <div class="form active">
                <form method="POST">
                    <h2>Verify Inquiry</h2>
                    <p class="auth-helper-text">We sent a 6-digit code to your email. Enter it to submit your inquiry.</p>
                    <?php if ($error): ?><div class="error-box"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                    <?php if ($message): ?><div class="success-box"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="text" name="otp" inputmode="numeric" maxlength="6" pattern="\d{6}" placeholder="6-digit code" required autofocus>
                    <button type="submit">Verify and Submit</button>
                    <div class="links">
                        <a href="/codesamplecaps/LOGIN/php/index.php#contact">Back to Home</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
