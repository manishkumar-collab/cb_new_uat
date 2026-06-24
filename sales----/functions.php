<?php
// /var/www/html/cb_new_uat/sales/functions.php

// Start the session
session_start();

// Database Connection
include_once '../config.php';

// --- General Helper Functions ---

/**
 * Sanitizes user input to prevent XSS attacks.
 * @param string $data The input string to sanitize.
 * @return string The sanitized string.
 */
if (!function_exists('sanitize_input')) {
    function sanitize_input($data) {
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data);
        return $data;
    }
}

/**
 * Redirects the user to a specific page with a notification message.
 * @param string $location The URL to redirect to.
 * @param string $message_type The type of message ('success', 'error', 'info').
 * @param string $message The message to display.
 */
if (!function_exists('redirect_with_message')) {
    function redirect_with_message($location, $message_type, $message) {
        $_SESSION['notification'] = [
            'message' => $message,
            'type' => $message_type
        ];
        header("Location: $location");
        exit();
    }
}

// --- User Authentication and Session Functions ---

/**
 * Checks if a user is currently logged in.
 * @return bool True if the user is logged in, false otherwise.
 */
if (!function_exists('is_logged_in')) {
    function is_logged_in() {
        return isset($_SESSION['user_id']);
    }
}

/**
 * Fetches the current user's details from the database.
 * @return array|null The user's data as an associative array, or null if not found.
 */
if (!function_exists('get_current_user')) {
    function get_current_user() {
        global $conn;
        if (is_logged_in()) {
            $user_id = $_SESSION['user_id'];
            $sql = "SELECT * FROM users WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            return $result->fetch_assoc();
        }
        return null;
    }
}

/**
 * Fetches user details by user ID.
 * @param int $user_id The ID of the user to fetch.
 * @return array|null The user's data as an associative array, or null if not found.
 */
if (!function_exists('get_user_by_id')) {
    function get_user_by_id($user_id) {
        global $conn;
        $sql = "SELECT * FROM users WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
}

// --- Sales Module Specific Functions ---

/**
 * Generates a unique reference number for a new sale request.
 * Format: SA-DDMMYYYY-XXXX (XXXX is a random 4-digit number)
 * @return string The generated reference number.
 */
if (!function_exists('generate_sales_reference_number')) {
    function generate_sales_reference_number() {
        return 'SA-' . date('dmy') . '-' . mt_rand(1000, 9999);
    }
}

/**
 * Fetches users mapped to a specific role (e.g., users under a manager).
 * @param string $role The role to filter by ('Manager', 'Head', 'Support').
 * @param int $current_user_id The ID of the user making the request.
 * @return array An array of users mapped to the specified role.
 */
if (!function_exists('get_mapped_users')) {
    function get_mapped_users($role, $current_user_id) {
        global $conn;
        $mapped_users = [];
        
        $sql = "SELECT id, full_name FROM users WHERE ";
        if ($role === 'Manager') {
            $sql .= "manager_id = ?";
        } elseif ($role === 'Head') {
            $sql .= "head_id = ?";
        } elseif ($role === 'Support') {
            $sql .= "support_id = ?";
        }
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $current_user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $mapped_users[] = $row;
        }
        
        return $mapped_users;
    }
}

/**
 * Formats a date for display.
 * @param string $date_string The date string to format.
 * @return string The formatted date string.
 */
if (!function_exists('format_date')) {
    function format_date($date_string) {
        if (empty($date_string)) return 'N/A';
        return date("d-M-Y", strtotime($date_string));
    }
}

/**
 * Displays a notification message stored in the session and then removes it.
 * This function should be called in the view where you want to display the message.
 */
if (!function_exists('display_message')) {
    function display_message() {
        if (isset($_SESSION['notification'])) {
            $message = $_SESSION['notification']['message'];
            $type = isset($_SESSION['notification']['type']) ? $_SESSION['notification']['type'] : 'info';
            
            // Bootstrap alert classes
            $alert_class = 'alert-info';
            if ($type === 'success') $alert_class = 'alert-success';
            if ($type === 'error') $alert_class = 'alert-danger';
            
            echo "<div class='alert $alert_class alert-dismissible fade show' role='alert'>
                    $message
                    <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
                  </div>";
            
            unset($_SESSION['notification']);
        }
    }
}

/**
 * Checks if a user has a specific role.
 * @param string $role The role to check.
 * @return bool True if the current user has the role, false otherwise.
 */
if (!function_exists('has_role')) {
    function has_role($role) {
        $user = get_current_user();
        return $user && $user['role'] === $role;
    }
}

/**
 * Handles file upload for payment screenshots or other documents.
 * @param array $file The file from $_FILES array.
 * @param string $upload_dir The directory to upload the file to.
 * @return string|false The path to the uploaded file, or false on failure.
 */
if (!function_exists('handle_file_upload')) {
    function handle_file_upload($file, $upload_dir) {
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_name = time() . '_' . basename($file['name']);
        $target_file = $upload_dir . $file_name;
        
        if (move_uploaded_file($file['tmp_name'], $target_file)) {
            return $target_file;
        }
        
        return false;
    }
}

?>