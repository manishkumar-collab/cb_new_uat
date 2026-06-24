<?php
require_once 'config.php';

// Check if user is logged in
if (!is_logged_in()) {
    show_notification('You must be logged in to perform this action', 'error');
    redirect('login.php');
}

// Get action and request ID
 $action = $_GET['action'] ?? '';
 $request_id = $_GET['id'] ?? 0;

if (empty($action) || empty($request_id)) {
    show_notification('Invalid action', 'error');
    redirect('dashboard_' . strtolower($_SESSION['role']) . '.php');
}

// Get request details
 $request_sql = "SELECT * FROM cashback_requests WHERE id = ?";
 $stmt = mysqli_prepare($conn, $request_sql);
mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
 $request_result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($request_result) === 0) {
    show_notification('Request not found', 'error');
    redirect('dashboard_' . strtolower($_SESSION['role']) . '.php');
}

 $request = mysqli_fetch_assoc($request_result);

// Get comments
 $comments = $_POST['comments'] ?? '';

// Process based on action
switch ($action) {
    case 'manager_approve':
        if (!has_role('Manager') || $request['status'] !== 'Pending') {
            show_notification('You are not authorized to perform this action', 'error');
            redirect('dashboard_' . strtolower($_SESSION['role']) . '.php');
        }
        
        // Update request status
        $update_sql = "UPDATE cashback_requests SET status = 'Manager Approved' WHERE id = ?";
        $stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($stmt, "i", $request_id);
        mysqli_stmt_execute($stmt);
        
        // Add approval record
        $approval_sql = "INSERT INTO approvals (request_id, approver_id, approver_role, status, comments) VALUES (?, ?, 'Manager', 'Approved', ?)";
        $stmt = mysqli_prepare($conn, $approval_sql);
        mysqli_stmt_bind_param($stmt, "iis", $request_id, $_SESSION['user_id'], $comments);
        mysqli_stmt_execute($stmt);
        
        show_notification('Request approved successfully', 'success');
        break;
        
    case 'manager_reject':
        if (!has_role('Manager') || $request['status'] !== 'Pending') {
            show_notification('You are not authorized to perform this action', 'error');
            redirect('dashboard_' . strtolower($_SESSION['role']) . '.php');
        }
        
        // Update request status
        $update_sql = "UPDATE cashback_requests SET status = 'Rejected' WHERE id = ?";
        $stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($stmt, "i", $request_id);
        mysqli_stmt_execute($stmt);
        
        // Add approval record
        $approval_sql = "INSERT INTO approvals (request_id, approver_id, approver_role, status, comments) VALUES (?, ?, 'Manager', 'Rejected', ?)";
        $stmt = mysqli_prepare($conn, $approval_sql);
        mysqli_stmt_bind_param($stmt, "iis", $request_id, $_SESSION['user_id'], $comments);
        mysqli_stmt_execute($stmt);
        
        show_notification('Request rejected', 'error');
        break;
        
    case 'head_approve':
        if (!has_role('Head') || $request['status'] !== 'Manager Approved') {
            show_notification('You are not authorized to perform this action', 'error');
            redirect('dashboard_' . strtolower($_SESSION['role']) . '.php');
        }
        
        // Update request status
        $update_sql = "UPDATE cashback_requests SET status = 'Head Approved' WHERE id = ?";
        $stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($stmt, "i", $request_id);
        mysqli_stmt_execute($stmt);
        
        // Add approval record
        $approval_sql = "INSERT INTO approvals (request_id, approver_id, approver_role, status, comments) VALUES (?, ?, 'Head', 'Approved', ?)";
        $stmt = mysqli_prepare($conn, $approval_sql);
        mysqli_stmt_bind_param($stmt, "iis", $request_id, $_SESSION['user_id'], $comments);
        mysqli_stmt_execute($stmt);
        
        show_notification('Request approved successfully', 'success');
        break;
        
    case 'head_reject':
        if (!has_role('Head') || $request['status'] !== 'Manager Approved') {
            show_notification('You are not authorized to perform this action', 'error');
            redirect('dashboard_' . strtolower($_SESSION['role']) . '.php');
        }
        
        // Update request status
        $update_sql = "UPDATE cashback_requests SET status = 'Rejected' WHERE id = ?";
        $stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($stmt, "i", $request_id);
        mysqli_stmt_execute($stmt);
        
        // Add approval record
        $approval_sql = "INSERT INTO approvals (request_id, approver_id, approver_role, status, comments) VALUES (?, ?, 'Head', 'Rejected', ?)";
        $stmt = mysqli_prepare($conn, $approval_sql);
        mysqli_stmt_bind_param($stmt, "iis", $request_id, $_SESSION['user_id'], $comments);
        mysqli_stmt_execute($stmt);
        
        show_notification('Request rejected', 'error');
        break;
        
    case 'validator_approve':
        if (!has_role('Validator') || $request['status'] !== 'Head Approved') {
            show_notification('You are not authorized to perform this action', 'error');
            redirect('dashboard_' . strtolower($_SESSION['role']) . '.php');
        }
        
        // Update request status
        $update_sql = "UPDATE cashback_requests SET status = 'Validator Approved' WHERE id = ?";
        $stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($stmt, "i", $request_id);
        mysqli_stmt_execute($stmt);
        
        // Add approval record
        $approval_sql = "INSERT INTO approvals (request_id, approver_id, approver_role, status, comments) VALUES (?, ?, 'Validator', 'Approved', ?)";
        $stmt = mysqli_prepare($conn, $approval_sql);
        mysqli_stmt_bind_param($stmt, "iis", $request_id, $_SESSION['user_id'], $comments);
        mysqli_stmt_execute($stmt);
        
        show_notification('Request approved successfully', 'success');
        break;
        
    case 'validator_reject':
        if (!has_role('Validator') || $request['status'] !== 'Head Approved') {
            show_notification('You are not authorized to perform this action', 'error');
            redirect('dashboard_' . strtolower($_SESSION['role']) . '.php');
        }
        
        // Update request status
        $update_sql = "UPDATE cashback_requests SET status = 'Validator Rejected' WHERE id = ?";
        $stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($stmt, "i", $request_id);
        mysqli_stmt_execute($stmt);
        
        // Add approval record
        $approval_sql = "INSERT INTO approvals (request_id, approver_id, approver_role, status, comments) VALUES (?, ?, 'Validator', 'Rejected', ?)";
        $stmt = mysqli_prepare($conn, $approval_sql);
        mysqli_stmt_bind_param($stmt, "iis", $request_id, $_SESSION['user_id'], $comments);
        mysqli_stmt_execute($stmt);
        
        // Get the approval ID for the validator rejection
        $approval_id = mysqli_insert_id($conn);
        
        // Update validator_id in cashback_requests table
        $update_validator_sql = "UPDATE cashback_requests SET validator_id = ? WHERE id = ?";
        $stmt = mysqli_prepare($conn, $update_validator_sql);
        mysqli_stmt_bind_param($stmt, "ii", $_SESSION['user_id'], $request_id);
        mysqli_stmt_execute($stmt);
        
        show_notification('Request rejected. User can now provide a justification.', 'error');
        break;
        
    case 'validator_review_justification':
        if (!has_role('Validator') || $request['status'] !== 'Validator Rejected') {
            show_notification('You are not authorized to perform this action', 'error');
            redirect('dashboard_' . strtolower($_SESSION['role']) . '.php');
        }
        
        // Check if user has provided justification
        $justification_sql = "SELECT * FROM user_justifications WHERE request_id = ? AND user_id = ? ORDER BY created_at DESC LIMIT 1";
        $stmt = mysqli_prepare($conn, $justification_sql);
        mysqli_stmt_bind_param($stmt, "ii", $request_id, $request['user_id']);
        mysqli_stmt_execute($stmt);
        $justification_result = mysqli_stmt_get_result($stmt);
        
        if (mysqli_num_rows($justification_result) === 0) {
            show_notification('No justification found for this request', 'error');
            redirect('view_request.php?id=' . $request_id);
        }
        
        // Add approval record for reviewing justification
        $approval_sql = "INSERT INTO approvals (request_id, approver_id, approver_role, status, comments) VALUES (?, ?, 'Validator', 'Approved', ?)";
        $stmt = mysqli_prepare($conn, $approval_sql);
        mysqli_stmt_bind_param($stmt, "iis", $request_id, $_SESSION['user_id'], $comments);
        mysqli_stmt_execute($stmt);
        
        // Update request status
        $update_sql = "UPDATE cashback_requests SET status = 'Validator Approved' WHERE id = ?";
        $stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($stmt, "i", $request_id);
        mysqli_stmt_execute($stmt);
        
        show_notification('Request approved after reviewing justification', 'success');
        break;
        
    case 'validator_reject_justification':
        if (!has_role('Validator') || $request['status'] !== 'Validator Rejected') {
            show_notification('You are not authorized to perform this action', 'error');
            redirect('dashboard_' . strtolower($_SESSION['role']) . '.php');
        }
        
        // Check if user has provided justification
        $justification_sql = "SELECT * FROM user_justifications WHERE request_id = ? AND user_id = ? ORDER BY created_at DESC LIMIT 1";
        $stmt = mysqli_prepare($conn, $justification_sql);
        mysqli_stmt_bind_param($stmt, "ii", $request_id, $request['user_id']);
        mysqli_stmt_execute($stmt);
        $justification_result = mysqli_stmt_get_result($stmt);
        
        if (mysqli_num_rows($justification_result) === 0) {
            show_notification('No justification found for this request', 'error');
            redirect('view_request.php?id=' . $request_id);
        }
        
        // Add approval record for rejecting justification
        $approval_sql = "INSERT INTO approvals (request_id, approver_id, approver_role, status, comments) VALUES (?, ?, 'Validator', 'Rejected', ?)";
        $stmt = mysqli_prepare($conn, $approval_sql);
        mysqli_stmt_bind_param($stmt, "iis", $request_id, $_SESSION['user_id'], $comments);
        mysqli_stmt_execute($stmt);
        
        // Update request status
        $update_sql = "UPDATE cashback_requests SET status = 'Rejected' WHERE id = ?";
        $stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($stmt, "i", $request_id);
        mysqli_stmt_execute($stmt);
        
        show_notification('Request rejected after reviewing justification', 'error');
        break;
        
    case 'finance_approve':
        if (!has_role('Finance') || $request['status'] !== 'Validator Approved') {
            show_notification('You are not authorized to perform this action', 'error');
            redirect('dashboard_' . strtolower($_SESSION['role']) . '.php');
        }
        
        // Update request status
        $update_sql = "UPDATE cashback_requests SET status = 'Finance Approved' WHERE id = ?";
        $stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($stmt, "i", $request_id);
        mysqli_stmt_execute($stmt);
        
        // Add approval record
        $approval_sql = "INSERT INTO approvals (request_id, approver_id, approver_role, status, comments) VALUES (?, ?, 'Finance', 'Approved', ?)";
        $stmt = mysqli_prepare($conn, $approval_sql);
        mysqli_stmt_bind_param($stmt, "iis", $request_id, $_SESSION['user_id'], $comments);
        mysqli_stmt_execute($stmt);
        
        show_notification('Request approved successfully', 'success');
        break;
        
    case 'finance_pay':
        if (!has_role('Finance') || $request['status'] !== 'Validator Approved') {
            show_notification('You are not authorized to perform this action', 'error');
            redirect('dashboard_' . strtolower($_SESSION['role']) . '.php');
        }
        
        // Get UTR number and payment screenshot
        $utr_number = $_POST['utr_number'] ?? '';
        $payment_screenshot = '';
        
        // Handle file upload
        if (isset($_FILES['payment_screenshot']) && $_FILES['payment_screenshot']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/payments/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_name = time() . '_' . basename($_FILES['payment_screenshot']['name']);
            $target_file = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['payment_screenshot']['tmp_name'], $target_file)) {
                $payment_screenshot = $target_file;
            }
        }
        
        // Update request status and payment details
        $update_sql = "UPDATE cashback_requests SET status = 'Finance Approved', utr_number = ?, payment_screenshot_url = ? WHERE id = ?";
        $stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($stmt, "ssi", $utr_number, $payment_screenshot, $request_id);
        mysqli_stmt_execute($stmt);
        
        // Add approval record
        $approval_sql = "INSERT INTO approvals (request_id, approver_id, approver_role, status, comments) VALUES (?, ?, 'Finance', 'Paid', ?)";
        $stmt = mysqli_prepare($conn, $approval_sql);
        mysqli_stmt_bind_param($stmt, "iis", $request_id, $_SESSION['user_id'], $comments);
        mysqli_stmt_execute($stmt);
        
        // Get the approval ID for the finance approval
        $approval_id = mysqli_insert_id($conn);
        
        // Update UTR number and payment screenshot in approvals table
        $update_approval_sql = "UPDATE approvals SET utr_number = ?, payment_screenshot_url = ? WHERE id = ?";
        $stmt = mysqli_prepare($conn, $update_approval_sql);
        mysqli_stmt_bind_param($stmt, "ssi", $utr_number, $payment_screenshot, $approval_id);
        mysqli_stmt_execute($stmt);
        
        show_notification('Payment processed successfully', 'success');
        break;
        
    case 'finance_reject':
        if (!has_role('Finance') || $request['status'] !== 'Validator Approved') {
            show_notification('You are not authorized to perform this action', 'error');
            redirect('dashboard_' . strtolower($_SESSION['role']) . '.php');
        }
        
        // Update request status
        $update_sql = "UPDATE cashback_requests SET status = 'Rejected' WHERE id = ?";
        $stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($stmt, "i", $request_id);
        mysqli_stmt_execute($stmt);
        
        // Add approval record
        $approval_sql = "INSERT INTO approvals (request_id, approver_id, approver_role, status, comments) VALUES (?, ?, 'Finance', 'Rejected', ?)";
        $stmt = mysqli_prepare($conn, $approval_sql);
        mysqli_stmt_bind_param($stmt, "iis", $request_id, $_SESSION['user_id'], $comments);
        mysqli_stmt_execute($stmt);
        
        show_notification('Request rejected', 'error');
        break;
        
    default:
        show_notification('Invalid action', 'error');
        break;
}

// Redirect back to request details
redirect('view_request.php?id=' . $request_id);
?>