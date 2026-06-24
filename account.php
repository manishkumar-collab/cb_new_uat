<?php
session_start();
// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php'); // Redirect to login page if not logged in
    exit();
}

// Include database configuration
require_once 'config.php';

// Get user information
 $user_id = $_SESSION['user_id'];
 $stmt = $conn->prepare("SELECT username, full_name, emp_id FROM users WHERE id = ?");
 $stmt->bind_param("i", $user_id);
 $stmt->execute();
 $result = $stmt->get_result();
 $user = $result->fetch_assoc();

// Handle password change form submission
 $message = '';
 $error = '';

if (isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validate inputs
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = "All fields are required.";
    } elseif ($new_password !== $confirm_password) {
        $error = "New password and confirm password do not match.";
    } elseif (strlen($new_password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } else {
        // Get current password hash from database
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user_data = $result->fetch_assoc();
        
        // Verify current password
        if (password_verify($current_password, $user_data['password'])) {
            // Hash new password
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            
            // Update password in database
            $update_stmt = $conn->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
            $update_stmt->bind_param("si", $new_password_hash, $user_id);
            
            if ($update_stmt->execute()) {
                $message = "Password changed successfully!";
            } else {
                $error = "Failed to change password. Please try again.";
            }
        } else {
            $error = "Current password is incorrect.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Account - Change Password</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f5f5f5;
            color: #333;
            line-height: 1.6;
        }
        
        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
        }
        
        header {
            background-color: #2c3e50;
            color: white;
            padding: 20px 0;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1000px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .logo {
            font-size: 24px;
            font-weight: bold;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #3498db;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }
        
        .user-details h3 {
            font-size: 16px;
            margin-bottom: 3px;
        }
        
        .user-details p {
            font-size: 14px;
            opacity: 0.8;
        }
        
        .account-container {
            display: flex;
            gap: 30px;
            margin-top: 30px;
        }
        
        .sidebar {
            flex: 0 0 250px;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 20px;
            height: fit-content;
        }
        
        .sidebar h3 {
            margin-bottom: 15px;
            color: #2c3e50;
            font-size: 18px;
        }
        
        .sidebar ul {
            list-style: none;
        }
        
        .sidebar li {
            margin-bottom: 10px;
        }
        
        .sidebar a {
            display: block;
            padding: 10px 15px;
            color: #555;
            text-decoration: none;
            border-radius: 5px;
            transition: all 0.3s ease;
        }
        
        .sidebar a:hover, .sidebar a.active {
            background-color: #f8f9fa;
            color: #3498db;
        }
        
        .content {
            flex: 1;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 30px;
        }
        
        .content h2 {
            margin-bottom: 20px;
            color: #2c3e50;
            font-size: 24px;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }
        
        .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background-color: #3498db;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
            font-weight: bold;
        }
        
        .profile-info h3 {
            font-size: 20px;
            margin-bottom: 5px;
        }
        
        .profile-info p {
            color: #666;
            margin-bottom: 3px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
        }
        
        .btn {
            display: inline-block;
            background-color: #3498db;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: background-color 0.3s;
        }
        
        .btn:hover {
            background-color: #2980b9;
        }
        
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .password-strength {
            margin-top: 5px;
            height: 5px;
            border-radius: 3px;
            background-color: #eee;
        }
        
        .password-strength div {
            height: 100%;
            border-radius: 3px;
            width: 0;
            transition: width 0.3s, background-color 0.3s;
        }
        
        .strength-weak {
            background-color: #e74c3c;
            width: 33%;
        }
        
        .strength-medium {
            background-color: #f39c12;
            width: 66%;
        }
        
        .strength-strong {
            background-color: #2ecc71;
            width: 100%;
        }
        
        footer {
            margin-top: 40px;
            padding: 20px 0;
            text-align: center;
            color: #666;
            font-size: 14px;
        }
        
        @media (max-width: 768px) {
            .account-container {
                flex-direction: column;
            }
            
            .sidebar {
                flex: none;
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="header-content">
            <div class="logo">CoverYou Portal</div>
            <div class="user-info">
                <div class="user-avatar"><?php echo strtoupper(substr($user['full_name'], 0, 1)); ?></div>
                <div class="user-details">
                    <h3><?php echo htmlspecialchars($user['full_name']); ?></h3>
                    <p><?php echo htmlspecialchars($user['emp_id']); ?></p>
                </div>
            </div>
        </div>
    </header>
    
    <div class="container">
        <div class="account-container">
            <aside class="sidebar">
                <h3>Account Settings</h3>
                <ul>
                    <li><a href="#" class="active">Change Password</a></li>
                    <li><a href="#">Profile Information</a></li>
                    <li><a href="#">Notification Settings</a></li>
                    <li><a href="#">Login History</a></li>
                    <li><a href="#">Logout</a></li>
                </ul>
            </aside>
            
            <main class="content">
                <h2>Change Password</h2>
                
                <div class="user-profile">
                    <div class="profile-avatar"><?php echo strtoupper(substr($user['full_name'], 0, 1)); ?></div>
                    <div class="profile-info">
                        <h3><?php echo htmlspecialchars($user['full_name']); ?></h3>
                        <p>Username: <?php echo htmlspecialchars($user['username']); ?></p>
                        <p>Employee ID: <?php echo htmlspecialchars($user['emp_id']); ?></p>
                    </div>
                </div>
                
                <?php if ($message): ?>
                    <div class="alert alert-success"><?php echo $message; ?></div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-error"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <form action="account.php" method="post">
                    <div class="form-group">
                        <label for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" required>
                        <div class="password-strength">
                            <div id="strength-meter"></div>
                        </div>
                        <small id="password-hint">Password must be at least 6 characters long.</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                    
                    <button type="submit" name="change_password" class="btn">Change Password</button>
                </form>
            </main>
        </div>
    </div>
    
    <footer>
        <p>&copy; <?php echo date('Y'); ?> CoverYou. All rights reserved.</p>
    </footer>
    
    <script>
        // Password strength checker
        const newPassword = document.getElementById('new_password');
        const strengthMeter = document.getElementById('strength-meter');
        const passwordHint = document.getElementById('password-hint');
        
        newPassword.addEventListener('input', function() {
            const password = this.value;
            let strength = 0;
            
            if (password.length >= 6) strength += 1;
            if (password.length >= 10) strength += 1;
            if (/[A-Z]/.test(password) && /[a-z]/.test(password)) strength += 1;
            if (/[0-9]/.test(password)) strength += 1;
            if (/[^A-Za-z0-9]/.test(password)) strength += 1;
            
            // Reset classes
            strengthMeter.className = '';
            
            if (password.length > 0) {
                if (strength <= 2) {
                    strengthMeter.classList.add('strength-weak');
                    passwordHint.textContent = 'Weak password. Include uppercase, lowercase, numbers, and special characters.';
                } else if (strength <= 4) {
                    strengthMeter.classList.add('strength-medium');
                    passwordHint.textContent = 'Medium strength. Adding special characters would make it stronger.';
                } else {
                    strengthMeter.classList.add('strength-strong');
                    passwordHint.textContent = 'Strong password!';
                }
            } else {
                passwordHint.textContent = 'Password must be at least 6 characters long.';
            }
        });
        
        // Check if passwords match
        const confirmPassword = document.getElementById('confirm_password');
        confirmPassword.addEventListener('input', function() {
            if (this.value !== newPassword.value) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
</body>
</html>