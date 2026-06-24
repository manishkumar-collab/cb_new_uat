<?php
require_once 'config.php';

// If user is already logged in, redirect to dashboard
if (is_logged_in()) {
    redirect('dashboard_' . strtolower($_SESSION['role']) . '.php');
}

// Process registration form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize_input($_POST['username']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $emp_id = sanitize_input($_POST['emp_id']);
    $full_name = sanitize_input($_POST['full_name']);
    $department = sanitize_input($_POST['department']);
    
    // Validate inputs
    if (empty($username) || empty($password) || empty($confirm_password) || empty($emp_id) || empty($full_name) || empty($department)) {
        show_notification('Please fill all fields', 'error');
        redirect('register.php');
    }
    
    // Check if passwords match
    if ($password !== $confirm_password) {
        show_notification('Passwords do not match', 'error');
        redirect('register.php');
    }
    
    // Check password strength (at least 6 characters)
    if (strlen($password) < 6) {
        show_notification('Password must be at least 6 characters long', 'error');
        redirect('register.php');
    }
    
    // Check if username or emp_id already exists
    $check_sql = "SELECT id FROM users WHERE username = ? OR emp_id = ?";
    $stmt = mysqli_prepare($conn, $check_sql);
    mysqli_stmt_bind_param($stmt, "ss", $username, $emp_id);
    mysqli_stmt_execute($stmt);
    $check_result = mysqli_stmt_get_result($stmt);
    
    if (mysqli_num_rows($check_result) > 0) {
        show_notification('Username or Employee ID already exists', 'error');
        redirect('register.php');
    }
    
    // Hash password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    // Set default role as 'User' - will need admin approval
    $default_role = 'User';
    
    // Insert new user
    $insert_sql = "INSERT INTO users (username, password, emp_id, full_name, department, role) 
                  VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $insert_sql);
    mysqli_stmt_bind_param($stmt, "ssssss", $username, $hashed_password, $emp_id, $full_name, $department, $default_role);
    
    if (mysqli_stmt_execute($stmt)) {
        show_notification('Registration successful! Please wait for admin approval before you can login.', 'success');
        redirect('login.php');
    } else {
        show_notification('Registration failed: ' . mysqli_error($conn), 'error');
        redirect('register.php');
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Cashback System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        :root {
            --primary: #f05d49;
            --primary-dark: #d84c38;
            --primary-light: #ff7d6a;
            --dark: #2d3748;
            --light: #ffffff;
            --gray: #e2e8f0;
            --text: #4a5568;
            --text-light: #718096;
            --shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            --radius: 6px;
        }
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            color: var(--text);
            line-height: 1.5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 15px;
        }
        .register-container {
            width: 100%;
            max-width: 450px;
            background-color: var(--light);
            border-radius: var(--radius);
            padding: 30px;
            box-shadow: var(--shadow);
        }
        .logo {
            text-align: center;
            margin-bottom: 25px;
        }
        .logo-icon {
            font-size: 36px;
            color: var(--primary);
            margin-bottom: 10px;
        }
        .logo-text {
            font-size: 24px;
            font-weight: 700;
            color: var(--dark);
        }
        .logo-text span {
            color: var(--primary);
        }
        .form-title {
            font-size: 20px;
            color: var(--dark);
            margin-bottom: 20px;
            text-align: center;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--dark);
            font-weight: 500;
        }
        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            font-size: 16px;
            transition: all 0.2s ease;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(240, 93, 73, 0.2);
        }
        .btn {
            width: 100%;
            padding: 12px;
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: var(--radius);
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn:hover {
            background-color: var(--primary-dark);
        }
        .alert {
            padding: 12px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-success {
            background-color: #f6ffed;
            border-left: 4px solid #389e0d;
            color: #389e0d;
        }
        .alert-error {
            background-color: #fff2f0;
            border-left: 4px solid #cf1322;
            color: #cf1322;
        }
        .login-link {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
            color: var(--text-light);
        }
        .login-link a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }
        .login-link a:hover {
            text-decoration: underline;
        }
        .password-strength {
            margin-top: 5px;
            font-size: 12px;
            color: var(--text-light);
        }
        .password-strength.weak {
            color: #e53e3e;
        }
        .password-strength.medium {
            color: #dd6b20;
        }
        .password-strength.strong {
            color: #38a169;
        }
        .info-box {
            background-color: #e6f7ff;
            border-left: 3px solid #1890ff;
            padding: 12px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            font-size: 13px;
            color: #004d80;
        }
        .info-box p {
            margin: 0;
        }
        /* Responsive Styles */
        @media (max-width: 480px) {
            .register-container {
                padding: 20px;
            }
            
            .form-control {
                font-size: 14px;
            }
            
            .btn {
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="logo">
            <i class="fas fa-user-plus logo-icon"></i>
            <div class="logo-text">Cashback <span>System</span></div>
        </div>
        
        <h2 class="form-title">Create Account</h2>
        
        <?php if (isset($_SESSION['notification'])): ?>
            <div class="alert alert-<?php echo $_SESSION['notification']['type']; ?>">
                <?php echo $_SESSION['notification']['message']; ?>
            </div>
            <?php unset($_SESSION['notification']); ?>
        <?php endif; ?>
        
        <div class="info-box">
            <p><i class="fas fa-info-circle"></i> After registration, your account will be reviewed by an administrator. You will be notified once your account is approved.</p>
        </div>
        
        <form action="register.php" method="post">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" class="form-control" placeholder="Choose a username" required>
            </div>
            
            <div class="form-group">
                <label for="emp_id">Employee ID</label>
                <input type="text" id="emp_id" name="emp_id" class="form-control" placeholder="Your employee ID" required>
            </div>
            
            <div class="form-group">
                <label for="full_name">Full Name</label>
                <input type="text" id="full_name" name="full_name" class="form-control" placeholder="Your full name" required>
            </div>
            
            <div class="form-group">
                <label for="department">Department</label>
                <input type="text" id="department" name="department" class="form-control" placeholder="Your department" required>
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" class="form-control" placeholder="Create a password" required>
                <div id="passwordStrength" class="password-strength"></div>
            </div>
            
            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Confirm your password" required>
            </div>
            
            <button type="submit" class="btn">Register</button>
        </form>
        
        <div class="login-link">
            Already have an account? <a href="login.php">Login here</a>
        </div>
    </div>
    
    <script>
        // Password strength checker
        document.getElementById('password').addEventListener('input', function() {
            const password = this.value;
            const strengthElement = document.getElementById('passwordStrength');
            
            // Reset classes
            strengthElement.classList.remove('weak', 'medium', 'strong');
            
            if (password.length === 0) {
                strengthElement.textContent = '';
                return;
            }
            
            // Check password strength
            let strength = 0;
            
            // Length check
            if (password.length >= 6) {
                strength += 1;
            }
            
            // Contains lowercase and uppercase
            if (password.match(/([a-z].*[A-Z])|([A-Z].*[a-z])/)) {
                strength += 1;
            }
            
            // Contains number
            if (password.match(/[0-9]/)) {
                strength += 1;
            }
            
            // Contains special character
            if (password.match(/[^a-zA-Z0-9]/)) {
                strength += 1;
            }
            
            // Update strength text and color
            if (strength < 2) {
                strengthElement.textContent = 'Weak password';
                strengthElement.classList.add('weak');
            } else if (strength < 3) {
                strengthElement.textContent = 'Medium password';
                strengthElement.classList.add('medium');
            } else {
                strengthElement.textContent = 'Strong password';
                strengthElement.classList.add('strong');
            }
        });
        
        // Confirm password validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;
            
            if (confirmPassword.length > 0) {
                if (password !== confirmPassword) {
                    this.setCustomValidity('Passwords do not match');
                } else {
                    this.setCustomValidity('');
                }
            }
        });
    </script>
</body>
</html>