<?php
require_once 'config.php';

// If user is already logged in, redirect to dashboard
if (is_logged_in()) {
    redirect('dashboard_' . strtolower($_SESSION['role']) . '.php');
}

// Process login form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize_input($_POST['username']);
    $password = $_POST['password'];
    
    // Check if fields are empty
    if (empty($username) || empty($password)) {
        show_notification('Please enter both username and password', 'error');
        redirect('login.php');
    }
    
    // Prepare SQL statement
    $sql = "SELECT id, username, password, emp_id, full_name, department, role FROM users WHERE username = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $username);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($result->num_rows === 1) {
        $user = mysqli_fetch_assoc($result);
        
        // Verify password
        if (password_verify($password, $user['password'])) {
            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['emp_id'] = $user['emp_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['department'] = $user['department'];
            $_SESSION['role'] = $user['role'];
            
            // Redirect to appropriate dashboard
            redirect('dashboard_' . strtolower($user['role']) . '.php');
        } else {
            show_notification('Invalid password', 'error');
            redirect('login.php');
        }
    } else {
        show_notification('User not found', 'error');
        redirect('login.php');
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>CB Account | Login</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="https://www.coveryou.in/images/favicon.png" type="image/png">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', 'Inter', system-ui, -apple-system, sans-serif;
        }
        :root {
            --primary: #f05d49;
            --primary-dark: #d84c38;
            --primary-light: #ff7d6a;
            --primary-fade: rgba(240, 93, 73, 0.1);
            --dark: #2d3748;
            --dark-light: #4a5568;
            --light: #ffffff;
            --gray-light: #f7fafc;
            --gray: #e2e8f0;
            --gray-dark: #cbd5e0;
            --text: #4a5568;
            --text-light: #718096;
            --shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
            --shadow-lg: 0 15px 40px rgba(0, 0, 0, 0.12);
            --radius: 10px;
            --radius-sm: 6px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #edf2f7 100%);
            color: var(--text);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 15px;
            overflow-x: hidden;
        }
        .bg-pattern {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: 
                radial-gradient(var(--primary-fade) 1px, transparent 1px),
                radial-gradient(var(--primary-fade) 1px, transparent 1px);
            background-size: 40px 40px;
            background-position: 0 0, 20px 20px;
            opacity: 0.4;
            z-index: -1;
        }
        .login-wrapper {
            display: flex;
            width: 100%;
            max-width: 900px;
            min-height: auto;
            max-height: 90vh;
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            background: var(--light);
        }
        .login-illustration {
            flex: 1;
            background: linear-gradient(145deg, var(--primary) 0%, var(--primary-dark) 100%);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 30px;
            color: white;
            position: relative;
            min-width: 0;
            overflow: hidden;
        }
        .login-illustration::after {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 30px;
            height: 100%;
            background: linear-gradient(to left, rgba(255,255,255,0.1), transparent);
        }
        .illustration-icon {
            font-size: 48px;
            margin-bottom: 15px;
            filter: drop-shadow(0 4px 8px rgba(0,0,0,0.2));
        }
        .illustration-title {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 10px;
            text-align: center;
        }
        .illustration-text {
            font-size: 14px;
            opacity: 0.9;
            text-align: center;
            max-width: 100%;
            line-height: 1.4;
            padding: 0 10px;
        }
        .login-container {
            flex: 1;
            padding: 35px 30px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-width: 0;
            overflow-y: auto;
        }
        .logo {
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
        }
        .logo-icon {
            font-size: 28px;
            color: var(--primary);
            margin-right: 12px;
            background: var(--primary-fade);
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .logo-text {
            font-size: 22px;
            font-weight: 700;
            color: var(--dark);
            line-height: 1.2;
        }
        .logo-text span {
            color: var(--primary);
        }
        .logo-subtitle {
            font-size: 13px;
            color: var(--text-light);
            font-weight: 400;
            margin-top: 2px;
            width: 100%;
        }
        .form-header {
            margin-bottom: 25px;
        }
        .form-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 6px;
            line-height: 1.3;
        }
        .form-subtitle {
            font-size: 14px;
            color: var(--text-light);
            line-height: 1.4;
        }
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: var(--dark-light);
            font-weight: 500;
            font-size: 13px;
        }
        .form-control {
            width: 100%;
            padding: 12px 14px;
            border: 1.5px solid var(--gray);
            border-radius: var(--radius-sm);
            font-size: 14px;
            transition: var(--transition);
            background: var(--gray-light);
        }
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            background: white;
            box-shadow: 0 0 0 3px rgba(240, 93, 73, 0.15);
        }
        .form-control::placeholder {
            color: var(--text-light);
            opacity: 0.6;
        }
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 38px;
            color: var(--text-light);
            cursor: pointer;
            transition: var(--transition);
            background: white;
            padding: 2px;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .password-toggle:hover {
            color: var(--primary);
            background: var(--gray-light);
        }
        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            font-size: 13px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .remember-me {
            display: flex;
            align-items: center;
        }
        .remember-me input {
            margin-right: 6px;
        }
        .forgot-password {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
            white-space: nowrap;
        }
        .forgot-password:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }
        .btn {
            width: 100%;
            padding: 13px;
            background: linear-gradient(to right, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            justify-content: center;
            align-items: center;
            box-shadow: 0 4px 12px rgba(240, 93, 73, 0.3);
            margin-top: 5px;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(240, 93, 73, 0.4);
        }
        .btn:active {
            transform: translateY(0);
        }
        .btn i {
            margin-right: 8px;
            font-size: 16px;
        }
        .alert {
            padding: 12px 14px;
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
            font-size: 13px;
            display: flex;
            align-items: center;
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
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
        .alert i {
            margin-right: 8px;
            font-size: 16px;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid var(--gray);
            font-size: 13px;
            color: var(--text-light);
        }
        .copyright {
            margin-bottom: 3px;
            line-height: 1.4;
        }
        .version {
            font-size: 11px;
            opacity: 0.7;
        }
        
        /* Responsive Design */
        @media (max-width: 992px) {
            .login-wrapper {
                max-width: 800px;
            }
        }
        
        @media (max-width: 768px) {
            body {
                padding: 10px;
                align-items: flex-start;
                min-height: 100vh;
                height: auto;
            }
            
            .login-wrapper {
                flex-direction: column;
                max-width: 100%;
                max-height: none;
                margin: 10px 0;
                height: auto;
            }
            
            .login-illustration {
                padding: 25px 20px;
                min-height: 180px;
            }
            
            .illustration-icon {
                font-size: 36px;
                margin-bottom: 10px;
            }
            
            .illustration-title {
                font-size: 20px;
            }
            
            .illustration-text {
                font-size: 13px;
                max-width: 100%;
            }
            
            .login-container {
                padding: 30px 25px;
                overflow-y: visible;
            }
            
            .logo {
                margin-bottom: 20px;
            }
            
            .form-header {
                margin-bottom: 20px;
            }
            
            .form-title {
                font-size: 22px;
            }
            
            .btn {
                padding: 14px;
            }
        }
        
        @media (max-width: 480px) {
            body {
                padding: 8px;
            }
            
            .login-container {
                padding: 25px 20px;
            }
            
            .logo-icon {
                width: 45px;
                height: 45px;
                font-size: 24px;
            }
            
            .logo-text {
                font-size: 20px;
            }
            
            .form-title {
                font-size: 20px;
            }
            
            .form-subtitle {
                font-size: 13px;
            }
            
            .form-group {
                margin-bottom: 18px;
            }
            
            .form-control {
                padding: 11px 12px;
                font-size: 15px;
            }
            
            .password-toggle {
                top: 36px;
                right: 10px;
            }
            
            .form-options {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            
            .forgot-password {
                align-self: flex-end;
            }
            
            .btn {
                padding: 13px;
                font-size: 15px;
            }
            
            .footer {
                margin-top: 25px;
                font-size: 12px;
            }
        }
        
        @media (max-height: 700px) and (min-width: 769px) {
            body {
                align-items: flex-start;
                padding-top: 20px;
                padding-bottom: 20px;
            }
            
            .login-wrapper {
                max-height: 95vh;
            }
            
            .login-container {
                overflow-y: auto;
                padding: 25px 30px;
            }
            
            .logo {
                margin-bottom: 20px;
            }
            
            .form-header {
                margin-bottom: 20px;
            }
            
            .footer {
                margin-top: 20px;
            }
        }
        
        /* Fix for very small screens */
        @media (max-width: 350px) {
            .login-wrapper {
                border-radius: 8px;
            }
            
            .login-container {
                padding: 20px 15px;
            }
            
            .logo {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .logo-icon {
                margin-bottom: 10px;
                margin-right: 0;
            }
            
            .form-title {
                font-size: 18px;
            }
        }
        
        /* Prevent horizontal scroll */
        html, body {
            max-width: 100%;
            overflow-x: hidden;
        }
    </style>
</head>
<body>
    <div class="bg-pattern"></div>
    
    <div class="login-wrapper">
        <div class="login-illustration">
            <i class="fas fa-chart-line illustration-icon"></i>
            <h2 class="illustration-title">Welcome Back</h2>
            <p class="illustration-text">Access your cb account dashboard and manage your rewards efficiently.</p>
           <h3 class="illustration-title small-title">
    Design and developed by <span class="bold-text">CoverYou IT Team</span>
</h3>
<p class="conceptualized-text">Conceptualized by Bhuban Thapa</p>

<style>
    .small-title {
        font-size: 18px;
        font-weight: 400;
        opacity: 0;
        animation: fadeUp 1.5s ease forwards;
        animation-delay: 0.5s;
    }
    
    .conceptualized-text {
        font-size: 12px;
        opacity: 0.8;
        position: absolute;
        bottom: 15px;
        right: 15px;
        text-align: right;
        opacity: 0;
        animation: fadeUp 1.5s ease forwards;
        animation-delay: 0.7s;
    }
    
    .bold-text {
        font-weight: 700;
    }

    @keyframes fadeUp {
        0% {
            opacity: 0;
            transform: translateY(10px);
        }
        100% {
            opacity: 1;
            transform: translateY(0);
        }
    }
</style>


        </div>
        
        <div class="login-container">
            <div class="logo">
                <div class="logo-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div>
                    <div class="logo-text">CB Account <span>System</span></div>
                    <div class="logo-subtitle">Employee Portal</div>
                </div>
            </div>
            
            <div class="form-header">
                <h1 class="form-title">Sign In to Account</h1>
                <p class="form-subtitle">Enter your credentials to access the system</p>
            </div>
            
            <?php if (isset($_SESSION['notification'])): ?>
                <div class="alert alert-<?php echo $_SESSION['notification']['type']; ?>">
                    <i class="fas fa-<?php echo $_SESSION['notification']['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <?php echo $_SESSION['notification']['message']; ?>
                </div>
                <?php unset($_SESSION['notification']); ?>
            <?php endif; ?>
            
            <form action="login.php" method="post" id="loginForm">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" class="form-control" placeholder="Enter your username" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-control" placeholder="Enter your password" required>
                    <span class="password-toggle" id="togglePassword">
                        <i class="fas fa-eye"></i>
                    </span>
                </div>
                
                <div class="form-options">
                    <div class="remember-me">
                        <input type="checkbox" id="remember" name="remember">
                        <label for="remember">Remember me</label>
                    </div>
                    <a href="#" class="forgot-password">Forgot password?</a>
                </div>
                
                <button type="submit" class="btn">
                    <i class="fas fa-sign-in-alt"></i> Login to Dashboard
                </button>
            </form>
            
            <div class="footer">
                <p class="copyright">&copy; 2025 Organization CB Account System. All rights reserved.</p>
                <p class="version">v2.1.0</p>
            </div>
        </div>
    </div>

    <script>
        // Password visibility toggle
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
        
        // Form animation on load
        document.addEventListener('DOMContentLoaded', function() {
            const formGroups = document.querySelectorAll('.form-group');
            formGroups.forEach((group, index) => {
                group.style.opacity = '0';
                group.style.transform = 'translateY(15px)';
                
                setTimeout(() => {
                    group.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
                    group.style.opacity = '1';
                    group.style.transform = 'translateY(0)';
                }, 80 * index);
            });
            
            // Auto-focus username field
            document.getElementById('username').focus();
        });
        
        // Form submission animation
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const btn = this.querySelector('.btn');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Authenticating...';
            btn.style.opacity = '0.8';
            btn.disabled = true;
        });
        
        // Prevent horizontal scrolling
        document.addEventListener('touchmove', function(e) {
            if (e.touches.length > 1 || e.scale && e.scale !== 1) {
                e.preventDefault();
            }
        }, { passive: false });
        
        // Adjust container height on resize
        window.addEventListener('resize', function() {
            const container = document.querySelector('.login-container');
            const wrapper = document.querySelector('.login-wrapper');
            
            if (window.innerHeight < 700 && window.innerWidth >= 768) {
                container.style.maxHeight = '75vh';
                wrapper.style.maxHeight = '85vh';
            } else {
                container.style.maxHeight = '';
                wrapper.style.maxHeight = '';
            }
        });
    </script>
</body>
</html>