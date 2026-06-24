<?php
require_once 'config.php';

// Check if user is logged in and has finance role
if (!is_logged_in() || !has_role('Finance')) {
    show_notification('You do not have permission to access this page', 'error');
    redirect('finance_requests.php');
}

// Check if request ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    show_notification('Invalid request', 'error');
    redirect('finance_requests.php');
}

 $request_id = $_GET['id'];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $comments = sanitize_input($_POST['comments']) ?? '';
    $utr_number = sanitize_input($_POST['utr_number']) ?? '';
    $payment_screenshot_url = '';
    
    // Handle file upload
    if (isset($_FILES['payment_screenshot']) && $_FILES['payment_screenshot']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['payment_screenshot'];
        $file_name = $file['name'];
        $file_tmp = $file['tmp_name'];
        $file_size = $file['size'];
        
        // Check file size (5MB max)
        if ($file_size > 5 * 1024 * 1024) {
            show_notification('File size must be less than 5MB', 'error');
            redirect('finance_requests.php');
        }
        
        // Check file type
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $file_type = mime_content_type($file_tmp);
        
        if (!in_array($file_type, $allowed_types)) {
            show_notification('Only JPG, PNG, and GIF files are allowed', 'error');
            redirect('finance_requests.php');
        }
        
        // Generate unique filename
        $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
        $new_filename = time() . '_' . uniqid() . '.' . $file_extension;
        $upload_path = 'uploads/payments/' . $new_filename;
        
        // Create directory if it doesn't exist
        if (!is_dir('uploads/payments/')) {
            mkdir('uploads/payments/', 0755, true);
        }
        
        // Move uploaded file
        if (move_uploaded_file($file_tmp, $upload_path)) {
            $payment_screenshot_url = $upload_path;
        } else {
            show_notification('Error uploading file', 'error');
            redirect('finance_requests.php');
        }
    }
    
    // Begin transaction
    mysqli_begin_transaction($conn);
    
    try {
        // Update cashback request status
        $update_sql = "UPDATE cashback_requests SET status = 'Finance Approved', utr_number = ?, payment_screenshot_url = ?, updated_at = NOW() WHERE id = ?";
        $stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($stmt, "ssi", $utr_number, $payment_screenshot_url, $request_id);
        mysqli_stmt_execute($stmt);
        
        // Insert approval record
        $approval_sql = "INSERT INTO approvals (request_id, approver_id, approver_role, status, comments) 
                         VALUES (?, ?, 'Finance', 'Paid', ?)";
        $stmt = mysqli_prepare($conn, $approval_sql);
        mysqli_stmt_bind_param($stmt, "iis", $request_id, $_SESSION['user_id'], $comments);
        mysqli_stmt_execute($stmt);
        
        // Commit transaction
        mysqli_commit($conn);
        
        show_notification('Request marked as paid successfully', 'success');
        redirect('finance_requests.php');
    } catch (Exception $e) {
        // Rollback transaction
        mysqli_rollback($conn);
        
        show_notification('Error processing payment: ' . $e->getMessage(), 'error');
        redirect('finance_requests.php');
    }
}
?>