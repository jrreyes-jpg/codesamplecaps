<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../controllers/UserController.php';

require_role('super_admin');

$csrfToken = auth_csrf_token('super_admin');
$userController = new UserController();

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_engineer'])) {
    if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'super_admin')) {
        $error = "Security check failed. Please try again.";
    } else {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    if (empty($full_name) || empty($email) || empty($password)) {
        $error = "Please fill in all fields.";
    } else {
        $result = $userController->findByEmail($email);
        if ($result && $result->num_rows > 0) {
            $error = "Email already exists.";
        } else {
            if ($userController->createEngineer($full_name, $email, $password)) {
                $success = "Engineer account created successfully!";
                $_POST = array();
            } else {
                $error = "Error creating account. Please try again.";
            }
        }
    }
    }
}

$engineers_result = $conn->query("SELECT id AS user_id, full_name, email, created_at FROM users WHERE role = 'engineer' ORDER BY created_at DESC");

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Engineer Account - Super Admin</title>
<link rel="stylesheet" href="../css/style.css">
<style>
    body {
        background: #f5f5f5;
        font-family: 'Poppins', sans-serif;
    }
    
    .admin-container {
        display: flex;
        min-height: 100vh;
        gap: 20px;
        padding: 20px;
    }
    
    .sidebar {
        width: 250px;
        background: white;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        height: fit-content;
    }
    
    .sidebar a {
        display: block;
        padding: 12px;
        margin: 10px 0;
        background: #007bff;
        color: white;
        text-decoration: none;
        border-radius: 6px;
        text-align: center;
        cursor: pointer;
        transition: 0.3s;
    }
    
    .sidebar a:hover {
        background: #0056b3;
    }
    
    .main-content {
        flex: 1;
        background: white;
        padding: 30px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .form-section, .engineers-section {
        margin-bottom: 40px;
    }
    
    .form-section h2 {
        color: #333;
        margin-bottom: 20px;
    }
    
    input {
        width: 100%;
        padding: 10px;
        margin: 8px 0;
        border: 1px solid #ddd;
        border-radius: 6px;
        box-sizing: border-box;
        font-family: inherit;
    }
    
    button {
        background: #28a745;
        color: white;
        padding: 12px 30px;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 16px;
        margin-top: 10px;
        transition: 0.3s;
    }
    
    button:hover {
        background: #218838;
    }
    
    .error-message {
        color: #d9534f;
        padding: 10px;
        background: #f2dede;
        border-radius: 4px;
        margin-bottom: 15px;
    }
    
    .success-message {
        color: #3c763d;
        padding: 10px;
        background: #dff0d8;
        border-radius: 4px;
        margin-bottom: 15px;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
    }
    
    table th, table td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid #ddd;
    }
    
    table th {
        background: #007bff;
        color: white;
    }
    
    table tr:hover {
        background: #f5f5f5;
    }
    
    .header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
    }
    
    .user-info {
        text-align: right;
    }
</style>
</head>
<body>
<?php auth_render_back_button_logout_script(); ?>

<div class="admin-container">
    <div class="sidebar">
        <h3>Super Admin Menu</h3>
        <a href="../dashboards/create_engineer.php">Create Engineer</a>
        <a href="../dashboards/super_admin_dashboard.php">Dashboard</a>
        <a href="../../LOGIN/php/logout.php">Logout</a>
    </div>
    
    <div class="main-content">
        <div class="header">
            <h1>Create Engineer Account</h1>
            <div class="user-info">
                <p>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?></p>
                <small><?php echo $_SESSION['role']; ?></small>
            </div>
        </div>
        
        <div class="form-section">
            <h2>Add New Engineer</h2>
            
            <?php if($error): ?>
                <p class="error-message"><?php echo $error; ?></p>
            <?php endif; ?>
            
            <?php if($success): ?>
                <p class="success-message"><?php echo $success; ?></p>
            <?php endif; ?>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="text" name="full_name" placeholder="Full Name" required 
                       value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
                
                <input type="email" name="email" placeholder="Email Address" required 
                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                
                <input type="password" name="password" placeholder="Temporary Password" required>
                
                <button type="submit" name="create_engineer">Create Engineer Account</button>
            </form>
        </div>
        
        <div class="engineers-section">
            <h2>All Engineers (<?php echo $engineers_result->num_rows; ?>)</h2>
            
            <?php if($engineers_result->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Created Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($engineer = $engineers_result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($engineer['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($engineer['email']); ?></td>
                                <td><?php echo date('M d, Y', strtotime($engineer['created_at'])); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No engineers created yet.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="/codesamplecaps/SUPERADMIN/js/super_admin_dashboard.js"></script>
</body>
</html>
