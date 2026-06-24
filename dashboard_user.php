<?php
require_once 'config.php';

// Check if user is logged in and has user role
if (!is_logged_in() || !has_role('User')) {
    show_notification('You do not have permission to access this page', 'error');
    redirect('login.php');
}

// Initialize filter variables
 $reference_filter = isset($_GET['reference_number']) ? $_GET['reference_number'] : '';
 $customer_filter = isset($_GET['customer_name']) ? $_GET['customer_name'] : '';
 $start_date_filter = isset($_GET['start_date']) ? $_GET['start_date'] : '';
 $end_date_filter = isset($_GET['end_date']) ? $_GET['end_date'] : '';

// Build the base SQL query
 $requests_sql = "SELECT * FROM cashback_requests WHERE user_id = ?";
 $params = [];
 $types = "i";
 $params[] = $_SESSION['user_id'];

// Dynamically add filter conditions to the SQL query
if (!empty($reference_filter)) {
    $requests_sql .= " AND reference_number LIKE ?";
    $types .= "s";
    $params[] = "%$reference_filter%";
}

if (!empty($customer_filter)) {
    $requests_sql .= " AND customer_name LIKE ?";
    $types .= "s";
    $params[] = "%$customer_filter%";
}

if (!empty($start_date_filter)) {
    $requests_sql .= " AND created_at >= ?";
    $types .= "s";
    $params[] = $start_date_filter;
}

if (!empty($end_date_filter)) {
    // Add one day to the end_date to include the entire day
    $end_date_plus_one = date('Y-m-d', strtotime($end_date_filter . ' +1 day'));
    $requests_sql .= " AND created_at < ?";
    $types .= "s";
    $params[] = $end_date_plus_one;
}

 $requests_sql .= " ORDER BY created_at DESC";

// Prepare and execute the statement
 $stmt = mysqli_prepare($conn, $requests_sql);

// Create references for bind_param
 $bind_params = [];
 $bind_params[] = $types;
foreach ($params as $key => $value) {
    $bind_params[] = &$params[$key];
}

// Use call_user_func_array to bind parameters dynamically
call_user_func_array('mysqli_stmt_bind_param', array_merge([$stmt], $bind_params));

mysqli_stmt_execute($stmt);
 $requests_result = mysqli_stmt_get_result($stmt);

// Group requests by date
 $requests_by_date = [];
while ($request = mysqli_fetch_assoc($requests_result)) {
    $date = date('d M Y', strtotime($request['created_at']));
    if (!isset($requests_by_date[$date])) {
        $requests_by_date[$date] = [];
    }
    $requests_by_date[$date][] = $request;
}

// Get statistics - अब Validator Approved और Validator Rejected स्टेटस भी शामिल करें
 $stats_sql = "SELECT 
            COUNT(*) AS total_requests,
            SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN status = 'Manager Approved' THEN 1 ELSE 0 END) AS manager_approved_count,
            SUM(CASE WHEN status = 'Head Approved' THEN 1 ELSE 0 END) AS head_approved_count,
            SUM(CASE WHEN status = 'Validator Approved' THEN 1 ELSE 0 END) AS validator_approved_count,
            SUM(CASE WHEN status = 'Validator Rejected' THEN 1 ELSE 0 END) AS validator_rejected_count,
            SUM(CASE WHEN status = 'Finance Approved' THEN 1 ELSE 0 END) AS finance_approved_count,
            SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) AS rejected_count,
            SUM(referral_amount) AS total_referral_amount,
            SUM(premium_with_gst) AS total_premium_with_gst,
            SUM(CASE WHEN form_type = 'CB' THEN 1 ELSE 0 END) AS cb_count,
            SUM(CASE WHEN form_type = 'Shortfall' THEN 1 ELSE 0 END) AS shortfall_count
            FROM cashback_requests 
            WHERE user_id = ?";
 $stmt = mysqli_prepare($conn, $stats_sql);
mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
 $stats_result = mysqli_stmt_get_result($stmt);
 $stats = mysqli_fetch_assoc($stats_result);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Dashboard - CB Account</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        .account-btn {
            padding: 8px 15px;
            background-color: var(--primary);
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
        .account-btn:hover {
            background-color: var(--primary-dark);
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
        .status-validator-approved {
            background-color: #e6fffb;
            color: #13c2c2;
        }
        .status-validator-rejected {
            background-color: #fff1f0;
            color: #f5222d;
        }
        .status-finance-approved {
            background-color: #f6ffed;
            color: #389e0d;
        }
        .status-rejected {
            background-color: #fff2f0;
            color: #cf1322;
        }
        .form-type-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }
        .form-type-cb {
            background-color: #e6f7ff;
            color: #096dd9;
        }
        .form-type-shortfall {
            background-color: #fff7e6;
            color: #d46b08;
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
        .form-link {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background-color: var(--primary);
            color: white;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            cursor: pointer;
            z-index: 100;
            transition: all 0.3s ease;
        }
        .form-link:hover {
            background-color: var(--primary-dark);
            transform: scale(1.1);
        }
        .form-link i {
            font-size: 24px;
        }
        .attachment-preview {
            max-width: 50px;
            max-height: 50px;
            border-radius: var(--radius);
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .attachment-preview:hover {
            transform: scale(1.1);
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
        /* Datewise Request Styles */
        .date-container {
            margin-bottom: 20px;
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            overflow: hidden;
        }
        .date-header {
            background-color: var(--dark);
            color: var(--light);
            padding: 2px 15px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .date-header:hover {
            background-color: #4a5568;
        }
        .date-header i {
            transition: transform 0.3s ease;
        }
        .date-header.collapsed i {
            transform: rotate(180deg);
        }
        .date-content {
            max-height: 2000px;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }
        .date-content.collapsed {
            max-height: 0;
        }
        .date-table-container {
            padding: 0;
        }
        .date-summary {
            font-size: 12px;
            color: var(--text-light);
        }
        /* Filter Section Styles */
        .filter-section {
            background-color: #f8fafc;
            border-radius: var(--radius);
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
        }
        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
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
        .filter-group input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            font-size: 14px;
        }
        .filter-actions {
            display: flex;
            gap: 10px;
        }
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1050;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            overflow: auto;
        }
        .modal-content {
            background-color: var(--light);
            margin: 10% auto;
            padding: 20px;
            border-radius: var(--radius);
            width: 90%;
            max-width: 500px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            animation: modalopen 0.3s;
        }
        @keyframes modalopen {
            from {opacity: 0; transform: translateY(-20px);}
            to {opacity: 1; transform: translateY(0);}
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--gray);
        }
        .modal-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
        }
        .close {
            color: var(--text-light);
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            transition: color 0.2s;
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
        .form-group input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            font-size: 14px;
        }
        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(240, 93, 73, 0.2);
        }
        .form-error {
            color: #e53e3e;
            font-size: 12px;
            margin-top: 5px;
        }
        .form-success {
            color: #389e0d;
            font-size: 12px;
            margin-top: 5px;
        }
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }
        .btn-secondary {
            background-color: #e2e8f0;
            color: var(--dark);
        }
        .btn-secondary:hover {
            background-color: #cbd5e0;
        }
        /* Loading Spinner */
        .spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
            margin-left: 10px;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        /* Notification Styles */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: var(--radius);
            color: white;
            font-weight: 500;
            z-index: 2000;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            display: none;
            animation: slideIn 0.3s ease-out;
        }
        .notification-success {
            background-color: #389e0d;
        }
        .notification-error {
            background-color: #e53e3e;
        }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
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
            
            .table-container {
                font-size: 12px;
            }
            
            th, td {
                padding: 6px 8px;
            }
            .filter-row {
                flex-direction: column;
            }
            .filter-group {
                width: 100%;
            }
            .modal-content {
                margin: 20% auto;
                width: 95%;
            }
            .user-info {
                flex-direction: column;
                align-items: flex-end;
                gap: 10px;
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
                <a href="dashboard_user.php" class="sidebar-menu-item active">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="index.php" class="sidebar-menu-item">
                    <i class="fas fa-plus-circle"></i> New Request
                </a>
                <?php if (isset($_SESSION['department']) && $_SESSION['department'] === 'Motor_Fresh'): ?>
                <a href="http://itsupport.coveryou.in/cb_new_uat/sales/index.php" class="sidebar-menu-item">
                    <i class="fas fa-briefcase"></i> Business
                </a>
                <?php endif; ?>
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
                        <i class="fas fa-user logo-icon"></i>
                        <div class="logo-text">User <span>Dashboard</span></div>
                    </div>
                    <p class="tagline">Track your CB Account</p>
                    
                    <div class="user-info">
                        <div class="user-details">
                            <div class="username"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                            <div class="user-role"><?php echo htmlspecialchars($_SESSION['role']); ?> - <?php echo htmlspecialchars($_SESSION['department']); ?></div>
                        </div>
                        <button class="account-btn" id="accountBtn">
                            <i class="fas fa-user-circle"></i> Account
                        </button>
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
                    <h2 class="section-title">My CB Account Requests</h2>
                    
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $stats['total_requests']; ?></div>
                            <div class="stat-label">Total Requests</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $stats['pending_count']; ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $stats['cb_count']; ?></div>
                            <div class="stat-label">CB Requests</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $stats['shortfall_count']; ?></div>
                            <div class="stat-label">Shortfall Requests</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $stats['finance_approved_count']; ?></div>
                            <div class="stat-label">Finance Approved</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value">₹<?php echo number_format($stats['total_referral_amount'], 2); ?></div>
                            <div class="stat-label">Total Referral Amount</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value">₹<?php echo number_format($stats['total_premium_with_gst'], 2); ?></div>
                            <div class="stat-label">Total Premium With GST</div>
                        </div>
                    </div>
                    
                    <!-- Filter Section -->
                    <div class="filter-section">
                        <form id="filterForm" method="GET" action="dashboard_user.php">
                            <div class="filter-row">
                                <div class="filter-group">
                                    <label for="reference_number">Reference #</label>
                                    <input type="text" id="reference_number" name="reference_number" value="<?php echo htmlspecialchars($reference_filter); ?>" placeholder="Enter reference number">
                                </div>
                                <div class="filter-group">
                                    <label for="customer_name">Customer</label>
                                    <input type="text" id="customer_name" name="customer_name" value="<?php echo htmlspecialchars($customer_filter); ?>" placeholder="Enter customer name">
                                </div>
                                <div class="filter-group">
                                    <label for="start_date">Start Date</label>
                                    <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date_filter); ?>">
                                </div>
                                <div class="filter-group">
                                    <label for="end_date">End Date</label>
                                    <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date_filter); ?>">
                                </div>
                                <div class="filter-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-filter"></i> Filter
                                    </button>
                                    <button type="button" class="btn btn-primary" onclick="resetFilters()">
                                        <i class="fas fa-redo"></i> Reset
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                    
                    <?php if (!empty($requests_by_date)): ?>
                        <div class="datewise-requests">
                            <?php foreach ($requests_by_date as $date => $date_requests): ?>
                                <div class="date-container">
                                    <div class="date-header" onclick="toggleDateSection(this)">
                                        <span><?php echo $date; ?></span>
                                        <span class="date-summary">
                                            <?php echo count($date_requests); ?> requests | 
                                            ₹<?php echo number_format(array_sum(array_column($date_requests, 'referral_amount')), 2); ?> total
                                        </span>
                                        <i class="fas fa-chevron-up"></i>
                                    </div>
                                    <div class="date-content">
                                        <div class="date-table-container">
                                            <table>
                                                <thead>
                                                    <tr>
                                                        <th>Reference #</th>
                                                        <th>Type</th>
                                                        <th>Customer</th>
                                                        <th>Month</th>
                                                        <th>Premium With GST</th>
                                                        <th>Referral Amount</th>
                                                        <th>Status</th>
                                                        <th>Date</th>
                                                        <th>Attachment</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($date_requests as $request): ?>
                                                        <?php
                                                        $status_class = '';
                                                        switch ($request['status']) {
                                                            case 'Pending':
                                                                $status_class = 'status-pending';
                                                                break;
                                                            case 'Manager Approved':
                                                                $status_class = 'status-manager-approved';
                                                                break;
                                                            case 'Head Approved':
                                                                $status_class = 'status-head-approved';
                                                                break;
                                                            case 'Validator Approved':
                                                                $status_class = 'status-validator-approved';
                                                                break;
                                                            case 'Validator Rejected':
                                                                $status_class = 'status-validator-rejected';
                                                                break;
                                                            case 'Finance Approved':
                                                                $status_class = 'status-finance-approved';
                                                                break;
                                                            case 'Rejected':
                                                                $status_class = 'status-rejected';
                                                                break;
                                                        }
                                                        
                                                        $form_type_class = '';
                                                        switch ($request['form_type']) {
                                                            case 'CB':
                                                                $form_type_class = 'form-type-cb';
                                                                break;
                                                            case 'Shortfall':
                                                                $form_type_class = 'form-type-shortfall';
                                                                break;
                                                        }
                                                        ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($request['reference_number']); ?></td>
                                                            <td>
                                                                <span class="form-type-badge <?php echo $form_type_class; ?>">
                                                                    <?php echo htmlspecialchars($request['form_type']); ?>
                                                                </span>
                                                            </td>
                                                            <td><?php echo htmlspecialchars($request['customer_name']); ?></td>
                                                            <td><?php echo htmlspecialchars($request['month'] . ' ' . $request['year']); ?></td>
                                                            <td>₹<?php echo number_format($request['premium_with_gst'], 2); ?></td>
                                                            <td>₹<?php echo number_format($request['referral_amount'], 2); ?></td>
                                                            <td>
                                                                <span class="status-badge <?php echo $status_class; ?>">
                                                                    <?php echo htmlspecialchars($request['status']); ?>
                                                                </span>
                                                            </td>
                                                            <td><?php echo date('d M Y', strtotime($request['created_at'])); ?></td>
                                                            <td>
                                                                <?php if (!empty($request['attachment_url'])): ?>
                                                                    <img src="<?php echo htmlspecialchars($request['attachment_url']); ?>" alt="Attachment" class="attachment-preview" onclick="viewAttachment('<?php echo htmlspecialchars($request['attachment_url']); ?>')">
                                                                <?php else: ?>
                                                                    <span style="color: var(--text-light);">N/A</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <button class="btn btn-primary" onclick="viewRequest(<?php echo $request['id']; ?>)">
                                                                    <i class="fas fa-eye"></i> View
                                                                </button>
                                                                <?php if ($request['status'] === 'Validator Rejected'): ?>
                                                                    <button class="btn btn-primary" onclick="provideJustification(<?php echo $request['id']; ?>)">
                                                                        <i class="fas fa-comment"></i> Justify
                                                                    </button>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-file-invoice"></i>
                            <p>No CB requests found</p>
                            <p style="margin-top: 10px;">Click button below to submit a new request.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Form Link Button -->
            <a href="index.php" class="form-link" title="Submit New Request">
                <i class="fas fa-plus"></i>
            </a>
        </main>
    </div>
    
    <!-- Change Password Modal -->
    <div id="changePasswordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Change Password</h3>
                <span class="close" id="closeModal">&times;</span>
            </div>
            <form id="changePasswordForm">
                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password" required>
                    <div id="current_password_error" class="form-error"></div>
                </div>
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" required>
                    <div id="new_password_error" class="form-error"></div>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                    <div id="confirm_password_error" class="form-error"></div>
                </div>
                <div id="form_message" class="form-error"></div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" id="cancelBtn">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        Change Password
                        <span class="spinner" id="passwordSpinner"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Notification -->
    <div id="notification" class="notification"></div>
    
    <script>
        function viewRequest(requestId) {
            window.location.href = 'view_request.php?id=' + requestId;
        }
        
        function provideJustification(requestId) {
            window.location.href = 'provide_justification.php?id=' + requestId;
        }
        
        function viewAttachment(url) {
            window.open(url, '_blank');
        }
        
        function toggleDateSection(element) {
            element.classList.toggle('collapsed');
            element.nextElementSibling.classList.toggle('collapsed');
        }
        
        function resetFilters() {
            window.location.href = 'dashboard_user.php';
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
        
        // Change Password Modal
        const modal = document.getElementById('changePasswordModal');
        const accountBtn = document.getElementById('accountBtn');
        const closeModal = document.getElementById('closeModal');
        const cancelBtn = document.getElementById('cancelBtn');
        const changePasswordForm = document.getElementById('changePasswordForm');
        const formMessage = document.getElementById('form_message');
        const passwordSpinner = document.getElementById('passwordSpinner');
        const notification = document.getElementById('notification');
        
        // Open modal when account button is clicked
        accountBtn.addEventListener('click', function(e) {
            e.preventDefault();
            modal.style.display = 'block';
            // Reset form
            changePasswordForm.reset();
            clearErrors();
        });
        
        // Close modal when close button or cancel button is clicked
        closeModal.addEventListener('click', function() {
            modal.style.display = 'none';
        });
        
        cancelBtn.addEventListener('click', function() {
            modal.style.display = 'none';
        });
        
        // Close modal when clicking outside of it
        window.addEventListener('click', function(event) {
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        });
        
// Handle form submission
changePasswordForm.addEventListener('submit', function(e) {
    e.preventDefault();
    
    // Clear previous errors
    clearErrors();
    
    // Get form values
    const currentPassword = document.getElementById('current_password').value;
    const newPassword = document.getElementById('new_password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    
    // Validate form
    let isValid = true;
    
    if (!currentPassword) {
        showError('current_password_error', 'Current password is required');
        isValid = false;
    }
    
    if (!newPassword) {
        showError('new_password_error', 'New password is required');
        isValid = false;
    } else if (newPassword.length < 6) {
        showError('new_password_error', 'Password must be at least 6 characters long');
        isValid = false;
    }
    
    if (!confirmPassword) {
        showError('confirm_password_error', 'Please confirm your new password');
        isValid = false;
    } else if (newPassword !== confirmPassword) {
        showError('confirm_password_error', 'Passwords do not match');
        isValid = false;
    }
    
    if (!isValid) return;
    
    // Show loading spinner
    passwordSpinner.style.display = 'inline-block';
    document.getElementById('submitBtn').disabled = true;
    
    // Create form data
    const formData = new FormData();
    formData.append('current_password', currentPassword);
    formData.append('new_password', newPassword);
    formData.append('confirm_password', confirmPassword);
    
    // Send AJAX request
    fetch('change_password.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        // Get the response as text first
        return response.text().then(text => {
            // Log the raw response to the console for debugging
            console.log("Raw server response:", text);
            
            // Try to parse as JSON
            try {
                const data = JSON.parse(text);
                // Check if the response was successful (status 200-299)
                if (!response.ok) {
                    // If server sent a JSON error with a non-200 status, throw it
                    throw new Error(data.message || 'Server returned an error');
                }
                return data;
            } catch (e) {
                // If parsing fails, it's not valid JSON
                console.error("Failed to parse JSON. Raw text:", text);
                throw new Error('Server response is not valid JSON. Check console for details.');
            }
        });
    })
    .then(data => {
        // Hide loading spinner
        passwordSpinner.style.display = 'none';
        document.getElementById('submitBtn').disabled = false;
        
        if (data.success) {
            // Show success notification
            showNotification(data.message, 'success');
            
            // Reset form and close modal
            changePasswordForm.reset();
            modal.style.display = 'none';
        } else {
            // Show error message from server
            formMessage.textContent = data.message;
            formMessage.className = 'form-error';
        }
    })
    .catch(error => {
        // Hide loading spinner
        passwordSpinner.style.display = 'none';
        document.getElementById('submitBtn').disabled = false;
        
        // Show a more user-friendly error
        formMessage.textContent = error.message || 'An error occurred. Please try again.';
        formMessage.className = 'form-error';
        console.error('Fetch Error:', error);
    });
});
        
        function showError(elementId, message) {
            document.getElementById(elementId).textContent = message;
        }
        
        function clearErrors() {
            document.getElementById('current_password_error').textContent = '';
            document.getElementById('new_password_error').textContent = '';
            document.getElementById('confirm_password_error').textContent = '';
            formMessage.textContent = '';
            formMessage.className = 'form-error';
        }
        
        function showNotification(message, type) {
            notification.textContent = message;
            notification.className = 'notification notification-' + type;
            notification.style.display = 'block';
            
            // Hide notification after 5 seconds
            setTimeout(function() {
                notification.style.display = 'none';
            }, 5000);
        }
    </script>
</body>
</html>