<?php
require_once 'config.php';

// Check if user is logged in and has admin role
if (!is_logged_in() || !has_role('Admin')) {
    echo json_encode(['success' => false, 'message' => 'You do not have permission to access this page']);
    exit;
}

// Get request ID from URL
 $requestId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($requestId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
    exit;
}

// Get request details
 $request_sql = "SELECT * FROM cashback_requests WHERE id = ?";
 $stmt = mysqli_prepare($conn, $request_sql);
mysqli_stmt_bind_param($stmt, "i", $requestId);
mysqli_stmt_execute($stmt);
 $result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($result) > 0) {
    $request = mysqli_fetch_assoc($result);
    echo json_encode(['success' => true, 'request' => $request]);
} else {
    echo json_encode(['success' => false, 'message' => 'Request not found']);
}
?>