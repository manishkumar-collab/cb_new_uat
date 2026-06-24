<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if config file exists
if (!file_exists('../config.php')) {
    die("Error: Config file not found. Please check the file path.");
}

require_once '../config.php';

// Check if functions file exists
if (!file_exists('functions.php')) {
    // Define basic functions if file doesn't exist
    function is_logged_in() {
        return isset($_SESSION['user_id']);
    }
    
    function has_role($role) {
        return isset($_SESSION['role']) && $_SESSION['role'] == $role;
    }
    
    function show_notification($message, $type) {
        $_SESSION['notification'] = array('message' => $message, 'type' => $type);
    }
    
    function redirect($url) {
        header("Location: $url");
        exit();
    }
} else {
    require_once 'functions.php';
}

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and has head role
if (!is_logged_in() || !has_role('Head')) {
    show_notification('You do not have permission to access this page', 'error');
    redirect('../login.php');
}

// Get head details
 $head_id = $_SESSION['user_id'];

// Initialize variables
 $error_message = "";
 $success_message = "";

// Get current month and year for filtering
 $current_month = date('m');
 $current_year = date('Y');

// Top Users Filter variables
 $top_month = isset($_GET['top_month']) ? $_GET['top_month'] : date('m');
 $top_year = isset($_GET['top_year']) ? $_GET['top_year'] : date('Y');

// NEW: Dashboard Charts Filter variables
 $chart_month = isset($_GET['chart_month']) ? $_GET['chart_month'] : date('m');
 $chart_year = isset($_GET['chart_year']) ? $_GET['chart_year'] : date('Y');

// Determine which tab should be active based on URL parameters
 $active_tab = 'pending-requests'; // Default tab changed to pending-requests
if (isset($_GET['tab'])) {
    switch ($_GET['tab']) {
        case 'dashboard':
            $active_tab = 'dashboard-content';
            break;
        case 'business':
            $active_tab = 'business-content';
            break;
        case 'user':
            $active_tab = 'user-business';
            break;
        case 'manager':
            $active_tab = 'manager-business';
            break;
        case 'pending':
            $active_tab = 'pending-requests';
            break;
        case 'paid':
            $active_tab = 'paid-requests';
            break;
    }
}

