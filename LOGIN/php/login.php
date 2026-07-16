<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';

auth_start_session();
auth_apply_no_cache_headers();

$config = Config::getInstance();
$max_attempts = (int)$config->get('LOGIN_MAX_ATTEMPTS', 1);
$lockout_minutes = (int)$config->get('LOGIN_LOCKOUT_MINUTES', 1);
$lockout_time = $lockout_minutes * 60;
$ip_address = $_SERVER['REMOTE_ADDR'] ?? '';

$error = '';
$failed_attempts_display = '';
$attempts = 0;
$error_class = '';
$login_flash = login_consume_flash();

if (isset($_GET['timeout'])) {
    $error = 'Your session expired after 15 minutes of inactivity. Please log in again.';
} elseif (isset($_GET['logout'])) {
    $error = 'You have been logged out successfully.';
} else {
    auth_redirect_authenticated_user();
    if ($login_flash['error'] !== '') {
        $error = $login_flash['error'];
        $failed_attempts_display = $login_flash['attempts_display'];
        $error_class = $login_flash['class'];
    }
}

function login_set_flash(string $error, string $attemptsDisplay = '', string $class = ''): void
{
    $_SESSION['login_flash'] = [
        'error' => $error,
        'attempts_display' => $attemptsDisplay,
        'class' => $class,
    ];
}

function login_consume_flash(): array
{
    $flash = $_SESSION['login_flash'] ?? [
        'error' => '',
        'attempts_display' => '',
        'class' => '',
    ];

    unset($_SESSION['login_flash']); // <-- Ito ang kulang

    return [
        'error' => (string)($flash['error'] ?? ''),
        'attempts_display' => (string)($flash['attempts_display'] ?? ''),
        'class' => (string)($flash['class'] ?? ''),
    ];
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
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $_SESSION['last_login_email'] = $email;

    if ($email === '' || $password === '') {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $attempt = login_get_attempt($conn, $email, $ip_address);
        $attempts = (int)($attempt['attempts'] ?? 0);
        $last_attempt = $attempt['last_attempt'] ?? null;

        if ($attempts >= $max_attempts) {
            $remaining_minutes = login_remaining_minutes($last_attempt, $lockout_time);
            if ($remaining_minutes > 0) {
                // Kapag locked na, huwag sabihin kung valid ba ang email para mas safe.
                $error = 'Too many attempts. Try again later or contact admin.';
                $failed_attempts_display = "Try again in about $remaining_minutes minute(s).";
                $error_class = 'error-locked';
            } else {
                login_clear_attempts($conn, $email, $ip_address);
                $attempts = 0;
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

            $passwordMatches = false;

            if ($user) {
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
            }

            if ($user && $passwordMatches) {
                if (($user['status'] ?? '') !== 'active') {
                    $error = 'Your account is inactive. Please contact the administrator.';
                } else {
                    login_clear_attempts($conn, $email, $ip_address);
                    unset($_SESSION['login_flash'], $_SESSION['last_login_email']);

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
                // Same message lagi kahit mali password, ibang account, o walang account.
                $attempts++;
                login_save_attempt($conn, $email, $ip_address, $attempts);

                if ($attempts >= $max_attempts) {
                    $error = 'Too many attempts. Try again later or contact admin.';
                    $failed_attempts_display = "Try again in about $lockout_minutes minute(s).";
                    $error_class = 'error-locked';
                } else {
                    $remaining = $max_attempts - $attempts;
                    $error = 'Invalid email or password.';
                    $failed_attempts_display = "Attempts left: $remaining";
                    $error_class = $attempts >= ($max_attempts - 2) ? 'error-warning' : '';
                }
            }
        }
    }

    if ($error !== '') {
        // Redirect after failed login para refresh ay hindi magdadagdag ng attempt.
        login_set_flash($error, $failed_attempts_display, $error_class);
        header('Location: /codesamplecaps/LOGIN/php/login.php');
        exit();
    }
}
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
                            <?php if ($failed_attempts_display !== ''): ?>
                                <div style="margin-top:5px; font-size:13px; color:#800000;">
                                    <?php echo htmlspecialchars($failed_attempts_display, ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <input type="email" name="email" placeholder="Email" required>

                    <div class="password-wrapper">
                        <input id="password" type="password" name="password" placeholder="Password" required>
                        <button type="button" class="togglePassword" data-target="password">Show</button>
                    </div>

                    <button type="submit" name="login" data-loading-text="Logging in...">Login</button>

                    <div class="links">
                        <a href="/codesamplecaps/LOGIN/php/forgot.php">Forgot Password?</a>
                        <a href="/codesamplecaps/LOGIN/php/index.php#contact" class="login-help-text">No account yet? Contact Admin.</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="../js/login.js"></script>
</body>
</html>
