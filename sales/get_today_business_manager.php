<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if config file exists
if (!file_exists('../config.php')) {
    die("Error: Config file not found. Please check the file path.");
}

require_once '../config.php';

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and has manager role
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] != 'Manager') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Get manager details
 $manager_id = $_SESSION['user_id'];

try {
    // Database connection check
    if (!$conn) {
        throw new Exception("Database connection failed");
    }
    
    // Get today's business data
    $today_business_sql = "SELECT 
                          SUM(sr.premium) AS today_amount,
                          COUNT(*) AS today_count
                          FROM sales_requests sr 
                          JOIN users u ON sr.user_id = u.id 
                          WHERE u.manager_id = ? AND sr.status = 'Head Paid' 
                          AND DATE(sr.updated_at) = CURDATE()";
    $stmt = mysqli_prepare($conn, $today_business_sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare today's business query: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "i", $manager_id);
    mysqli_stmt_execute($stmt);
    $today_business_result = mysqli_stmt_get_result($stmt);
    $today_business = mysqli_fetch_assoc($today_business_result);
    
    // Return the data as JSON
    header('Content-Type: application/json');
    echo json_encode([
        'amount' => $today_business['today_amount'] ? $today_business['today_amount'] : 0,
        'count' => $today_business['today_count'] ? $today_business['today_count'] : 0
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>