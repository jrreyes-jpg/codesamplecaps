<?php
session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/inquiry_otp.php';
require_once __DIR__ . '/../../config/inquiry_contact_validation.php';
require_once __DIR__ . '/../../services/EmailService.php';

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$message = '';
$error = '';
$isOtpExpired = false;

function verify_inquiry_redirect_home(string $status): void
{
    header('Location: /codesamplecaps/LOGIN/php/index.php?inquiry=' . rawurlencode($status));
    exit();
}

function verify_inquiry_redirect_verify(string $token, string $notice = ''): void
{
    $query = $notice === '' ? [] : ['otp_notice' => $notice];
    inquiry_otp_redirect('verify', $token, $query);
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

$timezone = new DateTimeZone('Asia/Manila');
$otpExpiresAt = DateTimeImmutable::createFromFormat(
    'Y-m-d H:i:s',
    (string)($pending['expires_at'] ?? ''),
    $timezone
);
if (!$otpExpiresAt) {
    verify_inquiry_redirect_home('invalid');
}

$maxAttempts = inquiry_otp_max_attempts();
$maxResends = inquiry_otp_max_resends();
$resendCooldownSeconds = inquiry_otp_resend_cooldown_seconds();
$now = new DateTimeImmutable('now', $timezone);
$isOtpExpired = $otpExpiresAt->getTimestamp() < $now->getTimestamp();
$isOtpLocked = (int)($pending['attempts'] ?? 0) >= $maxAttempts;
$resendCount = (int)($pending['resend_count'] ?? 0);
$resendLimitReached = $resendCount >= $maxResends;
$lastResendAt = !empty($pending['last_resend_at'])
    ? DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$pending['last_resend_at'], $timezone)
    : null;
$resendCooldownRemaining = $lastResendAt
    ? max(0, $resendCooldownSeconds - ($now->getTimestamp() - $lastResendAt->getTimestamp()))
    : 0;
$verificationUnavailable = $isOtpExpired || $isOtpLocked;
$resendCooldownUntil = $lastResendAt
    ? $lastResendAt->modify('+' . $resendCooldownSeconds . ' seconds')
    : null;
$otpState = $isOtpLocked ? 'locked' : ($isOtpExpired ? 'expired' : 'active');

if ($verificationUnavailable && $resendLimitReached) {
    $error = 'Verification limit reached. Please start a new inquiry.';
} elseif ($isOtpExpired) {
    $isOtpExpired = true;
    $error = 'Verification code has expired. Please request a new verification code.';
} elseif ($isOtpLocked) {
    $error = 'Too many incorrect attempts. Please request a new verification code.';
}

if (($_GET['resent'] ?? '') === '1') {
    $message = 'A new verification code was sent to your email.';
}

$otpNotice = trim((string)($_GET['otp_notice'] ?? ''));
if (!$verificationUnavailable && $otpNotice === 'invalid') {
    $error = 'Invalid verification code. Please check the 6-digit code and try again.';
} elseif (!$verificationUnavailable && $otpNotice === 'format') {
    $error = 'Enter the 6-digit code.';
} elseif ($otpNotice === 'resend_wait') {
    $error = 'Please wait before requesting another verification code.';
} elseif ($otpNotice === 'resend_failed') {
    $error = 'Could not send a new verification code. Please try again.';
} elseif ($otpNotice === 'save_failed') {
    $error = 'Could not save inquiry. Please try again.';
}

