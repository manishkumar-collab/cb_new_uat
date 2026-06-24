<?php
require_once 'config.php';

// Check if user is logged in
if (!is_logged_in()) {
    show_notification('You must be logged in to perform this action', 'error');
    redirect('login.php');
}

// Get request ID and justification text
 $request_id = $_POST['request_id'] ?? 0;
 $justification_text = $_POST['justification_text'] ?? '';

if (empty($request_id) || empty($justification_text)) {
    show_notification('Invalid request or missing justification', 'error');
    redirect('dashboard_' . strtolower($_SESSION['role']) . '.php');
}

// Get request details
 $request_sql = "SELECT * FROM cashback_requests WHERE id = ? AND user_id = ?";
 $stmt = mysqli_prepare($conn, $request_sql);
mysqli_stmt_bind_param($stmt, "ii", $request_id, $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
 $request_result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($request_result) === 0) {
    show_notification('Request not found or you are not authorized', 'error');
    redirect('dashboard_' . strtolower($_SESSION['role']) . '.php');
}

 $request = mysqli_fetch_assoc($request_result);

// Check if request is in Validator Rejected status
if ($request['status'] !== 'Validator Rejected') {
    show_notification('You can only provide justification for validator rejected requests', 'error');
    redirect('view_request.php?id=' . $request_id);
}

// Get the latest validator rejection approval ID
 $approval_sql = "SELECT id FROM approvals WHERE request_id = ? AND approver_role = 'Validator' AND status = 'Rejected' ORDER BY created_at DESC LIMIT 1";
 $stmt = mysqli_prepare($conn, $approval_sql);
mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
 $approval_result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($approval_result) === 0) {
    show_notification('Validator rejection not found', 'error');
    redirect('view_request.php?id=' . $request_id);
}

 $approval = mysqli_fetch_assoc($approval_result);
 $approval_id = $approval['id'];

// Check if user has already provided justification
 $check_sql = "SELECT id FROM user_justifications WHERE request_id = ? AND user_id = ? AND approval_id = ?";
 $stmt = mysqli_prepare($conn, $check_sql);
mysqli_stmt_bind_param($stmt, "iii", $request_id, $_SESSION['user_id'], $approval_id);
mysqli_stmt_execute($stmt);
 $check_result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($check_result) > 0) {
    show_notification('You have already provided a justification for this request', 'error');
    redirect('view_request.php?id=' . $request_id);
}

// Insert user justification
 $insert_sql = "INSERT INTO user_justifications (request_id, user_id, approval_id, justification_text) VALUES (?, ?, ?, ?)";
 $stmt = mysqli_prepare($conn, $insert_sql);
mysqli_stmt_bind_param($stmt, "iiis", $request_id, $_SESSION['user_id'], $approval_id, $justification_text);
mysqli_stmt_execute($stmt);

// Update request status to Pending Validation
 $update_sql = "UPDATE cashback_requests SET status = 'Pending Validation' WHERE id = ?";
 $stmt = mysqli_prepare($conn, $update_sql);
mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);

show_notification('Justification submitted successfully', 'success');
redirect('view_request.php?id=' . $request_id);
?>