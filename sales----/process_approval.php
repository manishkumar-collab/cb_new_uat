<?php
// FOR DEBUGGING ONLY - REMOVE IN PRODUCTION
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ... rest of your code starts here
session_start();
require_once '../config.php';
// ... and so on


// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['sale_id']) || !isset($_POST['action'])) {
    $_SESSION['notification'] = ['message' => 'Invalid request.', 'type' => 'error'];
    header('Location: index.php');
    exit();
}

 $sale_id = $_POST['sale_id'];
 $action = $_POST['action'];
 $comments = isset($_POST['comments']) ? trim($_POST['comments']) : ''; // Get comments and trim whitespace
 $user_id = $_SESSION['user_id'];
 $user_role = $_SESSION['role'];

// Fetch sale details to check permissions and current status
 $sql = "SELECT * FROM sales_requests WHERE id = ?";
 $stmt = $conn->prepare($sql);
 $stmt->bind_param("i", $sale_id);
 $stmt->execute();
 $result = $stmt->get_result();
 $sale = $result->fetch_assoc();

if (!$sale) {
    $_SESSION['notification'] = ['message' => 'Sale request not found.', 'type' => 'error'];
    header('Location: index.php');
    exit();
}

// Validate action based on user role and current status
 $new_status = '';
 $approval_role = '';

if ($user_role === 'Manager' && $sale['status'] === 'Pending' && $action === 'verify') {
    $new_status = 'Manager Verified';
    $approval_role = 'Manager';
} elseif (($user_role === 'Manager' || $user_role === 'Head') && $action === 'reject') {
    $new_status = 'Rejected';
    $approval_role = ($user_role === 'Manager') ? 'Manager' : 'Head';
} elseif ($user_role === 'Head' && $sale['status'] === 'Manager Verified' && $action === 'paid') {
    $new_status = 'Head Paid';
    $approval_role = 'Head';
} else {
    $_SESSION['notification'] = ['message' => 'You are not authorized to perform this action.', 'type' => 'error'];
    header('Location: index.php');
    exit();
}

// Start transaction for data integrity
 $conn->begin_transaction();

try {
    // Update sales_requests table
    $update_sql = "UPDATE sales_requests SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("si", $new_status, $sale_id);
    $update_stmt->execute();

    // IMPORTANT: Insert into sales_approvals table to record the action and comments
    // This is the data that index.php will fetch and display
    $approval_sql = "INSERT INTO sales_approvals (sales_request_id, approver_id, approver_role, status, comments) VALUES (?, ?, ?, ?, ?)";
    $approval_stmt = $conn->prepare($approval_sql);
    $approval_stmt->bind_param("issss", $sale_id, $user_id, $approval_role, $new_status, $comments);
    $approval_stmt->execute();

    // Commit transaction
    $conn->commit();

    $action_message = ucfirst($new_status);
    $_SESSION['notification'] = ['message' => "Sale request successfully {$action_message}.", 'type' => 'success'];
    header('Location: index.php');

} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    $_SESSION['notification'] = ['message' => 'Error: ' . $e->getMessage(), 'type' => 'error'];
    header('Location: index.php');
}

 $conn->close();
?>