$requestAction = trim((string)($_POST['action'] ?? 'verify'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $requestAction === 'resend') {
    if (!$verificationUnavailable) {
        verify_inquiry_redirect_verify($token);
    } elseif ($resendLimitReached) {
        verify_inquiry_redirect_verify($token);
    } elseif ($resendCooldownRemaining > 0) {
        verify_inquiry_redirect_verify($token, 'resend_wait');
    } else {
        $payload = json_decode((string)($pending['payload_json'] ?? ''), true);
        $clientName = is_array($payload) ? trim((string)($payload['client_name'] ?? '')) : '';
        $email = is_array($payload) ? trim((string)($payload['email'] ?? '')) : '';

        if ($clientName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            verify_inquiry_redirect_verify($token, 'resend_failed');
        } else {
            $newOtp = (string)random_int(100000, 999999);
            $newOtpHash = password_hash($newOtp, PASSWORD_DEFAULT);

            try {
                $conn->begin_transaction();
                $resendUpdate = $conn->prepare(
                    'UPDATE pending_service_inquiries
                     SET otp_hash = ?, attempts = 0, expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE),
                         resend_count = resend_count + 1, last_resend_at = NOW()
                     WHERE id = ? AND verified_at IS NULL
                       AND resend_count < ?
                       AND (attempts >= ? OR expires_at < NOW())
                       AND (last_resend_at IS NULL OR last_resend_at <= DATE_SUB(NOW(), INTERVAL 60 SECOND))'
                );
                $pendingId = (int)$pending['id'];
                $resendUpdate->bind_param('siii', $newOtpHash, $pendingId, $maxResends, $maxAttempts);
                $resendUpdate->execute();

                if ($resendUpdate->affected_rows !== 1) {
                    $conn->rollback();
                    verify_inquiry_redirect_verify($token, 'resend_wait');
                } else {
                    $expiryStmt = $conn->prepare('SELECT expires_at FROM pending_service_inquiries WHERE id = ? LIMIT 1');
                    $expiryStmt->bind_param('i', $pendingId);
                    $expiryStmt->execute();
                    $expiryRow = $expiryStmt->get_result()->fetch_assoc();
                    $newOtpExpiresAt = DateTimeImmutable::createFromFormat(
                        'Y-m-d H:i:s',
                        (string)($expiryRow['expires_at'] ?? ''),
                        $timezone
                    );

                    if (!$newOtpExpiresAt || !(new EmailService())->sendInquiryOtp($email, $clientName, $newOtp, $newOtpExpiresAt)) {
                        $conn->rollback();
                        verify_inquiry_redirect_verify($token, 'resend_failed');
                    } else {
                        $conn->commit();
                        inquiry_otp_redirect('verify', $token, ['resent' => '1']);
                    }
                }
            } catch (Throwable $exception) {
                $conn->rollback();
                error_log('Inquiry OTP resend failed: ' . $exception->getMessage());
                verify_inquiry_redirect_verify($token, 'resend_failed');
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !$verificationUnavailable) {
    $otp = trim((string)($_POST['otp'] ?? ''));

    if (!preg_match('/^\d{6}$/', $otp)) {
        verify_inquiry_redirect_verify($token, 'format');
    } elseif (!password_verify($otp, (string)$pending['otp_hash'])) {
        $update = $conn->prepare(
            'UPDATE pending_service_inquiries
             SET attempts = attempts + 1
             WHERE id = ? AND attempts < ? AND verified_at IS NULL'
        );
        $pendingId = (int)$pending['id'];
        $update->bind_param('ii', $pendingId, $maxAttempts);
        $update->execute();
        $failedAttempts = (int)($pending['attempts'] ?? 0) + 1;
        if ($update->affected_rows !== 1 || $failedAttempts >= $maxAttempts) {
            verify_inquiry_redirect_verify($token);
        } else {
            verify_inquiry_redirect_verify($token, 'invalid');
        }
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

        verify_inquiry_redirect_verify($token, 'save_failed');
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_inquiry_redirect_verify($token);
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
    <link rel="stylesheet" href="../css/public-confirmation.css">
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
                <form method="POST" id="verifyInquiryForm" data-otp-expires-at="<?php echo htmlspecialchars($otpExpiresAt->format(DateTimeInterface::ATOM), ENT_QUOTES, 'UTF-8'); ?>" data-otp-state="<?php echo htmlspecialchars($otpState, ENT_QUOTES, 'UTF-8'); ?>">
                    <h2>Verify Inquiry</h2>
                    <p class="auth-helper-text">We sent a 6-digit code to your email. Enter it here to submit your inquiry.</p>
                    <div class="verify-next-step" aria-label="What happens next">
                        <strong>What happens next?</strong>
                        <span>After verification, Admin will review your request and contact you by call or email.</span>
                    </div>
                    <?php if ($error): ?><div class="error-box"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                    <?php if ($message): ?><div class="success-box"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="verify" id="verifyInquiryAction">
                    <label class="floating-field verify-code-field">
                        <input class="js-otp-code" type="text" name="otp" inputmode="numeric" maxlength="6" pattern="\d{6}" placeholder=" " autocomplete="one-time-code" required autofocus<?php echo $verificationUnavailable ? ' disabled' : ''; ?>>
                        <span>6-digit code</span>
                    </label>
                    <p class="verify-otp-countdown" data-otp-countdown aria-live="polite">Code expires in: --:--</p>
                    <button type="submit" id="verifyInquiryButton"<?php echo $verificationUnavailable ? ' disabled' : ''; ?>><?php echo $isOtpLocked ? 'Verification code locked' : ($isOtpExpired ? 'Verification code expired' : 'Verify and Submit'); ?></button>
                    <?php if ($verificationUnavailable): ?>
                        <div class="verify-resend" aria-live="polite">
                            <?php if ($resendLimitReached): ?>
                                <p class="verify-resend__message">Verification limit reached. Please start a new inquiry.</p>
                            <?php else: ?>
                                <p class="verify-resend__cooldown" data-resend-cooldown-until="<?php echo htmlspecialchars($resendCooldownUntil?->format(DateTimeInterface::ATOM) ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php if ($resendCooldownRemaining > 0): ?>
                                        Send new code in <?php echo sprintf('%02d:%02d', intdiv($resendCooldownRemaining, 60), $resendCooldownRemaining % 60); ?>
                                    <?php else: ?>
                                        Request a new verification code.
                                    <?php endif; ?>
                                </p>
                                <button type="submit" id="resendInquiryOtpButton" data-action="resend" formnovalidate<?php echo $resendCooldownRemaining > 0 ? ' disabled' : ''; ?>>Send New Code</button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="links">
                        <a href="/codesamplecaps/LOGIN/php/index.php" id="backToHomeLink">Back to Home</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script src="../js/public-confirmation.js" defer></script>
    <script src="../js/verify_inquiry.js" defer></script>
</body>
</html>
