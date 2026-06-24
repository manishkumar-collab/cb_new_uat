<?php
require_once 'config.php';

// Check if user is logged in
if (!is_logged_in()) {
    show_notification('You must be logged in to perform this action', 'error');
    redirect('login.php');
}

// Check if request ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    show_notification('Invalid request', 'error');
    redirect('dashboard_' . strtolower($_SESSION['role']) . '.php');
}

 $request_id = $_GET['id'];
 $current_role = $_SESSION['role'];

// Fetch current request status to validate role permission
 $check_sql = "SELECT status FROM cashback_requests WHERE id = ?";
 $stmt = mysqli_prepare($conn, $check_sql);
mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
 $check_result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($check_result) === 0) {
    show_notification('Request not found', 'error');
    redirect('dashboard_' . strtolower($current_role) . '.php');
}

 $request = mysqli_fetch_assoc($check_result);
 $current_status = $request['status'];

// Validate if the current role is allowed to reject this request
 $can_reject = false;
 $new_status = 'Rejected'; // Default rejection status for Manager/Head/Finance

if ($current_role === 'Manager' && $current_status === 'Pending') {
    $can_reject = true;
    $new_status = 'Rejected';
} elseif ($current_role === 'Head' && $current_status === 'Manager Approved') {
    $can_reject = true;
    $new_status = 'Rejected';
} elseif ($current_role === 'Validator' && ($current_status === 'Head Approved' || $current_status === 'Pending Validation')) {
    $can_reject = true;
    // Must match exact ENUM value from table structure
    $new_status = 'Validator Rejected'; 
} elseif ($current_role === 'Finance' && $current_status === 'Validator Approved') {
    $can_reject = true;
    $new_status = 'Rejected';
}

if (!$can_reject) {
    show_notification('You do not have permission to reject this request at this stage', 'error');
    redirect('dashboard_' . strtolower($current_role) . '.php');
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $comments = sanitize_input($_POST['comments']) ?? '';
    
    // Begin transaction
    mysqli_begin_transaction($conn);
    
    try {
        // Update cashback request status dynamically
        $update_sql = "UPDATE cashback_requests SET status = ?, updated_at = NOW() WHERE id = ?";
        $stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($stmt, "si", $new_status, $request_id);
        mysqli_stmt_execute($stmt);
        
        // Insert approval record with current role
        $approval_sql = "INSERT INTO approvals (request_id, approver_id, approver_role, status, comments) 
                         VALUES (?, ?, ?, 'Rejected', ?)";
        $stmt = mysqli_prepare($conn, $approval_sql);
        mysqli_stmt_bind_param($stmt, "iiss", $request_id, $_SESSION['user_id'], $current_role, $comments);
        mysqli_stmt_execute($stmt);
        
        // Commit transaction
        mysqli_commit($conn);
        
        show_notification('Request rejected successfully', 'success');
        redirect('dashboard_' . strtolower($current_role) . '.php');
    } catch (Exception $e) {
        // Rollback transaction
        mysqli_rollback($conn);
        
        show_notification('Error rejecting request: ' . $e->getMessage(), 'error');
        redirect('dashboard_' . strtolower($current_role) . '.php');
    }
}
?>