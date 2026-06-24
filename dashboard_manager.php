<?php
require_once 'config.php';

// Check if user is logged in and has manager role
if (!is_logged_in() || !has_role('Manager')) {
    show_notification('You do not have permission to access this page', 'error');
    redirect('login.php');
}

// Get users under this manager
 $users_sql = "SELECT * FROM users WHERE manager_id = ?";
 $stmt = mysqli_prepare($conn, $users_sql);
if (!$stmt) {
    // Error preparing statement
    show_notification('Database error: ' . mysqli_error($conn), 'error');
    redirect('login.php');
}
mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
 $users_result = mysqli_stmt_get_result($stmt);

// Get pending cashback requests from users under this manager
 $pending_sql = "SELECT cr.*, u.full_name AS user_name, u.emp_id AS user_emp_id, u.department AS user_department
               FROM cashback_requests cr 
               JOIN users u ON cr.user_id = u.id 
               WHERE cr.status = 'Pending' AND u.manager_id = ?
               ORDER BY cr.created_at DESC";
 $stmt = mysqli_prepare($conn, $pending_sql);
if (!$stmt) {
    // Error preparing statement
    show_notification('Database error: ' . mysqli_error($conn), 'error');
    redirect('login.php');
}
mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
 $pending_result = mysqli_stmt_get_result($stmt);

// Get approved cashback requests by this manager (including those further approved by Head, Validator or Finance)
// FIXED: Added 'Validator Approved' to the status check
 $approved_sql = "SELECT cr.*, u.full_name AS user_name, u.emp_id AS user_emp_id, u.department AS user_department
                FROM cashback_requests cr 
                JOIN users u ON cr.user_id = u.id 
                WHERE (cr.status = 'Manager Approved' OR cr.status = 'Head Approved' OR cr.status = 'Validator Approved' OR cr.status = 'Finance Approved') AND u.manager_id = ?
                ORDER BY cr.created_at DESC";
 $stmt = mysqli_prepare($conn, $approved_sql);
if (!$stmt) {
    // Error preparing statement
    show_notification('Database error: ' . mysqli_error($conn), 'error');
    redirect('login.php');
}
mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
 $approved_result = mysqli_stmt_get_result($stmt);

// Get statistics
// FIXED: Added 'Validator Approved' to the status check in all relevant places
 $stats_sql = "SELECT 
             COUNT(*) AS total_requests,
             SUM(CASE WHEN cr.status = 'Pending' THEN 1 ELSE 0 END) AS pending_count,
             SUM(CASE WHEN cr.status = 'Manager Approved' THEN 1 ELSE 0 END) AS manager_approved_count,
             SUM(CASE WHEN cr.status = 'Head Approved' THEN 1 ELSE 0 END) AS head_approved_count,
             SUM(CASE WHEN cr.status = 'Validator Approved' THEN 1 ELSE 0 END) AS validator_approved_count,
             SUM(CASE WHEN cr.status = 'Finance Approved' THEN 1 ELSE 0 END) AS finance_approved_count,
             SUM(CASE WHEN cr.status = 'Pending' THEN cr.referral_amount ELSE 0 END) AS pending_amount,
             SUM(CASE WHEN cr.status = 'Manager Approved' THEN cr.referral_amount ELSE 0 END) AS manager_approved_amount,
             SUM(CASE WHEN cr.status = 'Head Approved' THEN cr.referral_amount ELSE 0 END) AS head_approved_amount,
             SUM(CASE WHEN cr.status = 'Validator Approved' THEN cr.referral_amount ELSE 0 END) AS validator_approved_amount,
             SUM(CASE WHEN cr.status = 'Finance Approved' THEN cr.referral_amount ELSE 0 END) AS finance_approved_amount,
             SUM(CASE WHEN cr.status IN ('Manager Approved', 'Head Approved', 'Validator Approved', 'Finance Approved') THEN cr.premium_with_gst ELSE 0 END) AS total_premium_with_gst,
             SUM(CASE WHEN cr.status IN ('Manager Approved', 'Head Approved', 'Validator Approved', 'Finance Approved') THEN cr.without_gst ELSE 0 END) AS total_without_gst
             FROM cashback_requests cr 
             JOIN users u ON cr.user_id = u.id 
             WHERE u.manager_id = ?";
 $stmt = mysqli_prepare($conn, $stats_sql);
