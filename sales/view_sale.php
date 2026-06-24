<?php
require_once '../config.php';
require_once 'functions.php';

// --- Helper Functions for Masking ---

/**
 * Masks mobile number (e.g., 78*******9)
 * Applied for 'User' and 'Manager' roles only.
 */
function maskMobile($number, $role) {
    if (in_array($role, ['User', 'Manager'])) {
        $length = strlen($number);
        if ($length > 3) {
            return substr($number, 0, 2) . str_repeat('*', $length - 3) . substr($number, -1);
        }
        return $number;
    }
    return $number; // Return original for other roles
}

/**
 * Masks Customer Name (e.g., Ma***h K***r)
 * Applied for 'User' and 'Manager' roles only.
 */
function maskName($name, $role) {
    // Only mask if role is User or Manager
    if (in_array($role, ['User', 'Manager'])) {
        $words = explode(' ', $name);
        $maskedWords = [];

        foreach ($words as $word) {
            $len = strlen($word);
            if ($len > 3) {
                // First 2 chars + Stars + Last 1 char
                $maskedWords[] = substr($word, 0, 2) . str_repeat('*', $len - 3) . substr($word, -1);
            } else {
                $maskedWords[] = $word;
            }
        }
        return implode(' ', $maskedWords);
    }
    return $name; // Return original for other roles
}

// Check if user is logged in
if (!is_logged_in()) {
    show_notification('Please login to continue', 'error');
    redirect('../login.php');
}

// Get request ID
 $request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get request details with full approval chain
 $request_sql = "SELECT sr.*, u.full_name as user_name, u.emp_id as user_emp_id, 
             m.full_name as manager_name, m.emp_id as manager_emp_id,
             h.full_name as head_name, h.emp_id as head_emp_id
             FROM sales_requests sr 
             JOIN users u ON sr.user_id = u.id 
             LEFT JOIN users m ON sr.manager_id = m.id
             LEFT JOIN users h ON sr.head_id = h.id
             WHERE sr.id = ?";
 $stmt = $conn->prepare($request_sql);
mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
 $request_result = mysqli_stmt_get_result($stmt);
 $request = mysqli_fetch_assoc($request_result);

if (!$request) {
    show_notification('Sales request not found', 'error');
    redirect('index.php');
}

// --- Age Calculation Logic (Years, Months, Days) ---
 $regDate = new DateTime($request['register_year']);
 $today = new DateTime();
 $diff = $regDate->diff($today);
// $displayAge = $diff->y . " Years, " . $diff->m . " Months, " . $diff->d . " Days";
  $displayAge = $diff->y . " Years, " . $diff->m . " Months, " . $diff->d . " Days Old";

// Apply Masking Logic based on session role
 $current_user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';

// 1. Mask Customer Name (Only for User and Manager)
 $request['name'] = maskName($request['name'], $current_user_role);

// 2. Mask Mobile Number (Only for User and Manager)
 $request['mobile_no'] = maskMobile($request['mobile_no'], $current_user_role);

// Get justifications for this request if it was rejected
 $justifications = [];
if ($request['status'] === 'Rejected') {
    $just_sql = "SELECT sj.*, u.full_name as user_name 
                FROM sales_justifications sj 
                JOIN users u ON sj.user_id = u.id 
                WHERE sj.sales_request_id = ? 
                ORDER BY sj.created_at DESC";
    $stmt = $conn->prepare($just_sql);
    mysqli_stmt_bind_param($stmt, "i", $request_id);
    mysqli_stmt_execute($stmt);
    $just_result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($just_result)) {
        $justifications[] = $row;
    }
}

// Get complete approval history with detailed information from the correct table
 $approvals_sql = "SELECT a.*, u.full_name as approver_name, u.emp_id as approver_emp_id
                 FROM approvals_sales a 
                 JOIN users u ON a.approver_id = u.id 
                 WHERE a.sales_request_id = ? 
                 ORDER BY a.created_at ASC";
 $stmt = $conn->prepare($approvals_sql);
mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
 $approvals_result = mysqli_stmt_get_result($stmt);
 $approvals = [];
