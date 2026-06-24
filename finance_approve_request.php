<?php
require_once 'config.php';

// Check if user is logged in and has finance role
if (!is_logged_in() || !has_role('Finance')) {
    show_notification('You do not have permission to access this page', 'error');
    redirect('login.php');
}

// Check if request ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    show_notification('Invalid request', 'error');
    redirect('dashboard_finance.php');
}

$request_id = $_GET['id'];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $comments = sanitize_input($_POST['comments']) ?? '';
    
    // Begin transaction
    mysqli_begin_transaction($conn);
    
    try {
        // Update cashback request status
        $update_sql = "UPDATE cashback_requests SET status = 'Finance Approved', updated_at = NOW() WHERE id = ?";
        $stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($stmt, "i", $request_id);
        mysqli_stmt_execute($stmt);
        
        // Insert approval record
        $approval_sql = "INSERT INTO approvals (request_id, approver_id, approver_role, status, comments) 
                         VALUES (?, ?, 'Finance', 'Approved', ?)";
        $stmt = mysqli_prepare($conn, $approval_sql);
        mysqli_stmt_bind_param($stmt, "iis", $request_id, $_SESSION['user_id'], $comments);
        mysqli_stmt_execute($stmt);
        
        // Commit transaction
        mysqli_commit($conn);
        
        show_notification('Request approved successfully', 'success');
        redirect('dashboard_finance.php');
    } catch (Exception $e) {
        // Rollback transaction
        mysqli_rollback($conn);
        
        show_notification('Error approving request: ' . $e->getMessage(), 'error');
        redirect('dashboard_finance.php');
    }
}
?>