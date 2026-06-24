<?php
require_once '../config.php';
require_once 'functions.php';

// Check if user is logged in and has manager role
if (!is_logged_in() || !has_role('Manager')) {
    show_notification('You do not have permission to access this page', 'error');
    redirect('../login.php');
}

// Generate a form token to prevent duplicate submissions
 $_SESSION['form_token'] = bin2hex(random_bytes(32));
 $form_token = $_SESSION['form_token'];

// Get manager details
 $manager_id = $_SESSION['user_id'];
 $manager_details = getUserDetails($manager_id);
 $head_id = $manager_details['head_id'];

// Get current month and year for filtering
 $current_month = date('m');
 $current_year = date('Y');

// Determine which tab should be active based on URL parameters
 $active_tab = 'pending-requests'; // Default tab
if (isset($_GET['tab'])) {
    switch ($_GET['tab']) {
        case 'dashboard':
            $active_tab = 'dashboard-content';
            break;
        case 'pending':
            $active_tab = 'pending-requests';
            break;
        case 'verified':
            $active_tab = 'verified-requests';
            break;
        case 'rejected':
            $active_tab = 'rejected-requests';
            break;
    }
}

// Get all pending sales requests from users under this manager
 $pending_requests_sql = "SELECT sr.*, u.full_name as user_name, u.emp_id as user_emp_id 
                        FROM sales_requests sr 
                        JOIN users u ON sr.user_id = u.id 
                        WHERE sr.status = 'Pending' AND u.manager_id = ?
                        ORDER BY sr.created_at DESC";
 $stmt = $conn->prepare($pending_requests_sql);
mysqli_stmt_bind_param($stmt, "i", $manager_id);
mysqli_stmt_execute($stmt);
 $pending_requests = mysqli_stmt_get_result($stmt);

// FIXED: Get all verified sales requests - Including both 'Manager Verified' and 'Head Paid' status
// This will show all requests that the manager has verified, regardless of their current status
 $verified_requests_sql = "SELECT sr.*, u.full_name as user_name, u.emp_id as user_emp_id,
                          a_s.comments as verify_comments, a_s.created_at as verify_date,
                          CASE WHEN sr.status = 'Head Paid' THEN 'Paid' ELSE 'Verified' END as current_status
                         FROM sales_requests sr 
                         JOIN users u ON sr.user_id = u.id 
                         LEFT JOIN approvals_sales a_s ON sr.id = a_s.sales_request_id AND a_s.status = 'Verified' AND a_s.approver_id = ?
                         WHERE (sr.status = 'Manager Verified' OR sr.status = 'Head Paid') AND u.manager_id = ?
                         ORDER BY sr.updated_at DESC";
 $stmt = $conn->prepare($verified_requests_sql);
mysqli_stmt_bind_param($stmt, "ii", $manager_id, $manager_id);
mysqli_stmt_execute($stmt);
 $verified_requests = mysqli_stmt_get_result($stmt);

// FIXED: Get all rejected sales requests with rejection reason
 $rejected_requests_sql = "SELECT sr.*, u.full_name as user_name, u.emp_id as user_emp_id,
                          a_s.comments as reject_comments, a_s.created_at as reject_date
                         FROM sales_requests sr 
                         JOIN users u ON sr.user_id = u.id 
                         LEFT JOIN approvals_sales a_s ON sr.id = a_s.sales_request_id AND a_s.status = 'Rejected' AND a_s.approver_id = ?
                         WHERE sr.status = 'Rejected' AND u.manager_id = ?
                         ORDER BY sr.updated_at DESC";
 $stmt = $conn->prepare($rejected_requests_sql);
mysqli_stmt_bind_param($stmt, "ii", $manager_id, $manager_id);
mysqli_stmt_execute($stmt);
 $rejected_requests = mysqli_stmt_get_result($stmt);

