<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'visitor_db');
define('DB_USER', 'mani_user');
define('DB_PASS', 'StrongPassword@123');

// Create connection
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Set charset
mysqli_set_charset($conn, "utf8");

// Start session
session_start();

// Helper function to sanitize input
function sanitize_input($data) {
    global $conn;
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return mysqli_real_escape_string($conn, $data);
}

// Helper function to check if user is logged in
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

// Helper function to check user role
function has_role($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

// Helper function to redirect
function redirect($url) {
    header("Location: $url");
    exit;
}

// Helper function to display notification
function show_notification($message, $type = 'success') {
    $_SESSION['notification'] = [
        'message' => $message,
        'type' => $type
    ];
}

// Helper function to format user name with role
function format_user_name($full_name, $role) {
    return htmlspecialchars($full_name) . " (" . htmlspecialchars($role) . ")";
}

?>