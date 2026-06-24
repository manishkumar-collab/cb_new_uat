<?php
// /var/www/html/cb_new_uat/sales/functions.php

// We will NOT start the session here anymore.
// The main config.php is already handling it.

// Include the main config file of the application.
require_once '../config.php';

// --- Define our module's functions ONLY if they don't already exist ---

if (!function_exists('is_logged_in')) {
    function is_logged_in() {
        return isset($_SESSION['user_id']);
    }
}

if (!function_exists('has_role')) {
    function has_role($role) {
        return isset($_SESSION['role']) && $_SESSION['role'] === $role;
    }
}

if (!function_exists('getUserDetails')) {
    function getUserDetails($userId) {
        global $conn;
        if (!$conn || $conn->connect_error) {
            error_log("Sales Module: Database connection failed or not found in getUserDetails().");
            return false;
        }
        $sql = "SELECT * FROM users WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            error_log("Sales Module: Prepare failed in getUserDetails(): " . $conn->error);
            return false;
        }
        $stmt->bind_param("i", $userId);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            return $result->fetch_assoc();
        } else {
            error_log("Sales Module: Execute failed in getUserDetails(): " . $stmt->error);
            return false;
        }
    }
}

if (!function_exists('generateReferenceNumber')) {
    function generateReferenceNumber() {
        return 'SA-' . date('ymd') . '-' . strtoupper(uniqid());
    }
}

if (!function_exists('generateUniqueSalesCode')) {
    function generateUniqueSalesCode($userId) {
        return 'SALE-' . $userId . '-' . date('YmdHis');
    }
}

if (!function_exists('addRequestHistory')) {
    function addRequestHistory($request_id, $user_id, $action, $comments = '') {
        global $conn;
        
        // Get user role
        $user_details = getUserDetails($user_id);
        $role = $user_details ? $user_details['role'] : 'Unknown';
        
        $sql = "INSERT INTO approvals_sales 
                (sales_request_id, approver_id, approver_role, status, comments, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())";
        
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            error_log("Sales Module: Prepare failed in addRequestHistory(): " . $conn->error);
            return false;
        }
        
        $stmt->bind_param("iisss", $request_id, $user_id, $role, $action, $comments);
        if ($stmt->execute()) {
            return true;
        } else {
            error_log("Sales Module: Execute failed in addRequestHistory(): " . $stmt->error);
            return false;
        }
    }
}

?>