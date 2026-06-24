<?php
require_once '../config.php';
require_once 'functions.php';

// Check if user is logged in
if (!is_logged_in()) {
    $_SESSION['notification'] = [
        'type' => 'error',
        'message' => 'Please login to continue'
    ];
    redirect('../login.php');
}

// Get request ID and action
 $request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
 $action = isset($_POST['action']) ? $_POST['action'] : '';
 $comments = isset($_POST['comments']) ? trim($_POST['comments']) : '';

// Get the referring page URL
 $referrer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'index.php';

// Validate request
if ($request_id <= 0 || empty($action)) {
    $_SESSION['notification'] = [
        'type' => 'error',
        'message' => 'Invalid request'
    ];
    redirect($referrer);
}

// Get request details with user information
 $request_sql = "SELECT sr.*, u.manager_id, u.head_id 
              FROM sales_requests sr 
              JOIN users u ON sr.user_id = u.id 
              WHERE sr.id = ?";
 $stmt = $conn->prepare($request_sql);
mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
 $request_result = mysqli_stmt_get_result($stmt);
 $request = mysqli_fetch_assoc($request_result);

if (!$request) {
    $_SESSION['notification'] = [
        'type' => 'error',
        'message' => 'Sales request not found'
    ];
    redirect($referrer);
}

// Get user role
 $user_details = getUserDetails($_SESSION['user_id']);
 $user_role = $user_details ? $user_details['role'] : 'Unknown';