if (!$stmt) {
    // Error preparing statement
    show_notification('Database error: ' . mysqli_error($conn), 'error');
    redirect('login.php');
}
mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
 $stats_result = mysqli_stmt_get_result($stmt);
 $stats = mysqli_fetch_assoc($stats_result);

// Calculate total approved count and amount
// FIXED: Added validator_approved_count and validator_approved_amount
 $stats['approved_count'] = $stats['manager_approved_count'] + $stats['head_approved_count'] + $stats['validator_approved_count'] + $stats['finance_approved_count'];
 $stats['approved_amount'] = $stats['manager_approved_amount'] + $stats['head_approved_amount'] + $stats['validator_approved_amount'] + $stats['finance_approved_amount'];

// Get user-wise statistics
// FIXED: Added 'Validator Approved' to the status check
 $user_stats_sql = "SELECT 
                  u.full_name AS user_name,
                  u.department AS user_department,
                  COUNT(*) AS count,
                  SUM(cr.referral_amount) AS amount,
                  SUM(cr.premium_with_gst) AS premium_with_gst,
                  SUM(cr.without_gst) AS without_gst
                  FROM cashback_requests cr 
                  JOIN users u ON cr.user_id = u.id 
                  WHERE u.manager_id = ? AND cr.status IN ('Manager Approved', 'Head Approved', 'Validator Approved', 'Finance Approved')
                  GROUP BY u.id
                  ORDER BY amount DESC";
 $stmt = mysqli_prepare($conn, $user_stats_sql);
if (!$stmt) {
    // Error preparing statement
    show_notification('Database error: ' . mysqli_error($conn), 'error');
    redirect('login.php');
}
mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
 $user_stats_result = mysqli_stmt_get_result($stmt);

// Get monthly statistics
// FIXED: Added 'Validator Approved' to the status check
 $monthly_sql = "SELECT 
              MONTH(cr.created_at) AS month,
              YEAR(cr.created_at) AS year,
              COUNT(*) AS count,
              SUM(cr.referral_amount) AS amount,
              SUM(cr.premium_with_gst) AS premium_with_gst,
              SUM(cr.without_gst) AS without_gst
              FROM cashback_requests cr 
              JOIN users u ON cr.user_id = u.id 
              WHERE u.manager_id = ? AND cr.status IN ('Manager Approved', 'Head Approved', 'Validator Approved', 'Finance Approved')
              GROUP BY MONTH(cr.created_at), YEAR(cr.created_at)
              ORDER BY year DESC, month DESC
              LIMIT 6";
 $stmt = mysqli_prepare($conn, $monthly_sql);
if (!$stmt) {
    // Error preparing statement
    show_notification('Database error: ' . mysqli_error($conn), 'error');
    redirect('login.php');
}
mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
 $monthly_result = mysqli_stmt_get_result($stmt);

// Get daily statistics for last 30 days
// FIXED: Added 'Validator Approved' to the status check
 $daily_sql = "SELECT 
            DAY(cr.created_at) AS day,
            MONTH(cr.created_at) AS month,
            YEAR(cr.created_at) AS year,
            COUNT(*) AS count,
            SUM(cr.referral_amount) AS amount,
            SUM(cr.premium_with_gst) AS premium_with_gst,
            SUM(cr.without_gst) AS without_gst
            FROM cashback_requests cr 
            JOIN users u ON cr.user_id = u.id 
            WHERE u.manager_id = ? AND cr.status IN ('Manager Approved', 'Head Approved', 'Validator Approved', 'Finance Approved') 
            AND cr.created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)
            GROUP BY DAY(cr.created_at), MONTH(cr.created_at), YEAR(cr.created_at)
            ORDER BY year DESC, month DESC, day DESC";
 $stmt = mysqli_prepare($conn, $daily_sql);
