<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';

auth_start_session();
auth_apply_no_cache_headers();

$config = Config::getInstance();
$max_attempts = (int)$config->get('LOGIN_MAX_ATTEMPTS', 5);
// Maximum failed attempts allowed from one IP address
$max_ip_attempts = 15;
$lockout_minutes = (int)$config->get('LOGIN_LOCKOUT_MINUTES', 15);
$lockout_time = $lockout_minutes * 60;
$ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
$ip_only_attempt_key = '__ip_only__';

$error = '';
$failed_attempts_display = '';
$attempts_left = null;
$attempts = 0;
$error_class = '';
$lock_type = '';
$login_flash = login_consume_flash();

/*
|--------------------------------------------------------------------------
| Restore remaining lockout seconds from flash session
|--------------------------------------------------------------------------
| After redirecting back to the login page, recover the remaining
| lockout time so JavaScript can continue the countdown.
|--------------------------------------------------------------------------
*/
$remaining_seconds = $login_flash['remaining_seconds'] ?? 0;
$flash_email = filter_var((string)($login_flash['email'] ?? ''), FILTER_VALIDATE_EMAIL)
    ? (string)$login_flash['email']
    : '';

if (isset($_GET['timeout'])) {
    $error = 'Your session expired after 15 minutes of inactivity. Please log in again.';
} elseif (isset($_GET['logout'])) {
    $error = 'You have been logged out successfully.';
    $error_class = 'error-danger';
} else {
    auth_redirect_authenticated_user();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $login_flash['error'] !== '') {
        $error = $login_flash['error'];
        $failed_attempts_display = $login_flash['attempts_display'];
        $attempts_left = $login_flash['attempts_left'];
        $error_class = $login_flash['class'];
        $lock_type = $login_flash['lock_type'];

        // Kapag lock message ito, database time ang kunin para hindi bumalik sa 15:00 sa refresh.
        if ($error_class === 'error-locked' && $lock_type === 'ip') {
            $ip_attempt_summary = login_get_ip_attempt_summary($conn, $ip_address);
            $remaining_seconds = login_remaining_seconds($ip_attempt_summary['last_attempt'] ?? null, $lockout_time);

            if ($remaining_seconds <= 0) {
                login_clear_ip_attempts($conn, $ip_address);
                unset($_SESSION['login_flash']);
                $error = '';
                $error_class = '';
                $lock_type = '';
            }
        } elseif ($error_class === 'error-locked' && $lock_type === 'email') {
            $lockedEmail = strtolower(trim((string)($login_flash['email'] ?? '')));
            $attempt = filter_var($lockedEmail, FILTER_VALIDATE_EMAIL)
                ? login_get_attempt($conn, $lockedEmail, $ip_address)
                : null;
            $remaining_seconds = $attempt
                ? login_remaining_seconds($attempt['last_attempt'] ?? null, $lockout_time)
                : 0;

            if ($remaining_seconds <= 0) {
                if ($attempt) {
                    login_clear_attempts($conn, $lockedEmail, $ip_address);
                }
                unset($_SESSION['login_flash']);
                $error = '';
                $error_class = '';
                $lock_type = '';
            }
        }

        // Non-lock errors should show once only. Fresh open/new tab stays blank.
        if ($error_class !== 'error-locked') {
            unset($_SESSION['login_flash']);
        }
    }
}

// Kahit refresh/hard refresh/new tab, database time ang susundin para tuloy-tuloy ang countdown.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $ip_attempt_summary = login_get_ip_attempt_summary($conn, $ip_address);

    if ((int)$ip_attempt_summary['attempts'] >= $max_ip_attempts) {
        $remaining_seconds = login_remaining_seconds($ip_attempt_summary['last_attempt'] ?? null, $lockout_time);

        if ($remaining_seconds > 0) {
            $error = 'This device has been temporarily locked due to multiple failed login attempts.';
            $failed_attempts_display = '';
            $error_class = 'error-locked';
            $lock_type = 'ip';
        } else {
            login_clear_ip_attempts($conn, $ip_address);
            $remaining_seconds = 0;
        }
    }
}

function login_set_flash(
    string $error,
    string $attemptsDisplay = '',
    string $class = '',
    int $remainingSeconds = 0,
    string $lockType = '',
    ?int $attemptsLeft = null,
    string $email = ''
): void
{
   $_SESSION['login_flash'] = [
    'error' => $error,
    'attempts_display' => $attemptsDisplay,
    'class' => $class,
    'remaining_seconds' => $remainingSeconds,
    'lock_type' => $lockType,
    'attempts_left' => $attemptsLeft,
    'email' => $email,
];
}

