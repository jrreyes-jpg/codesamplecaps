<?php
date_default_timezone_set('Asia/Manila');
session_start();

require_once __DIR__ . '/../../services/AuthService.php';

$error = "";
$success = "";
$token = trim($_GET['token'] ?? '');
$authService = new AuthService();

if (empty($token)) {
    $error = "Invalid or missing reset link.";
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && empty($error)) {
    $newPassword = trim($_POST['password'] ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');

    if (empty($newPassword) || empty($confirmPassword)) {
        $error = "All fields are required.";
    } elseif ($newPassword !== $confirmPassword) {
        $error = "Passwords do not match.";
    } elseif (strlen($newPassword) < 8) {
        $error = "Password must be at least 8 characters long.";
    } else {
        $result = $authService->resetPassword($token, $newPassword);

        if ($result['success']) {
            $success = $result['message'];
            $token = "";
        } else {
            $error = $result['error'];
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Edge Automation Portal</title>
    <link rel="stylesheet" href="../../SUPERADMIN/css/style.css">
    <link rel="stylesheet" href="../css/reset_password.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <canvas id="particles"></canvas>

    <div class="container">
        <div class="left-panel">
            <div class="logo">
                <img src="../../IMAGES/edge.jpg" alt="Edge Logo">
            </div>
            <h1 class="company-name">
                EDGE AUTOMATION TECHNOLOGY SERVICES, CO.
            </h1>
        </div>

        <div class="right-panel">
            <div class="form active">
                <form method="POST">
                    <h2>Create New Password</h2>

                    <?php if($error): ?>
                        <div class="error-box">
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <?php if($success): ?>
                        <div class="success-box">
                            <?php echo htmlspecialchars($success); ?>
                            <p class="success-link">
                                <a href="/codesamplecaps/LOGIN/php/login.php">
                                    Click here to login
                                </a>
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php if(!empty($token) && empty($success)): ?>
                        <p class="auth-helper-text">
                            Enter your new password below.
                        </p>

                        <div class="password-wrapper has-label">
                            <label for="password" class="password-label">New Password</label>
                            <input 
                                type="password" 
                                id="password" 
                                name="password" 
                                placeholder="Enter new password (min 8 characters)" 
                                required
                            >
                            <button type="button" class="togglePassword" data-target="password">Show</button>
                        </div>
                        <div class="password-strength">
                            <div class="strength" id="strengthBar"></div>
                        </div>
                        <small id="strengthText" class="strength-text"></small>

                        <div class="password-wrapper has-label">
                            <label for="confirm_password" class="password-label">Confirm Password</label>
                            <input 
                                type="password" 
                                id="confirm_password" 
                                name="confirm_password" 
                                placeholder="Confirm the password" 
                                required
                            >
                            <button type="button" class="togglePassword" data-target="confirm_password">Show</button>
                        </div>

                        <button type="submit" data-loading-text="Resetting password...">Reset Password</button>
                    <?php endif; ?>

                    <div class="links auth-links-spaced">
                        <a href="/codesamplecaps/LOGIN/php/login.php">Back to Login</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="../js/reset_password.js"></script>
</body>
</html>
