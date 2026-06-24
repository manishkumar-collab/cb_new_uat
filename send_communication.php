<?php
require_once 'config.php';

// Check if user is logged in
if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'You must be logged in']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $quote_request_id = $_POST['quote_request_id'];
    $message = $_POST['message'];
    $user_id = $_SESSION['user_id'];
    
    // Verify that this quote request belongs to this user
    $verify_sql = "SELECT id FROM quote_requests WHERE id = ? AND user_id = ?";
    $verify_stmt = mysqli_prepare($conn, $verify_sql);
    mysqli_stmt_bind_param($verify_stmt, "ii", $quote_request_id, $user_id);
    mysqli_stmt_execute($verify_stmt);
    $verify_result = mysqli_stmt_get_result($verify_stmt);
    
    if (mysqli_num_rows($verify_result) === 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }
    
    // Handle file upload
    $attachment_url = null;
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/communications/';
        if (!is_dir($upload_dir)) { 
            mkdir($upload_dir, 0755, true); 
        }
        $file_name = basename($_FILES["attachment"]["name"]);
        $attachment_url = $upload_dir . uniqid() . '_' . $file_name;
        move_uploaded_file($_FILES["attachment"]["tmp_name"], $attachment_url);
    }
    
    // Insert communication
    $insert_sql = "INSERT INTO quote_communications (quote_request_id, sender_id, message, attachment_url) VALUES (?, ?, ?, ?)";
    $insert_stmt = mysqli_prepare($conn, $insert_sql);
    mysqli_stmt_bind_param($insert_stmt, "iiss", $quote_request_id, $user_id, $message, $attachment_url);
    
    if (mysqli_stmt_execute($insert_stmt)) {
        // Update quote request status
        $update_sql = "UPDATE quote_requests SET status = 'User Replied' WHERE id = ?";
        $update_stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($update_stmt, "i", $quote_request_id);
        mysqli_stmt_execute($update_stmt);
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to send message']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>