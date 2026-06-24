<?php
require_once 'config.php';

// Check if user is logged in
if (!is_logged_in()) {
    show_notification('You do not have permission to access this page', 'error');
    redirect('login.php');
}

// Check if request ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    show_notification('Invalid request', 'error');
    redirect('dashboard_' . strtolower($_SESSION['role']) . '.php');
}

 $request_id = $_GET['id'];

// Get current request status
 $req_sql = "SELECT status FROM cashback_requests WHERE id = ?";
 $stmt = mysqli_prepare($conn, $req_sql);
mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
 $req_result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($req_result) === 0) {
    show_notification('Request not found', 'error');
    redirect('dashboard_' . strtolower($_SESSION['role']) . '.php');
}

 $current_request = mysqli_fetch_assoc($req_result);
 $current_status = $current_request['status'];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $comments = sanitize_input($_POST['comments']) ?? '';
    $role = $_SESSION['role'];
    $new_status = '';
    $can_approve = false;
    
    // Determine new status based on role and current status
    if ($role === 'Manager' && $current_status === 'Pending') {
        $new_status = 'Manager Approved';
        $can_approve = true;
    } elseif ($role === 'Head' && $current_status === 'Manager Approved') {
        $new_status = 'Head Approved';
        $can_approve = true;
    } elseif ($role === 'Validator' && ($current_status === 'Head Approved' || $current_status === 'Pending Validation')) {
        $new_status = 'Validator Approved';
        $can_approve = true;
    } elseif ($role === 'Finance' && $current_status === 'Validator Approved') {
        $new_status = 'Finance Approved';
        $can_approve = true;
    }
    
    if (!$can_approve) {
        show_notification('You cannot approve this request at its current state', 'error');
        redirect('view_request.php?id=' . $request_id);
    }
    
    // Begin transaction
    mysqli_begin_transaction($conn);
    
    try {
        // Handle Finance specific fields (UTR & Screenshot)
        $utr_number = null;
        $payment_screenshot_path = null;
        
        if ($role === 'Finance') {
            $utr_number = sanitize_input($_POST['utr_number']);
            
            // Handle Screenshot Upload
            if (isset($_FILES['payment_screenshot']) && $_FILES['payment_screenshot']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = 'uploads/payment_screenshots/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_ext = pathinfo($_FILES['payment_screenshot']['name'], PATHINFO_EXTENSION);
                $file_name = 'pay_' . $request_id . '_' . time() . '.' . $file_ext;
                $target_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES['payment_screenshot']['tmp_name'], $target_path)) {
                    $payment_screenshot_path = $target_path;
                } else {
                    throw new Exception('Failed to upload payment screenshot.');
                }
            }
            
            // Update cashback request with Finance details and Status
            $update_sql = "UPDATE cashback_requests SET status = ?, utr_number = ?, payment_screenshot_url = ?, updated_at = NOW() WHERE id = ?";
            $stmt = mysqli_prepare($conn, $update_sql);
            mysqli_stmt_bind_param($stmt, "sssi", $new_status, $utr_number, $payment_screenshot_path, $request_id);
        } else {
            // Update status for other roles
            $update_sql = "UPDATE cashback_requests SET status = ?, updated_at = NOW() WHERE id = ?";
            $stmt = mysqli_prepare($conn, $update_sql);
            mysqli_stmt_bind_param($stmt, "si", $new_status, $request_id);
        }
        
        mysqli_stmt_execute($stmt);
        
        // Insert approval record
        $approval_sql = "INSERT INTO approvals (request_id, approver_id, approver_role, status, comments) 
                         VALUES (?, ?, ?, 'Approved', ?)";
        $stmt = mysqli_prepare($conn, $approval_sql);
        mysqli_stmt_bind_param($stmt, "iiss", $request_id, $_SESSION['user_id'], $role, $comments);
        mysqli_stmt_execute($stmt);
        
        // Commit transaction
        mysqli_commit($conn);
        
        show_notification('Request approved successfully', 'success');
        redirect('dashboard_' . strtolower($role) . '.php');
    } catch (Exception $e) {
        // Rollback transaction
        mysqli_rollback($conn);
        
        show_notification('Error approving request: ' . $e->getMessage(), 'error');
        redirect('view_request.php?id=' . $request_id);
    }
}
?>