if (!$stmt) {
    // Error preparing statement
    show_notification('Database error: ' . mysqli_error($conn), 'error');
    redirect('login.php');
}
mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
 $daily_result = mysqli_stmt_get_result($stmt);

// Get profile/role-based statistics
// FIXED: Added 'Validator Approved' to the status check
 $profile_stats_sql = "SELECT 
                     u.department AS department,
                     COUNT(*) AS total_requests,
                     SUM(CASE WHEN cr.status = 'Pending' THEN 1 ELSE 0 END) AS pending_count,
                     SUM(CASE WHEN cr.status = 'Manager Approved' THEN 1 ELSE 0 END) AS manager_approved_count,
                     SUM(CASE WHEN cr.status = 'Head Approved' THEN 1 ELSE 0 END) AS head_approved_count,
                     SUM(CASE WHEN cr.status = 'Validator Approved' THEN 1 ELSE 0 END) AS validator_approved_count,
                     SUM(CASE WHEN cr.status = 'Finance Approved' THEN 1 ELSE 0 END) AS finance_approved_count,
                     SUM(CASE WHEN cr.status = 'Pending' THEN cr.referral_amount ELSE 0 END) AS pending_amount,
                     SUM(CASE WHEN cr.status = 'Manager Approved' THEN cr.referral_amount ELSE 0 END) AS manager_approved_amount,
                     SUM(CASE WHEN cr.status = 'Head Approved' THEN cr.referral_amount ELSE 0 END) AS head_approved_amount,
                     SUM(CASE WHEN cr.status = 'Validator Approved' THEN cr.referral_amount ELSE 0 END) AS validator_approved_amount,
                     SUM(CASE WHEN cr.status = 'Finance Approved' THEN cr.referral_amount ELSE 0 END) AS finance_approved_amount
                     FROM cashback_requests cr 
                     JOIN users u ON cr.user_id = u.id 
                     WHERE u.manager_id = ?
                     GROUP BY u.department
                     ORDER BY total_requests DESC";
 $stmt = mysqli_prepare($conn, $profile_stats_sql);
