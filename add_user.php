<?php
require_once 'config.php';

// Check if user is logged in and has admin role
if (!is_logged_in() || !has_role('Admin')) {
    show_notification('You do not have permission to access this page', 'error');
    redirect('login.php');
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize_input($_POST['username']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $emp_id = sanitize_input($_POST['emp_id']);
    $full_name = sanitize_input($_POST['full_name']);
    $department = sanitize_input($_POST['department']);
    $role = sanitize_input($_POST['role']);
    $manager_id = !empty($_POST['manager_id']) ? (int)$_POST['manager_id'] : null;
    $head_id = !empty($_POST['head_id']) ? (int)$_POST['head_id'] : null;
    
    // Check if username or emp_id already exists
    $check_sql = "SELECT id FROM users WHERE username = ? OR emp_id = ?";
    $stmt = mysqli_prepare($conn, $check_sql);
    mysqli_stmt_bind_param($stmt, "ss", $username, $emp_id);
    mysqli_stmt_execute($stmt);
    $check_result = mysqli_stmt_get_result($stmt);
    
    if (mysqli_num_rows($check_result) > 0) {
        show_notification('Username or Employee ID already exists', 'error');
        redirect('dashboard_admin.php');
    }
    
    // Insert new user
    $insert_sql = "INSERT INTO users (username, password, emp_id, full_name, department, role, manager_id, head_id) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $insert_sql);
    mysqli_stmt_bind_param($stmt, "ssssssii", $username, $password, $emp_id, $full_name, $department, $role, $manager_id, $head_id);
    
    if (mysqli_stmt_execute($stmt)) {
        show_notification('User added successfully', 'success');
        redirect('dashboard_admin.php');
    } else {
        show_notification('Error adding user: ' . mysqli_error($conn), 'error');
        redirect('dashboard_admin.php');
    }
}
?>