while ($row = mysqli_fetch_assoc($approvals_result)) {
    $approvals[] = $row;
}

// Create a timeline with all events
 $timeline = [];

// Add request creation event
 $timeline[] = [
    'type' => 'created',
    'title' => 'Request Created',
    'user' => $request['user_name'],
    'user_id' => $request['user_emp_id'],
    'date' => $request['created_at'],
    'description' => 'Sales request submitted by ' . $request['user_name']
];

// Add approval events
foreach ($approvals as $approval) {
    $type = '';
    $title = '';
    
    if ($approval['approver_role'] === 'Manager' && $approval['status'] === 'Verified') {
        $type = 'verified';
        $title = 'Request Verified by Manager';
    } elseif ($approval['approver_role'] === 'Manager' && $approval['status'] === 'Rejected') {
        $type = 'rejected';
        $title = 'Request Rejected by Manager';
    } elseif ($approval['approver_role'] === 'Head' && $approval['status'] === 'Paid') {
        $type = 'paid';
        $title = 'Request Marked as Paid by Head';
    } elseif ($approval['approver_role'] === 'User' && $approval['status'] === 'Resubmitted') {
        $type = 'resubmitted';
        $title = 'Request Resubmitted by User';
    }
    
    $timeline[] = [
        'type' => $type,
        'title' => $title,
        'user' => $approval['approver_name'],
        'user_id' => $approval['approver_emp_id'],
        'role' => $approval['approver_role'],
        'date' => $approval['created_at'],
        'comments' => $approval['comments'],
        'description' => $title . ' - ' . $approval['approver_name'] . ' (' . $approval['approver_role'] . ')'
    ];
}

// Add justifications if any
foreach ($justifications as $justification) {
    $timeline[] = [
        'type' => 'justification',
        'title' => 'Justification Added',
        'user' => $justification['user_name'],
        'user_id' => $justification['user_emp_id'],
        'date' => $justification['created_at'],
        'description' => 'Justification added by ' . $justification['user_name'],
        'comments' => $justification['justification_text']
    ];
}

// Sort timeline by date
usort($timeline, function($a, $b) {
    return strtotime($a['date']) - strtotime($b['date']);
});

// Check if current user can take action on this request
 $can_verify = false;
 $can_paid = false;
 $can_edit = false;

if (has_role('Manager') && $request['status'] === 'Pending') {
    // Check if this manager is assigned to this user
    $user_sql = "SELECT manager_id FROM users WHERE id = ?";
    $stmt = $conn->prepare($user_sql);
    mysqli_stmt_bind_param($stmt, "i", $request['user_id']);
    mysqli_stmt_execute($stmt);
    $user_result = mysqli_stmt_get_result($stmt);
    $user_data = mysqli_fetch_assoc($user_result);
    
    if ($user_data['manager_id'] == $_SESSION['user_id']) {
        $can_verify = true;
    }
}

if (has_role('Head') && $request['status'] === 'Manager Verified') {
    // Check if this head is assigned to this user
    $user_sql = "SELECT head_id FROM users WHERE id = ?";
    $stmt = $conn->prepare($user_sql);
    mysqli_stmt_bind_param($stmt, "i", $request['user_id']);
    mysqli_stmt_execute($stmt);
    $user_result = mysqli_stmt_get_result($stmt);
    $user_data = mysqli_fetch_assoc($user_result);
    
    if ($user_data['head_id'] == $_SESSION['user_id']) {
        $can_paid = true;
    }
}

