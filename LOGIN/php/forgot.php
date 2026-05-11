<?php
session_start();

require_once __DIR__ . '/../../services/AuthService.php';

$error = "";
$success = "";

if (isset($_SESSION['reset_success'])) {
    $success = $_SESSION['reset_success'];
    unset($_SESSION['reset_success']);
}

$authService = new AuthService();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email'] ?? '');

    $result = $authService->requestPasswordReset($email);

    if ($result['success']) {
        $_SESSION['reset_success'] = $result['message'] ?? 'If the email exists, a reset link will be sent.';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $error = $result['error'] ?? 'An error occurred.';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Edge Automation Portal</title>
    <link rel="stylesheet" href="../../SUPERADMIN/css/style.css">
    <link rel="stylesheet" href="../css/forgot.css">
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
                    <h2>Reset Password</h2>

                    <?php if($error): ?>
                        <div class="error-box">
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <?php if($success): ?>
                        <div class="success-box">
                            <?php echo htmlspecialchars($success); ?>
                        </div>
                    <?php endif; ?>

                    <p class="auth-helper-text">
                        Enter your email address and we'll send you a link to reset your password.
                    </p>

                    <input type="email" name="email" placeholder="Enter your email" required>

                    <button type="submit" id="resetBtn" data-loading-text="Sending reset link...">Send Reset Link</button>

                    <div class="links">
                        <a href="/codesamplecaps/LOGIN/php/login.php">Back to Login</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="../js/forgot.js"></script>
</body>
</html>

