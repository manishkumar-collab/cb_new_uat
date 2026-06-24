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

// Don't allow deletion of the currently logged in user
if ($user_id == $_SESSION['user_id']) {
    show_notification('You cannot delete your own account', 'error');
    redirect('dashboard_admin.php');
}

// Delete user
$delete_sql = "DELETE FROM users WHERE id = ?";
$stmt = mysqli_prepare($conn, $delete_sql);
mysqli_stmt_bind_param($stmt, "i", $user_id);

if (mysqli_stmt_execute($stmt)) {
    show_notification('User deleted successfully', 'success');
    redirect('dashboard_admin.php');
} else {
    show_notification('Error deleting user: ' . mysqli_error($conn), 'error');
    redirect('dashboard_admin.php');
}
?>