// MODIFIED: Get statistics for current month only, excluding rejected requests from total premium
 $stats_sql = "SELECT 
           COUNT(*) AS total_requests,
           SUM(CASE WHEN sr.status = 'Pending' THEN 1 ELSE 0 END) AS pending_count,
           SUM(CASE WHEN sr.status = 'Manager Verified' THEN 1 ELSE 0 END) AS verified_count,
           SUM(CASE WHEN sr.status = 'Head Paid' THEN 1 ELSE 0 END) AS paid_count,
           SUM(CASE WHEN sr.status = 'Rejected' THEN 1 ELSE 0 END) AS rejected_count,
           SUM(CASE WHEN sr.status != 'Rejected' THEN sr.premium ELSE 0 END) AS total_premium,
           SUM(CASE WHEN sr.status = 'Rejected' THEN sr.premium ELSE 0 END) AS rejected_premium
           FROM sales_requests sr 
           JOIN users u ON sr.user_id = u.id 
           WHERE u.manager_id = ? AND MONTH(sr.created_at) = ? AND YEAR(sr.created_at) = ?";
 $stmt = $conn->prepare($stats_sql);
mysqli_stmt_bind_param($stmt, "iii", $manager_id, $current_month, $current_year);
mysqli_stmt_execute($stmt);
 $stats_result = mysqli_stmt_get_result($stmt);
 $stats = mysqli_fetch_assoc($stats_result);

// NEW: Get today's business data
 $today_business_sql = "SELECT 
                      SUM(sr.premium) AS today_amount,
                      COUNT(*) AS today_count
                      FROM sales_requests sr 
                      JOIN users u ON sr.user_id = u.id 
                      WHERE u.manager_id = ? AND sr.status = 'Head Paid' 
                      AND DATE(sr.updated_at) = CURDATE()";
 $stmt = $conn->prepare($today_business_sql);
if (!$stmt) {
    throw new Exception("Failed to prepare today's business query: " . mysqli_error($conn));
}
mysqli_stmt_bind_param($stmt, "i", $manager_id);
mysqli_stmt_execute($stmt);
 $today_business_result = mysqli_stmt_get_result($stmt);
 $today_business = mysqli_fetch_assoc($today_business_result);

// MODIFIED: Get top 10 users by business for current month
 $top_users_sql = "SELECT u.id, u.full_name, u.emp_id, u.department,
                 SUM(sr.premium) as total_premium,
                 COUNT(*) as paid_count
                 FROM users u
                 LEFT JOIN sales_requests sr ON u.id = sr.user_id AND sr.status = 'Head Paid'
                 AND MONTH(sr.updated_at) = ? AND YEAR(sr.updated_at) = ?
                 WHERE u.manager_id = ? AND u.role = 'User'
                 GROUP BY u.id, u.full_name, u.emp_id, u.department
                 ORDER BY total_premium DESC
                 LIMIT 10";
 $stmt = $conn->prepare($top_users_sql);
if (!$stmt) {
    throw new Exception("Failed to prepare top users query: " . mysqli_error($conn));
}
mysqli_stmt_bind_param($stmt, "iii", $current_month, $current_year, $manager_id);
mysqli_stmt_execute($stmt);
 $top_users = mysqli_stmt_get_result($stmt);

// NEW: Get team members for this manager
 $team_members_sql = "SELECT * FROM users WHERE manager_id = ?";
 $stmt = $conn->prepare($team_members_sql);
if (!$stmt) {
    throw new Exception("Failed to prepare team members query: " . mysqli_error($conn));
}
mysqli_stmt_bind_param($stmt, "i", $manager_id);
mysqli_stmt_execute($stmt);
 $team_members_result = mysqli_stmt_get_result($stmt);

