<?php
// This file handles the user's response (Interested/Lost) to a specific quotation.
require_once 'config.php';

// Check if user is logged in
if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'You must be logged in']);
    exit;
}

// This script only accepts POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

if (isset($_POST['quotation_id']) && isset($_POST['response'])) {
    $quotation_id = (int)$_POST['quotation_id'];
    $response = sanitize_input($_POST['response']);
    $user_id = $_SESSION['user_id'];
    
    // Verify that this quotation belongs to a request made by this user
    $verify_sql = "SELECT q.id FROM quotations q 
                  JOIN quote_requests qr ON q.quote_request_id = qr.id 
                  WHERE q.id = ? AND qr.user_id = ?";
    $verify_stmt = mysqli_prepare($conn, $verify_sql);
    mysqli_stmt_bind_param($verify_stmt, "ii", $quotation_id, $user_id);
    mysqli_stmt_execute($verify_stmt);
    $verify_result = mysqli_stmt_get_result($verify_stmt);
    
    if (mysqli_num_rows($verify_result) === 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }
    
    // Update quotation response
    $update_sql = "UPDATE quotations SET user_response = ? WHERE id = ?";
    $update_stmt = mysqli_prepare($conn, $update_sql);
    mysqli_stmt_bind_param($update_stmt, "si", $response, $quotation_id);
    
    if (mysqli_stmt_execute($update_stmt)) {
        // Get quote request ID to update its status
        $request_sql = "SELECT quote_request_id FROM quotations WHERE id = ?";
        $request_stmt = mysqli_prepare($conn, $request_sql);
        mysqli_stmt_bind_param($request_stmt, "i", $quotation_id);
        mysqli_stmt_execute($request_stmt);
        $request_result = mysqli_stmt_get_result($request_stmt);
        $request_data = mysqli_fetch_assoc($request_result);
        $quote_request_id = $request_data['quote_request_id'];
        
        // Update the main quote request status
        $new_status = ($response === 'Interested') ? 'User Interested' : 'Lost';
        $status_update_sql = "UPDATE quote_requests SET status = ? WHERE id = ?";
        $status_update_stmt = mysqli_prepare($conn, $status_update_sql);
        mysqli_stmt_bind_param($status_update_stmt, "si", $new_status, $quote_request_id);
        mysqli_stmt_execute($status_update_stmt);
        
        echo json_encode(['success' => true, 'message' => 'Your response has been recorded.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update response.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters.']);
}
?>