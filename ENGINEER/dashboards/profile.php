<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/audit_log.php';
require_once __DIR__ . '/../includes/engineer_helpers.php';

require_role('engineer');

$userId = (int)($_SESSION['user_id'] ?? 0);
$taskStatusOptions = ['pending', 'ongoing', 'completed', 'delayed'];
$data = engineer_fetch_data($conn, $userId, $taskStatusOptions);
$profile = $data['engineer_profile'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Engineer Profile - Edge Automation</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/engineer-sidebar.css">
    <link rel="stylesheet" href="../css/engineer.css">
</head>
<body>
<?php include '../sidebar/sidebar_engineer.php'; ?>

<div class="main-content">
    <div class="section-heading">
        <div>
            <p class="section-kicker">Profile</p>
            <h2>Profile Snapshot</h2>
            <p class="section-caption">Read-only account details so the engineer page stays focused on execution.</p>
        </div>
    </div>

    <div class="profile-summary">
        <span class="profile-chip">Open Tasks: <?php echo (int)$data['open_task_count']; ?></span>
        <span class="profile-chip">Assigned Projects: <?php echo (int)$data['assigned_count']; ?></span>
        <span class="profile-chip">Completed Projects: <?php echo (int)$data['completed_count']; ?></span>
    </div>
    <div class="profile-form">
        <div class="form-group">
            <label>Full Name</label>
            <input type="text" value="<?php echo htmlspecialchars((string)($profile['full_name'] ?? '')); ?>" disabled>
        </div>
        <div class="form-group">
            <label>Email</label>
            <input type="email" value="<?php echo htmlspecialchars((string)($profile['email'] ?? '')); ?>" disabled>
        </div>
        <div class="form-group">
            <label>Phone</label>
            <input type="text" value="<?php echo htmlspecialchars((string)($profile['phone'] ?? '')); ?>" disabled>
        </div>
        <div class="form-group">
            <label>Account Status</label>
            <input type="text" value="<?php echo htmlspecialchars(ucfirst((string)($profile['status'] ?? 'active'))); ?>" disabled>
        </div>
        <div class="form-group">
            <label>Role</label>
            <input type="text" value="<?php echo htmlspecialchars(ucfirst((string)($profile['role'] ?? 'engineer'))); ?>" disabled>
        </div>
        <p class="profile-note">Need profile details changed? Coordinate with the super admin so your account record stays consistent.</p>
        <a class="btn btn-link" href="/codesamplecaps/LOGIN/php/forgot.php">Reset Password</a>
    </div>
</div>

<script src="../js/engineer.js"></script>

</body>
</html>