// NEW: Get last 7 days sales data for chart
 $weekly_sales_sql = "SELECT DATE(sr.updated_at) as date, 
                    SUM(sr.premium) as paid_amount,
                    COUNT(*) as paid_count
                    FROM sales_requests sr 
                    JOIN users u ON sr.user_id = u.id 
                    WHERE u.manager_id = ? AND sr.status = 'Head Paid' 
                    AND sr.updated_at >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)
                    GROUP BY DATE(sr.updated_at)
                    ORDER BY date ASC";
 $stmt = $conn->prepare($weekly_sales_sql);
if (!$stmt) {
    throw new Exception("Failed to prepare weekly sales query: " . mysqli_error($conn));
}
mysqli_stmt_bind_param($stmt, "i", $manager_id);
mysqli_stmt_execute($stmt);
 $weekly_sales = mysqli_stmt_get_result($stmt);

// NEW: Get last 7 days total business
 $weekly_total_sql = "SELECT SUM(sr.premium) as total_amount, COUNT(*) as total_count
                     FROM sales_requests sr 
                     JOIN users u ON sr.user_id = u.id 
                     WHERE u.manager_id = ? AND sr.status = 'Head Paid' 
                     AND sr.updated_at >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)";
 $stmt = $conn->prepare($weekly_total_sql);
