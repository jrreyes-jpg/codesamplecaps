<?php
    require_once __DIR__ . '/../../config/auth_middleware.php';
    require_once __DIR__ . '/../../config/database.php';

    auth_start_session();
    auth_apply_no_cache_headers();

    $error = "";
    $failed_attempts_display = "";

    if (isset($_GET['timeout'])) {
        $error = "Your session expired after 15 minutes of inactivity. Please log in again.";
    } elseif (isset($_GET['logout'])) {
        $error = "You have been logged out successfully.";
    } else {
        auth_redirect_authenticated_user();
    }

    $max_attempts = 10;
$lockout_time = 15 * 60;
$ip_address = $_SERVER['REMOTE_ADDR'];

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login'])) {

    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
$error = "Invalid email or password.";
    if (!empty($email) && !empty($password)) {

        $checkAttempt = $conn->prepare("SELECT attempts, last_attempt FROM login_attempts WHERE email = ? AND ip_address = ?");
        $checkAttempt->bind_param("ss", $email, $ip_address);
        $checkAttempt->execute();
        $attemptResult = $checkAttempt->get_result();

        $attempts = 0;
        $last_attempt = null;

        if ($attemptResult->num_rows === 1) {
            $attemptData = $attemptResult->fetch_assoc();
            $attempts = (int)$attemptData['attempts'];
            $last_attempt = $attemptData['last_attempt'];

            if ($attempts >= $max_attempts && strtotime($last_attempt) + $lockout_time > time()) {
$error = "Too many failed login attempts.<br><br>
Your account has been temporarily locked for 15 minutes for security reasons.";
$failed_attempts_display = "Attempt $attempts of $max_attempts.<br>
Account will be temporarily locked after $max_attempts failed attempts.";
                goto end_login;
            }
        }

        $stmt = $conn->prepare("SELECT id, full_name, password, role FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {

            $user = $result->fetch_assoc();

            if (password_verify($password, $user['password'])) {

                $deleteAttempts = $conn->prepare("DELETE FROM login_attempts WHERE email = ? AND ip_address = ?");
                $deleteAttempts->bind_param("ss", $email, $ip_address);
                $deleteAttempts->execute();

                $dashboardPath = auth_dashboard_path_for_role($user['role']);
                if ($dashboardPath === null) {
                    $error = "Your account role is not allowed to access this system.";
                    goto end_login;
                }

                auth_login_user($user);
                header('Location: ' . $dashboardPath);
                exit();

            } else {
                $attempts++;
            }

        } else {
            $attempts++;
        }

        if ($attemptResult->num_rows === 1) {

            $updateAttempt = $conn->prepare("UPDATE login_attempts SET attempts = ?, last_attempt = NOW() WHERE email = ? AND ip_address = ?");
            $updateAttempt->bind_param("iss", $attempts, $email, $ip_address);
            $updateAttempt->execute();

        } else {

            $insertAttempt = $conn->prepare("INSERT INTO login_attempts (email, ip_address, attempts, last_attempt) VALUES (?, ?, ?, NOW())");
            $insertAttempt->bind_param("ssi", $email, $ip_address, $attempts);
            $insertAttempt->execute();
        }

        $failed_attempts_display = "Failed attempts: $attempts / $max_attempts";

    } else {
        $error = "Please fill in all fields.";
    }
}

end_login:
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edge Automation Portal</title>
    <link rel="icon" type="image/x-icon" href="../../IMAGES/edge.jpg">

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
<link rel="stylesheet" href="../css/login.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    </head>

    <body>
        <a href="../php/index.php"
class="back-home animate__animated animate__fadeInDown">
← Back to Home
</a>

    <canvas id="particles"></canvas>

    <div class="container">

    <div class="left-panel">
    <div class="logo" id="logo3d">
        <img src="../../IMAGES/edge.jpg" alt="Edge Logo">
    </div>
    <h1 class="company-name">
        EDGE AUTOMATION TECHNOLOGY SERVICES, CO.
    </h1></div>

    <div class="right-panel">

        <!-- LOGIN FORM -->
        <div class="form active" id="loginForm">
            <form method="POST">
                <div class="mobile-login-brand">
                    <img src="../../IMAGES/edge.jpg" alt="Edge Automation logo">
                    <strong>EDGE Automation</strong>
                    <span>Secure Portal</span>
                </div>
                <h2>Login</h2>
    <?php if($error): ?>
<div class="error-box <?php echo ($attempts >= 8 ? 'error-warning' : ($attempts >= $max_attempts ? 'error-locked' : '')); ?>">
                <?php echo $error; ?>
            <?php if(!empty($failed_attempts_display)): ?>
                <div style="margin-top:5px; font-size:13px; color:#800000;">
                    <?php echo $failed_attempts_display; ?>
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
</div>         </form>
        </div>

    </div>

    </div>

    <script src="../js/login.js"></script>
    </body>
    </html>