// Process action based on user role and request status
if ($action == 'verify' && has_role('Manager') && $request['status'] == 'Pending') {
    // Check if this manager is assigned to this user
    if ($request['manager_id'] == $_SESSION['user_id']) {
        // Check if already verified
        $check_sql = "SELECT id FROM approvals_sales 
                     WHERE sales_request_id = ? AND approver_role = 'Manager' AND status = 'Verified'";
        $stmt = $conn->prepare($check_sql);
        mysqli_stmt_bind_param($stmt, "i", $request_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if (mysqli_num_rows($result) == 0) {
            // Start transaction
            mysqli_begin_transaction($conn);
            
            try {
                // Update request status
                $update_sql = "UPDATE sales_requests SET status = 'Manager Verified' WHERE id = ?";
                $stmt = $conn->prepare($update_sql);
                mysqli_stmt_bind_param($stmt, "i", $request_id);
                mysqli_stmt_execute($stmt);
                
                // Prepare comment - use user's comment if provided, otherwise use default
                $final_comment = !empty($comments) ? $comments : 'Request verified by manager';
                
                // Add to approvals table - ONLY ONCE
                $insert_sql = "INSERT INTO approvals_sales 
                              (sales_request_id, approver_id, approver_role, status, comments, created_at) 
                              VALUES (?, ?, ?, 'Verified', ?, NOW())";
                $stmt = $conn->prepare($insert_sql);
                // FIXED: Changed "iis" to "iiss" to match all 4 variables
                mysqli_stmt_bind_param($stmt, "iiss", $request_id, $_SESSION['user_id'], $user_role, $final_comment);
                mysqli_stmt_execute($stmt);
                
                // Commit transaction
                mysqli_commit($conn);
                
                $_SESSION['notification'] = [
                    'type' => 'success',
                    'message' => 'Request verified successfully'
                ];
            } catch (Exception $e) {
                // Rollback transaction on error
                mysqli_rollback($conn);
                
                $_SESSION['notification'] = [
                    'type' => 'error',
                    'message' => 'Error verifying request: ' . $e->getMessage()
                ];
                // For debugging, you can uncomment the line below
                // error_log("Verify Error: " . $e->getMessage());
            }
        } else {
            $_SESSION['notification'] = [
                'type' => 'error',
                'message' => 'Request already verified'
            ];
        }
    } else {
        $_SESSION['notification'] = [
            'type' => 'error',
            'message' => 'You are not authorized to verify this request'
        ];
    }
} elseif ($action == 'reject' && has_role('Manager') && $request['status'] == 'Pending') {
    // Check if this manager is assigned to this user
    if ($request['manager_id'] == $_SESSION['user_id']) {
        // Check if already rejected
        $check_sql = "SELECT id FROM approvals_sales 
                     WHERE sales_request_id = ? AND approver_role = 'Manager' AND status = 'Rejected'";
        $stmt = $conn->prepare($check_sql);
        mysqli_stmt_bind_param($stmt, "i", $request_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if (mysqli_num_rows($result) == 0) {
            // Start transaction
            mysqli_begin_transaction($conn);
            
            try {
                // Update request status
                $update_sql = "UPDATE sales_requests SET status = 'Rejected' WHERE id = ?";
                $stmt = $conn->prepare($update_sql);
                mysqli_stmt_bind_param($stmt, "i", $request_id);
                mysqli_stmt_execute($stmt);
                
                // Prepare comment - use user's comment if provided, otherwise use default
                $final_comment = !empty($comments) ? $comments : 'Request rejected by manager';
                
                // Add to approvals table - ONLY ONCE
                $insert_sql = "INSERT INTO approvals_sales 
                              (sales_request_id, approver_id, approver_role, status, comments, created_at) 
                              VALUES (?, ?, ?, 'Rejected', ?, NOW())";
                $stmt = $conn->prepare($insert_sql);
                // FIXED: Changed "iis" to "iiss"
                mysqli_stmt_bind_param($stmt, "iiss", $request_id, $_SESSION['user_id'], $user_role, $final_comment);
                mysqli_stmt_execute($stmt);
                
                // Commit transaction
                mysqli_commit($conn);
                
                $_SESSION['notification'] = [
                    'type' => 'success',
                    'message' => 'Request rejected successfully'
                ];
            } catch (Exception $e) {
                // Rollback transaction on error
                mysqli_rollback($conn);
                
                $_SESSION['notification'] = [
                    'type' => 'error',
                    'message' => 'Error rejecting request: ' . $e->getMessage()
                ];
            }
        } else {
            $_SESSION['notification'] = [
                'type' => 'error',
                'message' => 'Request already rejected'
            ];
        }
    } else {
        $_SESSION['notification'] = [
            'type' => 'error',
            'message' => 'You are not authorized to reject this request'
        ];
    }
} elseif ($action == 'paid' && has_role('Head') && $request['status'] == 'Manager Verified') {
    // Check if this head is assigned to this user
    if ($request['head_id'] == $_SESSION['user_id']) {
        // Check if already marked as paid
        $check_sql = "SELECT id FROM approvals_sales 
                     WHERE sales_request_id = ? AND approver_role = 'Head' AND status = 'Paid'";
        $stmt = $conn->prepare($check_sql);
        mysqli_stmt_bind_param($stmt, "i", $request_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if (mysqli_num_rows($result) == 0) {
            // Start transaction
            mysqli_begin_transaction($conn);
            
            try {
                // Update request status
                $update_sql = "UPDATE sales_requests SET status = 'Head Paid' WHERE id = ?";
                $stmt = $conn->prepare($update_sql);
                mysqli_stmt_bind_param($stmt, "i", $request_id);
                mysqli_stmt_execute($stmt);
                
                // Prepare comment - use user's comment if provided, otherwise use default
                $final_comment = !empty($comments) ? $comments : 'Request marked as paid by head';
                
                // Add to approvals table - ONLY ONCE
                $insert_sql = "INSERT INTO approvals_sales 
                              (sales_request_id, approver_id, approver_role, status, comments, created_at) 
                              VALUES (?, ?, ?, 'Paid', ?, NOW())";
                $stmt = $conn->prepare($insert_sql);
                // FIXED: Changed "iis" to "iiss"
                mysqli_stmt_bind_param($stmt, "iiss", $request_id, $_SESSION['user_id'], $user_role, $final_comment);
                mysqli_stmt_execute($stmt);
                
                // Commit transaction
                mysqli_commit($conn);
                
                $_SESSION['notification'] = [
                    'type' => 'success',
                    'message' => 'Request marked as paid successfully'
                ];
            } catch (Exception $e) {
                // Rollback transaction on error
                mysqli_rollback($conn);
                
                $_SESSION['notification'] = [
                    'type' => 'error',
                    'message' => 'Error marking request as paid: ' . $e->getMessage()
                ];
            }
        } else {
            $_SESSION['notification'] = [
                'type' => 'error',
                'message' => 'Request already marked as paid'
            ];
        }
    } else {
        $_SESSION['notification'] = [
            'type' => 'error',
            'message' => 'You are not authorized to mark this request as paid'
        ];
    }
} elseif ($action == 'resubmit' && has_role('User') && $request['status'] == 'Rejected') {
    // Check if this user owns this request
    if ($request['user_id'] == $_SESSION['user_id']) {
        // Start transaction
        mysqli_begin_transaction($conn);
        
        try {
            // Update request status back to pending
            $update_sql = "UPDATE sales_requests SET status = 'Pending' WHERE id = ?";
            $stmt = $conn->prepare($update_sql);
            mysqli_stmt_bind_param($stmt, "i", $request_id);
            mysqli_stmt_execute($stmt);
            
            // Prepare comment - use user's comment if provided, otherwise use default
            $final_comment = !empty($comments) ? $comments : 'Request resubmitted by user';
            
            // Add to approvals table - ONLY ONCE
            $insert_sql = "INSERT INTO approvals_sales 
                          (sales_request_id, approver_id, approver_role, status, comments, created_at) 
                          VALUES (?, ?, ?, 'Resubmitted', ?, NOW())";
            $stmt = $conn->prepare($insert_sql);
            // FIXED: Changed "iis" to "iiss"
            mysqli_stmt_bind_param($stmt, "iiss", $request_id, $_SESSION['user_id'], $user_role, $final_comment);
            mysqli_stmt_execute($stmt);
            
            // Add justification if provided
            if (!empty($comments)) {
                $just_sql = "INSERT INTO sales_justifications 
                            (sales_request_id, user_id, justification_text, created_at) 
                            VALUES (?, ?, ?, NOW())";
                $stmt = $conn->prepare($just_sql);
                mysqli_stmt_bind_param($stmt, "iis", $request_id, $_SESSION['user_id'], $comments);
                mysqli_stmt_execute($stmt);
            }
            
            // Commit transaction
            mysqli_commit($conn);
            
            $_SESSION['notification'] = [
                'type' => 'success',
                'message' => 'Request resubmitted successfully'
            ];
        } catch (Exception $e) {
            // Rollback transaction on error
            mysqli_rollback($conn);
            
            $_SESSION['notification'] = [
                'type' => 'error',
                'message' => 'Error resubmitting request: ' . $e->getMessage()
            ];
        }
    } else {
        $_SESSION['notification'] = [
            'type' => 'error',
            'message' => 'You are not authorized to resubmit this request'
        ];
    }
} else {
    $_SESSION['notification'] = [
        'type' => 'error',
        'message' => 'Invalid action or unauthorized'
    ];
}

// Redirect back to the referring page
redirect($referrer);
?>