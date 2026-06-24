<?php
require_once 'config.php';

// Check if user is logged in and has admin role
if (!is_logged_in() || !has_role('Admin')) {
    show_notification('You do not have permission to access this page', 'error');
    redirect('login.php');
}

// Check if user ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    show_notification('Invalid user ID', 'error');
    redirect('dashboard_admin.php');
}

 $user_id = $_GET['id'];

// Get user details
 $user_sql = "SELECT * FROM users WHERE id = ?";
 $stmt = mysqli_prepare($conn, $user_sql);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
 $user_result = mysqli_stmt_get_result($stmt);

// Check if user exists
if (mysqli_num_rows($user_result) === 0) {
    show_notification('User not found', 'error');
    redirect('dashboard_admin.php');
}

 $user = mysqli_fetch_assoc($user_result);

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize_input($_POST['username']);
    $emp_id = sanitize_input($_POST['emp_id']);
    $full_name = sanitize_input($_POST['full_name']);
    $department = sanitize_input($_POST['department']);
    $role = sanitize_input($_POST['role']);
    
    // Handle manager_id and head_id - they can be empty
    $manager_id = !empty($_POST['manager_id']) ? (int)$_POST['manager_id'] : null;
    $head_id = !empty($_POST['head_id']) ? (int)$_POST['head_id'] : null;
    
    // Check if username or emp_id already exists for other users
    $check_sql = "SELECT id FROM users WHERE (username = ? OR emp_id = ?) AND id != ?";
    $stmt = mysqli_prepare($conn, $check_sql);
    mysqli_stmt_bind_param($stmt, "ssi", $username, $emp_id, $user_id);
    mysqli_stmt_execute($stmt);
    $check_result = mysqli_stmt_get_result($stmt);
    
    if (mysqli_num_rows($check_result) > 0) {
        show_notification('Username or Employee ID already exists', 'error');
        redirect('edit_user.php?id=' . $user_id);
    }
    
    // Start building the update query
    $update_sql = "UPDATE users SET username = ?, emp_id = ?, full_name = ?, department = ?, role = ?";
    $params = array($username, $emp_id, $full_name, $department, $role);
    $types = "sssss";
    
    // Add manager_id and head_id to query if they're not null
    if ($manager_id !== null) {
        $update_sql .= ", manager_id = ?";
        $params[] = $manager_id;
        $types .= "i";
    } else {
        $update_sql .= ", manager_id = NULL";
    }
    
    if ($head_id !== null) {
        $update_sql .= ", head_id = ?";
        $params[] = $head_id;
        $types .= "i";
    } else {
        $update_sql .= ", head_id = NULL";
    }
    
    // Check if password update is requested
    if (!empty($_POST['new_password'])) {
        $new_password = sanitize_input($_POST['new_password']);
        $confirm_password = sanitize_input($_POST['confirm_password']);
        
        if ($new_password !== $confirm_password) {
            show_notification('Passwords do not match', 'error');
            redirect('edit_user.php?id=' . $user_id);
        }
        
        if (strlen($new_password) < 6) {
            show_notification('Password must be at least 6 characters long', 'error');
            redirect('edit_user.php?id=' . $user_id);
        }
        
        // Hash the password
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        $update_sql .= ", password = ?";
        $params[] = $hashed_password;
        $types .= "s";
    }
    
    // Add WHERE clause
    $update_sql .= " WHERE id = ?";
    $params[] = $user_id;
    $types .= "i";
    
    // Prepare and execute the statement
    $stmt = mysqli_prepare($conn, $update_sql);
    
    // Convert array to references for bind_param
    $bind_params = array();
    foreach ($params as $key => $value) {
        $bind_params[$key] = &$params[$key];
    }
    
    // Add types as first parameter
    array_unshift($bind_params, $types);
    
    // Use call_user_func_array to bind parameters
    call_user_func_array('mysqli_stmt_bind_param', array_merge(array($stmt), $bind_params));
    
    if (mysqli_stmt_execute($stmt)) {
        show_notification('User updated successfully', 'success');
        redirect('dashboard_admin.php');
    } else {
        show_notification('Error updating user: ' . mysqli_error($conn), 'error');
        redirect('edit_user.php?id=' . $user_id);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User - Cashback System</title>
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
            padding: 15px;
            font-size: 14px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
        }
        header {
            text-align: center;
            margin-bottom: 20px;
            padding: 15px;
            background: var(--light);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }
        .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 10px;
        }
        .logo-icon {
            font-size: 24px;
            color: var(--primary);
        }
        .logo-text {
            font-size: 22px;
            font-weight: 700;
            color: var(--dark);
        }
        .logo-text span {
            color: var(--primary);
        }
        .form-container {
            background-color: var(--light);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
        }
        .section-title {
            font-size: 16px;
            color: var(--primary);
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--gray);
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: var(--dark);
            font-weight: 500;
        }
        .form-control {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            font-size: 14px;
        }
        .btn {
            padding: 8px 16px;
            border-radius: var(--radius);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 14px;
        }
        .btn-primary {
            background-color: var(--primary);
            color: white;
        }
        .btn-primary:hover {
            background-color: var(--primary-dark);
        }
        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--primary);
            color: var(--primary);
        }
        .btn-outline:hover {
            background-color: var(--primary);
            color: white;
        }
        .form-footer {
            display: flex;
            justify-content: space-between;
            margin-top: 20px;
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
        .checkbox-group {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }
        .checkbox-group input {
            margin-right: 8px;
        }
        .password-section {
            background-color: #f8fafc;
            padding: 15px;
            border-radius: var(--radius);
            margin-bottom: 15px;
            border: 1px dashed var(--gray);
        }
        .password-section h3 {
            margin-bottom: 10px;
            color: var(--primary);
            font-size: 14px;
        }
        .password-hint {
            font-size: 12px;
            color: var(--text-light);
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="logo">
                <i class="fas fa-user-edit logo-icon"></i>
                <div class="logo-text">Edit <span>User</span></div>
            </div>
        </header>
        
        <?php if (isset($_SESSION['notification'])): ?>
            <div class="alert alert-<?php echo $_SESSION['notification']['type']; ?>">
                <?php echo $_SESSION['notification']['message']; ?>
            </div>
            <?php unset($_SESSION['notification']); ?>
        <?php endif; ?>
        
        <div class="form-container">
            <h2 class="section-title">Edit User</h2>
            
            <form action="edit_user.php?id=<?php echo $user_id; ?>" method="post">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="emp_id">Employee ID</label>
                    <input type="text" id="emp_id" name="emp_id" class="form-control" value="<?php echo htmlspecialchars($user['emp_id']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="full_name">Full Name</label>
                    <input type="text" id="full_name" name="full_name" class="form-control" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="department">Department</label>
                    <input type="text" id="department" name="department" class="form-control" value="<?php echo htmlspecialchars($user['department']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="role">Role</label>
                    <select id="role" name="role" class="form-control" required>
                        <option value="Admin" <?php echo $user['role'] === 'Admin' ? 'selected' : ''; ?>>Admin</option>
                        <option value="Head" <?php echo $user['role'] === 'Head' ? 'selected' : ''; ?>>Head</option>
                        <option value="Manager" <?php echo $user['role'] === 'Manager' ? 'selected' : ''; ?>>Manager</option>
                        <option value="User" <?php echo $user['role'] === 'User' ? 'selected' : ''; ?>>User</option>
                        <option value="Finance" <?php echo $user['role'] === 'Finance' ? 'selected' : ''; ?>>Finance</option>
                        <option value="Validator" <?php echo $user['role'] === 'Validator' ? 'selected' : ''; ?>>Validator</option>
                         <option value="Support" <?php echo $user['role'] === 'Support' ? 'selected' : ''; ?>>Support</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="manager_id">Manager</label>
                    <select id="manager_id" name="manager_id" class="form-control">
                        <option value="">None</option>
                        <?php
                        $managers_sql = "SELECT id, full_name FROM users WHERE role = 'Manager'";
                        $managers_result = mysqli_query($conn, $managers_sql);
                        while ($manager = mysqli_fetch_assoc($managers_result)) {
                            $selected = $user['manager_id'] == $manager['id'] ? 'selected' : '';
                            echo "<option value='{$manager['id']}' $selected>{$manager['full_name']}</option>";
                        }
                        ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="head_id">Head</label>
                    <select id="head_id" name="head_id" class="form-control">
                        <option value="">None</option>
                        <?php
                        $heads_sql = "SELECT id, full_name FROM users WHERE role = 'Head'";
                        $heads_result = mysqli_query($conn, $heads_sql);
                        while ($head = mysqli_fetch_assoc($heads_result)) {
                            $selected = $user['head_id'] == $head['id'] ? 'selected' : '';
                            echo "<option value='{$head['id']}' $selected>{$head['full_name']}</option>";
                        }
                        ?>
                    </select>
                </div>
                
                <div class="password-section">
                    <h3><i class="fas fa-lock"></i> Password Update (Optional)</h3>
                    <p class="password-hint">Leave these fields empty if you don't want to change the password</p>
                    
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" class="form-control">
                        <p class="password-hint">Password must be at least 6 characters long</p>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control">
                    </div>
                </div>
                
                <?php if (isset($user['is_approved'])): ?>
                <div class="checkbox-group">
                    <input type="checkbox" id="is_approved" name="is_approved" value="1" <?php echo $user['is_approved'] ? 'checked' : ''; ?>>
                    <label for="is_approved">Account Approved</label>
                </div>
                <?php endif; ?>
                
                <div class="form-footer">
                    <a href="dashboard_admin.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Toggle password fields visibility
        document.addEventListener('DOMContentLoaded', function() {
            const newPasswordField = document.getElementById('new_password');
            const confirmPasswordField = document.getElementById('confirm_password');
            const passwordSection = document.querySelector('.password-section');
            
            // Add toggle visibility button
            const toggleBtn = document.createElement('button');
            toggleBtn.type = 'button';
            toggleBtn.className = 'btn btn-outline';
            toggleBtn.style.marginTop = '5px';
            toggleBtn.innerHTML = '<i class="fas fa-eye"></i> Show Password';
            toggleBtn.style.fontSize = '12px';
            toggleBtn.style.padding = '5px 10px';
            
            // Insert after new password field
            newPasswordField.parentNode.insertBefore(toggleBtn, newPasswordField.nextSibling);
            
            // Toggle password visibility
            toggleBtn.addEventListener('click', function() {
                if (newPasswordField.type === 'password') {
                    newPasswordField.type = 'text';
                    confirmPasswordField.type = 'text';
                    toggleBtn.innerHTML = '<i class="fas fa-eye-slash"></i> Hide Password';
                } else {
                    newPasswordField.type = 'password';
                    confirmPasswordField.type = 'password';
                    toggleBtn.innerHTML = '<i class="fas fa-eye"></i> Show Password';
                }
            });
            
            // Validate password match
            confirmPasswordField.addEventListener('input', function() {
                if (newPasswordField.value !== confirmPasswordField.value && confirmPasswordField.value !== '') {
                    confirmPasswordField.style.borderColor = '#e53e3e';
                } else {
                    confirmPasswordField.style.borderColor = '';
                }
            });
        });
    </script>
</body>
</html>