if (has_role('User') && $request['status'] === 'Rejected' && $request['user_id'] == $_SESSION['user_id']) {
    $can_edit = true;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Sales Request - Sales System</title>
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
        .btn-warning {
            background-color: #d46b08;
            color: white;
        }
        .btn-warning:hover {
            background-color: #ad4e00;
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
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .detail-card {
            background-color: #f8fafc;
            border-radius: var(--radius);
            padding: 15px;
            box-shadow: var(--shadow);
        }
        .detail-card h3 {
            font-size: 16px;
            margin-bottom: 15px;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .detail-card h3 i {
            color: var(--primary);
        }
        .detail-row {
            display: flex;
            margin-bottom: 10px;
        }
        .detail-label {
            font-weight: 500;
            width: 140px;
            color: var(--text-light);
        }
        .detail-value {
            flex: 1;
        }
        .timeline {
            position: relative;
            padding-left: 30px;
            margin-top: 20px;
        }
        .timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 0;
            height: 100%;
            width: 2px;
            background-color: var(--gray);
        }
        .timeline-item {
            position: relative;
            margin-bottom: 25px;
        }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -24px;
            top: 5px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background-color: var(--primary);
        }
        .timeline-item.created::before {
            background-color: #718096;
        }
        .timeline-item.verified::before {
            background-color: #096dd9;
        }
        .timeline-item.paid::before {
            background-color: #389e0d;
        }
        .timeline-item.rejected::before {
            background-color: #cf1322;
        }
        .timeline-item.justification::before {
            background-color: #d46b08;
        }
        .timeline-item.resubmitted::before {
            background-color: #722ed1;
        }
        .timeline-date {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 5px;
            font-weight: 600;
        }
        .timeline-content {
            background-color: #f8fafc;
            padding: 15px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }
        .timeline-title {
            font-weight: 600;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .timeline-title i {
            font-size: 14px;
        }
        .timeline-description {
            font-size: 13px;
            margin-bottom: 8px;
        }
        .timeline-comments {
            font-size: 13px;
            font-style: italic;
            color: var(--text-light);
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid var(--gray);
        }
        .timeline-user {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 5px;
        }
        .timeline-role {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 500;
            margin-left: 5px;
        }
        .timeline-role.manager {
            background-color: #e6f7ff;
            color: #096dd9;
        }
        .timeline-role.head {
            background-color: #f6ffed;
            color: #389e0d;
        }
        .justification-card {
            background-color: #fff7e6;
            border-left: 4px solid #d46b08;
            padding: 10px;
            border-radius: var(--radius);
            margin-bottom: 10px;
        }
        .justification-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        .justification-user {
            font-weight: 600;
        }
        .justification-date {
            font-size: 12px;
            color: var(--text-light);
        }
        .actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
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
        .screenshot-preview {
            max-width: 200px;
            max-height: 200px;
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            margin-top: 5px;
            cursor: pointer;
            transition: transform 0.2s ease;
        }
        .screenshot-preview:hover {
            transform: scale(1.05);
        }
        .image-modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            text-align: center;
        }
        .image-modal-content {
            max-width: 90%;
            max-height: 90%;
            margin: 5% auto;
            display: block;
            border-radius: var(--radius);
        }
        .image-modal-close {
            position: absolute;
            top: 15px;
            right: 35px;
            color: var(--light);
            font-size: 40px;
            font-weight: bold;
            cursor: pointer;
        }
        .image-modal-close:hover {
            color: var(--primary);
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
            .detail-grid {
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
                    <div class="sidebar-logo-text">Sales System</div>
                </div>
            </div>
            
            <div class="sidebar-user">
                <div class="sidebar-user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                <div class="sidebar-user-role"><?php echo htmlspecialchars($_SESSION['role']); ?> - <?php echo htmlspecialchars($_SESSION['department']); ?></div>
            </div>
            
            <nav class="sidebar-menu">
                <?php if (has_role('User')): ?>
                    <a href="index.php" class="sidebar-menu-item">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                    <a href="new_sale.php" class="sidebar-menu-item">
                        <i class="fas fa-plus-circle"></i> New Sale
                    </a>
                <?php elseif (has_role('Manager')): ?>
                    <a href="manager.php" class="sidebar-menu-item">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                <?php elseif (has_role('Head')): ?>
                    <a href="head.php" class="sidebar-menu-item">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                <?php elseif (has_role('Support')): ?>
                    <a href="support.php" class="sidebar-menu-item">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                <?php elseif (has_role('Finance')): ?>
                    <a href="finance.php" class="sidebar-menu-item">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                <?php endif; ?>
                <a href="../dashboard_user.php" class="sidebar-menu-item">
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
                        <div class="logo-text">Sales <span>Request</span></div>
                    </div>
                    <p class="tagline">View sales request details</p>
                    
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
                
                <div class="dashboard-container">
                    <div class="actions">
                        <a href="javascript:history.back()" class="btn btn-primary">
                            <i class="fas fa-arrow-left"></i> Back
                        </a>
                        
                        <?php if ($can_verify): ?>
                            <button class="btn btn-success" onclick="verifyRequest()">
                                <i class="fas fa-check"></i> Verify
                            </button>
                            <button class="btn btn-danger" onclick="rejectRequest()">
                                <i class="fas fa-times"></i> Reject
                            </button>
                        <?php endif; ?>
                        
                        <?php if ($can_paid): ?>
                            <button class="btn btn-success" onclick="markAsPaid()">
                                <i class="fas fa-check"></i> Mark as Paid
                            </button>
                        <?php endif; ?>
                        
                        <?php if ($can_edit): ?>
                            <a href="new_sale.php?edit_id=<?php echo $request_id; ?>" class="btn btn-warning">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                        <?php endif; ?>
                    </div>
                    
                    <h2 class="section-title">Request Details</h2>
                    
                    <div class="detail-grid">
                        <div class="detail-card">
                            <h3><i class="fas fa-info-circle"></i> Basic Information</h3>
                            <div class="detail-row">
                                <div class="detail-label">Reference #:</div>
                                <div class="detail-value"><?php echo htmlspecialchars($request['reference_number']); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Unique Code:</div>
                                <div class="detail-value"><?php echo htmlspecialchars($request['unique_code']); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Date:</div>
                                <div class="detail-value"><?php echo date('d M Y', strtotime($request['date'])); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Status:</div>
                                <div class="detail-value">
                                    <?php
                                    $status_class = '';
                                    switch ($request['status']) {
                                        case 'Pending':
                                            $status_class = 'status-pending';
                                            break;
                                        case 'Manager Verified':
                                            $status_class = 'status-manager-verified';
                                            break;
                                        case 'Head Paid':
                                            $status_class = 'status-head-paid';
                                            break;
                                        case 'Rejected':
                                            $status_class = 'status-rejected';
                                            break;
                                    }
                                    ?>
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <?php echo htmlspecialchars($request['status']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="detail-card">
                            <h3><i class="fas fa-user"></i> User Information</h3>
                            <div class="detail-row">
                                <div class="detail-label">Name:</div>
                                <div class="detail-value"><?php echo htmlspecialchars($request['user_name']); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Employee ID:</div>
                                <div class="detail-value"><?php echo htmlspecialchars($request['user_emp_id']); ?></div>
                            </div>
                            <?php if ($request['manager_name']): ?>
                                <div class="detail-row">
                                    <div class="detail-label">Manager:</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($request['manager_name']); ?> (<?php echo htmlspecialchars($request['manager_emp_id']); ?>)</div>
                                </div>
                            <?php endif; ?>
                            <?php if ($request['head_name']): ?>
                                <div class="detail-row">
                                    <div class="detail-label">Head:</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($request['head_name']); ?> (<?php echo htmlspecialchars($request['head_emp_id']); ?>)</div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="detail-card">
                            <h3><i class="fas fa-car"></i> Vehicle Information</h3>
                            <div class="detail-row">
                                <div class="detail-label">Customer Name:</div>
                                <!-- Name is already masked in PHP variable -->
                                <div class="detail-value"><?php echo htmlspecialchars($request['name']); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Mobile Number:</div>
                                <!-- Mobile is already masked in PHP variable -->
                                <div class="detail-value"><?php echo htmlspecialchars($request['mobile_no']); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Vehicle Number:</div>
                                <div class="detail-value"><?php echo htmlspecialchars($request['vehicle_number']); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Make:</div>
                                <div class="detail-value"><?php echo htmlspecialchars($request['make']); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Model:</div>
                                <div class="detail-value"><?php echo htmlspecialchars($request['model']); ?></div>
                            </div>
                           <div class="detail-row">
    <div class="detail-label">Register Year:</div>
    <!-- Date ko readable format me dikhaya aur naye calculated age variable ko lagaya -->
    <div class="detail-value"><?php echo date('d M Y', strtotime($request['register_year'])); ?> (<?php echo $displayAge; ?>)</div>
</div>
                            <div class="detail-row">
                                <div class="detail-label">Wheeler:</div>
                                <div class="detail-value"><?php echo htmlspecialchars($request['wheeler']); ?> Wheeler</div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Fuel Type:</div>
                                <div class="detail-value"><?php echo htmlspecialchars($request['fuel_type']); ?></div>
                            </div>
                        </div>
                        
                        <div class="detail-card">
                            <h3><i class="fas fa-file-invoice"></i> Policy Information</h3>
                            <div class="detail-row">
                                <div class="detail-label">Quotation Number:</div>
                                <div class="detail-value"><?php echo htmlspecialchars($request['quotation_number']); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">CCS Lead ID:</div>
                                <div class="detail-value"><?php echo htmlspecialchars($request['ccs_lead_id']); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Insurance Company:</div>
                                <div class="detail-value"><?php echo htmlspecialchars($request['insurance_company']); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Policy Type:</div>
                                <div class="detail-value"><?php echo htmlspecialchars($request['category']); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Deal Type:</div>
                                <div class="detail-value"><?php echo htmlspecialchars($request['deal_type']); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Multi/Single:</div>
                                <div class="detail-value"><?php echo htmlspecialchars($request['multi_single']); ?></div>
                            </div>
                        </div>
                        
                        <div class="detail-card">
                            <h3><i class="fas fa-rupee-sign"></i> Financial Information</h3>
                            <div class="detail-row">
                                <div class="detail-label">Premium:</div>
                                <div class="detail-value">₹<?php echo number_format($request['premium'], 2); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Premium (w/o GST):</div>
                                <div class="detail-value">₹<?php echo number_format($request['premium_wo_gst'], 2); ?></div>
                            </div>
                            <?php if ($request['tp_premium']): ?>
                                <div class="detail-row">
                                    <div class="detail-label">TP Premium:</div>
                                    <div class="detail-value">₹<?php echo number_format($request['tp_premium'], 2); ?></div>
                                </div>
                            <?php endif; ?>
                            <div class="detail-row">
                                <div class="detail-label">Payment Screenshot:</div>
                                <div class="detail-value">
                                    <?php echo htmlspecialchars($request['payment_screenshot_attached']); ?>
                                    <?php if ($request['payment_screenshot_attached'] === 'Yes' && !empty($request['payment_screenshot_url'])): ?>
                                        <img src="../<?php echo htmlspecialchars($request['payment_screenshot_url']); ?>" 
                                             alt="Payment Screenshot" 
                                             class="screenshot-preview" 
                                             onclick="openImageModal('../<?php echo htmlspecialchars($request['payment_screenshot_url']); ?>')">
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="detail-card">
                            <h3><i class="fas fa-map-marker-alt"></i> Location Information</h3>
                            <div class="detail-row">
                                <div class="detail-label">City:</div>
                                <div class="detail-value"><?php echo htmlspecialchars($request['city']); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">State:</div>
                                <div class="detail-value"><?php echo htmlspecialchars($request['state']); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">CC:</div>
                                <div class="detail-value"><?php echo htmlspecialchars($request['cc']); ?></div>
                            </div>
                        </div>
                        
                        <div class="detail-card">
                            <h3><i class="fas fa-users"></i> Team Information</h3>
                            <div class="detail-row">
                                <div class="detail-label">RM Name:</div>
                                <div class="detail-value"><?php echo htmlspecialchars($request['rm_name']); ?></div>
                            </div>
                            <?php if ($request['leader_name']): ?>
                                <div class="detail-row">
                                    <div class="detail-label">Leader Name:</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($request['leader_name']); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($request['remarks']): ?>
                            <div class="detail-card">
                                <h3><i class="fas fa-comment"></i> Remarks</h3>
                                <div class="detail-value"><?php echo nl2br(htmlspecialchars($request['remarks'])); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <h3 class="section-title">Request History Timeline</h3>
                    <div class="timeline">
                        <?php if (empty($timeline)): ?>
                            <div class="timeline-item">
                                <div class="timeline-content">
                                    <div class="timeline-description">No history available for this request.</div>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($timeline as $event): ?>
                                <div class="timeline-item <?php echo $event['type']; ?>">
                                    <div class="timeline-date"><?php echo date('d M Y H:i:s', strtotime($event['date'])); ?></div>
                                    <div class="timeline-content">
                                        <div class="timeline-title">
                                            <?php
                                            $icon = '';
                                            switch ($event['type']) {
                                                case 'created':
                                                    $icon = 'fas fa-plus-circle';
                                                    break;
                                                case 'verified':
                                                    $icon = 'fas fa-check-circle';
                                                    break;
                                                case 'paid':
                                                    $icon = 'fas fa-money-check-alt';
                                                    break;
                                                case 'rejected':
                                                    $icon = 'fas fa-times-circle';
                                                    break;
                                                case 'justification':
                                                    $icon = 'fas fa-comment-alt';
                                                    break;
                                                case 'resubmitted':
                                                    $icon = 'fas fa-redo';
                                                    break;
                                            }
                                            ?>
                                            <i class="<?php echo $icon; ?>"></i>
                                            <?php echo htmlspecialchars($event['title']); ?>
                                        </div>
                                        <div class="timeline-user">
                                            By: <?php echo htmlspecialchars($event['user']); ?> 
                                            <?php if (isset($event['user_id'])): ?>(<?php echo htmlspecialchars($event['user_id']); ?>)<?php endif; ?>
                                            <?php if (isset($event['role'])): ?>
                                                <span class="timeline-role <?php echo strtolower($event['role']); ?>">
                                                    <?php echo htmlspecialchars($event['role']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="timeline-description"><?php echo htmlspecialchars($event['description']); ?></div>
                                        <?php if (isset($event['comments']) && !empty($event['comments'])): ?>
                                            <div class="timeline-comments">
                                                <strong>Comments:</strong> <?php echo nl2br(htmlspecialchars($event['comments'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Image Modal for Screenshot Preview -->
    <div id="imageModal" class="image-modal">
        <span class="image-modal-close" onclick="closeImageModal()">&times;</span>
        <img class="image-modal-content" id="modalImage">
    </div>
    
    <!-- Verify Modal -->
    <div id="verifyModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">Verify Sales Request</h4>
                <span class="close" onclick="closeModal('verifyModal')">&times;</span>
            </div>
            <form method="POST" action="process_action.php">
                <input type="hidden" name="request_id" value="<?php echo $request_id; ?>">
                <input type="hidden" name="action" value="verify">
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
            <form method="POST" action="process_action.php">
                <input type="hidden" name="request_id" value="<?php echo $request_id; ?>">
                <input type="hidden" name="action" value="reject">
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
    
    <!-- Mark as Paid Modal -->
    <div id="paidModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">Mark Sales Request as Paid</h4>
                <span class="close" onclick="closeModal('paidModal')">&times;</span>
            </div>
            <form method="POST" action="process_action.php">
                <input type="hidden" name="request_id" value="<?php echo $request_id; ?>">
                <input type="hidden" name="action" value="paid">
                <div class="form-group">
                    <label for="paid_comments">Comments (Optional)</label>
                    <textarea class="form-control" id="paid_comments" name="comments" rows="3"></textarea>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-danger" onclick="closeModal('paidModal')">Cancel</button>
                    <button type="submit" class="btn btn-success">Mark as Paid</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function verifyRequest() {
            document.getElementById('verifyModal').style.display = 'block';
        }
        
        function rejectRequest() {
            document.getElementById('rejectModal').style.display = 'block';
        }
        
        function markAsPaid() {
            document.getElementById('paidModal').style.display = 'block';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // Image modal functions
        function openImageModal(imageSrc) {
            document.getElementById('modalImage').src = imageSrc;
            document.getElementById('imageModal').style.display = 'block';
        }
        
        function closeImageModal() {
            document.getElementById('imageModal').style.display = 'none';
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
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
            
            // Close image modal when clicking outside the image
            if (event.target === document.getElementById('imageModal')) {
                closeImageModal();
            }
        }
    </script>
</body>
</html>