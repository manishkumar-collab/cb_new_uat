<?php
require_once 'config.php';

// Check if user is logged in
if (!is_logged_in()) {
    show_notification('You must be logged in to view this page', 'error');
    redirect('login.php');
}

// Check if request ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    show_notification('Invalid request', 'error');
    redirect('dashboard_' . strtolower($_SESSION['role']) . '.php');
}

 $request_id = $_GET['id'];

// Get request details
 $request_sql = "SELECT cr.*, u.full_name AS user_name, u.emp_id AS user_emp_id, u.department AS user_department,
               m.full_name AS manager_name, m.emp_id AS manager_emp_id,
               h.full_name AS head_name, h.emp_id AS head_emp_id
               FROM cashback_requests cr 
               JOIN users u ON cr.user_id = u.id 
               LEFT JOIN users m ON u.manager_id = m.id 
               LEFT JOIN users h ON m.head_id = h.id 
               WHERE cr.id = ?";
 $stmt = mysqli_prepare($conn, $request_sql);
mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
 $request_result = mysqli_stmt_get_result($stmt);

// Check if request exists
if (mysqli_num_rows($request_result) === 0) {
    show_notification('Request not found', 'error');
    redirect('dashboard_' . strtolower($_SESSION['role']) . '.php');
}

 $request = mysqli_fetch_assoc($request_result);

// Get approval history with UTR number and payment screenshot
 $approvals_sql = "SELECT a.*, u.full_name AS approver_name, cr.utr_number, cr.payment_screenshot_url
                 FROM approvals a 
                 JOIN users u ON a.approver_id = u.id 
                 LEFT JOIN cashback_requests cr ON a.request_id = cr.id
                 WHERE a.request_id = ? 
                 ORDER BY a.created_at";
 $stmt = mysqli_prepare($conn, $approvals_sql);
mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
 $approvals_result = mysqli_stmt_get_result($stmt);

// Get user justifications for validator rejections
 $justifications_sql = "SELECT * FROM user_justifications 
                      WHERE request_id = ? 
                      ORDER BY created_at DESC";
 $stmt = mysqli_prepare($conn, $justifications_sql);
mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
 $justifications_result = mysqli_stmt_get_result($stmt);

// Store justifications in an array for easier access
 $justifications = [];
if (mysqli_num_rows($justifications_result) > 0) {
    while ($justification = mysqli_fetch_assoc($justifications_result)) {
        $justifications[] = $justification;
    }
}

// Check if user can provide justification (when validator rejected and user is the request owner)
 $canJustify = false;
if (($request['status'] === 'Validator Rejected' || $request['status'] === 'Pending Validation') && $request['user_id'] == $_SESSION['user_id']) {
    // Check if user has already provided justification
    $hasJustified = false;
    foreach ($justifications as $justification) {
        if ($justification['user_id'] == $_SESSION['user_id']) {
            $hasJustified = true;
            break;
        }
    }
    
    $canJustify = !$hasJustified;
}

// Check if validator can review justification (when user provided justification)
 $validatorCanReview = false;