if (!$stmt) {
    throw new Exception("Failed to prepare weekly total query: " . mysqli_error($conn));
}
mysqli_stmt_bind_param($stmt, "i", $manager_id);
mysqli_stmt_execute($stmt);
 $weekly_total_result = mysqli_stmt_get_result($stmt);
 $weekly_total = mysqli_fetch_assoc($weekly_total_result);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Dashboard - Sales System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="https://www.coveryou.in/images/favicon.png" type="image/png">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Use the same styles as user dashboard */
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
            max-width: 1200px;
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
        .btn-danger {
            background-color: #cf1322;
            color: white;
        }
        .btn-danger:hover {
            background-color: #a8071a;
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
        .chart-container {
            height: 300px;
            margin-bottom: 20px;
        }
        .team-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .team-member-card {
            background-color: #f8fafc;
            border-radius: var(--radius);
            padding: 15px;
            box-shadow: var(--shadow);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .team-member-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        .team-member-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        .team-member-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }
        .team-member-info {
            flex: 1;
        }
        .team-member-name {
            font-weight: 600;
            color: var(--dark);
        }
        .team-member-details {
            font-size: 12px;
            color: var(--text-light);
        }
        .team-member-stats {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
        }
        .team-member-stat {
            text-align: center;
            flex: 1;
        }
        .team-member-stat-value {
            font-size: 16px;
            font-weight: 600;
            color: var(--primary);
        }
        .team-member-stat-label {
            font-size: 11px;
            color: var(--text-light);
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
        /* NEW: Styles for Today's Business and Top Users sections */
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
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
        }
        .top-user-card {
            background: var(--light);
            border-radius: var(--radius);
            padding: 15px;
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            position: relative;
            overflow: hidden;
        }
        .top-user-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
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
        .top-user-amount {
            font-weight: 700;
            color: var(--primary);
            font-size: 16px;
        }
        .top-user-card.rank-1 {
            border: 2px solid #FFD700;
            background: linear-gradient(135deg, #fff9e6 0%, #ffffff 100%);
        }
        .top-user-card.rank-1 .top-user-name {
            color: #d4af37;
            font-weight: 700;
        }
        .top-user-card.rank-1 .top-user-amount {
            color: #d4af37;
            font-size: 18px;
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
        .comment-cell {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .comment-cell:hover {
            white-space: normal;
            word-wrap: break-word;
        }
        /* NEW: Style for rejected requests card */
        .stat-card.rejected {
            border-left: 3px solid #cf1322;
        }
        .stat-card.rejected .stat-value {
            color: #cf1322;
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
            .team-grid {
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
                    <div class="sidebar-logo-text">Dashboard</div>
                </div>
            </div>
            
            <div class="sidebar-user">
                <div class="sidebar-user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                <div class="sidebar-user-role"><?php echo htmlspecialchars($_SESSION['role']); ?> - <?php echo htmlspecialchars($_SESSION['department']); ?></div>
            </div>
            
            <nav class="sidebar-menu">
                <a href="manager.php" class="sidebar-menu-item active">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="../dashboard_manager.php" class="sidebar-menu-item">
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
                        <div class="logo-text">Manager <span>Dashboard</span></div>
                    </div>
                    <p class="tagline">Verify and manage sales requests from your team</p>
                    
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
                
                <?php if (isset($_SESSION['notification'])): ?>
                    <div class="alert alert-<?php echo $_SESSION['notification']['type']; ?>">
                        <?php echo $_SESSION['notification']['message']; ?>
                    </div>
                    <?php unset($_SESSION['notification']); ?>
                <?php endif; ?>
                
                <!-- NEW: Today's Business Section -->
                <div class="today-business">
                    <div>
                        <div class="today-business-title">
                            <i class="fas fa-chart-line"></i> Today's Business
                            <span class="live-indicator">
                                <span class="live-dot"></span> Live
                            </span>
                        </div>
                        <div class="today-business-value" id="todayBusinessAmount">₹<?php echo number_format($today_business['today_amount'], 2); ?></div>
                        <div class="today-business-count" id="todayBusinessCount"><?php echo $today_business['today_count']; ?> policies</div>
                    </div>
                    <i class="fas fa-coins today-business-icon"></i>
                </div>
                
                <!-- NEW: Top 10 Users Section -->
                <div class="top-users">
                    <h3 class="section-title">Top 10 Performers - <?php echo date('F Y'); ?></h3>
                    <div class="top-users-grid">
                        <?php 
                        $rank = 1;
                        if (mysqli_num_rows($top_users) > 0): 
                            while ($user = mysqli_fetch_assoc($top_users)): ?>
                                <div class="top-user-card <?php echo $rank == 1 ? 'rank-1' : ''; ?>">
                                    <div class="top-user-rank <?php 
                                        echo $rank == 1 ? 'gold' : ($rank == 2 ? 'silver' : ($rank == 3 ? 'bronze' : '')); 
                                    ?>">
                                        <?php echo $rank; ?>
                                    </div>
                                    <div class="top-user-info">
                                        <div class="top-user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                        <div class="top-user-details"><?php echo htmlspecialchars($user['emp_id']); ?> • <?php echo htmlspecialchars($user['department']); ?></div>
                                        <div class="top-user-amount">₹<?php echo number_format($user['total_premium'], 2); ?></div>
                                    </div>
                                </div>
                                <?php $rank++; ?>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="no-data" style="grid-column: 1 / -1;">
                                <i class="fas fa-users"></i>
                                <p>No users found</p>
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
                        <div class="tab <?php echo ($active_tab == 'verified-requests') ? 'active' : ''; ?>" onclick="openTab(event, 'verified-requests')">
                            <i class="fas fa-check-circle"></i> Verified Requests
                        </div>
                        <div class="tab <?php echo ($active_tab == 'rejected-requests') ? 'active' : ''; ?>" onclick="openTab(event, 'rejected-requests')">
                            <i class="fas fa-times-circle"></i> Rejected Requests
                        </div>
                    </div>
                </div>
                
                <div class="dashboard-container">
                    <!-- Pending Requests Tab (now first) -->
                    <div id="pending-requests" class="tab-content <?php echo ($active_tab == 'pending-requests') ? 'active' : ''; ?>">
                        <h3 class="section-title">Pending Sales Requests</h3>
                        
                        <?php if (mysqli_num_rows($pending_requests) > 0): ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Reference #</th>
                                            <th>User</th>
                                            <th>Customer</th>
                                            <th>Premium</th>
                                            <th>Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($request = mysqli_fetch_assoc($pending_requests)): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($request['reference_number']); ?></td>
                                                <td><?php echo htmlspecialchars($request['user_name']); ?> (<?php echo htmlspecialchars($request['user_emp_id']); ?>)</td>
                                                <td><?php echo htmlspecialchars($request['name']); ?></td>
                                                <td>₹<?php echo number_format($request['premium'], 2); ?></td>
                                                <td><?php echo date('d M Y', strtotime($request['created_at'])); ?></td>
                                                <td>
                                                    <button class="btn btn-success" onclick="verifyRequest(<?php echo $request['id']; ?>)">
                                                        <i class="fas fa-check"></i> Verify
                                                    </button>
                                                    <button class="btn btn-danger" onclick="rejectRequest(<?php echo $request['id']; ?>)">
                                                        <i class="fas fa-times"></i> Reject
                                                    </button>
                                                    <button class="btn btn-primary" onclick="viewRequest(<?php echo $request['id']; ?>)">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="no-data">
                                <i class="fas fa-clipboard-check"></i>
                                <p>No pending sales requests</p>
                            </div>
                        <?php endif; ?>
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
                                <div class="stat-value"><?php echo $stats['pending_count']; ?></div>
                                <div class="stat-label">Pending Verification</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?php echo $stats['verified_count']; ?></div>
                                <div class="stat-label">Verified</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?php echo $stats['paid_count']; ?></div>
                                <div class="stat-label">Paid</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?php echo $stats['rejected_count']; ?></div>
                                <div class="stat-label">Rejected</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value">₹<?php echo number_format($stats['total_premium'], 2); ?></div>
                                <div class="stat-label">Total Premium </div>
                            </div>
                            <!-- NEW: Rejected Requests Card -->
                            <div class="stat-card rejected">
                                <div class="stat-value">₹<?php echo number_format($stats['rejected_premium'], 2); ?></div>
                                <div class="stat-label">Rejected Premium (<?php echo $stats['rejected_count']; ?>)</div>
                            </div>
                        </div>
                        
                        <!-- NEW: Last 7 days business summary -->
                        <h3 class="section-title">Last 7 Days Business Summary</h3>
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
                        
                        <!-- NEW: Last 7 days business trend chart -->
                        <h3 class="section-title">Last 7 Days Business Trend</h3>
                        <div class="chart-container">
                            <canvas id="weeklyChart"></canvas>
                        </div>
                        
                        <!-- NEW: Team members section -->
                        <h3 class="section-title">Your Team Members</h3>
                        <?php if (mysqli_num_rows($team_members_result) > 0): ?>
                            <div class="team-grid">
                                <?php 
                                // Reset result pointer to reuse it
                                mysqli_data_seek($team_members_result, 0);
                                
                                while ($member = mysqli_fetch_assoc($team_members_result)): ?>
                                    <?php
                                    // Get pending count for this team member
                                    $pending_count_sql = "SELECT COUNT(*) AS count 
                                                       FROM sales_requests sr 
                                                       WHERE sr.user_id = ? AND sr.status = 'Pending'";
                                    $stmt = mysqli_prepare($conn, $pending_count_sql);
                                    mysqli_stmt_bind_param($stmt, "i", $member['id']);
                                    mysqli_stmt_execute($stmt);
                                    $pending_count_result = mysqli_stmt_get_result($stmt);
                                    $pending_count = mysqli_fetch_assoc($pending_count_result)['count'];
                                    
                                    // Get member amount
                                    $member_amount_sql = "SELECT SUM(sr.premium) AS amount 
                                                       FROM sales_requests sr 
                                                       WHERE sr.user_id = ? AND sr.status = 'Head Paid'";
                                    $stmt = mysqli_prepare($conn, $member_amount_sql);
                                    mysqli_stmt_bind_param($stmt, "i", $member['id']);
                                    mysqli_stmt_execute($stmt);
                                    $member_amount_result = mysqli_stmt_get_result($stmt);
                                    $member_amount = mysqli_fetch_assoc($member_amount_result)['amount'];
                                    ?>
                                    <div class="team-member-card">
                                        <div class="team-member-header">
                                            <div class="team-member-avatar">
                                                <?php echo strtoupper(substr($member['full_name'], 0, 2)); ?>
                                            </div>
                                            <div class="team-member-info">
                                                <div class="team-member-name"><?php echo htmlspecialchars($member['full_name']); ?></div>
                                                <div class="team-member-details"><?php echo htmlspecialchars($member['emp_id']); ?> • <?php echo htmlspecialchars($member['department']); ?></div>
                                            </div>
                                        </div>
                                        <div class="team-member-stats">
                                            <div class="team-member-stat">
                                                <div class="team-member-stat-value"><?php echo $pending_count; ?></div>
                                                <div class="team-member-stat-label">Pending</div>
                                            </div>
                                            <div class="team-member-stat">
                                                <div class="team-member-stat-value">₹<?php echo number_format($member_amount, 0); ?></div>
                                                <div class="team-member-stat-label">Total Business</div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="no-data">
                                <i class="fas fa-users"></i>
                                <p>No team members found</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Verified Requests Tab -->
                    <div id="verified-requests" class="tab-content <?php echo ($active_tab == 'verified-requests') ? 'active' : ''; ?>">
                        <h3 class="section-title">Verified Sales Requests</h3>
                        
                        <?php if (mysqli_num_rows($verified_requests) > 0): ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Reference #</th>
                                            <th>User</th>
                                            <th>Customer</th>
                                            <th>Premium</th>
                                            <th>Status</th>
                                            <th>Verified Date</th>
                                            <th>Comments</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($request = mysqli_fetch_assoc($verified_requests)): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($request['reference_number']); ?></td>
                                                <td><?php echo htmlspecialchars($request['user_name']); ?> (<?php echo htmlspecialchars($request['user_emp_id']); ?>)</td>
                                                <td><?php echo htmlspecialchars($request['name']); ?></td>
                                                <td>₹<?php echo number_format($request['premium'], 2); ?></td>
                                                <td>
                                                    <span class="status-badge <?php 
                                                        echo $request['current_status'] == 'Paid' ? 'status-head-paid' : 'status-manager-verified'; 
                                                    ?>">
                                                        <?php echo $request['current_status']; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('d M Y', strtotime($request['verify_date'])); ?></td>
                                                <td class="comment-cell"><?php echo htmlspecialchars($request['verify_comments'] ? $request['verify_comments'] : 'N/A'); ?></td>
                                                <td>
                                                    <button class="btn btn-primary" onclick="viewRequest(<?php echo $request['id']; ?>)">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="no-data">
                                <i class="fas fa-clipboard-check"></i>
                                <p>No verified sales requests</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Rejected Requests Tab -->
                    <div id="rejected-requests" class="tab-content <?php echo ($active_tab == 'rejected-requests') ? 'active' : ''; ?>">
                        <h3 class="section-title">Rejected Sales Requests</h3>
                        
                        <?php if (mysqli_num_rows($rejected_requests) > 0): ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Reference #</th>
                                            <th>User</th>
                                            <th>Customer</th>
                                            <th>Premium</th>
                                            <th>Rejected Date</th>
                                            <th>Reason</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($request = mysqli_fetch_assoc($rejected_requests)): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($request['reference_number']); ?></td>
                                                <td><?php echo htmlspecialchars($request['user_name']); ?> (<?php echo htmlspecialchars($request['user_emp_id']); ?>)</td>
                                                <td><?php echo htmlspecialchars($request['name']); ?></td>
                                                <td>₹<?php echo number_format($request['premium'], 2); ?></td>
                                                <td><?php echo date('d M Y', strtotime($request['reject_date'])); ?></td>
                                                <td class="comment-cell"><?php echo htmlspecialchars($request['reject_comments'] ? $request['reject_comments'] : 'N/A'); ?></td>
                                                <td>
                                                    <button class="btn btn-primary" onclick="viewRequest(<?php echo $request['id']; ?>)">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="no-data">
                                <i class="fas fa-clipboard-check"></i>
                                <p>No rejected sales requests</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Verify Modal -->
    <div id="verifyModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">Verify Sales Request</h4>
                <span class="close" onclick="closeModal('verifyModal')">&times;</span>
            </div>
            <!-- REVISED: Added ID and Token -->
            <form method="POST" action="process_action.php" id="verifyForm">
                <input type="hidden" name="request_id" id="verify_request_id">
                <input type="hidden" name="action" value="verify">
                <input type="hidden" name="form_token" value="<?php echo htmlspecialchars($form_token); ?>">
                <div class="form-group">
                    <label for="verify_comments">Comments (Optional)</label>
                    <textarea class="form-control" id="verify_comments" name="comments" rows="3"></textarea>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-danger" onclick="closeModal('verifyModal')">Cancel</button>
                    <button type="submit" class="btn btn-success">Verify</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reject Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">Reject Sales Request</h4>
                <span class="close" onclick="closeModal('rejectModal')">&times;</span>
            </div>
            <!-- REVISED: Added ID and Token -->
            <form method="POST" action="process_action.php" id="rejectForm">
                <input type="hidden" name="request_id" id="reject_request_id">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="form_token" value="<?php echo htmlspecialchars($form_token); ?>">
                <div class="form-group">
                    <label for="reject_comments">Reason for Rejection *</label>
                    <textarea class="form-control" id="reject_comments" name="comments" rows="3" required></textarea>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-danger" onclick="closeModal('rejectModal')">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
    // Wait for the DOM to be fully loaded before running the script
    document.addEventListener('DOMContentLoaded', function() {
        
        // Function to prevent double submission of forms
        function preventDoubleSubmission(formId) {
            const form = document.getElementById(formId);
            if (form) {
                form.addEventListener('submit', function() {
                    const submitBtn = form.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        // Disable the button and change its text to show processing
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                    }
                });
            }
        }

        // Initialize the forms to prevent double submission
        preventDoubleSubmission('verifyForm');
        preventDoubleSubmission('rejectForm');
        
        // NEW: Function to update today's business data
        function updateTodayBusiness() {
            fetch('get_today_business_manager.php')
                .then(response => response.json())
                .then(data => {
                    document.getElementById('todayBusinessAmount').textContent = '₹' + parseFloat(data.amount).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                    document.getElementById('todayBusinessCount').textContent = data.count + ' policies';
                })
                .catch(error => console.error('Error fetching today\'s business:', error));
        }
        
        // Update today's business every 30 seconds
        setInterval(updateTodayBusiness, 30000);
        
        // Prepare data for the weekly chart
        const weeklySalesData = <?php 
            $chart_data = [];
            while ($row = mysqli_fetch_assoc($weekly_sales)) {
                $chart_data[] = $row;
            }
            echo json_encode($chart_data);
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
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    });

    // Global functions for tab switching and modal controls
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
            case 'verified-requests':
                tabParam = 'verified';
                break;
            case 'rejected-requests':
                tabParam = 'rejected';
                break;
        }
        
        if (tabParam) {
            url.searchParams.set('tab', tabParam);
        } else {
            url.searchParams.delete('tab');
        }
        
        window.history.replaceState({}, '', url);
    }
    
    function verifyRequest(requestId) {
        document.getElementById('verify_request_id').value = requestId;
        document.getElementById('verifyModal').style.display = 'block';
    }
    
    function rejectRequest(requestId) {
        document.getElementById('reject_request_id').value = requestId;
        document.getElementById('rejectModal').style.display = 'block';
    }
    
    function viewRequest(requestId) {
        window.location.href = 'view_sale.php?id=' + requestId;
    }
    
    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
    }
    </script>
</body>
</html>