<?php
require_once 'config.php';

// Check if user is logged in and has validator role
if (!is_logged_in() || !has_role('Validator')) {
    echo json_encode(['success' => false, 'message' => 'You do not have permission to perform this action']);
    exit;
}

// Get POST data
 $requestId = $_POST['requestId'] ?? 0;
 $action = $_POST['action'] ?? '';
 $comments = $_POST['comments'] ?? '';

// Validate input
if (empty($requestId) || !in_array($action, ['approve', 'reject'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// Get current user ID
 $validatorId = $_SESSION['user_id'];

// Begin transaction
mysqli_begin_transaction($conn);

try {
    // Update cashback request status
    $status = ($action === 'approve') ? 'Validator Approved' : 'Validator Rejected';
    $updateSql = "UPDATE cashback_requests SET status = ?, updated_at = NOW() WHERE id = ?";
    $updateStmt = mysqli_prepare($conn, $updateSql);
    mysqli_stmt_bind_param($updateStmt, "si", $status, $requestId);
    mysqli_stmt_execute($updateStmt);
    
    // Insert record into approvals table
    $approvalStatus = ($action === 'approve') ? 'Approved' : 'Rejected';
    $insertSql = "INSERT INTO approvals (request_id, approver_id, approver_role, status, comments, created_at) 
                  VALUES (?, ?, 'Validator', ?, ?, NOW())";
    $insertStmt = mysqli_prepare($conn, $insertSql);
    mysqli_stmt_bind_param($insertStmt, "iiss", $requestId, $validatorId, $approvalStatus, $comments);
    mysqli_stmt_execute($insertStmt);
    
    // Commit transaction
    mysqli_commit($conn);
    
    echo json_encode(['success' => true, 'message' => 'Request has been ' . $action . 'd successfully']);
} catch (Exception $e) {
    // Rollback transaction on error
    mysqli_rollback($conn);
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
}
?>