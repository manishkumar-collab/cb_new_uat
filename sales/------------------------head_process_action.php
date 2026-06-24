<?php
require_once '../config.php';
require_once 'functions.php';

// Check if user is logged in and has head role
if (!is_logged_in() || !has_role('Head')) {
    show_notification('You do not have permission to access this page', 'error');
    redirect('../login.php');
}

// Get head details
 $head_id = $_SESSION['user_id'];

// Handle form submission for marking sales requests as paid
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $request_id = $_POST['request_id'];
    $action = $_POST['action'];
    $comments = $_POST['comments'] ?? '';
    
    if ($action === 'paid') {
        // Update sales request status to Head Paid
        $sql = "UPDATE sales_requests SET status = 'Head Paid', head_id = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        mysqli_stmt_bind_param($stmt, "ii", $head_id, $request_id);
        mysqli_stmt_execute($stmt);
        
        // Add approval record to the correct table with correct status
        $sql = "INSERT INTO approvals_sales (sales_request_id, approver_id, approver_role, status, comments) VALUES (?, ?, 'Head', 'Paid', ?)";
        $stmt = $conn->prepare($sql);
        mysqli_stmt_bind_param($stmt, "iis", $request_id, $head_id, $comments);
        mysqli_stmt_execute($stmt);
        
        // Add to user's sales account
        // Get the request details
        $request_sql = "SELECT * FROM sales_requests WHERE id = ?";
        $stmt = $conn->prepare($request_sql);
        mysqli_stmt_bind_param($stmt, "i", $request_id);
        mysqli_stmt_execute($stmt);
        $request_result = mysqli_stmt_get_result($stmt);
        $request_data = mysqli_fetch_assoc($request_result);
        
        if ($request_data) {
            // Add to user's sales account (you might have a separate table for this)
            // For now, we'll just mark it as paid in the sales_requests table
            // In a real implementation, you might add this to a user_sales table
        }
        
        show_notification('Sales request marked as paid successfully', 'success');
    }
    
    redirect('head.php');
}
?>