if (!$stmt) {
    // Error preparing statement
    show_notification('Database error: ' . mysqli_error($conn), 'error');
    redirect('login.php');
}
mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
 $profile_stats_result = mysqli_stmt_get_result($stmt);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Dashboard - CB Account</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="icon" href="https://www.coveryou.in/images/favicon.png" type="image/png">
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
        /* Sidebar Styles */
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
        /* Main Content Styles */
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
            background-color: #38a169;
            color: white;
        }
        .btn-success:hover {
            background-color: #2f855a;
        }
        .btn-danger {
            background-color: #e53e3e;
            color: white;
        }
        .btn-danger:hover {
            background-color: #c53030;
        }
        .btn-info {
            background-color: #3182ce;
            color: white;
        }
        .btn-info:hover {
            background-color: #2c5aa0;
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
        .status-manager-approved {
            background-color: #e6f7ff;
            color: #096dd9;
        }
        .status-head-approved {
            background-color: #f9f0ff;
            color: #722ed1;
        }
        .status-finance-approved {
            background-color: #f6ffed;
            color: #389e0d;
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
        .tabs {
            display: flex;
            border-bottom: 1px solid var(--gray);
            margin-bottom: 20px;
        }
        .tab {
            padding: 10px 20px;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            font-weight: 500;
        }
        .tab.active {
            border-bottom-color: var(--primary);
            color: var(--primary);
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
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            padding: 15px;
        }
        .modal-content {
            background-color: var(--light);
            border-radius: var(--radius);
            width: 100%;
            max-width: 500px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.15);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .modal-title {
            font-size: 18px;
            color: var(--dark);
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: var(--text);
        }
        .modal-body {
            margin-bottom: 15px;
        }
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: var(--dark);
            font-weight: 500;
        }
        .form-control {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            font-size: 14px;
        }
        textarea.form-control {
            min-height: 80px;
            resize: vertical;
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
        .team-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .team-card {
            background: var(--light);
            border-radius: var(--radius);
            padding: 15px;
            box-shadow: var(--shadow);
        }
        .team-card h3 {
            font-size: 16px;
            color: var(--dark);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .team-card h3 i {
            color: var(--primary);
        }
        .team-stats {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
        }
        .team-stat {
            text-align: center;
        }
        .team-stat-value {
            font-size: 18px;
            font-weight: bold;
            color: var(--primary);
        }
        .team-stat-label {
            font-size: 12px;
            color: var(--text-light);
        }
        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .analytics-card {
            background: var(--light);
            border-radius: var(--radius);
            padding: 15px;
            box-shadow: var(--shadow);
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
        .chart-container {
            height: 300px;
            margin-bottom: 30px;
        }
        .progress-bar {
            height: 8px;
            background-color: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 5px;
        }
        .progress-fill {
            height: 100%;
            background-color: var(--primary);
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
        /* Responsive Styles */
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
            
            .dashboard-container {
                padding: 15px;
            }
            
            .tabs {
                flex-direction: column;
            }
            
            .tab {
                border-bottom: 1px solid var(--gray);
                border-right: none;
            }
            
            .table-container {
                font-size: 12px;
            }
            
            th, td {
                padding: 6px 8px;
            }
            
            .team-grid {
                grid-template-columns: 1fr;
            }
            
            .analytics-grid {
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
                    <i class="fas fa-coins sidebar-logo-icon"></i>
                    <div class="sidebar-logo-text">CB Account</div>
                </div>
            </div>
            
            <div class="sidebar-user">
                <div class="sidebar-user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                <div class="sidebar-user-role"><?php echo htmlspecialchars($_SESSION['role']); ?></div>
            </div>
            
            <nav class="sidebar-menu">
                <a href="dashboard_manager.php" class="sidebar-menu-item active">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="http://itsupport.coveryou.in/cb_new_uat/sales/manager.php" class="sidebar-menu-item">
                    <i class="fas fa-briefcase"></i> Business
                </a>
             <!--   <a href="pending_requests.php" class="sidebar-menu-item">
                    <i class="fas fa-clock"></i> Pending Requests
                </a>
                <a href="approved_requests.php" class="sidebar-menu-item">
                    <i class="fas fa-check-circle"></i> Approved Requests
                </a>
                <a href="team_analytics.php" class="sidebar-menu-item">
                    <i class="fas fa-chart-line"></i> Team Analytics
                </a>
                <a href="profile.php" class="sidebar-menu-item">
                    <i class="fas fa-user-circle"></i> My Profile
                </a>
                <a href="settings.php" class="sidebar-menu-item">
                    <i class="fas fa-cog"></i> Settings
                </a>
                <a href="help.php" class="sidebar-menu-item">
                    <i class="fas fa-question-circle"></i> Help
                </a> -->
                <a href="logout.php" class="sidebar-menu-item">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </nav>
            
            <div class="sidebar-footer">
                &copy; <?php echo date('Y'); ?> CB Account System
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
                        <i class="fas fa-user-tie logo-icon"></i>
                        <div class="logo-text">Manager <span>Dashboard</span></div>
                    </div>
                    <p class="tagline">Team management and approval workflow</p>
                    
                    <div class="user-info">
                        <div class="user-details">
                            <div class="username"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                            <div class="user-role"><?php echo htmlspecialchars($_SESSION['role']); ?></div>
                        </div>
                        <a href="logout.php" class="logout-btn">
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
                
                <div class="dashboard-container">
                    <h2 class="section-title">Team Overview</h2>
                    
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-value"><?php echo mysqli_num_rows($users_result); ?></div>
                            <div class="stat-label">Team Members</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $stats['pending_count']; ?></div>
                            <div class="stat-label">Pending Requests</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $stats['approved_count']; ?></div>
                            <div class="stat-label">Approved Requests</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value">₹<?php echo number_format($stats['pending_amount'], 2); ?></div>
                            <div class="stat-label">Pending Amount</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value">₹<?php echo number_format($stats['approved_amount'], 2); ?></div>
                            <div class="stat-label">Approved Amount</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value">₹<?php echo number_format($stats['total_premium_with_gst'], 2); ?></div>
                            <div class="stat-label">Total Premium (with GST)</div>
                        </div>
                    </div>
                    
              <!--      <div class="chart-container">
                        <canvas id="monthlyChart"></canvas>
                    </div> -->
                    
                    <div class="team-grid">
                        <?php 
                        // Reset result pointer to reuse it
                        mysqli_data_seek($users_result, 0);
                        
                        while ($user = mysqli_fetch_assoc($users_result)): 
                            // Get pending count for this user
                            $pending_count_sql = "SELECT COUNT(*) AS count 
                                                FROM cashback_requests 
                                                WHERE user_id = ? AND status = 'Pending'";
                            $stmt = mysqli_prepare($conn, $pending_count_sql);
                            if (!$stmt) {
                                // Error preparing statement
                                echo "<div class='alert alert-error'>Database error: " . mysqli_error($conn) . "</div>";
                                continue;
                            }
                            mysqli_stmt_bind_param($stmt, "i", $user['id']);
                            mysqli_stmt_execute($stmt);
                            $pending_count_result = mysqli_stmt_get_result($stmt);
                            $pending_count = mysqli_fetch_assoc($pending_count_result)['count'];
                            
                            // Get approved count for this user (including all approval levels)
                            $approved_count_sql = "SELECT COUNT(*) AS count 
                                                FROM cashback_requests 
                                                WHERE user_id = ? AND status IN ('Manager Approved', 'Head Approved', 'Finance Approved')";
                            $stmt = mysqli_prepare($conn, $approved_count_sql);
                            if (!$stmt) {
                                // Error preparing statement
                                echo "<div class='alert alert-error'>Database error: " . mysqli_error($conn) . "</div>";
                                continue;
                            }
                            mysqli_stmt_bind_param($stmt, "i", $user['id']);
                            mysqli_stmt_execute($stmt);
                            $approved_count_result = mysqli_stmt_get_result($stmt);
                            $approved_count = mysqli_fetch_assoc($approved_count_result)['count'];
                            
                            // Get total amount for this user (including all approval levels)
                            $amount_sql = "SELECT SUM(referral_amount) AS total 
                                           FROM cashback_requests 
                                           WHERE user_id = ? AND status IN ('Manager Approved', 'Head Approved', 'Finance Approved')";
                            $stmt = mysqli_prepare($conn, $amount_sql);
                            if (!$stmt) {
                                // Error preparing statement
                                echo "<div class='alert alert-error'>Database error: " . mysqli_error($conn) . "</div>";
                                continue;
                            }
                            mysqli_stmt_bind_param($stmt, "i", $user['id']);
                            mysqli_stmt_execute($stmt);
                            $amount_result = mysqli_stmt_get_result($stmt);
                            $total_amount = mysqli_fetch_assoc($amount_result)['total'] ?? 0;
                        ?>
                            <div class="team-card">
                                <h3><i class="fas fa-user"></i> <?php echo htmlspecialchars($user['full_name']); ?></h3>
                                <p style="color: var(--text-light); margin-bottom: 10px;"><?php echo htmlspecialchars($user['department']); ?> | EMP ID: <?php echo htmlspecialchars($user['emp_id']); ?></p>
                                <div class="team-stats">
                                    <div class="team-stat">
                                        <div class="team-stat-value"><?php echo $pending_count; ?></div>
                                        <div class="team-stat-label">Pending</div>
                                    </div>
                                    <div class="team-stat">
                                        <div class="team-stat-value"><?php echo $approved_count; ?></div>
                                        <div class="team-stat-label">Approved</div>
                                    </div>
                                    <div class="team-stat">
                                        <div class="team-stat-value">₹<?php echo number_format($total_amount, 2); ?></div>
                                        <div class="team-stat-label">Amount</div>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                    
                    <div class="analytics-grid">
                        <div class="analytics-card">
                            <h3>User-wise Analytics</h3>
                            <div class="table-container">
                                <table class="analytics-table">
                                    <thead>
                                        <tr>
                                            <th>User</th>
                                            <th>Department</th>
                                            <th>Requests</th>
                                            <th>Premium (with GST)</th>
                                            <th>Cashback Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($user_stat = mysqli_fetch_assoc($user_stats_result)): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($user_stat['user_name']); ?></td>
                                                <td><?php echo htmlspecialchars($user_stat['user_department']); ?></td>
                                                <td><?php echo $user_stat['count']; ?></td>
                                                <td>₹<?php echo number_format($user_stat['premium_with_gst'], 2); ?></td>
                                                <td>₹<?php echo number_format($user_stat['amount'], 2); ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <div class="analytics-card">
                            <h3>Profile/Role-based Statistics</h3>
                            <div class="table-container">
                                <table class="analytics-table">
                                    <thead>
                                        <tr>
                                            <th>Department</th>
                                            <th>Total Requests</th>
                                            <th>Pending</th>
                                            <th>Manager Approved</th>
                                            <th>Head Approved</th>
                                            <th>Finance Approved</th>
                                            <th>Pending Amount</th>
                                            <th>Approved Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($profile_stat = mysqli_fetch_assoc($profile_stats_result)): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($profile_stat['department']); ?></td>
                                                <td><?php echo $profile_stat['total_requests']; ?></td>
                                                <td><?php echo $profile_stat['pending_count']; ?></td>
                                                <td><?php echo $profile_stat['manager_approved_count']; ?></td>
                                                <td><?php echo $profile_stat['head_approved_count']; ?></td>
                                                <td><?php echo $profile_stat['finance_approved_count']; ?></td>
                                                <td>₹<?php echo number_format($profile_stat['pending_amount'], 2); ?></td>
                                                <td>₹<?php echo number_format($profile_stat['manager_approved_amount'] + $profile_stat['head_approved_amount'] + $profile_stat['finance_approved_amount'], 2); ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <div class="analytics-grid">
                        <div class="analytics-card">
                            <h3>Monthly Performance</h3>
                            <div class="table-container">
                                <table class="analytics-table">
                                    <thead>
                                        <tr>
                                            <th>Month</th>
                                            <th>Requests</th>
                                            <th>Premium (with GST)</th>
                                            <th>Premium (without GST)</th>
                                            <th>CB Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($month = mysqli_fetch_assoc($monthly_result)): ?>
                                            <tr>
                                                <td><?php echo date('M Y', strtotime($month['year'] . '-' . $month['month'] . '-01')); ?></td>
                                                <td><?php echo $month['count']; ?></td>
                                                <td>₹<?php echo number_format($month['premium_with_gst'], 2); ?></td>
                                                <td>₹<?php echo number_format($month['without_gst'], 2); ?></td>
                                                <td>₹<?php echo number_format($month['amount'], 2); ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <div class="tabs">
                        <div class="tab active" onclick="openTab(event, 'pending-tab')">Pending Requests</div>
                        <div class="tab" onclick="openTab(event, 'approved-tab')">Approved Requests</div>
                        <div class="tab" onclick="openTab(event, 'daily-tab')">Daily Analytics</div>
                    </div>
                    
                    <div id="pending-tab" class="tab-content active">
                        <h3>Pending Requests</h3>
                        
                        <?php if (mysqli_num_rows($pending_result) > 0): ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Reference #</th>
                                            <th>User</th>
                                            <th>Customer</th>
                                            <th>Premium (with GST)</th>
                                            <th>Premium (without GST)</th>
                                            <th>Amount</th>
                                            <th>Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($request = mysqli_fetch_assoc($pending_result)): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($request['reference_number']); ?></td>
                                                <td><?php echo htmlspecialchars($request['user_name']); ?></td>
                                                <td><?php echo htmlspecialchars($request['customer_name']); ?></td>
                                                <td>₹<?php echo number_format($request['premium_with_gst'], 2); ?></td>
                                                <td>₹<?php echo number_format($request['without_gst'], 2); ?></td>
                                                <td>₹<?php echo number_format($request['referral_amount'], 2); ?></td>
                                                <td><?php echo date('d M Y', strtotime($request['created_at'])); ?></td>
                                                <td>
                                                    <button class="btn btn-info" onclick="viewRequest(<?php echo $request['id']; ?>)">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                    <button class="btn btn-success" onclick="approveRequest(<?php echo $request['id']; ?>)">
                                                        <i class="fas fa-check"></i> Approve
                                                    </button>
                                                    <button class="btn btn-danger" onclick="rejectRequest(<?php echo $request['id']; ?>)">
                                                        <i class="fas fa-times"></i> Reject
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="no-data">
                                <i class="fas fa-file-invoice"></i>
                                <p>No pending requests</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div id="approved-tab" class="tab-content">
                        <h3>Approved Requests</h3>
                        
                        <?php if (mysqli_num_rows($approved_result) > 0): ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Reference #</th>
                                            <th>User</th>
                                            <th>Customer</th>
                                            <th>Premium (with GST)</th>
                                            <th>Premium (without GST)</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th>Last Updated</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($request = mysqli_fetch_assoc($approved_result)): ?>
                                            <?php
                                            $status_class = '';
                                            switch ($request['status']) {
                                                case 'Manager Approved':
                                                    $status_class = 'status-manager-approved';
                                                    break;
                                                case 'Head Approved':
                                                    $status_class = 'status-head-approved';
                                                    break;
                                                case 'Finance Approved':
                                                    $status_class = 'status-finance-approved';
                                                    break;
                                            }
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($request['reference_number']); ?></td>
                                                <td><?php echo htmlspecialchars($request['user_name']); ?></td>
                                                <td><?php echo htmlspecialchars($request['customer_name']); ?></td>
                                                <td>₹<?php echo number_format($request['premium_with_gst'], 2); ?></td>
                                                <td>₹<?php echo number_format($request['without_gst'], 2); ?></td>
                                                <td>₹<?php echo number_format($request['referral_amount'], 2); ?></td>
                                                <td>
                                                    <span class="status-badge <?php echo $status_class; ?>">
                                                        <?php echo htmlspecialchars($request['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('d M Y', strtotime($request['updated_at'])); ?></td>
                                                <td>
                                                    <button class="btn btn-info" onclick="viewRequest(<?php echo $request['id']; ?>)">
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
                                <i class="fas fa-file-invoice"></i>
                                <p>No approved requests</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div id="daily-tab" class="tab-content">
                        <h3>Daily Analytics (Last 30 Days)</h3>
                        
                        <?php if (mysqli_num_rows($daily_result) > 0): ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Requests</th>
                                            <th>Premium (with GST)</th>
                                            <th>Premium (without GST)</th>
                                            <th>CB Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($day = mysqli_fetch_assoc($daily_result)): ?>
                                            <tr>
                                                <td><?php echo date('d M Y', strtotime($day['year'] . '-' . $day['month'] . '-' . $day['day'])); ?></td>
                                                <td><?php echo $day['count']; ?></td>
                                                <td>₹<?php echo number_format($day['premium_with_gst'], 2); ?></td>
                                                <td>₹<?php echo number_format($day['without_gst'], 2); ?></td>
                                                <td>₹<?php echo number_format($day['amount'], 2); ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="no-data">
                                <i class="fas fa-calendar-day"></i>
                                <p>No daily data available</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Approve Request Modal -->
    <div id="approveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Approve Request</h3>
                <button class="modal-close" onclick="document.getElementById('approveModal').style.display='none'">&times;</button>
            </div>
            <form id="approveForm" method="post">
                <div class="modal-body">
                    <p>Are you sure you want to approve this request?</p>
                    <div class="form-group">
                        <label for="approveComments">Comments <span style="color:red;">*</span></label>
                      <!--  <textarea id="approveComments" name="comments" class="form-control"></textarea> -->
                        <textarea id="approveComments" name="comments" class="form-control" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="document.getElementById('approveModal').style.display='none'">Cancel</button>
                    <button type="submit" class="btn btn-success">Approve</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Reject Request Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Reject Request</h3>
                <button class="modal-close" onclick="document.getElementById('rejectModal').style.display='none'">&times;</button>
            </div>
            <form id="rejectForm" method="post">
                <div class="modal-body">
                    <p>Are you sure you want to reject this request?</p>
                    <div class="form-group">
                        <label for="rejectComments">Reason for Rejection</label>
                        <textarea id="rejectComments" name="comments" class="form-control" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="document.getElementById('rejectModal').style.display='none'">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
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
        }
        
        let currentRequestId = null;
        
        function approveRequest(requestId) {
            currentRequestId = requestId;
            document.getElementById('approveModal').style.display = 'flex';
            document.getElementById('approveForm').action = 'approve_request.php?id=' + requestId;
        }
        
        function rejectRequest(requestId) {
            currentRequestId = requestId;
            document.getElementById('rejectModal').style.display = 'flex';
            document.getElementById('rejectForm').action = 'reject_request.php?id=' + requestId;
        }
        
        function viewRequest(requestId) {
            window.location.href = 'view_request.php?id=' + requestId;
        }
        
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
        
        // Render monthly chart
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('monthlyChart').getContext('2d');
            
            const monthlyData = <?php 
                $data = [];
                // Reset result pointer to reuse it
                mysqli_data_seek($monthly_result, 0);
                while ($row = mysqli_fetch_assoc($monthly_result)) {
                    $data[] = $row;
                }
                echo json_encode($data);
            ?>;
            
            const labels = monthlyData.map(item => {
                const date = new Date(item.year, item.month - 1);
                return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
            });
            
            const counts = monthlyData.map(item => item.count);
            const amounts = monthlyData.map(item => item.amount);
            const premiums = monthlyData.map(item => item.premium_with_gst);
            
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Number of Requests',
                        data: counts,
                        backgroundColor: 'rgba(240, 93, 73, 0.6)',
                        borderColor: 'rgba(240, 93, 73, 1)',
                        borderWidth: 1,
                        yAxisID: 'y-counts'
                    }, {
                        label: 'Total Amount (₹)',
                        data: amounts,
                        type: 'line',
                        backgroundColor: 'rgba(75, 192, 192, 0.2)',
                        borderColor: 'rgba(75, 192, 192, 1)',
                        borderWidth: 2,
                        fill: true,
                        yAxisID: 'y-amounts'
                    }, {
                        label: 'Premium with GST (₹)',
                        data: premiums,
                        type: 'line',
                        backgroundColor: 'rgba(153, 102, 255, 0.2)',
                        borderColor: 'rgba(153, 102, 255, 1)',
                        borderWidth: 2,
                        fill: true,
                        yAxisID: 'y-premiums'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        'y-counts': {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Number of Requests'
                            },
                            ticks: {
                                precision: 0
                            }
                        },
                        'y-amounts': {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Total Amount (₹)'
                            },
                            grid: {
                                drawOnChartArea: false
                            }
                        },
                        'y-premiums': {
                            type: 'linear',
                            display: false,
                            beginAtZero: true
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>