if (has_role('Validator') && ($request['status'] === 'Validator Rejected' || $request['status'] === 'Pending Validation')) {
    // Check if user has provided justification
    foreach ($justifications as $justification) {
        if ($justification['user_id'] == $request['user_id']) {
            $validatorCanReview = true;
            break;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Request - CB Account</title>
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
            /* Original color scheme */
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
            min-height: 100vh;
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
        
        /* Professional Dashboard Layout with Original Colors */
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .dashboard-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--dark);
        }
        .request-number {
            font-size: 14px;
            color: var(--text);
            background-color: var(--gray);
            padding: 6px 12px;
            border-radius: var(--radius);
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            grid-template-rows: auto auto;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .dashboard-card {
            background-color: var(--light);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 20px;
            position: relative;
            border-top: 4px solid var(--primary);
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        
        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--gray);
        }
        
        .card-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .card-title i {
            color: var(--primary);
        }
        
        /* Request Details Card */
        .request-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            flex-grow: 1;
        }
        
        .detail-item {
            display: flex;
            flex-direction: column;
        }
        
        .detail-label {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 4px;
            font-weight: 500;
        }
        
        .detail-value {
            font-weight: 600;
            color: var(--dark);
            font-size: 14px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .status-pending { background-color: #fff7e6; color: #d46b08; }
        .status-manager-approved { background-color: #e6f7ff; color: #096dd9; }
        .status-head-approved { background-color: #f9f0ff; color: #722ed1; }
        .status-validator-approved { background-color: #e6fffb; color: #13c2c2; }
        .status-finance-approved { background-color: #f6ffed; color: #389e0d; }
        .status-rejected { background-color: #fff2f0; color: #cf1322; }
        .status-validator-rejected { background-color: #fff1f0; color: #ff4d4f; }
        .status-pending-validation { background-color: #f3e8ff; color: #9254de; }
        
        /* Form Type Badge Styles */
        .form-type-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            margin-left: 10px;
        }
        .form-type-cb { background-color: #e6f7ff; color: #096dd9; }
        .form-type-shortfall { background-color: #fff7e6; color: #d46b08; }
        
        /* New Professional Financial Details Card */
        .financial-card {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-top: 15px;
        }
        .financial-header {
            background-color: var(--primary);
            color: white;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .financial-header i { font-size: 18px; }
        .financial-header h3 { font-size: 16px; font-weight: 600; margin: 0; }
        .financial-content { display: flex; flex-wrap: wrap; padding: 0; }
        .financial-item {
            flex: 1 0 50%;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            border-bottom: 1px solid rgba(226, 232, 240, 0.7);
            border-right: 1px solid rgba(226, 232, 240, 0.7);
            position: relative;
            transition: all 0.2s ease;
        }
        .financial-item:nth-child(even) { border-right: none; }
        .financial-item:nth-child(n+5) { border-bottom: none; }
        .financial-item:hover { background-color: rgba(240, 93, 73, 0.05); }
        .financial-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: rgba(240, 93, 73, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            flex-shrink: 0;
        }
        .financial-icon i { color: var(--primary); font-size: 18px; }
        .financial-details { flex-grow: 1; }
        .financial-label { font-size: 12px; color: var(--text-light); margin-bottom: 2px; font-weight: 500; }
        .financial-value { font-size: 16px; font-weight: 700; color: var(--dark); }
        .financial-value.primary { color: var(--primary); }
        
        /* Attachment Card */
        .attachment-container {
            display: flex;
            justify-content: center;
            align-items: center;
            flex-grow: 1;
        }
        .attachment-preview {
            max-width: 100%;
            max-height: 200px;
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .attachment-preview:hover { box-shadow: var(--shadow); }
        
        /* Approval Timeline */
        .timeline { position: relative; padding-left: 30px; flex-grow: 1; }
        .timeline::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 2px;
            background-color: var(--gray);
        }
        .timeline-item { position: relative; margin-bottom: 20px; padding-bottom: 15px; }
        .timeline-item:last-child { margin-bottom: 0; }
        .timeline-dot {
            position: absolute;
            left: -34px;
            top: 5px;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background-color: var(--light);
            border: 2px solid var(--primary);
        }
        .timeline-content { background-color: #f8fafc; padding: 12px; border-radius: var(--radius); }
        .timeline-header { display: flex; justify-content: space-between; margin-bottom: 5px; }
        .timeline-role { font-weight: 600; color: var(--dark); font-size: 14px; }
        .timeline-status { font-size: 12px; font-weight: 500; }
        .timeline-approver { font-size: 12px; color: var(--text-light); margin-bottom: 5px; }
        .timeline-comments { font-size: 13px; color: var(--text); margin-top: 5px; }
        .timeline-date { font-size: 11px; color: var(--text-light); text-align: right; margin-top: 5px; }
        
        /* User Justification Styles */
        .user-justification {
            background-color: #f0f9ff;
            border-left: 4px solid #096dd9;
            padding: 10px;
            margin-top: 10px;
            border-radius: var(--radius);
        }
        .justification-header { display: flex; justify-content: space-between; margin-bottom: 5px; }
        .justification-title { font-weight: 600; color: #096dd9; font-size: 13px; }
        .justification-content { font-size: 13px; color: var(--text); }
        .justification-date { font-size: 11px; color: var(--text-light); text-align: right; margin-top: 5px; }
        
        /* Action Buttons */
        .action-buttons { display: flex; gap: 10px; margin-top: 20px; }
        .btn {
            padding: 8px 16px;
            border-radius: var(--radius);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 14px;
            text-decoration: none;
        }
        .btn-primary { background-color: var(--primary); color: white; }
        .btn-primary:hover { background-color: var(--primary-dark); }
        .btn-success { background-color: #38a169; color: white; }
        .btn-success:hover { background-color: #2f855a; }
        .btn-danger { background-color: #e53e3e; color: white; }
        .btn-danger:hover { background-color: #c53030; }
        .btn-outline { background-color: transparent; border: 1px solid var(--primary); color: var(--primary); }
        .btn-outline:hover { background-color: var(--primary); color: white; }
        
        /* Alert */
        .alert {
            padding: 12px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success { background-color: #f6ffed; border-left: 4px solid #389e0d; color: #389e0d; }
        .alert-error { background-color: #fff2f0; border-left: 4px solid #cf1322; color: #cf1322; }
        
        /* Modal */
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
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .modal-title { font-size: 18px; color: var(--dark); font-weight: 600; }
        .modal-close { background: none; border: none; font-size: 20px; cursor: pointer; color: var(--text); }
        .modal-body { margin-bottom: 15px; }
        .modal-footer { display: flex; justify-content: flex-end; gap: 10px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; color: var(--dark); font-weight: 500; }
        .form-control {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            font-size: 14px;
        }
        textarea.form-control { min-height: 80px; resize: vertical; }
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
        
        /* UTR Number Style */
        .utr-info {
            background-color: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: var(--radius);
            padding: 8px 12px;
            margin-top: 5px;
            font-size: 12px;
            color: #0369a1;
        }
        .utr-number { font-weight: 600; color: #0c4a6e; }
        
        /* Payment Screenshot Style */
        .payment-screenshot {
            margin-top: 10px;
            max-width: 100%;
            border-radius: var(--radius);
            border: 1px solid var(--gray);
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-block;
        }
        .payment-screenshot:hover { box-shadow: var(--shadow); }
        .payment-screenshot img {
            max-width: 100%;
            max-height: 150px;
            border-radius: var(--radius);
        }
        .payment-screenshot-info {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-top: 5px;
            font-size: 12px;
            color: var(--text-light);
        }
        .view-screenshot-btn {
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: var(--radius);
            padding: 4px 8px;
            font-size: 12px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-top: 5px;
            transition: background-color 0.2s ease;
        }
        .view-screenshot-btn:hover { background-color: var(--primary-dark); }
        
        /* Responsive Styles */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.active { transform: translateX(0); }
            .main-content { margin-left: 0; width: 100%; padding: 60px 15px 15px; }
            .menu-toggle { display: block; }
            .dashboard-grid { grid-template-columns: 1fr; }
            .request-details, .financial-details { grid-template-columns: 1fr; }
            .dashboard-card { padding: 15px; }
            .action-buttons { flex-direction: column; }
            .btn { width: 100%; justify-content: center; }
            .financial-item { flex: 1 0 100%; border-right: none !important; }
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
                <a href="dashboard_<?php echo strtolower($_SESSION['role']); ?>.php" class="sidebar-menu-item">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <?php if (has_role('User')): ?>
                    <a href="index.php" class="sidebar-menu-item">
                        <i class="fas fa-plus-circle"></i> New Request
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
                        <i class="fas fa-file-invoice-dollar logo-icon"></i>
                        <div class="logo-text">CB Account <span>Request</span></div>
                    </div>
                    <p class="tagline">Request details and approval history</p>
                    
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
                        <i class="fas fa-<?php echo $_SESSION['notification']['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                        <?php echo $_SESSION['notification']['message']; ?>
                    </div>
                    <?php unset($_SESSION['notification']); ?>
                <?php endif; ?>
                
                <div class="dashboard-header">
                    <h2 class="dashboard-title">Request Details</h2>
                    <div class="request-number">Ref: <?php echo htmlspecialchars($request['reference_number']); ?></div>
                </div>
                
                <div class="dashboard-grid">
                    <!-- Request Details Card -->
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-info-circle"></i>
                                Request Information
                            </h3>
                            <div>
                                <span class="status-badge <?php 
                                    switch ($request['status']) {
                                        case 'Pending': echo 'status-pending'; break;
                                        case 'Manager Approved': echo 'status-manager-approved'; break;
                                        case 'Head Approved': echo 'status-head-approved'; break;
                                        case 'Validator Approved': echo 'status-validator-approved'; break;
                                        case 'Finance Approved': echo 'status-finance-approved'; break;
                                        case 'Rejected': echo 'status-rejected'; break;
                                        case 'Validator Rejected': echo 'status-validator-rejected'; break;
                                        case 'Pending Validation': echo 'status-pending-validation'; break;
                                    }
                                ?>">
                                    <?php echo htmlspecialchars($request['status']); ?>
                                </span>
                                <span class="form-type-badge <?php 
                                    if ($request['form_type'] === 'CB') {
                                        echo 'form-type-cb';
                                    } else {
                                        echo 'form-type-shortfall';
                                    }
                                ?>">
                                    <?php echo htmlspecialchars($request['form_type']); ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="request-details">
                            <div class="detail-item">
                                <div class="detail-label">RM Name</div>
                                <div class="detail-value"><?php echo htmlspecialchars($request['rm_name']); ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">RM EMP ID</div>
                                <div class="detail-value"><?php echo htmlspecialchars($request['rm_emp_id']); ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Department</div>
                                <div class="detail-value"><?php echo htmlspecialchars($request['department']); ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Customer Name</div>
                                <div class="detail-value"><?php echo htmlspecialchars($request['customer_name']); ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Mobile Number</div>
                                <div class="detail-value"><?php echo htmlspecialchars($request['mobile_number']); ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Month</div>
                                <div class="detail-value"><?php echo htmlspecialchars($request['month'] . ' ' . $request['year']); ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Insurance Company</div>
                                <div class="detail-value"><?php echo htmlspecialchars($request['insurance_company']); ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Policy Type</div>
                                <div class="detail-value"><?php echo htmlspecialchars($request['policy_type']); ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Created Date</div>
                                <div class="detail-value"><?php echo date('d M Y', strtotime($request['created_at'])); ?></div>
                            </div>
                            
                            <!-- Additional fields for Shortfall type -->
                            <?php if ($request['form_type'] === 'Shortfall'): ?>
                                <div class="detail-item">
                                    <div class="detail-label">Policy Copy</div>
                                    <div class="detail-value">
                                        <?php if (!empty($request['policy_copy_url'])): ?>
                                            <a href="<?php echo htmlspecialchars($request['policy_copy_url']); ?>" target="_blank" class="btn btn-primary" style="padding: 4px 8px; font-size: 12px;">
                                                <i class="fas fa-file-pdf"></i> View Policy
                                            </a>
                                        <?php else: ?>
                                            N/A
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="detail-item">
                                    <div class="detail-label">Payment Link</div>
                                    <div class="detail-value">
                                        <?php if (!empty($request['payment_link'])): ?>
                                            <a href="<?php echo htmlspecialchars($request['payment_link']); ?>" target="_blank" class="btn btn-primary" style="padding: 4px 8px; font-size: 12px;">
                                                <i class="fas fa-link"></i> View Link
                                            </a>
                                        <?php else: ?>
                                            N/A
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Clean up the Reason field to show only the original reason -->
                            <div class="detail-item" style="grid-column: span 2;">
                                <div class="detail-label">Reason</div>
                                <div class="detail-value"><?php 
                                    // Clean up the reason to remove any old appended justification text
                                    $reason_parts = explode("\n\n || User Justification: ", $request['reason']);
                                    echo htmlspecialchars(trim($reason_parts[0])); 
                                ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Financial Details Card - Redesigned -->
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-chart-line"></i>
                                Financial Details
                            </h3>
                        </div>
                        
                        <div class="financial-card">
                            <div class="financial-header">
                                <i class="fas fa-coins"></i>
                                <h3>Payment Summary</h3>
                            </div>
                            
                            <div class="financial-content">
                                <div class="financial-item">
                                    <div class="financial-icon">
                                        <i class="fas fa-money-bill-wave"></i>
                                    </div>
                                    <div class="financial-details">
                                        <div class="financial-label">Premium With GST</div>
                                        <div class="financial-value">₹<?php echo number_format($request['premium_with_gst'], 2); ?></div>
                                    </div>
                                </div>
                                
                                <div class="financial-item">
                                    <div class="financial-icon">
                                        <i class="fas fa-calculator"></i>
                                    </div>
                                    <div class="financial-details">
                                        <div class="financial-label">Without GST</div>
                                        <div class="financial-value">₹<?php echo number_format($request['without_gst'], 2); ?></div>
                                    </div>
                                </div>
                                
                                <div class="financial-item">
                                    <div class="financial-icon">
                                        <i class="fas fa-hand-holding-usd"></i>
                                    </div>
                                    <div class="financial-details">
                                        <div class="financial-label">Referral Amount</div>
                                        <div class="financial-value primary">₹<?php echo number_format($request['referral_amount'], 2); ?></div>
                                    </div>
                                </div>
                                
                                <div class="financial-item">
                                    <div class="financial-icon">
                                        <i class="fas fa-percentage"></i>
                                    </div>
                                    <div class="financial-details">
                                        <div class="financial-label">Referral Ratio</div>
                                        <div class="financial-value"><?php 
                                            if ($request['without_gst'] > 0) {
                                                echo round(($request['referral_amount'] / $request['without_gst']) * 100, 2) . '%';
                                            } else {
                                                echo 'N/A';
                                            }
                                        ?></div>
                                    </div>
                                </div>
                                
                                <div class="financial-item">
                                    <div class="financial-icon">
                                        <i class="fas fa-calendar-alt"></i>
                                    </div>
                                    <div class="financial-details">
                                        <div class="financial-label">Created Date</div>
                                        <div class="financial-value"><?php echo date('d M Y', strtotime($request['created_at'])); ?></div>
                                    </div>
                                </div>
                                
                                <div class="financial-item">
                                    <div class="financial-icon">
                                        <i class="fas fa-clock"></i>
                                    </div>
                                    <div class="financial-details">
                                        <div class="financial-label">Last Updated</div>
                                        <div class="financial-value"><?php echo date('d M Y', strtotime($request['updated_at'])); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Attachment Card -->
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-paperclip"></i>
                                Account Details Attachment
                            </h3>
                        </div>
                        
                        <div class="attachment-container">
                            <?php if (!empty($request['attachment_url'])): ?>
                                <img src="<?php echo htmlspecialchars($request['attachment_url']); ?>" alt="Attachment" class="attachment-preview" onclick="window.open('<?php echo htmlspecialchars($request['attachment_url']); ?>', '_blank')">
                            <?php else: ?>
                                <p style="color: var(--text-light);">No attachment available</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Approval History Card -->
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-history"></i>
                                Approval History
                            </h3>
                        </div>
                        
                        <div class="timeline">
                            <?php if (mysqli_num_rows($approvals_result) > 0): ?>
                                <?php while ($approval = mysqli_fetch_assoc($approvals_result)): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-dot"></div>
                                        <div class="timeline-content">
                                            <div class="timeline-header">
                                                <div class="timeline-role"><?php echo htmlspecialchars($approval['approver_role']); ?></div>
                                                <div class="timeline-status <?php 
                                                    if ($approval['status'] === 'Approved') {
                                                        echo $approval['approver_role'] === 'Validator' ? 'status-validator-approved' : 'status-finance-approved';
                                                    } else {
                                                        echo $approval['approver_role'] === 'Validator' ? 'status-validator-rejected' : 'status-rejected';
                                                    }
                                                ?>">
                                                    <?php echo htmlspecialchars($approval['status']); ?>
                                                </div>
                                            </div>
                                            <div class="timeline-approver">
                                                <i class="fas fa-user"></i> <?php echo htmlspecialchars($approval['approver_name']); ?>
                                            </div>
                                            
                                            <!-- FINAL LOGIC FOR DISPLAYING COMMENTS AND JUSTIFICATIONS -->
                                            
                                            <!-- Step 1: Display the approver's standard comment (for Validator, Manager, Head, etc.) -->
                                            <?php if (!empty($approval['comments'])): ?>
                                                <div class="timeline-comments">
                                                    <i class="fas fa-comment"></i> <?php echo htmlspecialchars($approval['comments']); ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <!-- Step 2: IF this is a Validator Rejection, THEN check for and display the user's justification -->
                                            <?php if ($approval['approver_role'] === 'Validator' && $approval['status'] === 'Rejected'): ?>
                                                <?php 
                                                // Find the user's justification for this specific rejection
                                                mysqli_data_seek($justifications_result, 0); // Reset pointer for each loop iteration
                                                $user_justification_text = '';
                                                $justification_date = '';
                                                while ($justification = mysqli_fetch_assoc($justifications_result)) {
                                                    if ($justification['approval_id'] == $approval['id']) {
                                                        $user_justification_text = htmlspecialchars($justification['justification_text']);
                                                        $justification_date = $justification['created_at'];
                                                        break; // Found it, no need to continue looping
                                                    }
                                                }
                                                
                                                // If a justification exists, display it in its own styled container
                                                if (!empty($user_justification_text)):
                                                ?>
                                                    <div class="user-justification">
                                                        <div class="justification-header">
                                                            <div class="justification-title">
                                                                <i class="fas fa-user"></i> User Justification
                                                            </div>
                                                        </div>
                                                        <div class="justification-content">
                                                            <?php echo $user_justification_text; ?>
                                                        </div>
                                                        <div class="justification-date">
                                                            <?php echo date('d M Y, h:i A', strtotime($justification_date)); ?>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            
                                            <!-- END OF CHANGES -->
                                            
                                            <?php if ($approval['approver_role'] === 'Finance' && !empty($approval['utr_number'])): ?>
                                                <div class="utr-info">
                                                    <i class="fas fa-receipt"></i> <span class="utr-number">UTR: <?php echo htmlspecialchars($approval['utr_number']); ?></span>
                                                    
                                                    <?php if (!empty($approval['payment_screenshot_url'])): ?>
                                                        <div class="payment-screenshot">
                                                            <img src="<?php echo htmlspecialchars($approval['payment_screenshot_url']); ?>" alt="Payment Screenshot" onclick="window.open('<?php echo htmlspecialchars($approval['payment_screenshot_url']); ?>', '_blank')">
                                                            <div class="payment-screenshot-info">
                                                                <i class="fas fa-image"></i> Payment Screenshot
                                                            </div>
                                                        </div>
                                                        <button class="view-screenshot-btn" onclick="window.open('<?php echo htmlspecialchars($approval['payment_screenshot_url']); ?>', '_blank')">
                                                            <i class="fas fa-eye"></i> View Full Screenshot
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <div class="timeline-date">
                                                <?php echo date('d M Y, h:i A', strtotime($approval['created_at'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <p style="color: var(--text-light);">No approval history available</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="action-buttons">
                    <a href="dashboard_<?php echo strtolower($_SESSION['role']); ?>.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                    
                    <?php if (has_role('Manager') && $request['status'] === 'Pending'): ?>
                        <button class="btn btn-success" onclick="approveRequest(<?php echo $request['id']; ?>)">
                            <i class="fas fa-check"></i> Approve
                        </button>
                        <button class="btn btn-danger" onclick="rejectRequest(<?php echo $request['id']; ?>)">
                            <i class="fas fa-times"></i> Reject
                        </button>
                    <?php endif; ?>
                    
                    <?php if (has_role('Head') && $request['status'] === 'Manager Approved'): ?>
                        <button class="btn btn-success" onclick="approveRequest(<?php echo $request['id']; ?>)">
                            <i class="fas fa-check"></i> Approve
                        </button>
                        <button class="btn btn-danger" onclick="rejectRequest(<?php echo $request['id']; ?>)">
                            <i class="fas fa-times"></i> Reject
                        </button>
                    <?php endif; ?>
                    
                    <?php if (has_role('Validator') && ($request['status'] === 'Head Approved' || $validatorCanReview)): ?>
                        <button class="btn btn-success" onclick="approveRequest(<?php echo $request['id']; ?>)">
                            <i class="fas fa-check"></i> Approve
                        </button>
                        <button class="btn btn-danger" onclick="rejectRequest(<?php echo $request['id']; ?>)">
                            <i class="fas fa-times"></i> Reject
                        </button>
                    <?php endif; ?>
                    
                    <?php if (has_role('Finance') && $request['status'] === 'Validator Approved'): ?>
                        <button class="btn btn-success" onclick="payRequest(<?php echo $request['id']; ?>)">
                            <i class="fas fa-money-check"></i> Pay
                        </button>
                        <button class="btn btn-danger" onclick="rejectRequest(<?php echo $request['id']; ?>)">
                            <i class="fas fa-times"></i> Reject
                        </button>
                    <?php endif; ?>
                    
                    <!-- Add Justification Button for Users when Validator Rejected -->
                    <?php if ($canJustify): ?>
                        <button class="btn btn-primary" onclick="showJustificationForm()">
                            <i class="fas fa-comment"></i> Provide Justification
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Modals (unchanged) -->
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
                                <label for="approveComments">Comments (Optional)</label>
                                <textarea id="approveComments" name="comments" class="form-control"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline" onclick="document.getElementById('approveModal').style.display='none'">Cancel</button>
                            <button type="submit" class="btn btn-success">Approve</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <div id="payModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3 class="modal-title">Mark as Paid</h3>
                        <button class="modal-close" onclick="document.getElementById('payModal').style.display='none'">&times;</button>
                    </div>
                    <form id="payForm" method="post" enctype="multipart/form-data">
                        <div class="modal-body">
                            <p>Are you sure you want to mark this request as paid?</p>
                            <div class="form-group">
                                <label for="utrNumber">UTR Number <span style="color:red;">*</span></label>
                                <input type="text" id="utrNumber" name="utr_number" class="form-control" placeholder="Enter UTR number" required>
                                <div class="utr-info">
                                    <i class="fas fa-info-circle"></i> UTR (Unique Transaction Reference) number is a unique identifier for your transaction
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="paymentScreenshot">Payment Screenshot</label>
                                <div class="file-upload" style="position: relative; display: inline-block; cursor: pointer; width: 100%;">
                                    <input type="file" id="paymentScreenshot" name="payment_screenshot" accept="image/*" onchange="previewFile(this)" style="position: absolute; left: -9999px;">
                                    <label for="paymentScreenshot" style="display: block; padding: 10px 12px; border: 1px dashed var(--gray); border-radius: var(--radius); background-color: #f8fafc; cursor: pointer; text-align: center; transition: all 0.3s ease;">
                                        <i class="fas fa-cloud-upload-alt"></i> Click to upload payment screenshot
                                    </label>
                                </div>
                                <div class="file-info" style="margin-top: 8px; font-size: 12px; color: var(--text-light);">Accepted formats: JPG, PNG, GIF. Max size: 5MB</div>
                                <div id="filePreview" class="file-preview" style="margin-top: 10px; max-width: 100%; border-radius: var(--radius); display: none; height: 200px; overflow: hidden; position: relative;">
                                    <img id="previewImg" src="" alt="Payment Screenshot" style="width: 100%; height: 100%; object-fit: contain; border-radius: var(--radius); box-shadow: var(--shadow);">
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="payComments">Comment <span style="color:red;">*</span></label>
                                <textarea id="payComments" name="comments" class="form-control" required></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline" onclick="document.getElementById('payModal').style.display='none'">Cancel</button>
                            <button type="submit" class="btn btn-success">Mark as Paid</button>
                        </div>
                    </form>
                </div>
            </div>
            
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
            
            <div id="justificationModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3 class="modal-title">Provide Justification</h3>
                        <button class="modal-close" onclick="document.getElementById('justificationModal').style.display='none'">&times;</button>
                    </div>
                    <form id="justificationForm" method="post" action="submit_justification.php">
                        <input type="hidden" name="request_id" value="<?php echo $request_id; ?>">
                        <div class="modal-body">
                            <p>Your request has been rejected by the validator. Please provide a justification for reconsideration.</p>
                            <div class="form-group">
                                <label for="justificationText">Justification <span style="color:red;">*</span></label>
                                <textarea id="justificationText" name="justification" class="form-control" required></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline" onclick="document.getElementById('justificationModal').style.display='none'">Cancel</button>
                            <button type="submit" class="btn btn-primary">Submit Justification</button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        function approveRequest(requestId) {
            let formAction = '';
            
            <?php if (has_role('Manager')): ?>
                formAction = 'process_approval.php?action=manager_approve&id=' + requestId;
            <?php elseif (has_role('Head')): ?>
                formAction = 'process_approval.php?action=head_approve&id=' + requestId;
            <?php elseif (has_role('Validator')): ?>
                <?php if ($validatorCanReview): ?>
                    formAction = 'process_approval.php?action=validator_review_justification&id=' + requestId;
                <?php else: ?>
                    formAction = 'process_approval.php?action=validator_approve&id=' + requestId;
                <?php endif; ?>
            <?php elseif (has_role('Finance')): ?>
                formAction = 'process_approval.php?action=finance_approve&id=' + requestId;
            <?php endif; ?>
            
            document.getElementById('approveModal').style.display = 'flex';
            document.getElementById('approveForm').action = formAction;
        }
        
        function payRequest(requestId) {
            document.getElementById('payModal').style.display = 'flex';
            document.getElementById('payForm').action = 'process_approval.php?action=finance_pay&id=' + requestId;
        }
        
        function rejectRequest(requestId) {
            let formAction = '';
            
            <?php if (has_role('Manager')): ?>
                formAction = 'process_approval.php?action=manager_reject&id=' + requestId;
            <?php elseif (has_role('Head')): ?>
                formAction = 'process_approval.php?action=head_reject&id=' + requestId;
            <?php elseif (has_role('Validator')): ?>
                <?php if ($validatorCanReview): ?>
                    formAction = 'process_approval.php?action=validator_reject_justification&id=' + requestId;
                <?php else: ?>
                    formAction = 'process_approval.php?action=validator_reject&id=' + requestId;
                <?php endif; ?>
            <?php elseif (has_role('Finance')): ?>
                formAction = 'process_approval.php?action=finance_reject&id=' + requestId;
            <?php endif; ?>
            
            document.getElementById('rejectModal').style.display = 'flex';
            document.getElementById('rejectForm').action = formAction;
        }
        
        function showJustificationForm() {
            document.getElementById('justificationModal').style.display = 'flex';
        }
        
        function previewFile(input) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                
                reader.onload = function(e) {
                    document.getElementById('previewImg').setAttribute('src', e.target.result);
                    document.getElementById('filePreview').style.display = 'block';
                }
                
                reader.readAsDataURL(input.files[0]);
            }
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
    </script>
</body>
</html>