function login_consume_flash(): array
{
    $flash = $_SESSION['login_flash'] ?? [
        'error' => '',
        'attempts_display' => '',
        'class' => '',
        'remaining_seconds' => 0,
        'lock_type' => '',
        'attempts_left' => null,
        'email' => '',
    ];

    return [
        'error' => (string)($flash['error'] ?? ''),
        'attempts_display' => (string)($flash['attempts_display'] ?? ''),
        'class' => (string)($flash['class'] ?? ''),
        'remaining_seconds' => (int)($flash['remaining_seconds'] ?? 0),
        'lock_type' => (string)($flash['lock_type'] ?? ''),
        'attempts_left' => isset($flash['attempts_left']) ? (int)$flash['attempts_left'] : null,
        'email' => (string)($flash['email'] ?? ''),
    ];
}

function login_remember_email(string $email): void
{
    if ($email === '') {
        return;
    }

    setcookie('edge_last_login_email', $email, [
        'expires' => time() + (30 * 24 * 60 * 60),
        'path' => '/codesamplecaps',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function login_get_remembered_email(): string
{
    $email = strtolower(trim((string)($_COOKIE['edge_last_login_email'] ?? '')));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function login_clear_remembered_email(): void
{
    setcookie('edge_last_login_email', '', [
        'expires' => time() - 3600,
        'path' => '/codesamplecaps',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function login_remaining_seconds(?string $lastAttempt, int $lockoutTime): int
{
    $lastAttemptTime = $lastAttempt ? strtotime($lastAttempt) : false;
    if ($lastAttemptTime === false) {
        return 0;
    }

    return max(0, ($lastAttemptTime + $lockoutTime) - time());
}

function login_get_attempt(mysqli $conn, string $email, string $ipAddress): ?array
{
    $stmt = $conn->prepare(
        'SELECT attempts, last_attempt
         FROM login_attempts
         WHERE email = ? AND ip_address = ?
         ORDER BY last_attempt DESC, id DESC
         LIMIT 1'
    );
    $stmt->bind_param('ss', $email, $ipAddress);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return $row ?: null;
}

function login_get_ip_attempt_summary(mysqli $conn, string $ipAddress): array
{
    $stmt = $conn->prepare(
        'SELECT SUM(attempts) AS total_attempts, MAX(last_attempt) AS last_attempt
         FROM login_attempts
         WHERE ip_address = ?'
    );
    $stmt->bind_param('s', $ipAddress);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];

    return [
        'attempts' => (int)($row['total_attempts'] ?? 0),
        'last_attempt' => $row['last_attempt'] ?? null,
    ];
}
// =========================================================
// Get total failed attempts from the same IP address
// =========================================================
function login_get_ip_attempts(mysqli $conn, string $ipAddress): int
{
    $stmt = $conn->prepare(
        'SELECT SUM(attempts) AS total_attempts
         FROM login_attempts
         WHERE ip_address = ?'
    );
    $stmt->bind_param('s', $ipAddress);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return (int)($row['total_attempts'] ?? 0);
}

// =========================================================
// Clear all attempts from the same IP address
// =========================================================
function login_clear_ip_attempts(mysqli $conn, string $ipAddress): void
{
    $stmt = $conn->prepare('DELETE FROM login_attempts WHERE ip_address = ?');
    $stmt->bind_param('s', $ipAddress);
    $stmt->execute();
}

function login_clear_attempts(mysqli $conn, string $email, string $ipAddress): void
{
    $stmt = $conn->prepare('DELETE FROM login_attempts WHERE email = ? AND ip_address = ?');
    $stmt->bind_param('ss', $email, $ipAddress);
    $stmt->execute();
}

function login_save_attempt(mysqli $conn, string $email, string $ipAddress, int $attempts): void
{
    $stmt = $conn->prepare('UPDATE login_attempts SET attempts = ?, last_attempt = NOW() WHERE email = ? AND ip_address = ?');
    $stmt->bind_param('iss', $attempts, $email, $ipAddress);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        return;
    }

    $stmt = $conn->prepare('INSERT INTO login_attempts (email, ip_address, attempts, last_attempt) VALUES (?, ?, ?, NOW())');
    $stmt->bind_param('ssi', $email, $ipAddress, $attempts);
    $stmt->execute();
}

function login_save_ip_only_attempt(mysqli $conn, string $ipAddress, string $ipOnlyKey): void
{
    // Para sa fake/non-existing email: device counter lang, walang fake email record.
    $attempt = login_get_attempt($conn, $ipOnlyKey, $ipAddress);
    $attempts = (int)($attempt['attempts'] ?? 0) + 1;
    login_save_attempt($conn, $ipOnlyKey, $ipAddress, $attempts);
}

function login_remaining_minutes(?string $lastAttempt, int $lockoutTime): int
{
    $lastAttemptTime = $lastAttempt ? strtotime($lastAttempt) : false;
    if ($lastAttemptTime === false) {
        return 0;
    }

    $remainingSeconds = ($lastAttemptTime + $lockoutTime) - time();
    return max(0, (int)ceil($remainingSeconds / 60));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Huwag hayaang lumang email lock flash ang mag-control sa bagong email submit.
    $error = '';
    $failed_attempts_display = '';
    $attempts_left = null;
    $error_class = '';
    $lock_type = '';
    $remaining_seconds = 0;

    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $_SESSION['last_login_email'] = $email;

    if ($email === '' || $password === '') {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Kapag sobra na ang failed login sa same device, i-lock din ito.
        $ip_attempt_summary = login_get_ip_attempt_summary($conn, $ip_address);
        $ip_attempts = (int)$ip_attempt_summary['attempts'];

        if ($ip_attempts >= $max_ip_attempts) {
            $remaining_seconds = login_remaining_seconds($ip_attempt_summary['last_attempt'] ?? null, $lockout_time);

            if ($remaining_seconds > 0) {
                $error = 'This device has been temporarily locked due to multiple failed login attempts.';
                $failed_attempts_display = '';
                $error_class = 'error-locked';
                $lock_type = 'ip';
            } else {
                login_clear_ip_attempts($conn, $ip_address);
            }
        }

        if ($error_class !== 'error-locked') {
            $stmt = $conn->prepare(
                "SELECT id, full_name, email, password, role, status
                 FROM users
                 WHERE LOWER(email) = ?
                 LIMIT 1"
            );
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            if (!$user) {
                login_save_ip_only_attempt($conn, $ip_address, $ip_only_attempt_key);

                $ip_attempt_summary = login_get_ip_attempt_summary($conn, $ip_address);
                if ((int)$ip_attempt_summary['attempts'] >= $max_ip_attempts) {
                    $remaining_seconds = login_remaining_seconds($ip_attempt_summary['last_attempt'] ?? null, $lockout_time);

                    if ($remaining_seconds > 0) {
                        $error = 'This device has been temporarily locked due to multiple failed login attempts.';
                        $failed_attempts_display = '';
                        $error_class = 'error-locked';
                        $lock_type = 'ip';
                    }
                }

                if ($error_class !== 'error-locked') {
                    $error = 'Invalid email or password.';
                    $failed_attempts_display = '';
                    $error_class = 'error-danger';
                }
            } else {
                $attempt = login_get_attempt($conn, $email, $ip_address);
                $attempts = (int)($attempt['attempts'] ?? 0);
                $last_attempt = $attempt['last_attempt'] ?? null;

                if ($attempts >= $max_attempts) {
                    $remaining_seconds = login_remaining_seconds($last_attempt, $lockout_time);

                    if ($remaining_seconds > 0) {
                        $error = 'This email is locked. Try again later or contact admin.';
                        $failed_attempts_display = '';
                        $error_class = 'error-locked';
                        $lock_type = 'email';
                    } else {
                        login_clear_attempts($conn, $email, $ip_address);
                        $attempts = 0;
                    }
                }

                if ($error_class !== 'error-locked') {
                    $passwordMatches = false;

                    $storedPassword = (string)$user['password'];
                    $passwordMatches = password_verify($password, $storedPassword);

                    $passwordInfo = password_get_info($storedPassword);

                    if (!$passwordMatches && empty($passwordInfo['algo'])) {
                        $passwordMatches = hash_equals($storedPassword, $password);

                        if ($passwordMatches) {
                            $newHash = password_hash($password, PASSWORD_DEFAULT);
                            $updatePassword = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
                            $updatePassword->bind_param('si', $newHash, $user['id']);
                            $updatePassword->execute();
                        }
                    }

                    if ($passwordMatches) {
                        if (($user['status'] ?? '') !== 'active') {
                            $error = 'Your account is inactive. Please contact the administrator.';
                        } else {
                            login_clear_attempts($conn, $email, $ip_address);
                            unset($_SESSION['login_flash'], $_SESSION['last_login_email']);
                            login_clear_remembered_email();

                            $dashboardPath = auth_dashboard_path_for_role($user['role'] ?? null);
                            if ($dashboardPath === null) {
                                $error = 'Your account role is not allowed to access this system.';
                            } else {
                                auth_login_user($user);
                                header('Location: ' . $dashboardPath);
                                exit();
                            }
                        }
                    } else {
                        $attempts++;
                        login_save_attempt($conn, $email, $ip_address, $attempts);

                        $ip_attempt_summary = login_get_ip_attempt_summary($conn, $ip_address);
                        if ((int)$ip_attempt_summary['attempts'] >= $max_ip_attempts) {
                            $remaining_seconds = login_remaining_seconds($ip_attempt_summary['last_attempt'] ?? null, $lockout_time);

                            if ($remaining_seconds > 0) {
                                $error = 'This device has been temporarily locked due to multiple failed login attempts.';
                                $failed_attempts_display = '';
                                $error_class = 'error-locked';
                                $lock_type = 'ip';
                            }
                        }

                        if ($error_class === 'error-locked' && $lock_type === 'ip') {
                            // Device lock has priority.
                        } elseif ($attempts >= $max_attempts) {
                            $remaining_seconds = $lockout_time;
                            $error = 'This email is locked. Try again later or contact admin.';
                            $failed_attempts_display = '';
                            $error_class = 'error-locked';
                            $lock_type = 'email';
                        } else {
                            $remaining = $max_attempts - $attempts;
                            $attempts_left = $remaining;

                            $error = 'Invalid email or password.';

                            $failed_attempts_display = "Attempts left for this email: $remaining";

                            $error_class = 'error-attempt-' . $remaining;
                        }
                    }
                }
            }
        }
    }

    if ($error !== '') {
        login_set_flash(
            $error,
            $failed_attempts_display,
            $error_class,
            $remaining_seconds ?? 0,
            $lock_type,
            $attempts_left,
            $email
        );

        header('Location: /codesamplecaps/LOGIN/php/login.php');
        exit();
    }
}

$is_device_locked = $error_class === 'error-locked' && $lock_type === 'ip';
$is_email_locked = $error_class === 'error-locked' && $lock_type === 'email';
$email_input_value = (!$is_device_locked && !$is_email_locked && $error !== '' && $flash_email !== '')
    ? $flash_email
    : '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edge Automation Portal</title>
    <link rel="icon" type="image/x-icon" href="../../IMAGES/edge.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
    <link rel="stylesheet" href="../css/login.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <a href="../php/index.php" class="back-home animate__animated animate__fadeInDown">
        &larr; Back to Home
    </a>

    <canvas id="particles"></canvas>

    <div class="container">
        <div class="left-panel">
            <div class="logo" id="logo3d">
                <img src="../../IMAGES/edge.jpg" alt="Edge Logo">
            </div>
            <h1 class="company-name">
                EDGE AUTOMATION TECHNOLOGY SERVICES, CO.
            </h1>
        </div>

        <div class="right-panel">
            <div class="form active" id="loginForm">
                <form method="POST">
                    <div class="mobile-login-brand">
                        <img src="../../IMAGES/edge.jpg" alt="Edge Automation logo">
                        <strong>EDGE Automation</strong>
                        <span>Secure Portal</span>
                    </div>
                    <h2>Login</h2>

                    <?php if ($error): ?>
                        <div class="error-box <?php echo htmlspecialchars($error_class, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                           <?php if ($error_class === 'error-locked'): ?>

    <div id="lockoutCountdown" class="lockout-countdown">
        <?php echo sprintf('%02d:%02d', intdiv((int)$remaining_seconds, 60), ((int)$remaining_seconds % 60)); ?>
    </div>

<?php elseif ($failed_attempts_display !== ''): ?>

    <div class="attempt-status" data-attempts-left="<?php echo htmlspecialchars((string)($attempts_left ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($failed_attempts_display, ENT_QUOTES, 'UTF-8'); ?>
    </div>

<?php endif; ?>
                        </div>
                    <?php endif; ?>

<input
    type="email"
    name="email"
    placeholder="Email"
    value="<?php echo htmlspecialchars($email_input_value, ENT_QUOTES, 'UTF-8'); ?>"
    required
    <?= $is_device_locked ? 'disabled' : ''; ?>
>
                    <div class="password-wrapper">
                      <input
    id="password"
    type="password"
    name="password"
    placeholder="Password"
    required
    <?= ($is_device_locked || $is_email_locked) ? 'disabled' : ''; ?>
>
                       <button
    type="button"
    class="togglePassword"
    data-target="password"
    <?= ($is_device_locked || $is_email_locked) ? 'disabled' : ''; ?>
>
    Show
</button>
                    </div>

                   <button
    type="submit"
    name="login"
    data-loading-text="Logging in..."
    <?= ($is_device_locked || $is_email_locked) ? 'disabled' : ''; ?>
>
    Login
</button>

                    <div class="links">
                        <a href="/codesamplecaps/LOGIN/php/forgot.php">Forgot Password?</a>
                        <a href="/codesamplecaps/LOGIN/php/index.php#contact" class="login-help-text">No account yet? Contact Admin.</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
<script>
window.lockoutConfig = {
    seconds: <?php echo (int)$remaining_seconds; ?>,
    lockType: <?php echo json_encode($lock_type); ?>
};
</script>
    <script src="../js/login.js"></script>
</body>
</html>