try {
    // Database connection check
    if (!$conn) {
        throw new Exception("Database connection failed");
    }
    
    // Handle export request
    if (isset($_GET['export']) && $_GET['export'] == 'all') {
        // Get all paid sales requests for export
        $export_sql = "SELECT sr.*, u.full_name as user_name, u.emp_id as user_emp_id, u.department as user_department,
                      m.full_name as manager_name, m.emp_id as manager_emp_id,
                      DATE_FORMAT(sr.updated_at, '%d-%m-%Y') as paid_date
                      FROM sales_requests sr 
                      JOIN users u ON sr.user_id = u.id 
                      JOIN users m ON u.manager_id = m.id
                      WHERE sr.status = 'Head Paid' AND u.head_id = ?
                      ORDER BY sr.updated_at DESC";
        
        $stmt = mysqli_prepare($conn, $export_sql);
        if (!$stmt) {
            throw new Exception("Failed to prepare export query: " . mysqli_error($conn));
        }
        
        mysqli_stmt_bind_param($stmt, "i", $head_id);
        mysqli_stmt_execute($stmt);
        $export_result = mysqli_stmt_get_result($stmt);
        
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="business_report_' . date('Y-m-d') . '.csv"');
        
        // Create a file pointer
        $output = fopen('php://output', 'w');
        
        // Add BOM to fix Excel UTF-8 issues
        fputs($output, "\xEF\xBB\xBF");
        
        // Set column headers
        fputcsv($output, array(
            'Reference #', 'User Name', 'User Emp ID', 'Department', 
            'Manager Name', 'Manager Emp ID', 'Customer Name', 'Mobile Number', 
            'Insurance Company', 'Policy Type', 
            'Premium', 'Paid Date'
        ));
        
        // Output each row of the data
        while ($row = mysqli_fetch_assoc($export_result)) {
            fputcsv($output, array(
                $row['reference_number'],
                $row['user_name'],
                $row['user_emp_id'],
                $row['user_department'],
                $row['manager_name'],
                $row['manager_emp_id'],
                $row['name'],
                $row['mobile'],
                $row['insurance_company'],
                $row['policy_type'],
                $row['premium'],
                $row['paid_date']
            ));
        }
        
        fclose($output);
        exit;
    }
    
    // Initialize filter variables
    $selected_month = isset($_GET['month']) ? $_GET['month'] : date('m');
    $selected_year = isset($_GET['year']) ? $_GET['year'] : date('Y');
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-7 days'));
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
    $user_filter = isset($_GET['user']) ? $_GET['user'] : '';
    $manager_filter = isset($_GET['manager']) ? $_GET['manager'] : '';
    
    // User Business filters
    $user_month = isset($_GET['user_month']) ? $_GET['user_month'] : date('m');
    $user_year = isset($_GET['user_year']) ? $_GET['user_year'] : date('Y');
    
    // Manager Business filters
    $manager_month = isset($_GET['manager_month']) ? $_GET['manager_month'] : date('m');
    $manager_year = isset($_GET['manager_year']) ? $_GET['manager_year'] : date('Y');
    
    // Get managers under this head
    $managers_sql = "SELECT * FROM users WHERE head_id = ? AND role = 'Manager'";
    $stmt = mysqli_prepare($conn, $managers_sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare managers query: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "i", $head_id);
    mysqli_stmt_execute($stmt);
    $managers_result = mysqli_stmt_get_result($stmt);
    
    // Get all users under managers who report to this head
    $users_sql = "SELECT u.* FROM users u JOIN users m ON u.manager_id = m.id WHERE m.head_id = ?";
    $stmt = mysqli_prepare($conn, $users_sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare users query: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "i", $head_id);
    mysqli_stmt_execute($stmt);
    $users_result = mysqli_stmt_get_result($stmt);
    
    // Get all paid sales requests (For Paid Requests tab - keeps Paid Date)
    $paid_requests_sql = "SELECT sr.*, u.full_name as user_name, u.emp_id as user_emp_id, u.department as user_department,
                         m.full_name as manager_name, m.emp_id as manager_emp_id,
                         DATE_FORMAT(sr.updated_at, '%Y-%m-%d') as paid_date
                         FROM sales_requests sr 
                         JOIN users u ON sr.user_id = u.id 
                         JOIN users m ON u.manager_id = m.id
                         WHERE sr.status = 'Head Paid' AND u.head_id = ?
                         ORDER BY sr.updated_at DESC";
    $stmt = mysqli_prepare($conn, $paid_requests_sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare paid requests query: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "i", $head_id);
    mysqli_stmt_execute($stmt);
    $paid_requests = mysqli_stmt_get_result($stmt);
    
    // Get all pending sales requests
    $pending_requests_sql = "SELECT sr.*, u.full_name as user_name, u.emp_id as user_emp_id, u.department as user_department,
                           m.full_name as manager_name, m.emp_id as manager_emp_id,
                           DATE_FORMAT(sr.created_at, '%Y-%m-%d') as created_date
                           FROM sales_requests sr 
                           JOIN users u ON sr.user_id = u.id 
                           JOIN users m ON u.manager_id = m.id
                           WHERE sr.status = 'Manager Verified' AND u.head_id = ?
                           ORDER BY sr.created_at DESC";
    $stmt = mysqli_prepare($conn, $pending_requests_sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare pending requests query: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "i", $head_id);
    mysqli_stmt_execute($stmt);
    $pending_requests = mysqli_stmt_get_result($stmt);
    
    // FIXED: Get last 7 days sales data for chart (Based on Created Date)
    $weekly_sales_sql = "SELECT DATE(sr.created_at) as date, 
                        SUM(sr.premium) as paid_amount,
                        COUNT(*) as paid_count
                        FROM sales_requests sr 
                        JOIN users u ON sr.user_id = u.id 
                        WHERE u.head_id = ? AND sr.status = 'Head Paid' 
                        AND sr.created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)
                        GROUP BY DATE(sr.created_at)
                        ORDER BY date ASC";
    $stmt = mysqli_prepare($conn, $weekly_sales_sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare weekly sales query: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "i", $head_id);
    mysqli_stmt_execute($stmt);
    $weekly_sales = mysqli_stmt_get_result($stmt);
    
    // FIXED: Get last 7 days total business (Based on Created Date)
    $weekly_total_sql = "SELECT SUM(sr.premium) as total_amount, COUNT(*) as total_count
                         FROM sales_requests sr 
                         JOIN users u ON sr.user_id = u.id 
                         WHERE u.head_id = ? AND sr.status = 'Head Paid' 
                         AND sr.created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)";
    $stmt = mysqli_prepare($conn, $weekly_total_sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare weekly total query: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "i", $head_id);
    mysqli_stmt_execute($stmt);
    $weekly_total_result = mysqli_stmt_get_result($stmt);
    $weekly_total = mysqli_fetch_assoc($weekly_total_result);
    
    // Get monthly sales data for charts (Keeping Paid Date for trends)
    $monthly_sales_sql = "SELECT DATE_FORMAT(sr.updated_at, '%Y-%m') as month, 
                         SUM(sr.premium) as paid_amount,
                         COUNT(*) as paid_count
                         FROM sales_requests sr 
                         JOIN users u ON sr.user_id = u.id 
                         WHERE u.head_id = ? AND sr.status = 'Head Paid'
                         GROUP BY DATE_FORMAT(sr.updated_at, '%Y-%m')
                         ORDER BY month DESC
                         LIMIT 12";
    $stmt = mysqli_prepare($conn, $monthly_sales_sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare monthly sales query: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "i", $head_id);
    mysqli_stmt_execute($stmt);
    $monthly_sales = mysqli_stmt_get_result($stmt);
    
    // Get user-wise business data
    $user_business_sql = "SELECT u.id, u.full_name, u.emp_id, u.department,
                         SUM(sr.premium) as total_premium,
                         COUNT(*) as paid_count
                         FROM users u
                         LEFT JOIN sales_requests sr ON u.id = sr.user_id AND sr.status = 'Head Paid'
                         WHERE u.head_id = ? AND u.role = 'User'
                         GROUP BY u.id, u.full_name, u.emp_id, u.department
                         ORDER BY total_premium DESC";
    $stmt = mysqli_prepare($conn, $user_business_sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare user business query: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "i", $head_id);
    mysqli_stmt_execute($stmt);
    $user_business = mysqli_stmt_get_result($stmt);
    
    // Get manager-wise business data
    $manager_business_sql = "SELECT u.id, u.full_name, u.emp_id, u.department,
                            SUM(sr.premium) as total_premium,
                            COUNT(*) as paid_count
                            FROM users u
                            LEFT JOIN users team_members ON u.id = team_members.manager_id
                            LEFT JOIN sales_requests sr ON team_members.id = sr.user_id AND sr.status = 'Head Paid'
                            WHERE u.head_id = ? AND u.role = 'Manager'
                            GROUP BY u.id, u.full_name, u.emp_id, u.department
                            ORDER BY total_premium DESC";
    $stmt = mysqli_prepare($conn, $manager_business_sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare manager business query: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "i", $head_id);
    mysqli_stmt_execute($stmt);
    $manager_business = mysqli_stmt_get_result($stmt);
    
    // MODIFIED: Get user-wise business data for specific month/year (CHANGED TO CREATED DATE)
    $user_business_filtered_sql = "SELECT u.id, u.full_name, u.emp_id, u.department,
                                   SUM(sr.premium) as total_premium,
                                   COUNT(*) as paid_count
                                   FROM users u
                                   LEFT JOIN sales_requests sr ON u.id = sr.user_id AND sr.status = 'Head Paid' 
                                   AND MONTH(sr.created_at) = ? AND YEAR(sr.created_at) = ?
                                   WHERE u.head_id = ? AND u.role = 'User'
                                   GROUP BY u.id, u.full_name, u.emp_id, u.department
                                   ORDER BY total_premium DESC";
    $stmt = mysqli_prepare($conn, $user_business_filtered_sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare filtered user business query: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "iii", $user_month, $user_year, $head_id);
    mysqli_stmt_execute($stmt);
    $user_business_filtered = mysqli_stmt_get_result($stmt);
    
    // MODIFIED: Get manager-wise business data for specific month/year (CHANGED TO CREATED DATE)
    $manager_business_filtered_sql = "SELECT u.id, u.full_name, u.emp_id, u.department,
                                      SUM(sr.premium) as total_premium,
                                      COUNT(*) as paid_count
                                      FROM users u
                                      LEFT JOIN users team_members ON u.id = team_members.manager_id
                                      LEFT JOIN sales_requests sr ON team_members.id = sr.user_id AND sr.status = 'Head Paid'
                                      AND MONTH(sr.created_at) = ? AND YEAR(sr.created_at) = ?
                                      WHERE u.head_id = ? AND u.role = 'Manager'
                                      GROUP BY u.id, u.full_name, u.emp_id, u.department
                                      ORDER BY total_premium DESC";
    $stmt = mysqli_prepare($conn, $manager_business_filtered_sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare filtered manager business query: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "iii", $manager_month, $manager_year, $head_id);
    mysqli_stmt_execute($stmt);
    $manager_business_filtered = mysqli_stmt_get_result($stmt);
    
    // MODIFIED: Get statistics for current month ONLY based on CREATED DATE
    $stats_sql = "SELECT 
               COUNT(*) AS total_requests,
               SUM(CASE WHEN sr.status = 'Manager Verified' THEN 1 ELSE 0 END) AS verified_count,
               SUM(CASE WHEN sr.status = 'Head Paid' THEN 1 ELSE 0 END) AS paid_count,
               SUM(CASE WHEN sr.status != 'Rejected' THEN sr.premium ELSE 0 END) AS total_premium,
               SUM(CASE WHEN sr.status = 'Head Paid' THEN sr.premium ELSE 0 END) AS paid_premium,
               SUM(CASE WHEN sr.status = 'Rejected' THEN 1 ELSE 0 END) AS rejected_count,
               SUM(CASE WHEN sr.status = 'Rejected' THEN sr.premium ELSE 0 END) AS rejected_premium
               FROM sales_requests sr 
               JOIN users u ON sr.user_id = u.id 
               WHERE u.head_id = ? AND MONTH(sr.created_at) = ? AND YEAR(sr.created_at) = ?";
    $stmt = mysqli_prepare($conn, $stats_sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare stats query: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "iii", $head_id, $current_month, $current_year);
    mysqli_stmt_execute($stmt);
    $stats_result = mysqli_stmt_get_result($stmt);
    $stats = mysqli_fetch_assoc($stats_result);
    
    // UPDATED: Get today's business data
    // Logic: Shows policies where the entered 'date' is today and status is Head Paid.
    $today_business_sql = "SELECT 
                          SUM(sr.premium) AS today_amount,
                          COUNT(*) AS today_count
                          FROM sales_requests sr 
                          JOIN users u ON sr.user_id = u.id 
                          WHERE u.head_id = ? AND sr.status = 'Head Paid' 
                          AND DATE(`date`) = CURDATE()"; // Using `date` column (policy date) vs created_at
    $stmt = mysqli_prepare($conn, $today_business_sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare today's business query: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "i", $head_id);
    mysqli_stmt_execute($stmt);
    $today_business_result = mysqli_stmt_get_result($stmt);
    $today_business = mysqli_fetch_assoc($today_business_result);
    
    // MODIFIED: Get top 10 users by business for selected month/year (CHANGED TO CREATED DATE)
    $top_users_sql = "SELECT u.id, u.full_name, u.emp_id, u.department,
                     COALESCE(SUM(sr.premium), 0) as total_premium,
                     COALESCE(COUNT(sr.id), 0) as paid_count
                     FROM users u
                     LEFT JOIN sales_requests sr ON u.id = sr.user_id 
                     AND sr.status = 'Head Paid'
                     AND MONTH(sr.created_at) = ? 
                     AND YEAR(sr.created_at) = ?
                     WHERE u.head_id = ? AND u.role = 'User'
                     GROUP BY u.id, u.full_name, u.emp_id, u.department
                     ORDER BY total_premium DESC
                     LIMIT 8";
    $stmt = mysqli_prepare($conn, $top_users_sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare top users query: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "iii", $top_month, $top_year, $head_id);
    mysqli_stmt_execute($stmt);
    $top_users = mysqli_stmt_get_result($stmt);

    // NEW: Calculate Total Premium for the selected Month/Year (Top Users Header)
    $top_month_total_sql = "SELECT COALESCE(SUM(sr.premium), 0) as total_premium
                            FROM sales_requests sr 
                            JOIN users u ON sr.user_id = u.id
                            WHERE u.head_id = ? AND sr.status = 'Head Paid'
                            AND MONTH(sr.created_at) = ? 
                            AND YEAR(sr.created_at) = ?";
    $stmt = mysqli_prepare($conn, $top_month_total_sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare top month total query: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "iii", $head_id, $top_month, $top_year);
    mysqli_stmt_execute($stmt);
    $top_month_total_result = mysqli_stmt_get_result($stmt);
    $top_month_total = mysqli_fetch_assoc($top_month_total_result);

    // NEW: Data for Dashboard Content Add-ons (State, Fuel, Category)
    // Added Month/Year filter based on `date` column
    
    // 1. State-wise Data
    $state_stats_sql = "SELECT sr.state, COUNT(*) as count
                        FROM sales_requests sr
                        JOIN users u ON sr.user_id = u.id
                        WHERE u.head_id = ? AND sr.status = 'Head Paid'
                        AND sr.state IS NOT NULL AND sr.state != ''
                        AND MONTH(`date`) = ? AND YEAR(`date`) = ?
                        GROUP BY sr.state
                        ORDER BY count DESC";
    $stmt = mysqli_prepare($conn, $state_stats_sql);
    mysqli_stmt_bind_param($stmt, "iii", $head_id, $chart_month, $chart_year);
    mysqli_stmt_execute($stmt);
    $state_stats = mysqli_stmt_get_result($stmt);

    // 2. Fuel Type Data
    $fuel_stats_sql = "SELECT sr.fuel_type, COUNT(*) as count
                       FROM sales_requests sr
                       JOIN users u ON sr.user_id = u.id
                       WHERE u.head_id = ? AND sr.status = 'Head Paid'
                       AND sr.fuel_type IS NOT NULL AND sr.fuel_type != ''
                       AND MONTH(`date`) = ? AND YEAR(`date`) = ?
                       GROUP BY sr.fuel_type
                       ORDER BY count DESC";
    $stmt = mysqli_prepare($conn, $fuel_stats_sql);
    mysqli_stmt_bind_param($stmt, "iii", $head_id, $chart_month, $chart_year);
    mysqli_stmt_execute($stmt);
    $fuel_stats = mysqli_stmt_get_result($stmt);

    // 3. Category Data
    $category_stats_sql = "SELECT sr.category, COUNT(*) as count
                          FROM sales_requests sr
                          JOIN users u ON sr.user_id = u.id
                          WHERE u.head_id = ? AND sr.status = 'Head Paid'
                          AND sr.category IS NOT NULL AND sr.category != ''
                          AND MONTH(`date`) = ? AND YEAR(`date`) = ?
                          GROUP BY sr.category
                          ORDER BY count DESC";
    $stmt = mysqli_prepare($conn, $category_stats_sql);
    mysqli_stmt_bind_param($stmt, "iii", $head_id, $chart_month, $chart_year);
    mysqli_stmt_execute($stmt);
    $category_stats = mysqli_stmt_get_result($stmt);
    
    // MODIFIED: Build the WHERE clause for Business Reports tab (CHANGED TO CREATED DATE)
    $where_clause = "WHERE u.head_id = ? AND sr.status = 'Head Paid'";
    $params = array($head_id);
    $types = "i";
    
    // Add date range filter if provided (Now filters by Created Date)
    if (!empty($start_date) && !empty($end_date)) {
        $where_clause .= " AND DATE(sr.created_at) BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
        $types .= "ss";
    }
    
    // Add user filter if provided
    if (!empty($user_filter)) {
        $where_clause .= " AND u.id = ?";
        $params[] = $user_filter;
        $types .= "i";
    }
    
    // Add manager filter if provided
    if (!empty($manager_filter)) {
        $where_clause .= " AND m.id = ?";
        $params[] = $manager_filter;
        $types .= "i";
    }
    
    // Add month filter if provided (Now filters by Created Date month)
    if (!empty($selected_month)) {
        $where_clause .= " AND MONTH(sr.created_at) = ?";
        $params[] = $selected_month;
        $types .= "i";
    }
    
    // Add year filter if provided (Now filters by Created Date year)
    if (!empty($selected_year)) {
        $where_clause .= " AND YEAR(sr.created_at) = ?";
        $params[] = $selected_year;
        $types .= "i";
    }
    
    // Get filtered business data (Business Reports Tab - Now sorts by Created Date)
    $filtered_business_sql = "SELECT sr.*, u.full_name as user_name, u.emp_id as user_emp_id, u.department as user_department,
                             m.full_name as manager_name, m.emp_id as manager_emp_id,
                             DATE_FORMAT(sr.created_at, '%d-%m-%Y') as sale_date
                             FROM sales_requests sr 
                             JOIN users u ON sr.user_id = u.id 
                             JOIN users m ON u.manager_id = m.id
                             $where_clause
                             ORDER BY sr.created_at DESC";
    $stmt = mysqli_prepare($conn, $filtered_business_sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare filtered business query: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $filtered_business = mysqli_stmt_get_result($stmt);
    
    // Get filtered statistics
    $filtered_stats_sql = "SELECT 
                           COUNT(*) AS total_requests,
                           SUM(sr.premium) AS total_premium
                           FROM sales_requests sr 
                           JOIN users u ON sr.user_id = u.id 
                           JOIN users m ON u.manager_id = m.id
                           $where_clause";
    $stmt = mysqli_prepare($conn, $filtered_stats_sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare filtered stats query: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $filtered_stats_result = mysqli_stmt_get_result($stmt);
    $filtered_stats = mysqli_fetch_assoc($filtered_stats_result);
    
    // Get unique months for dropdown (Based on Created Date now)
    $months_sql = "SELECT DISTINCT MONTH(sr.created_at) as month, YEAR(sr.created_at) as year
                   FROM sales_requests sr 
                   JOIN users u ON sr.user_id = u.id 
                   JOIN users m ON u.manager_id = m.id
                   WHERE u.head_id = ? AND sr.status = 'Head Paid'
                   ORDER BY year DESC, month DESC";
    $stmt = mysqli_prepare($conn, $months_sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare months query: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "i", $head_id);
    mysqli_stmt_execute($stmt);
    $months_result = mysqli_stmt_get_result($stmt);
    
} catch (Exception $e) {
    $error_message = "Error: " . $e->getMessage();
    // In a production environment, you might want to log this error instead of displaying it
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Head Dashboard - Sales System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="https://www.coveryou.in/images/favicon.png" type="image/png">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        :root {
            --primary: #f05d49;
            --primary-dark: #d84c38;
            --primary-light: #ff7d6a;
            --dark: #2d3748;
            --light: #ffffff;
            --gray: #e2e8f0;
            --text: #4a5568;
            --text-light: #718096;
            --shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            --radius: 6px;
            --sidebar-width: 250px;
        }
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            color: var(--text);
            line-height: 1.5;
            min-height: 100vh;
            font-size: 14px;
        }
        .app-container {
            display: flex;
            min-height: 100vh;
        }
        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--dark);
            color: var(--light);
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: transform 0.3s ease;
        }
        .sidebar-header {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .sidebar-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 5px;
        }
        .sidebar-logo-icon {
            font-size: 24px;
            color: var(--primary);
        }
        .sidebar-logo-text {
            font-size: 20px;
            font-weight: 700;
        }
        .sidebar-user {
            padding: 15px;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            font-size: 13px;
        }
        .sidebar-user-name {
            font-weight: 600;
            margin-bottom: 3px;
        }
        .sidebar-user-role {
            color: var(--text-light);
            font-size: 12px;
        }
        .sidebar-menu {
            padding: 15px 0;
        }
        .sidebar-menu-item {
            display: block;
            padding: 12px 20px;
            color: var(--light);
            text-decoration: none;
            transition: background-color 0.2s ease;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .sidebar-menu-item:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        .sidebar-menu-item.active {
            background-color: var(--primary);
        }
        .sidebar-menu-item i {
            width: 20px;
            text-align: center;
        }
        .sidebar-footer {
            padding: 15px;
            text-align: center;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            font-size: 12px;
            color: var(--text-light);
            position: absolute;
            bottom: 0;
            width: 100%;
        }
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 15px;
            width: calc(100% - var(--sidebar-width));
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        header {
            text-align: center;
            margin-bottom: 20px;
            padding: 15px;
            background: var(--light);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            position: relative;
        }
        .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 10px;
        }
        .logo-icon {
            font-size: 24px;
            color: var(--primary);
        }
        .logo-text {
            font-size: 22px;
            font-weight: 700;
            color: var(--dark);
        }
        .logo-text span {
            color: var(--primary);
        }
        .tagline {
            color: var(--text-light);
            font-size: 14px;
            margin-bottom: 5px;
        }
        .user-info {
            position: absolute;
            top: 15px;
            right: 15px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .user-details {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }
        .username {
            font-weight: 600;
            color: var(--dark);
        }
        .user-role {
            font-size: 12px;
            color: var(--text-light);
        }
        .logout-btn {
            padding: 8px 15px;
            background-color: #e53e3e;
            color: white;
            border: none;
            border-radius: var(--radius);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 13px;
            transition: background-color 0.3s ease;
        }
        .logout-btn:hover {
            background-color: #c53030;
        }
        .dashboard-container {
            background-color: var(--light);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
        }
        .section-title {
            font-size: 16px;
            color: var(--primary);
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--gray);
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: var(--light);
            border-radius: var(--radius);
            padding: 15px;
            box-shadow: var(--shadow);
            text-align: center;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: var(--primary);
            margin-bottom: 5px;
        }
        .stat-label {
            font-size: 14px;
            color: var(--text-light);
        }
        .table-container {
            overflow-x: auto;
            margin-top: 15px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th, td {
            padding: 8px 12px;
            text-align: left;
            border-bottom: 1px solid var(--gray);
        }
        th {
            background-color: #f8fafc;
            font-weight: 600;
            color: var(--dark);
        }
        tr:hover {
            background-color: #f8fafc;
        }
        .btn {
            padding: 6px 12px;
            border-radius: var(--radius);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 13px;
            text-decoration: none;
        }
        .btn-primary {
            background-color: var(--primary);
            color: white;
        }
        .btn-primary:hover {
            background-color: var(--primary-dark);
        }
        .btn-success {
            background-color: #389e0d;
            color: white;
        }
        .btn-success:hover {
            background-color: #237804;
        }
        .btn-info {
            background-color: #096dd9;
            color: white;
        }
        .btn-info:hover {
            background-color: #0050b3;
        }
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }
        .status-pending {
            background-color: #fff7e6;
            color: #d46b08;
        }
        .status-manager-verified {
            background-color: #e6f7ff;
            color: #096dd9;
        }
        .status-head-paid {
            background-color: #f6ffed;
            color: #389e0d;
        }
        .status-rejected {
            background-color: #fff2f0;
            color: #cf1322;
        }
        .alert {
            padding: 12px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-success {
            background-color: #f6ffed;
            border-left: 4px solid #389e0d;
            color: #389e0d;
        }
        .alert-error {
            background-color: #fff2f0;
            border-left: 4px solid #cf1322;
            color: #cf1322;
        }
        .no-data {
            text-align: center;
            padding: 30px;
            color: var(--text-light);
        }
        .no-data i {
            font-size: 36px;
            margin-bottom: 10px;
            color: var(--gray);
        }
        .tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--gray);
            justify-content: space-between;
            align-items: center;
        }
        .tab-buttons {
            display: flex;
        }
        .tab {
            padding: 10px 20px;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            font-weight: 500;
        }
        .tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }
        .modal-content {
            background-color: var(--light);
            margin: 10% auto;
            padding: 20px;
            border-radius: var(--radius);
            width: 80%;
            max-width: 500px;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .modal-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
        }
        .close {
            font-size: 24px;
            font-weight: bold;
            color: var(--text-light);
            cursor: pointer;
        }
        .close:hover {
            color: var(--dark);
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--dark);
        }
        .form-control {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            font-size: 14px;
        }
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        .chart-container {
            height: 300px;
            margin-bottom: 20px;
        }
        .menu-toggle {
            display: none;
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1001;
            background-color: var(--dark);
            color: var(--light);
            border: none;
            border-radius: var(--radius);
            padding: 8px 12px;
            cursor: pointer;
        }
        .filter-container {
            background-color: #f8fafc;
            padding: 15px;
            border-radius: var(--radius);
            margin-bottom: 20px;
        }
        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 10px;
        }
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--dark);
        }
        .filter-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        .business-summary {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
            padding: 10px;
            background-color: #f8fafc;
            border-radius: var(--radius);
        }
        .business-summary-item {
            text-align: center;
        }
        .business-summary-value {
            font-size: 18px;
            font-weight: bold;
            color: var(--primary);
        }
        .business-summary-label {
            font-size: 12px;
            color: var(--text-light);
        }
        .hierarchy {
            margin-bottom: 20px;
        }
        .hierarchy-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
        }
        .hierarchy-item {
            padding: 15px;
            background-color: #f8fafc;
            border-radius: var(--radius);
            border-left: 3px solid var(--primary);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .hierarchy-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        .hierarchy-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .hierarchy-subtitle {
            font-size: 12px;
            color: var(--text-light);
        }
        .hierarchy-stats {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
        }
        .hierarchy-stat {
            text-align: center;
            flex: 1;
        }
        .hierarchy-stat-value {
            font-size: 16px;
            font-weight: 600;
            color: var(--primary);
        }
        .hierarchy-stat-label {
            font-size: 11px;
            color: var(--text-light);
        }
        .search-box {
            position: relative;
            margin-bottom: 15px;
        }
        .search-box input {
            width: 100%;
            padding: 8px 12px 8px 35px;
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            font-size: 14px;
        }
        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
        }
        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        .analytics-card {
            background: var(--light);
            border-radius: var(--radius);
            padding: 15px;
            box-shadow: var(--shadow);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .analytics-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        .analytics-card h3 {
            font-size: 16px;
            color: var(--dark);
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--gray);
        }
        .analytics-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .analytics-table th, .analytics-table td {
            padding: 6px 8px;
            text-align: left;
            border-bottom: 1px solid var(--gray);
        }
        .analytics-table th {
            background-color: #f8fafc;
            font-weight: 600;
            color: var(--dark);
        }
        .analytics-table tr:hover {
            background-color: #f8fafc;
        }
        .floating-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: var(--primary);
            color: white;
            border: none;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            cursor: pointer;
            z-index: 999;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            transition: all 0.3s ease;
        }
        .floating-btn:hover {
            background-color: var(--primary-dark);
            transform: scale(1.1);
        }
        .error-container {
            background-color: #fff2f0;
            border: 1px solid #ffccc7;
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 20px;
        }
        .error-title {
            color: #cf1322;
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 10px;
        }
        .error-message {
            color: #a8071a;
            margin-bottom: 15px;
        }
        .error-actions {
            display: flex;
            gap: 10px;
        }
        .weekly-summary {
            background-color: #f8fafc;
            border-radius: var(--radius);
            padding: 15px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-around;
            flex-wrap: wrap;
        }
        .weekly-summary-item {
            text-align: center;
            flex: 1;
            min-width: 150px;
        }
        .weekly-summary-value {
            font-size: 22px;
            font-weight: bold;
            color: var(--primary);
            margin-bottom: 5px;
        }
        .weekly-summary-label {
            font-size: 14px;
            color: var(--text-light);
        }
        /* Styles for Today's Business and Top Users sections */
        .today-business {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            overflow: hidden;
        }
        .today-business::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 150px;
            height: 150px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transform: translate(30%, -30%);
        }
        .today-business-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .today-business-value {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        .today-business-count {
            font-size: 16px;
            opacity: 0.9;
        }
        .today-business-icon {
            font-size: 60px;
            opacity: 0.2;
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
        }
        .top-users {
            margin-bottom: 20px;
        }
        .top-users-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 15px;
        }
        .top-user-card {
            background: var(--light);
            border-radius: var(--radius);
            padding: 15px;
            box-shadow: var(--shadow);
            display: flex;
            flex-direction: column;
            gap: 10px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            position: relative;
            overflow: hidden;
        }
        .top-user-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        .top-user-header {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .top-user-rank {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 18px;
        }
        .top-user-rank.gold {
            background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%);
            box-shadow: 0 0 15px rgba(255, 215, 0, 0.7);
            animation: glow 2s infinite alternate;
        }
        @keyframes glow {
            from {
                box-shadow: 0 0 10px rgba(255, 215, 0, 0.7);
            }
            to {
                box-shadow: 0 0 20px rgba(255, 215, 0, 0.9);
            }
        }
        .top-user-rank.silver {
            background: linear-gradient(135deg, #C0C0C0 0%, #808080 100%);
        }
        .top-user-rank.bronze {
            background: linear-gradient(135deg, #CD7F32 0%, #8B4513 100%);
        }
        .top-user-info {
            flex: 1;
        }
        .top-user-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 3px;
        }
        .top-user-details {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 5px;
        }
        .top-user-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            background: #f8fafc;
            padding: 10px;
            border-radius: 4px;
        }
        .top-user-stat {
            text-align: center;
        }
        .top-user-stat-label {
            font-size: 10px;
            color: var(--text-light);
            text-transform: uppercase;
        }
        .top-user-stat-value {
            font-weight: 700;
            color: var(--primary);
            font-size: 14px;
        }
        .top-user-card.rank-1 {
            border: 2px solid #FFD700;
            background: linear-gradient(135deg, #fff9e6 0%, #ffffff 100%);
        }
        .top-user-card.rank-1 .top-user-name {
            color: #d4af37;
            font-weight: 700;
        }
        .top-user-card.rank-1::after {
            content: '👑';
            position: absolute;
            top: 5px;
            right: 10px;
            font-size: 20px;
        }
        .live-indicator {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            color: #389e0d;
            margin-left: 10px;
        }
        .live-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: #389e0d;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% {
                transform: scale(0.95);
                box-shadow: 0 0 0 0 rgba(56, 158, 13, 0.7);
            }
            70% {
                transform: scale(1);
                box-shadow: 0 0 0 5px rgba(56, 158, 13, 0);
            }
            100% {
                transform: scale(0.95);
                box-shadow: 0 0 0 0 rgba(56, 158, 13, 0);
            }
        }
        /* Style for rejected requests card */
        .stat-card.rejected {
            border-left: 3px solid #cf1322;
        }
        .stat-card.rejected .stat-value {
            color: #cf1322;
        }
        /* Helper for Premium without GST */
        .text-sm {
            font-size: 11px;
            color: var(--text-light);
        }
        .premium-excl-gst {
            color: var(--dark);
            font-weight: 600;
        }
        .top-users-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--gray);
            flex-wrap: wrap;
            gap: 10px;
        }
        .top-users-title {
            font-size: 16px;
            color: var(--primary);
            margin: 0;
        }
        .top-users-filters {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .top-users-filters select {
            padding: 5px 10px;
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            font-size: 12px;
        }
        
        /* Total Month Premium Box */
        .total-month-premium-box {
            background-color: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: var(--radius);
            padding: 10px 15px;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .tmp-label {
            font-size: 12px;
            color: #0369a1;
            font-weight: 600;
        }
        .tmp-amount {
            font-size: 18px;
            color: #0c4a6e;
            font-weight: 700;
        }
        .tmp-sub {
            font-size: 11px;
            color: #075985;
        }

        /* Grid for Today's Business Stats */
        .today-stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 10px;
        }
        .today-stat-box {
            background: rgba(255,255,255,0.1);
            padding: 10px;
            border-radius: var(--radius);
        }
        .today-stat-label {
            font-size: 12px;
            opacity: 0.8;
        }
        .today-stat-value {
            font-size: 18px;
            font-weight: 700;
        }

        /* Dashboard Add-ons Grid */
        .dashboard-add-ons-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            margin-top: 30px;
        }
        
        .add-on-section {
            background: #fff;
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
        }

        .chart-table-row {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            align-items: start;
        }
        
        .small-table {
            width: 100%;
            font-size: 12px;
        }
        .small-table th {
            background-color: var(--dark);
            color: white;
        }
        .small-table td, .small-table th {
            padding: 6px;
            border: 1px solid #e2e8f0;
        }

        @media (max-width: 992px) {
            .chart-table-row {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.active {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 60px 15px 15px;
            }
            .menu-toggle {
                display: block;
            }
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .filter-row {
                flex-direction: column;
            }
            .filter-group {
                min-width: 100%;
            }
            .analytics-grid {
                grid-template-columns: 1fr;
            }
            .hierarchy-grid {
                grid-template-columns: 1fr;
            }
            .top-users-grid {
                grid-template-columns: 1fr;
            }
            .today-business {
                flex-direction: column;
                text-align: center;
            }
            .today-business-icon {
                position: static;
                transform: none;
                margin-top: 10px;
            }
            .top-users-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            .top-users-filters {
                width: 100%;
                justify-content: space-between;
            }
            .top-users-filters select {
                flex: 1;
            }
            .chart-table-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class="fas fa-chart-line sidebar-logo-icon"></i>
                    <div class="sidebar-logo-text">Head Dashboard</div>
                </div>
            </div>
            
            <div class="sidebar-user">
                <div class="sidebar-user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                <div class="sidebar-user-role"><?php echo htmlspecialchars($_SESSION['role']); ?> - <?php echo htmlspecialchars($_SESSION['department']); ?></div>
            </div>
            
            <nav class="sidebar-menu">
                <a href="head.php" class="sidebar-menu-item active">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="../dashboard_head.php" class="sidebar-menu-item">
                    <i class="fas fa-coins"></i> CB Account
                </a>
                <a href="../logout.php" class="sidebar-menu-item">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a> 
            </nav>
            
            <div class="sidebar-footer">
                &copy; <?php echo date('Y'); ?> Sales Management System
            </div>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            
            <div class="container">
                <header>
                    <div class="logo">
                        <i class="fas fa-chart-line logo-icon"></i>
                        <div class="logo-text">Head <span>Dashboard</span></div>
                    </div>
                    <p class="tagline">Approve and manage verified sales requests</p>
                    
                    <div class="user-info">
                        <div class="user-details">
                            <div class="username"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                            <div class="user-role"><?php echo htmlspecialchars($_SESSION['role']); ?> - <?php echo htmlspecialchars($_SESSION['department']); ?></div>
                        </div>
                        <a href="../logout.php" class="logout-btn">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </header>
                
                <?php if (!empty($error_message)): ?>
                    <div class="error-container">
                        <div class="error-title">System Error</div>
                        <div class="error-message"><?php echo $error_message; ?></div>
                        <div class="error-actions">
                            <button class="btn btn-primary" onclick="location.reload()">
                                <i class="fas fa-sync-alt"></i> Reload Page
                            </button>
                            <a href="../logout.php" class="btn btn-info">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['notification'])): ?>
                    <div class="alert alert-<?php echo $_SESSION['notification']['type']; ?>">
                        <?php echo $_SESSION['notification']['message']; ?>
                    </div>
                    <?php unset($_SESSION['notification']); ?>
                <?php endif; ?>
                
                <?php if (empty($error_message)): ?>
                    <!-- NEW: Today's Business Section -->
                    <div class="today-business">
                        <div style="width: 100%;">
                            <div class="today-business-title">
                                <i class="fas fa-chart-line"></i> Today's Business
                                <span class="live-indicator">
                                    <span class="live-dot"></span> Live
                                </span>
                            </div>
                            <div class="today-stats-grid">
                                <div class="today-stat-box">
                                    <div class="today-stat-label">Total Premium (Incl. GST)</div>
                                    <div class="today-stat-value">₹<?php echo number_format($today_business['today_amount'], 2); ?></div>
                                </div>
                                <div class="today-stat-box">
                                    <div class="today-stat-label">Total Premium (Excl. GST)</div>
                                    <div class="today-stat-value">₹<?php echo number_format($today_business['today_amount'] / 1.18, 2); ?></div>
                                </div>
                            </div>
                            <div style="margin-top: 10px; opacity: 0.9; font-size: 14px;">
                                <?php echo $today_business['today_count']; ?> Policies
                            </div>
                        </div>
                        <i class="fas fa-coins today-business-icon"></i>
                    </div>
                    
                    <!-- NEW: Top 10 Users Section -->
                    <div class="top-users">
                        <div class="top-users-header">
                            <h3 class="top-users-title">Top 10 Performers</h3>
                            
                            <!-- Add-on: Total Month Premium -->
                            <div class="total-month-premium-box">
                                <div class="tmp-label">Total Monthly Premium (<?php echo date('F', mktime(0, 0, 0, $top_month, 1)) . ' ' . $top_year; ?>)</div>
                                <div class="tmp-amount">₹<?php echo number_format($top_month_total['total_premium'], 2); ?></div>
                                <div class="tmp-sub">Excl. GST: ₹<?php echo number_format($top_month_total['total_premium'] / 1.18, 2); ?></div>
                            </div>

                            <div class="top-users-filters">
                                <form method="GET" action="head.php">
                                    <input type="hidden" name="tab" value="<?php echo isset($_GET['tab']) ? $_GET['tab'] : 'dashboard-content'; ?>">
                                    <select name="top_month" class="form-control" onchange="this.form.submit()">
                                        <?php for ($m = 1; $m <= 12; $m++): ?>
                                            <option value="<?php echo str_pad($m, 2, '0', STR_PAD_LEFT); ?>" <?php echo ($top_month == str_pad($m, 2, '0', STR_PAD_LEFT)) ? 'selected' : ''; ?>>
                                                <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                    <select name="top_year" class="form-control" onchange="this.form.submit()">
                                        <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                                            <option value="<?php echo $y; ?>" <?php echo ($top_year == $y) ? 'selected' : ''; ?>>
                                                <?php echo $y; ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                </form>
                            </div>
                        </div>
                        
                        <div class="top-users-grid">
                            <?php 
                            $rank = 1;
                            if (mysqli_num_rows($top_users) > 0): 
                                while ($user = mysqli_fetch_assoc($top_users)): ?>
                                    <div class="top-user-card <?php echo $rank == 1 ? 'rank-1' : ''; ?>">
                                        <div class="top-user-header">
                                            <div class="top-user-rank <?php 
                                                echo $rank == 1 ? 'gold' : ($rank == 2 ? 'silver' : ($rank == 3 ? 'bronze' : '')); 
                                            ?>">
                                                <?php echo $rank; ?>
                                            </div>
                                            <div class="top-user-info">
                                                <div class="top-user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                                <div class="top-user-details"><?php echo htmlspecialchars($user['emp_id']); ?> • <?php echo htmlspecialchars($user['department']); ?></div>
                                            </div>
                                        </div>
                                        <div class="top-user-stats">
                                            <div class="top-user-stat">
                                                <div class="top-user-stat-label">Premium (Incl.)</div>
                                                <div class="top-user-stat-value">₹<?php echo number_format($user['total_premium'], 2); ?></div>
                                            </div>
                                            <div class="top-user-stat">
                                                <div class="top-user-stat-label">Premium (Excl.)</div>
                                                <div class="top-user-stat-value">₹<?php echo number_format($user['total_premium'] / 1.18, 2); ?></div>
                                            </div>
                                        </div>
                                        <div style="font-size: 11px; color: var(--text-light); text-align: center;">
                                            <?php echo $user['paid_count']; ?> Policies
                                        </div>
                                    </div>
                                    <?php $rank++; ?>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="no-data" style="grid-column: 1 / -1;">
                                    <i class="fas fa-users"></i>
                                    <p>No users found for selected month</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Tabs with Pending Requests first -->
                    <div class="tabs">
                        <div class="tab-buttons">
                            <div class="tab <?php echo ($active_tab == 'pending-requests') ? 'active' : ''; ?>" onclick="openTab(event, 'pending-requests')">
                                <i class="fas fa-clock"></i> Pending Requests
                            </div>
                            <div class="tab <?php echo ($active_tab == 'dashboard-content') ? 'active' : ''; ?>" onclick="openTab(event, 'dashboard-content')">
                                <i class="fas fa-tachometer-alt"></i> Dashboard
                            </div>
                            <div class="tab <?php echo ($active_tab == 'business-content') ? 'active' : ''; ?>" onclick="openTab(event, 'business-content')">
                                <i class="fas fa-chart-pie"></i> Business Reports
                            </div>
                            <div class="tab <?php echo ($active_tab == 'user-business') ? 'active' : ''; ?>" onclick="openTab(event, 'user-business')">
                                <i class="fas fa-users"></i> User Business
                            </div>
                            <div class="tab <?php echo ($active_tab == 'manager-business') ? 'active' : ''; ?>" onclick="openTab(event, 'manager-business')">
                                <i class="fas fa-user-tie"></i> Manager Business
                            </div>
                            <div class="tab <?php echo ($active_tab == 'paid-requests') ? 'active' : ''; ?>" onclick="openTab(event, 'paid-requests')">
                                <i class="fas fa-check-circle"></i> Paid Requests
                            </div>
                        </div>
                        <a href="?export=all" class="btn btn-primary">
                            <i class="fas fa-download"></i> Export All
                        </a>
                    </div>
                    
                    <div class="dashboard-container">
                        <!-- Pending Requests Tab (now first) -->
                        <div id="pending-requests" class="tab-content <?php echo ($active_tab == 'pending-requests') ? 'active' : ''; ?>">
                            <h3 class="section-title">Pending Requests</h3>
                            
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Reference #</th>
                                            <th>User</th>
                                            <th>Manager</th>
                                            <th>Customer</th>
                                            <th>Premium (Incl. GST)</th>
                                            <th>Premium (Excl. GST)</th>
                                            <th>Created Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (mysqli_num_rows($pending_requests) > 0): ?>
                                            <?php while ($request = mysqli_fetch_assoc($pending_requests)): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($request['reference_number']); ?></td>
                                                    <td><?php echo htmlspecialchars($request['user_name']); ?> (<?php echo htmlspecialchars($request['user_emp_id']); ?>)</td>
                                                    <td><?php echo htmlspecialchars($request['manager_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($request['name']); ?></td>
                                                    <td>₹<?php echo number_format($request['premium'], 2); ?></td>
                                                    <td class="premium-excl-gst">₹<?php echo number_format($request['premium'] / 1.18, 2); ?></td>
                                                    <td><?php echo $request['created_date']; ?></td>
                                                    <td>
                                                        <button class="btn btn-success" onclick="markAsPaid(<?php echo $request['id']; ?>)">
                                                            <i class="fas fa-check"></i> Mark as Paid
                                                        </button>
                                                        <button class="btn btn-primary" onclick="viewRequest(<?php echo $request['id']; ?>)">
                                                            <i class="fas fa-eye"></i> View
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="8" class="no-data">
                                                    <i class="fas fa-clipboard-check"></i>
                                                    <p>No pending requests</p>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Dashboard Tab -->
                        <div id="dashboard-content" class="tab-content <?php echo ($active_tab == 'dashboard-content') ? 'active' : ''; ?>">
                            <h2 class="section-title">Sales Request Statistics - <?php echo date('F Y'); ?></h2>
                            
                            <div class="stats-grid">
                                <div class="stat-card">
                                    <div class="stat-value"><?php echo $stats['total_requests']; ?></div>
                                    <div class="stat-label">Total Requests</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-value"><?php echo $stats['verified_count']; ?></div>
                                    <div class="stat-label">Pending Payment</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-value"><?php echo $stats['paid_count']; ?></div>
                                    <div class="stat-label">Paid</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-value">₹<?php echo number_format($stats['total_premium'], 2); ?></div>
                                    <div class="stat-label">Total Premium </div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-value">₹<?php echo number_format($stats['paid_premium'], 2); ?></div>
                                    <div class="stat-label">Paid Premium</div>
                                </div>
                                <!-- NEW: Rejected Requests Card -->
                                <div class="stat-card rejected">
                                    <div class="stat-value">₹<?php echo number_format($stats['rejected_premium'], 2); ?></div>
                                    <div class="stat-label">Rejected Premium (<?php echo $stats['rejected_count']; ?> requests)</div>
                                </div>
                            </div>
                            
                            <!-- New section for last 7 days total business -->
                            <h3 class="section-title">Last 7 Days Business Summary (Based on Sale Date)</h3>
                            <div class="weekly-summary">
                                <div class="weekly-summary-item">
                                    <div class="weekly-summary-value">₹<?php echo number_format($weekly_total['total_amount'], 2); ?></div>
                                    <div class="weekly-summary-label">Total Amount</div>
                                </div>
                                <div class="weekly-summary-item">
                                    <div class="weekly-summary-value"><?php echo $weekly_total['total_count']; ?></div>
                                    <div class="weekly-summary-label">Total Count</div>
                                </div>
                            </div>
                            
                            <h3 class="section-title">Last 7 Days Business Trend</h3>
                            <div class="chart-container">
                                <canvas id="weeklyChart"></canvas>
                            </div>
                            
                            <h3 class="section-title">Monthly Sales Trend</h3>
                            <div class="chart-container">
                                <canvas id="salesChart"></canvas>
                            </div>
                            
                            <!-- NEW: Dashboard Content Add-ons -->
                            <div class="dashboard-add-ons-grid">
                                
                                <!-- Chart Filter Section -->
                                <div class="filter-container">
                                    <form method="GET" action="head.php">
                                        <input type="hidden" name="tab" value="dashboard">
                                        <div class="filter-row">
                                            <div class="filter-group">
                                                <label for="chart_month">Select Month for Analysis</label>
                                                <select name="chart_month" id="chart_month" class="form-control">
                                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                                        <option value="<?php echo str_pad($m, 2, '0', STR_PAD_LEFT); ?>" <?php echo ($chart_month == str_pad($m, 2, '0', STR_PAD_LEFT)) ? 'selected' : ''; ?>>
                                                            <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                                        </option>
                                                    <?php endfor; ?>
                                                </select>
                                            </div>
                                            <div class="filter-group">
                                                <label for="chart_year">Select Year for Analysis</label>
                                                <select name="chart_year" id="chart_year" class="form-control">
                                                    <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                                                        <option value="<?php echo $y; ?>" <?php echo ($chart_year == $y) ? 'selected' : ''; ?>>
                                                            <?php echo $y; ?>
                                                        </option>
                                                    <?php endfor; ?>
                                                </select>
                                            </div>
                                            <div class="filter-actions" style="margin-top: auto; margin-bottom: auto;">
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="fas fa-filter"></i> Update Analysis
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>

                                <!-- 1. Political Map / State Analysis -->
                                <div class="add-on-section">
                                    <h3 class="section-title"><i class="fas fa-map-marked-alt"></i> State-wise Policy Distribution</h3>
                                    <div class="chart-table-row">
                                        <!-- Chart Visualization (Bar Chart acting as Map Visual) -->
                                        <div class="chart-container" style="height: 300px; border-right: 1px solid var(--gray); padding-right: 10px;">
                                            <canvas id="stateChart"></canvas>
                                        </div>
                                        <!-- Excel Type Table -->
                                        <div style="overflow-y: auto; max-height: 300px;">
                                            <table class="small-table">
                                                <thead>
                                                    <tr>
                                                        <th>State</th>
                                                        <th>Count</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if (mysqli_num_rows($state_stats) > 0): ?>
                                                        <?php while ($state = mysqli_fetch_assoc($state_stats)): ?>
                                                            <tr>
                                                                <td><?php echo htmlspecialchars($state['state']); ?></td>
                                                                <td style="text-align: right;"><?php echo $state['count']; ?></td>
                                                            </tr>
                                                        <?php endwhile; ?>
                                                        <?php mysqli_data_seek($state_stats, 0); // Reset for chart ?>
                                                    <?php else: ?>
                                                        <tr><td colspan="2">No data available</td></tr>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                                <!-- 2 & 3. Fuel Type & Category -->
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                                    <!-- Fuel Type -->
                                    <div class="add-on-section">
                                        <h3 class="section-title"><i class="fas fa-gas-pump"></i> Fuel Type Analysis</h3>
                                        <div style="height: 250px; position: relative;">
                                            <canvas id="fuelChart"></canvas>
                                        </div>
                                    </div>

                                    <!-- Category -->
                                    <div class="add-on-section">
                                        <h3 class="section-title"><i class="fas fa-tags"></i> Category Analysis</h3>
                                        <div style="height: 250px; position: relative;">
                                            <canvas id="categoryChart"></canvas>
                                        </div>
                                    </div>
                                </div>

                            </div>

                            <h3 class="section-title" style="margin-top: 30px;">Reporting Structure</h3>
                            
                            <?php if (mysqli_num_rows($managers_result) > 0): ?>
                                <div class="hierarchy-grid">
                                    <?php 
                                    // Reset result pointer to reuse it
                                    mysqli_data_seek($managers_result, 0);
                                    
                                    while ($manager = mysqli_fetch_assoc($managers_result)): ?>
                                        <?php
                                        // Get team members for this manager
                                        $team_sql = "SELECT * FROM users WHERE manager_id = ?";
                                        $stmt = mysqli_prepare($conn, $team_sql);
                                        mysqli_stmt_bind_param($stmt, "i", $manager['id']);
                                        mysqli_stmt_execute($stmt);
                                        $team_result = mysqli_stmt_get_result($stmt);
                                        
                                        // Get pending count for this manager
                                        $pending_count_sql = "SELECT COUNT(*) AS count 
                                                           FROM sales_requests sr 
                                                           JOIN users u ON sr.user_id = u.id 
                                                           WHERE u.manager_id = ? AND sr.status = 'Manager Verified'";
                                        $stmt = mysqli_prepare($conn, $pending_count_sql);
                                        mysqli_stmt_bind_param($stmt, "i", $manager['id']);
                                        mysqli_stmt_execute($stmt);
                                        $pending_count_result = mysqli_stmt_get_result($stmt);
                                        $pending_count = mysqli_fetch_assoc($pending_count_result)['count'];
                                        
                                        // Get team amount for this manager
                                        $team_amount_sql = "SELECT SUM(sr.premium) AS amount 
                                                         FROM sales_requests sr 
                                                         JOIN users u ON sr.user_id = u.id 
                                                         WHERE u.manager_id = ? AND sr.status = 'Head Paid'";
                                        $stmt = mysqli_prepare($conn, $team_amount_sql);
                                        mysqli_stmt_bind_param($stmt, "i", $manager['id']);
                                        mysqli_stmt_execute($stmt);
                                        $team_amount_result = mysqli_stmt_get_result($stmt);
                                        $team_amount = mysqli_fetch_assoc($team_amount_result)['amount'];
                                        ?>
                                        <div class="hierarchy-item">
                                            <div class="hierarchy-title">
                                                <div>
                                                    <i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($manager['full_name']); ?> 
                                                    (<?php echo htmlspecialchars($manager['department']); ?>)
                                                </div>
                                                <div style="color: var(--primary);"><?php echo $pending_count; ?> pending</div>
                                            </div>
                                            <div class="hierarchy-subtitle">
                                                EMP ID: <?php echo htmlspecialchars($manager['emp_id']); ?>
                                            </div>
                                            <div class="hierarchy-stats">
                                                <div class="hierarchy-stat">
                                                    <div class="hierarchy-stat-value"><?php echo mysqli_num_rows($team_result); ?></div>
                                                    <div class="hierarchy-stat-label">Team Members</div>
                                                </div>
                                                <div class="hierarchy-stat">
                                                    <div class="hierarchy-stat-value">₹<?php echo number_format($team_amount, 2); ?></div>
                                                    <div class="hierarchy-stat-label">Team Amount</div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            <?php else: ?>
                                <div class="no-data">
                                    <i class="fas fa-users"></i>
                                    <p>No managers found</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Business Reports Tab -->
                        <div id="business-content" class="tab-content <?php echo ($active_tab == 'business-content') ? 'active' : ''; ?>">
                            <h3 class="section-title">Business Reports</h3>
                            
                            <!-- Month and Year Filter -->
                            <div class="filter-container">
                                <form method="GET" action="head.php">
                                    <input type="hidden" name="tab" value="business">
                                    <div class="filter-row">
                                        <div class="filter-group">
                                            <label for="month">Month</label>
                                            <select name="month" id="month" class="form-control">
                                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                                    <option value="<?php echo str_pad($m, 2, '0', STR_PAD_LEFT); ?>" <?php echo ($selected_month == str_pad($m, 2, '0', STR_PAD_LEFT)) ? 'selected' : ''; ?>>
                                                        <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                                    </option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                        <div class="filter-group">
                                            <label for="year">Year</label>
                                            <select name="year" id="year" class="form-control">
                                                <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                                                    <option value="<?php echo $y; ?>" <?php echo ($selected_year == $y) ? 'selected' : ''; ?>>
                                                        <?php echo $y; ?>
                                                    </option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="filter-actions">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-filter"></i> Apply Filter
                                        </button>
                                        <a href="head.php?tab=business" class="btn btn-info">
                                            <i class="fas fa-redo"></i> Reset
                                        </a>
                                    </div>
                                </form>
                            </div>
                            
                            <!-- Date Range Filter -->
                            <div class="filter-container">
                                <form method="GET" action="head.php">
                                    <input type="hidden" name="tab" value="business">
                                    <div class="filter-row">
                                        <div class="filter-group">
                                            <label for="start_date">Start Date</label>
                                            <input type="date" name="start_date" id="start_date" class="form-control" value="<?php echo $start_date; ?>">
                                        </div>
                                        <div class="filter-group">
                                            <label for="end_date">End Date</label>
                                            <input type="date" name="end_date" id="end_date" class="form-control" value="<?php echo $end_date; ?>">
                                        </div>
                                        <div class="filter-group">
                                            <label for="user">User</label>
                                            <select name="user" id="user" class="form-control">
                                                <option value="">All Users</option>
                                                <?php 
                                                // Reset result pointer to reuse it
                                                mysqli_data_seek($users_result, 0);
                                                
                                                while ($user = mysqli_fetch_assoc($users_result)): ?>
                                                    <option value="<?php echo $user['id']; ?>" <?php echo ($user_filter == $user['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($user['full_name']); ?> (<?php echo htmlspecialchars($user['emp_id']); ?>)
                                                    </option>
                                                <?php endwhile; ?>
                                            </select>
                                        </div>
                                        <div class="filter-group">
                                            <label for="manager">Manager</label>
                                            <select name="manager" id="manager" class="form-control">
                                                <option value="">All Managers</option>
                                                <?php 
                                                // Reset result pointer to reuse it
                                                mysqli_data_seek($managers_result, 0);
                                                
                                                while ($manager = mysqli_fetch_assoc($managers_result)): ?>
                                                    <option value="<?php echo $manager['id']; ?>" <?php echo ($manager_filter == $manager['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($manager['full_name']); ?>
                                                    </option>
                                                <?php endwhile; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="filter-actions">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-filter"></i> Apply Filter
                                        </button>
                                        <a href="head.php?tab=business" class="btn btn-info">
                                            <i class="fas fa-redo"></i> Reset
                                        </a>
                                    </div>
                                </form>
                            </div>
                            
                            <!-- Filtered Business Summary -->
                            <div class="business-summary">
                                <div class="business-summary-item">
                                    <div class="business-summary-value"><?php echo $filtered_stats['total_requests']; ?></div>
                                    <div class="business-summary-label">Total Requests</div>
                                </div>
                                <div class="business-summary-item">
                                    <div class="business-summary-value">₹<?php echo number_format($filtered_stats['total_premium'], 2); ?></div>
                                    <div class="business-summary-label">Total Premium</div>
                                </div>
                            </div>
                            
                            <!-- Filtered Business Table -->
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Reference #</th>
                                            <th>User</th>
                                            <th>Manager</th>
                                            <th>Customer</th>
                                            <th>Premium (Incl. GST)</th>
                                            <th>Premium (Excl. GST)</th>
                                            <th>Sale Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (mysqli_num_rows($filtered_business) > 0): ?>
                                            <?php while ($request = mysqli_fetch_assoc($filtered_business)): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($request['reference_number']); ?></td>
                                                    <td><?php echo htmlspecialchars($request['user_name']); ?> (<?php echo htmlspecialchars($request['user_emp_id']); ?>)</td>
                                                    <td><?php echo htmlspecialchars($request['manager_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($request['name']); ?></td>
                                                    <td>₹<?php echo number_format($request['premium'], 2); ?></td>
                                                    <td class="premium-excl-gst">₹<?php echo number_format($request['premium'] / 1.18, 2); ?></td>
                                                    <td><?php echo $request['sale_date']; ?></td>
                                                    <td>
                                                        <button class="btn btn-primary" onclick="viewRequest(<?php echo $request['id']; ?>)">
                                                            <i class="fas fa-eye"></i> View
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="8" class="no-data">
                                                    <i class="fas fa-clipboard-check"></i>
                                                    <p>No business data found for the selected filters</p>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- User Business Tab -->
                        <div id="user-business" class="tab-content <?php echo ($active_tab == 'user-business') ? 'active' : ''; ?>">
                            <h3 class="section-title">User-wise Business</h3>
                            
                            <!-- Month and Year Filter -->
                            <div class="filter-container">
                                <form method="GET" action="head.php">
                                    <input type="hidden" name="tab" value="user">
                                    <div class="filter-row">
                                        <div class="filter-group">
                                            <label for="user_month">Month</label>
                                            <select name="user_month" id="user_month" class="form-control">
                                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                                    <option value="<?php echo str_pad($m, 2, '0', STR_PAD_LEFT); ?>" <?php echo ($user_month == str_pad($m, 2, '0', STR_PAD_LEFT)) ? 'selected' : ''; ?>
                                                        <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                                    </option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                        <div class="filter-group">
                                            <label for="user_year">Year</label>
                                            <select name="user_year" id="user_year" class="form-control">
                                                <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                                                    <option value="<?php echo $y; ?>" <?php echo ($user_year == $y) ? 'selected' : ''; ?>>
                                                        <?php echo $y; ?>
                                                    </option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="filter-actions">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-filter"></i> Apply Filter
                                        </button>
                                        <a href="head.php?tab=user" class="btn btn-info">
                                            <i class="fas fa-redo"></i> Reset
                                        </a>
                                    </div>
                                </form>
                            </div>
                            
                            <div class="search-box">
                                <i class="fas fa-search"></i>
                                <input type="text" id="userSearch" placeholder="Search users...">
                            </div>
                            
                            <div class="table-container">
                                <table id="userBusinessTable">
                                    <thead>
                                        <tr>
                                            <th class="sortable" onclick="sortTable(0, 'userBusinessTable')">User Name</th>
                                            <th class="sortable" onclick="sortTable(1, 'userBusinessTable')">Employee ID</th>
                                            <th class="sortable" onclick="sortTable(2, 'userBusinessTable')">Department</th>
                                            <th class="sortable" onclick="sortTable(3, 'userBusinessTable')">Paid Count</th>
                                            <th class="sortable" onclick="sortTable(4, 'userBusinessTable')">Premium (Incl. GST)</th>
                                            <th class="sortable" onclick="sortTable(5, 'userBusinessTable')">Premium (Excl. GST)</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (mysqli_num_rows($user_business_filtered) > 0): ?>
                                            <?php while ($user = mysqli_fetch_assoc($user_business_filtered)): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($user['emp_id']); ?></td>
                                                    <td><?php echo htmlspecialchars($user['department']); ?></td>
                                                    <td><?php echo $user['paid_count']; ?></td>
                                                    <td>₹<?php echo number_format($user['total_premium'], 2); ?></td>
                                                    <td class="premium-excl-gst">₹<?php echo number_format($user['total_premium'] / 1.18, 2); ?></td>
                                                    <td>
                                                        <button class="btn btn-primary" onclick="viewUserBusiness(<?php echo $user['id']; ?>)">
                                                            <i class="fas fa-eye"></i> View
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="7" class="no-data">
                                                    <i class="fas fa-users"></i>
                                                    <p>No users found</p>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Manager Business Tab -->
                        <div id="manager-business" class="tab-content <?php echo ($active_tab == 'manager-business') ? 'active' : ''; ?>">
                            <h3 class="section-title">Manager-wise Business</h3>
                            
                            <!-- Month and Year Filter -->
                            <div class="filter-container">
                                <form method="GET" action="head.php">
                                    <input type="hidden" name="tab" value="manager">
                                    <div class="filter-row">
                                        <div class="filter-group">
                                            <label for="manager_month">Month</label>
                                            <select name="manager_month" id="manager_month" class="form-control">
                                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                                    <option value="<?php echo str_pad($m, 2, '0', STR_PAD_LEFT); ?>" <?php echo ($manager_month == str_pad($m, 2, '0', STR_PAD_LEFT)) ? 'selected' : ''; ?>
                                                        <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                                    </option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                        <div class="filter-group">
                                            <label for="manager_year">Year</label>
                                            <select name="manager_year" id="manager_year" class="form-control">
                                                <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                                                    <option value="<?php echo $y; ?>" <?php echo ($manager_year == $y) ? 'selected' : ''; ?>>
                                                        <?php echo $y; ?>
                                                    </option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="filter-actions">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-filter"></i> Apply Filter
                                        </button>
                                        <a href="head.php?tab=manager" class="btn btn-info">
                                            <i class="fas fa-redo"></i> Reset
                                        </a>
                                    </div>
                                </form>
                            </div>
                            
                            <div class="search-box">
                                <i class="fas fa-search"></i>
                                <input type="text" id="managerSearch" placeholder="Search managers...">
                            </div>
                            
                            <div class="table-container">
                                <table id="managerBusinessTable">
                                    <thead>
                                        <tr>
                                            <th class="sortable" onclick="sortTable(0, 'managerBusinessTable')">Manager Name</th>
                                            <th class="sortable" onclick="sortTable(1, 'managerBusinessTable')">Employee ID</th>
                                            <th class="sortable" onclick="sortTable(2, 'managerBusinessTable')">Department</th>
                                            <th class="sortable" onclick="sortTable(3, 'managerBusinessTable')">Team Paid Count</th>
                                            <th class="sortable" onclick="sortTable(4, 'managerBusinessTable')">Total Premium (Incl. GST)</th>
                                            <th class="sortable" onclick="sortTable(5, 'managerBusinessTable')">Total Premium (Excl. GST)</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (mysqli_num_rows($manager_business_filtered) > 0): ?>
                                            <?php while ($manager = mysqli_fetch_assoc($manager_business_filtered)): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($manager['full_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($manager['emp_id']); ?></td>
                                                    <td><?php echo htmlspecialchars($manager['department']); ?></td>
                                                    <td><?php echo $manager['paid_count']; ?></td>
                                                    <td>₹<?php echo number_format($manager['total_premium'], 2); ?></td>
                                                    <td class="premium-excl-gst">₹<?php echo number_format($manager['total_premium'] / 1.18, 2); ?></td>
                                                    <td>
                                                        <button class="btn btn-primary" onclick="viewManagerBusiness(<?php echo $manager['id']; ?>)">
                                                            <i class="fas fa-eye"></i> View
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="7" class="no-data">
                                                    <i class="fas fa-users"></i>
                                                    <p>No managers found</p>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Paid Requests Tab -->
                        <div id="paid-requests" class="tab-content <?php echo ($active_tab == 'paid-requests') ? 'active' : ''; ?>">
                            <h3 class="section-title">Paid Requests</h3>
                            
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Reference #</th>
                                            <th>User</th>
                                            <th>Manager</th>
                                            <th>Customer</th>
                                            <th>Premium (Incl. GST)</th>
                                            <th>Premium (Excl. GST)</th>
                                            <th>Paid Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (mysqli_num_rows($paid_requests) > 0): ?>
                                            <?php while ($request = mysqli_fetch_assoc($paid_requests)): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($request['reference_number']); ?></td>
                                                    <td><?php echo htmlspecialchars($request['user_name']); ?> (<?php echo htmlspecialchars($request['user_emp_id']); ?>)</td>
                                                    <td><?php echo htmlspecialchars($request['manager_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($request['name']); ?></td>
                                                    <td>₹<?php echo number_format($request['premium'], 2); ?></td>
                                                    <td class="premium-excl-gst">₹<?php echo number_format($request['premium'] / 1.18, 2); ?></td>
                                                    <td><?php echo $request['paid_date']; ?></td>
                                                    <td>
                                                        <button class="btn btn-primary" onclick="viewRequest(<?php echo $request['id']; ?>)">
                                                            <i class="fas fa-eye"></i> View
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="8" class="no-data">
                                                    <i class="fas fa-clipboard-check"></i>
                                                    <p>No paid requests</p>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <!-- Mark as Paid Modal -->
    <div id="paidModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">Mark Sales Request as Paid</h4>
                <span class="close" onclick="closeModal('paidModal')">&times;</span>
            </div>
            <form method="POST" action="process_action.php">
                <input type="hidden" name="request_id" id="paid_request_id">
                <input type="hidden" name="action" value="paid">
                <div class="form-group">
                    <label for="comments">Comments (Optional)</label>
                    <textarea class="form-control" id="comments" name="comments" rows="3"></textarea>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-danger" onclick="closeModal('paidModal')">Cancel</button>
                    <button type="submit" class="btn btn-success">Mark as Paid</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Floating button to scroll to top -->
    <button class="floating-btn" onclick="scrollToTop()">
        <i class="fas fa-arrow-up"></i>
    </button>
    
    <script>
        // NEW: Function to update today's business data
        function updateTodayBusiness() {
            fetch('get_today_business.php')
                .then(response => response.json())
                .then(data => {
                    // Update Total Premium Incl.
                    const inclAmount = document.querySelector('.today-stat-value'); // First stat box
                    if(inclAmount) inclAmount.textContent = '₹' + parseFloat(data.amount).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                    
                    // Update Total Premium Excl.
                    const exclAmount = document.querySelectorAll('.today-stat-value')[1]; // Second stat box
                    if(exclAmount) exclAmount.textContent = '₹' + (parseFloat(data.amount) / 1.18).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});

                    // Update Policy Count
                    const countElement = document.querySelector('.today-business .today-business-count');
                    if(countElement) countElement.textContent = data.count + ' policies';
                })
                .catch(error => console.error('Error fetching today\'s business:', error));
        }
        
        // Update today's business every 30 seconds
        setInterval(updateTodayBusiness, 30000);
        
        // Prepare data for the weekly chart
        const weeklySalesData = <?php 
            if (!empty($error_message)) {
                echo '[]';
            } else {
                $chart_data = [];
                while ($row = mysqli_fetch_assoc($weekly_sales)) {
                    $chart_data[] = $row;
                }
                echo json_encode($chart_data);
            }
        ?>;
        
        // Extract labels and data for the weekly chart
        const weeklyLabels = weeklySalesData.map(item => {
            const date = new Date(item.date);
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        });
        
        const weeklyAmounts = weeklySalesData.map(item => item.paid_amount);
        const weeklyCounts = weeklySalesData.map(item => item.paid_count);
        
        // Create the weekly chart
        const weeklyCtx = document.getElementById('weeklyChart').getContext('2d');
        const weeklyChart = new Chart(weeklyCtx, {
            type: 'line',
            data: {
                labels: weeklyLabels,
                datasets: [{
                    label: 'Daily Premium (₹)',
                    data: weeklyAmounts,
                    borderColor: '#f05d49',
                    backgroundColor: 'rgba(240, 93, 73, 0.1)',
                    tension: 0.4,
                    fill: true,
                    yAxisID: 'y'
                }, {
                    label: 'Daily Count',
                    data: weeklyCounts,
                    borderColor: '#389e0d',
                    backgroundColor: 'rgba(56, 158, 13, 0.1)',
                    tension: 0.4,
                    fill: true,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Amount (₹)'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Count'
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                }
            }
        });
        
        // Prepare data for the monthly chart
        const monthlySalesData = <?php 
            if (!empty($error_message)) {
                echo '[]';
            } else {
                // Reset the result pointer to the beginning
                mysqli_data_seek($monthly_sales, 0);
                $chart_data = [];
                while ($row = mysqli_fetch_assoc($monthly_sales)) {
                    $chart_data[] = $row;
                }
                echo json_encode($chart_data);
            }
        ?>;
        
        // Extract labels and data for the monthly chart
        const labels = monthlySalesData.map(item => {
            const date = new Date(item.month + '-01');
            return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short' });
        }).reverse();
        
        const paidAmounts = monthlySalesData.map(item => item.paid_amount).reverse();
        const paidCounts = monthlySalesData.map(item => item.paid_count).reverse();
        
        // Create the monthly chart
        const ctx = document.getElementById('salesChart').getContext('2d');
        const salesChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Paid Amount (₹)',
                    data: paidAmounts,
                    borderColor: '#f05d49',
                    backgroundColor: 'rgba(240, 93, 73, 0.1)',
                    tension: 0.4,
                    fill: true,
                    yAxisID: 'y'
                }, {
                    label: 'Paid Count',
                    data: paidCounts,
                    borderColor: '#389e0d',
                    backgroundColor: 'rgba(56, 158, 13, 0.1)',
                    tension: 0.4,
                    fill: true,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Amount (₹)'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Count'
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                }
            }
        });

        // --- NEW ADD-ON CHARTS ---

        // 1. State Chart
        const stateData = <?php 
            if (!empty($error_message) || !isset($state_stats)) {
                echo '[]';
            } else {
                $chart_data = [];
                while ($row = mysqli_fetch_assoc($state_stats)) {
                    $chart_data[] = $row;
                }
                echo json_encode($chart_data);
            }
        ?>;
        const stateLabels = stateData.map(item => item.state);
        const stateCounts = stateData.map(item => item.count);
        
        const stateCtx = document.getElementById('stateChart').getContext('2d');
        new Chart(stateCtx, {
            type: 'bar',
            data: {
                labels: stateLabels,
                datasets: [{
                    label: 'Policies',
                    data: stateCounts,
                    backgroundColor: 'rgba(54, 162, 235, 0.6)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y', // Horizontal Bar Chart for better state name reading
                plugins: {
                    legend: { display: false }
                }
            }
        });

        // 2. Fuel Type Chart
        const fuelData = <?php 
            if (!empty($error_message) || !isset($fuel_stats)) {
                echo '[]';
            } else {
                $chart_data = [];
                while ($row = mysqli_fetch_assoc($fuel_stats)) {
                    $chart_data[] = $row;
                }
                echo json_encode($chart_data);
            }
        ?>;
        const fuelLabels = fuelData.map(item => item.fuel_type);
        const fuelCounts = fuelData.map(item => item.count);

        const fuelCtx = document.getElementById('fuelChart').getContext('2d');
        new Chart(fuelCtx, {
            type: 'doughnut',
            data: {
                labels: fuelLabels,
                datasets: [{
                    data: fuelCounts,
                    backgroundColor: [
                        '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40'
                    ],
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right' }
                }
            }
        });

        // 3. Category Chart
        const categoryData = <?php 
            if (!empty($error_message) || !isset($category_stats)) {
                echo '[]';
            } else {
                $chart_data = [];
                while ($row = mysqli_fetch_assoc($category_stats)) {
                    $chart_data[] = $row;
                }
                echo json_encode($chart_data);
            }
        ?>;
        const categoryLabels = categoryData.map(item => item.category);
        const categoryCounts = categoryData.map(item => item.count);

        const categoryCtx = document.getElementById('categoryChart').getContext('2d');
        new Chart(categoryCtx, {
            type: 'pie',
            data: {
                labels: categoryLabels,
                datasets: [{
                    data: categoryCounts,
                    backgroundColor: [
                        '#f05d49', '#389e0d', '#096dd9', '#d48806', '#531dab', '#cf1322'
                    ],
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right' }
                }
            }
        });

        function openTab(evt, tabName) {
            var i, tabcontent, tabs;
            
            tabcontent = document.getElementsByClassName("tab-content");
            for (i = 0; i < tabcontent.length; i++) {
                tabcontent[i].classList.remove("active");
            }
            
            tabs = document.getElementsByClassName("tab");
            for (i = 0; i < tabs.length; i++) {
                tabs[i].classList.remove("active");
            }
            
            document.getElementById(tabName).classList.add("active");
            evt.currentTarget.classList.add("active");
            
            // Update URL to reflect the active tab
            var url = new URL(window.location);
            var tabParam = '';
            
            switch(tabName) {
                case 'pending-requests':
                    tabParam = 'pending';
                    break;
                case 'dashboard-content':
                    tabParam = 'dashboard';
                    break;
                case 'business-content':
                    tabParam = 'business';
                    break;
                case 'user-business':
                    tabParam = 'user';
                    break;
                case 'manager-business':
                    tabParam = 'manager';
                    break;
                case 'paid-requests':
                    tabParam = 'paid';
                    break;
            }
            
            if (tabParam) {
                url.searchParams.set('tab', tabParam);
            } else {
                url.searchParams.delete('tab');
            }
            
            window.history.replaceState({}, '', url);
        }
        
        function viewRequest(requestId) {
            window.location.href = 'view_sale.php?id=' + requestId;
        }
        
        function viewUserBusiness(userId) {
            window.location.href = 'user_business.php?id=' + userId;
        }
        
        function viewManagerBusiness(managerId) {
            window.location.href = 'manager_business.php?id=' + managerId;
        }
        
        function markAsPaid(requestId) {
            document.getElementById('paid_request_id').value = requestId;
            document.getElementById('paidModal').style.display = 'block';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        function sortTable(columnIndex, tableId) {
            var table, rows, switching, i, x, y, shouldSwitch;
            table = document.getElementById(tableId);
            switching = true;
            
            // Get the current sort direction
            var th = table.getElementsByTagName("th")[columnIndex];
            var sortDirection = "asc";
            
            // Check if the column is already sorted
            if (th.classList.contains("sorted-asc")) {
                sortDirection = "desc";
                th.classList.remove("sorted-asc");
                th.classList.add("sorted-desc");
            } else if (th.classList.contains("sorted-desc")) {
                sortDirection = "asc";
                th.classList.remove("sorted-desc");
                th.classList.add("sorted-asc");
            } else {
                // Remove sorted class from all headers
                var headers = table.getElementsByTagName("th");
                for (i = 0; i < headers.length; i++) {
                    headers[i].classList.remove("sorted-asc", "sorted-desc");
                }
                th.classList.add("sorted-asc");
            }
            
            // Make a loop that will continue until no switching has been done
            while (switching) {
                switching = false;
                rows = table.rows;
                
                // Loop through all table rows (except the first, which contains table headers)
                for (i = 1; i < (rows.length - 1); i++) {
                    shouldSwitch = false;
                    
                    // Get the two elements you want to compare, one from current row and one from the next
                    x = rows[i].getElementsByTagName("TD")[columnIndex];
                    y = rows[i + 1].getElementsByTagName("TD")[columnIndex];
                    
                    // Check if the two rows should switch place, based on the direction
                    if (sortDirection === "asc") {
                        if (x.innerHTML.toLowerCase() > y.innerHTML.toLowerCase()) {
                            shouldSwitch = true;
                            break;
                        }
                    } else if (sortDirection === "desc") {
                        if (x.innerHTML.toLowerCase() < y.innerHTML.toLowerCase()) {
                            shouldSwitch = true;
                            break;
                        }
                    }
                }
                
                if (shouldSwitch) {
                    rows[i].parentNode.insertBefore(rows[i + 1], rows[i]);
                    switching = true;
                }
            }
        }
        
        // Search functionality for user table
        document.getElementById('userSearch').addEventListener('keyup', function() {
            var input, filter, table, tr, td, i, txtValue;
            input = document.getElementById('userSearch');
            filter = input.value.toUpperCase();
            table = document.getElementById('userBusinessTable');
            tr = table.getElementsByTagName('tr');
            
            for (i = 0; i < tr.length; i++) {
                td = tr[i].getElementsByTagName('td')[0];
                if (td) {
                    txtValue = td.textContent || td.innerText;
                    if (txtValue.toUpperCase().indexOf(filter) > -1) {
                        tr[i].style.display = '';
                    } else {
                        tr[i].style.display = 'none';
                    }
                }
            }
        });
        
        // Search functionality for manager table
        document.getElementById('managerSearch').addEventListener('keyup', function() {
            var input, filter, table, tr, td, i, txtValue;
            input = document.getElementById('managerSearch');
            filter = input.value.toUpperCase();
            table = document.getElementById('managerBusinessTable');
            tr = table.getElementsByTagName('tr');
            
            for (i = 0; i < tr.length; i++) {
                td = tr[i].getElementsByTagName('td')[0];
                if (td) {
                    txtValue = td.textContent || td.innerText;
                    if (txtValue.toUpperCase().indexOf(filter) > -1) {
                        tr[i].style.display = '';
                    } else {
                        tr[i].style.display = 'none';
                    }
                }
            }
        });
        
        // Toggle sidebar on mobile
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const menuToggle = document.getElementById('menuToggle');
            
            if (window.innerWidth <= 768 && 
                !sidebar.contains(event.target) && 
                !menuToggle.contains(event.target) && 
                sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
            }
        });
        
        // Scroll to top
        function scrollToTop() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>