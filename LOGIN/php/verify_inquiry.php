<?php
session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/inquiry_otp.php';
require_once __DIR__ . '/../../config/inquiry_contact_validation.php';

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$message = '';
$error = '';

function verify_inquiry_redirect_home(string $status): void
{
    header('Location: /codesamplecaps/LOGIN/php/index.php?inquiry=' . rawurlencode($status));
    exit();
}

if ($token === '') {
    verify_inquiry_redirect_home('invalid');
}

$stmt = $conn->prepare(
    'SELECT * FROM pending_service_inquiries
     WHERE token = ?
     LIMIT 1'
);
$stmt->bind_param('s', $token);
$stmt->execute();
$pending = $stmt->get_result()->fetch_assoc();

if (!$pending) {
    verify_inquiry_redirect_home('invalid');
}

if (!empty($pending['verified_at'])) {
    verify_inquiry_redirect_home('success');
}

$otpExpiresAt = DateTimeImmutable::createFromFormat(
    'Y-m-d H:i:s',
    (string)($pending['expires_at'] ?? ''),
    new DateTimeZone('Asia/Manila')
);
if (!$otpExpiresAt) {
    verify_inquiry_redirect_home('invalid');
}

if ($otpExpiresAt->getTimestamp() < time()) {
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
        $error = 'Invalid or expired code. Please try again.';
    } else {
        $payload = json_decode((string)$pending['payload_json'], true);
        if (!is_array($payload)) {
            verify_inquiry_redirect_home('invalid');
        }

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
        $contactNo = normalize_ph_mobile((string)($payload['contact_no'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !is_valid_ph_mobile($contactNo)) {
            verify_inquiry_redirect_home('invalid');
        }

        $contactValidation = inquiry_contact_validation_result($conn, $clientName, $email, $contactNo);
        if (!$contactValidation['valid']) {
            verify_inquiry_redirect_home((string)$contactValidation['status']);
        }

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
    <link rel="icon" type="image/x-icon" href="../../IMAGES/edge.jpg">
    <link rel="stylesheet" href="../css/auth-shared.css">
    <link rel="stylesheet" href="../css/verify_inquiry.css">
</head>
<body>
    <?php if (($_GET['sent'] ?? '') === '1'): ?>
        <div class="verify-toast verify-toast-success" id="verifySentToast">
            Verification code sent. Please check your email.
        </div>
    <?php endif; ?>
    <div class="container verify-inquiry-shell">
        <div class="left-panel verify-inquiry-brand">
            <div class="logo verify-inquiry-logo">
                <img src="../../IMAGES/edge.jpg" alt="Edge Automation logo">
            </div>
            <h1 class="company-name">EDGE AUTOMATION</h1>
            <p>Secure inquiry verification</p>
        </div>
        <div class="right-panel">
            <div class="form active verify-inquiry-card">
                <form method="POST" id="verifyInquiryForm" data-otp-expires-at="<?php echo htmlspecialchars($otpExpiresAt->format(DateTimeInterface::ATOM), ENT_QUOTES, 'UTF-8'); ?>">
                    <h2>Verify Inquiry</h2>
                    <p class="auth-helper-text">We sent a 6-digit code to your email. Enter it here to submit your inquiry.</p>
                    <div class="verify-next-step" aria-label="What happens next">
                        <strong>What happens next?</strong>
                        <span>After verification, Admin will review your request and contact you by call or email.</span>
                    </div>
                    <?php if ($error): ?><div class="error-box"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                    <?php if ($message): ?><div class="success-box"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                    <label class="floating-field verify-code-field">
                        <input class="js-otp-code" type="text" name="otp" inputmode="numeric" maxlength="6" pattern="\d{6}" placeholder=" " autocomplete="one-time-code" required autofocus>
                        <span>6-digit code</span>
                    </label>
                    <p class="verify-otp-countdown" data-otp-countdown aria-live="polite">Code expires in: --:--</p>
                    <button type="submit" id="verifyInquiryButton">Verify and Submit</button>
                    <div class="links">
                        <a href="/codesamplecaps/LOGIN/php/index.php" id="backToHomeLink">Back to Home</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script src="../js/verify_inquiry.js" defer></script>
</body>
</html>
