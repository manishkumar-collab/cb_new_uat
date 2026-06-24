<?php
require_once 'config.php';

// Check if user is logged in and has head role
if (!is_logged_in() || !has_role('Head')) {
    show_notification('You do not have permission to access this page', 'error');
    redirect('login.php');
}

// Check if request ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    show_notification('Invalid request', 'error');
    redirect('dashboard_head.php');
}

 $request_id = $_GET['id'];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $comments = sanitize_input($_POST['comments']) ?? '';
    
    // --- NEW: Check Validator Setting ---
    $is_validator_active = true; // Default to true
    
    $check_setting_sql = "SELECT setting_value FROM system_settings WHERE setting_key = 'validator_active'";
    $setting_stmt = mysqli_prepare($conn, $check_setting_sql);
    if ($setting_stmt) {
        mysqli_stmt_execute($setting_stmt);
        $setting_result = mysqli_stmt_get_result($setting_stmt);
        if ($row = mysqli_fetch_assoc($setting_result)) {
            $is_validator_active = ($row['setting_value'] == '1');
        }
    }

    // Decide the next status based on validator setting
    if ($is_validator_active) {
        // Normal Flow: Head approves, goes to Validator
        $next_status = 'Head Approved';
    } else {
        // Bypass Flow: Head approves, skips Validator, goes to Finance
        // We set status to 'Validator Approved' so it moves to the next stage in the workflow
        $next_status = 'Validator Approved';
    }
    // ----------------------------------
    
    // --- NEW: Fetch Finance ID mapped to this Head ---
    $head_id = $_SESSION['user_id'];
    $mapped_finance_id = null;
    
    $finance_sql = "SELECT finance_id FROM users WHERE id = ?";
    $finance_stmt = mysqli_prepare($conn, $finance_sql);
    if ($finance_stmt) {
        mysqli_stmt_bind_param($finance_stmt, "i", $head_id);
        mysqli_stmt_execute($finance_stmt);
        $finance_result = mysqli_stmt_get_result($finance_stmt);
        if ($fin_row = mysqli_fetch_assoc($finance_result)) {
            $mapped_finance_id = $fin_row['finance_id']; // Will be NULL if not mapped
        }
    }
    // ------------------------------------------------
    
    // Begin transaction
    mysqli_begin_transaction($conn);
    
    try {
        // Update cashback request status AND finance_id
        $update_sql = "UPDATE cashback_requests SET status = ?, finance_id = ?, updated_at = NOW() WHERE id = ?";
        $stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($stmt, "sii", $next_status, $mapped_finance_id, $request_id);
        mysqli_stmt_execute($stmt);
        
        // Insert Head approval record
        $approval_sql = "INSERT INTO approvals (request_id, approver_id, approver_role, status, comments) 
                         VALUES (?, ?, 'Head', 'Approved', ?)";
        $stmt = mysqli_prepare($conn, $approval_sql);
        mysqli_stmt_bind_param($stmt, "iis", $request_id, $_SESSION['user_id'], $comments);
        mysqli_stmt_execute($stmt);

        // --- NEW: If Validator is INACTIVE, auto-insert Validator approval ---
        if (!$is_validator_active) {
            // We insert a dummy validator approval to bypass the step logically in the database
            $bypass_sql = "INSERT INTO approvals (request_id, approver_id, approver_role, status, comments) 
                           VALUES (?, ?, 'Validator', 'Approved', 'Auto-approved (Validator Bypass)')";
            // We use the Head's ID for the record or could use NULL, using Head ID for audit trail
            $stmt = mysqli_prepare($conn, $bypass_sql);
            mysqli_stmt_bind_param($stmt, "ii", $request_id, $_SESSION['user_id']);
            mysqli_stmt_execute($stmt);
        }
        // --------------------------------------------------------------
        
        // Commit transaction
        mysqli_commit($conn);
        
        show_notification('Request approved successfully', 'success');
        redirect('dashboard_head.php');
    } catch (Exception $e) {
        // Rollback transaction
        mysqli_rollback($conn);
        
        show_notification('Error approving request: ' . $e->getMessage(), 'error');
        redirect('dashboard_head.php');
    }
}
?>