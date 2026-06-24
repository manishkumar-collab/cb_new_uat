<?php
require_once 'config.php';

// Check if user is logged in and has admin role
if (!is_logged_in() || !has_role('Admin')) {
    show_notification('You do not have permission to access this page', 'error');
    redirect('login.php');
}

// --- Handle Validator Toggle Request ---
if (isset($_POST['action']) && $_POST['action'] == 'toggleValidator') {
    $status = $_POST['status']; // 'active' or 'inactive'
    $val = ($status == 'active') ? '1' : '0';
    
    // 1. Update Setting
    $sql = "INSERT INTO system_settings (setting_key, setting_value) VALUES ('validator_active', '$val')
            ON DUPLICATE KEY UPDATE setting_value = '$val'";
    mysqli_query($conn, $sql);

    // 2. Log History
    $log_sql = "INSERT INTO validator_settings_log (setting_value, changed_by) VALUES ('$val', ?)";
    $stmt = mysqli_prepare($conn, $log_sql);
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    
    if (mysqli_affected_rows($conn) > 0) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    exit;
}
// -----------------------------------------

// --- Handle Force Update Status ---
if (isset($_POST['action']) && $_POST['action'] == 'forceUpdateStatus') {
    $requestId = $_POST['request_id'];
    $newStatus = $_POST['new_status'];
    
    // Validate status to prevent arbitrary values
    $valid_statuses = ['Pending', 'Manager Approved', 'Head Approved', 'Validator Approved', 'Finance Approved', 'Rejected', 'Validator Rejected'];
    if (!in_array($newStatus, $valid_statuses)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status selected']);
        exit;
    }
    
    $update_sql = "UPDATE cashback_requests SET status = ? WHERE id = ?";
    $stmt = mysqli_prepare($conn, $update_sql);
    mysqli_stmt_bind_param($stmt, "si", $newStatus, $requestId);
    
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => true, 'message' => 'Request status forcefully updated to ' . $newStatus]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: Failed to update status']);
    }
    exit;
}
// ---------------------------------------

// --- Handle Bypass Manager Toggle ---
if (isset($_POST['action']) && $_POST['action'] == 'toggleBypassManager') {
    $userId = intval($_POST['user_id']);
    $bypassValue = intval($_POST['bypass_value']);
    
    // Validate user exists
    $check_sql = "SELECT id, full_name, bypass_manager FROM users WHERE id = ?";
    $check_stmt = mysqli_prepare($conn, $check_sql);
    mysqli_stmt_bind_param($check_stmt, "i", $userId);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    
    if ($check_result && mysqli_num_rows($check_result) > 0) {
        $user_data = mysqli_fetch_assoc($check_result);
        
        // Update bypass_manager
        $update_sql = "UPDATE users SET bypass_manager = ? WHERE id = ?";
        $update_stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($update_stmt, "ii", $bypassValue, $userId);
        
        if (mysqli_stmt_execute($update_stmt)) {
            // Log the change
            $log_sql = "INSERT INTO bypass_manager_log (target_user_id, bypass_value, changed_by) VALUES (?, ?, ?)";
            $log_stmt = mysqli_prepare($conn, $log_sql);
            mysqli_stmt_bind_param($log_stmt, "iii", $userId, $bypassValue, $_SESSION['user_id']);
            mysqli_stmt_execute($log_stmt);
            
            $status_text = $bypassValue ? 'BYPASSED' : 'NORMAL';
            echo json_encode([
                'success' => true, 
                'message' => "{$user_data['full_name']} manager bypass set to {$status_text}"
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: Failed to update']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'User not found']);
    }
    exit;
}
// ---------------------------------------

// Handle AJAX requests for updating user role and reporting structure
if (isset($_POST['action']) && $_POST['action'] == 'updateUserRoleAndReporting') {
    $userId = $_POST['user_id'];
    $newRole = $_POST['role'];
    $managerId = !empty($_POST['manager_id']) ? $_POST['manager_id'] : NULL;
    $headId = !empty($_POST['head_id']) ? $_POST['head_id'] : NULL;
    $validatorId = !empty($_POST['validator_id']) ? $_POST['validator_id'] : NULL;
    $financeId = !empty($_POST['finance_id']) ? $_POST['finance_id'] : NULL; // NEW
    
    // Update user role
    $update_role_sql = "UPDATE users SET role = ? WHERE id = ?";
    $role_stmt = mysqli_prepare($conn, $update_role_sql);
    mysqli_stmt_bind_param($role_stmt, "si", $newRole, $userId);
    $role_updated = mysqli_stmt_execute($role_stmt);
    
    // Update reporting structure (Added finance_id)
    $update_reporting_sql = "UPDATE users SET manager_id = ?, head_id = ?, validator_id = ?, finance_id = ? WHERE id = ?";
    $reporting_stmt = mysqli_prepare($conn, $update_reporting_sql);
    mysqli_stmt_bind_param($reporting_stmt, "iiiii", $managerId, $headId, $validatorId, $financeId, $userId);
    $reporting_updated = mysqli_stmt_execute($reporting_stmt);
    
    if ($role_updated && $reporting_updated) {
        echo json_encode(['success' => true, 'message' => 'User role and reporting structure updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update user']);
    }
    exit;
}

// Handle AJAX requests for updating cashback request
if (isset($_POST['action']) && $_POST['action'] == 'updateCashbackRequest') {
    $requestId = $_POST['request_id'];
    $fields = [
        'rm_emp_id', 'rm_name', 'department', 'customer_name', 'mobile_number',
        'month', 'year', 'insurance_company', 'policy_type', 'premium_with_gst',
        'without_gst', 'referral_amount', 'reason', 'utr_number'
    ];
    
    $update_parts = [];
    $params = [];
    $types = '';
    
    foreach ($fields as $field) {
        if (isset($_POST[$field])) {
            $update_parts[] = "$field = ?";
            $params[] = $_POST[$field];
            $types .= is_numeric($_POST[$field]) ? 'd' : 's';
        }
    }
    
    if (!empty($update_parts)) {
        $update_sql = "UPDATE cashback_requests SET " . implode(', ', $update_parts) . " WHERE id = ?";
        $params[] = $requestId;
        $types .= 'i';
        
        $stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        
        if (mysqli_stmt_execute($stmt)) {
            echo json_encode(['success' => true, 'message' => 'Request updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update request']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'No fields to update']);
    }
    exit;
}

// --- Ensure Log Tables Exist ---
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `validator_settings_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_value` varchar(10) NOT NULL,
  `changed_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `bypass_manager_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `target_user_id` int(11) NOT NULL,
  `bypass_value` tinyint(1) NOT NULL,
  `changed_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;");

// --- Fetch Current Validator Setting ---
 $validator_active = true; // Default to true
 $check_setting_sql = "SELECT setting_value FROM system_settings WHERE setting_key = 'validator_active'";
 $setting_result = mysqli_query($conn, $check_setting_sql);
if ($setting_result && mysqli_num_rows($setting_result) > 0) {
    $row = mysqli_fetch_assoc($setting_result);
    $validator_active = ($row['setting_value'] == '1');
}
// ------------------------------------------

// --- Fetch Change History ---
 $log_sql = "SELECT l.setting_value, u.full_name, l.created_at 
           FROM validator_settings_log l 
           JOIN users u ON l.changed_by = u.id 
           ORDER BY l.created_at DESC LIMIT 20";
 $log_result = mysqli_query($conn, $log_sql);
// ---------------------------------

// Get all users (Added finance mapping join)
 $users_sql = "SELECT u.*, 
            m.full_name AS manager_name, 
            h.full_name AS head_name,
            v.full_name AS validator_name,
            f.full_name AS finance_name
            FROM users u 
            LEFT JOIN users m ON u.manager_id = m.id 
            LEFT JOIN users h ON u.head_id = h.id
            LEFT JOIN users v ON u.validator_id = v.id
            LEFT JOIN users f ON u.finance_id = f.id
            ORDER BY u.role, u.full_name";
 $users_result = mysqli_query($conn, $users_sql);

// Get all cashback requests with manager information
 $requests_sql = "SELECT cr.*, u.full_name AS user_name, m.full_name AS manager_name, u.bypass_manager
                FROM cashback_requests cr 
                JOIN users u ON cr.user_id = u.id
                LEFT JOIN users m ON u.manager_id = m.id
                ORDER BY cr.created_at DESC";
 $requests_result = mysqli_query($conn, $requests_sql);

// Get all managers for dropdown
 $managers_sql = "SELECT id, full_name FROM users WHERE role = 'Manager'";
 $managers_result = mysqli_query($conn, $managers_sql);
 $managers = [];
while ($manager = mysqli_fetch_assoc($managers_result)) {
    $managers[] = $manager;
}

// Get all heads for dropdown
 $heads_sql = "SELECT id, full_name FROM users WHERE role = 'Head'";
 $heads_result = mysqli_query($conn, $heads_sql);
 $heads = [];
while ($head = mysqli_fetch_assoc($heads_result)) {
    $heads[] = $head;
}

// Get all validators for dropdown
 $validators_sql = "SELECT id, full_name FROM users WHERE role = 'Validator'";
 $validators_result = mysqli_query($conn, $validators_sql);
 $validators = [];
while ($validator = mysqli_fetch_assoc($validators_result)) {
    $validators[] = $validator;
}

// NEW: Get all finance for dropdown
 $finances_sql = "SELECT id, full_name FROM users WHERE role = 'Finance'";
 $finances_result = mysqli_query($conn, $finances_sql);
 $finances = [];
while ($finance = mysqli_fetch_assoc($finances_result)) {
    $finances[] = $finance;
}

// Get statistics
 $stats_sql = "SELECT 
            COUNT(*) AS total_users,
            SUM(CASE WHEN role = 'Admin' THEN 1 ELSE 0 END) AS admin_count,
            SUM(CASE WHEN role = 'Head' THEN 1 ELSE 0 END) AS head_count,
            SUM(CASE WHEN role = 'Manager' THEN 1 ELSE 0 END) AS manager_count,
            SUM(CASE WHEN role = 'User' THEN 1 ELSE 0 END) AS user_count,
            SUM(CASE WHEN role = 'Finance' THEN 1 ELSE 0 END) AS finance_count,
            SUM(CASE WHEN role = 'Validator' THEN 1 ELSE 0 END) AS validator_count,
            SUM(CASE WHEN bypass_manager = 1 THEN 1 ELSE 0 END) AS bypass_count
            FROM users";
 $stats_result = mysqli_query($conn, $stats_sql);
 $stats = mysqli_fetch_assoc($stats_result);

 $request_stats_sql = "SELECT 
                    COUNT(*) AS total_requests,
                    SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN status = 'Manager Approved' THEN 1 ELSE 0 END) AS manager_approved_count,
                    SUM(CASE WHEN status = 'Head Approved' THEN 1 ELSE 0 END) AS head_approved_count,
                    SUM(CASE WHEN status = 'Validator Approved' THEN 1 ELSE 0 END) AS validator_approved_count,
                    SUM(CASE WHEN status = 'Finance Approved' THEN 1 ELSE 0 END) AS finance_approved_count,
                    SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) AS rejected_count,
                    SUM(CASE WHEN status = 'Validator Rejected' THEN 1 ELSE 0 END) AS validator_rejected_count
                    FROM cashback_requests";
 $request_stats_result = mysqli_query($conn, $request_stats_sql);
 $request_stats = mysqli_fetch_assoc($request_stats_result);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Cashback System</title>
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
            --success: #38a169;
            --warning: #d69e2e;
            --bypass-color: #e53e3e;
        }
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            color: var(--text);
            line-height: 1.5;
            min-height: 100vh;
            padding: 15px;
            font-size: 14px;
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
        .logo-icon { font-size: 24px; color: var(--primary); }
        .logo-text { font-size: 22px; font-weight: 700; color: var(--dark); }
        .logo-text span { color: var(--primary); }
        .tagline { color: var(--text-light); font-size: 14px; margin-bottom: 5px; }
        .user-info {
            position: absolute;
            top: 15px;
            right: 15px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .user-details { display: flex; flex-direction: column; align-items: flex-end; }
        .username { font-weight: 600; color: var(--dark); }
        .user-role { font-size: 12px; color: var(--text-light); }
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
        .logout-btn:hover { background-color: #c53030; }
        .dashboard-container {
            background-color: var(--light);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            overflow: auto;
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
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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
        .stat-value { font-size: 24px; font-weight: bold; color: var(--primary); margin-bottom: 5px; }
        .stat-label { font-size: 14px; color: var(--text-light); }
        .stat-card.stat-bypass .stat-value { color: var(--bypass-color); }
        .table-container { overflow-x: auto; margin-top: 15px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 8px 12px; text-align: left; border-bottom: 1px solid var(--gray); }
        th { background-color: #f8fafc; font-weight: 600; color: var(--dark); white-space: nowrap; }
        tr:hover { background-color: #f8fafc; }
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
        .btn-primary { background-color: var(--primary); color: white; }
        .btn-primary:hover { background-color: var(--primary-dark); }
        .btn-info { background-color: #3182ce; color: white; }
        .btn-info:hover { background-color: #2c5aa0; }
        .btn-danger { background-color: #e53e3e; color: white; }
        .btn-danger:hover { background-color: #c53030; }
        .btn-success { background-color: #38a169; color: white; }
        .btn-success:hover { background-color: #2f855a; }
        .btn-warning { background-color: #dd6b20; color: white; }
        .btn-warning:hover { background-color: #c05621; }
        .btn-outline { background-color: transparent; border: 1px solid var(--gray); color: var(--text); }
        .btn-outline:hover { background-color: var(--gray); }
        .btn-sm { padding: 4px 8px; font-size: 12px; }
        .btn-bypass-on {
            background-color: #fff5f5;
            color: var(--bypass-color);
            border: 1px solid #feb2b2;
            font-weight: 600;
        }
        .btn-bypass-on:hover { background-color: #fed7d7; }
        .btn-bypass-off {
            background-color: #f7fafc;
            color: #a0aec0;
            border: 1px solid #e2e8f0;
        }
        .btn-bypass-off:hover { background-color: #edf2f7; }
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            white-space: nowrap;
        }
        .status-pending { background-color: #fff7e6; color: #d46b08; }
        .status-manager-approved { background-color: #e6f7ff; color: #096dd9; }
        .status-head-approved { background-color: #f9f0ff; color: #722ed1; }
        .status-validator-approved { background-color: #f0f5ff; color: #2f54eb; }
        .status-finance-approved { background-color: #f6ffed; color: #389e0d; }
        .status-rejected { background-color: #fff2f0; color: #cf1322; }
        .status-validator-rejected { background-color: #fff1f0; color: #a8071a; }
        .alert { padding: 12px; border-radius: var(--radius); margin-bottom: 20px; font-size: 14px; }
        .alert-success { background-color: #f6ffed; border-left: 4px solid #389e0d; color: #389e0d; }
        .alert-error { background-color: #fff2f0; border-left: 4px solid #cf1322; color: #cf1322; }
        .alert-warning { background-color: #fffbe6; border-left: 4px solid #d46b08; color: #d46b08; }
        .tabs { display: flex; border-bottom: 1px solid var(--gray); margin-bottom: 20px; }
        .tab { padding: 10px 20px; cursor: pointer; border-bottom: 2px solid transparent; font-weight: 500; }
        .tab.active { border-bottom-color: var(--primary); color: var(--primary); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
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
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .modal-title { font-size: 18px; color: var(--dark); }
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
        .no-data { text-align: center; padding: 30px; color: var(--text-light); }
        .no-data i { font-size: 36px; margin-bottom: 10px; color: var(--gray); }
        .search-container { display: flex; margin-bottom: 15px; gap: 10px; }
        .search-input { flex: 1; padding: 8px 10px; border: 1px solid var(--gray); border-radius: var(--radius); font-size: 14px; }
        .filter-container { display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap; }
        .filter-select { padding: 8px 10px; border: 1px solid var(--gray); border-radius: var(--radius); font-size: 14px; }
        .inline-edit {
            background-color: transparent;
            border: 1px solid transparent;
            padding: 4px 8px;
            border-radius: var(--radius);
            cursor: pointer;
            transition: all 0.2s ease;
            width: 100%;
        }
        .inline-edit:hover { background-color: #f8fafc; border-color: var(--gray); }
        .inline-edit:focus { outline: none; border-color: var(--primary); background-color: var(--light); }
        .notification-badge {
            position: absolute;
            top: -5px; right: -5px;
            background-color: #e53e3e;
            color: white;
            border-radius: 50%;
            width: 20px; height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: bold;
        }
        .notification-container { position: relative; margin-right: 15px; }
        .notification-dropdown {
            position: absolute;
            top: 100%; right: 0;
            background-color: var(--light);
            border-radius: var(--radius);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.15);
            width: 300px;
            max-height: 400px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }
        .notification-item { padding: 10px; border-bottom: 1px solid var(--gray); }
        .notification-item:last-child { border-bottom: none; }
        .notification-title { font-weight: 600; margin-bottom: 5px; }
        .notification-message { font-size: 13px; color: var(--text-light); }
        .notification-time { font-size: 11px; color: var(--text-light); margin-top: 5px; }
        .action-buttons { display: flex; gap: 5px; flex-wrap: wrap; }
        .update-row { display: flex; gap: 5px; align-items: center; flex-wrap: wrap; }
        .export-container { display: flex; justify-content: flex-end; margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--gray); }

        /* Config Card */
        .config-card {
            background: #fff;
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            padding: 15px 20px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--shadow);
        }
        .config-info h4 { font-size: 16px; color: var(--dark); margin-bottom: 5px; }
        .config-info p { font-size: 13px; color: var(--text-light); margin: 0; }
        .config-controls { display: flex; align-items: center; gap: 12px; }
        .status-text { font-weight: 700; font-size: 14px; min-width: 70px; text-align: right; }
        .status-active { color: var(--success); }
        .status-inactive { color: #e53e3e; }

        /* Toggle Switch */
        .switch { position: relative; display: inline-block; width: 50px; height: 24px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 16px; width: 16px;
            left: 4px; bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .slider { background-color: var(--success); }
        input:checked + .slider:before { transform: translateX(26px); }

        /* Bypass Toggle - Small inline version */
        .switch-sm { position: relative; display: inline-block; width: 38px; height: 20px; }
        .switch-sm input { opacity: 0; width: 0; height: 0; }
        .slider-sm {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #cbd5e0;
            transition: .3s;
            border-radius: 20px;
        }
        .slider-sm:before {
            position: absolute;
            content: "";
            height: 14px; width: 14px;
            left: 3px; bottom: 3px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }
        .switch-sm input:checked + .slider-sm { background-color: var(--bypass-color); }
        .switch-sm input:checked + .slider-sm:before { transform: translateX(18px); }

        /* Workflow */
        .workflow-wrapper {
            background: #f8fafc;
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 20px;
        }
        .workflow-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            padding: 10px 0;
            margin-bottom: 15px;
        }
        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            position: relative;
            z-index: 2;
            transition: all 0.3s ease;
        }
        .step-box {
            width: 100px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            background: #e2e8f0;
            color: #718096;
            border: 2px solid transparent;
            transition: all 0.3s;
        }
        .step.active .step-box { background: #dbeafe; border-color: var(--primary); color: var(--primary); }
        .step.disabled .step-box { opacity: 0.4; background: #f3f4f6; border: 2px dashed #cbd5e0; }
        .connector {
            flex: 1;
            height: 2px;
            background: #cbd5e0;
            margin: 0 5px;
            position: relative;
            top: -8px;
        }
        .connector::after {
            content: '';
            position: absolute;
            right: -2px; top: -4px;
            width: 0; height: 0;
            border-top: 5px solid transparent;
            border-bottom: 5px solid transparent;
            border-left: 8px solid #cbd5e0;
        }
        .bypass-connector {
            position: absolute;
            height: 2px;
            background: var(--success);
            top: 50%;
            left: 0;
            width: 0;
            z-index: 1;
            transition: width 0.6s ease-in-out;
            opacity: 0;
        }
        .bypass-connector::after {
            content: '';
            position: absolute;
            right: -2px; top: -4px;
            width: 0; height: 0;
            border-top: 5px solid transparent;
            border-bottom: 5px solid transparent;
            border-left: 8px solid var(--success);
        }
        body.validator-inactive .bypass-connector { width: 100%; opacity: 1; }
        body.validator-inactive .step-validator { opacity: 0.3; transform: scale(0.9); }
        body.validator-inactive .connector-validator { opacity: 0.1; }

        /* Bypass row highlight */
        tr.bypass-row { background-color: #fff5f5 !important; }
        tr.bypass-row:hover { background-color: #fed7d7 !important; }

        /* Bypass badge in requests table */
        .bypass-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 700;
            background: #fff5f5;
            color: var(--bypass-color);
            border: 1px solid #feb2b2;
            margin-left: 4px;
            vertical-align: middle;
        }

        /* Accordion */
        .log-section { border-top: 1px solid var(--gray); padding-top: 10px; }
        .accordion-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 12px;
            background-color: #e2e8f0;
            border-radius: var(--radius);
            cursor: pointer;
            transition: background 0.2s;
        }
        .accordion-header:hover { background-color: #cbd5e0; }
        .accordion-title { font-size: 14px; font-weight: 600; color: var(--dark); }
        .accordion-icon { transition: transform 0.3s ease; color: var(--text-light); }
        .accordion-header.active .accordion-icon { transform: rotate(180deg); }
        .accordion-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s ease-out;
            background: #fff;
            border: 1px solid var(--gray);
            border-top: none;
            border-radius: 0 0 var(--radius) var(--radius);
        }
        .accordion-content.open {
            max-height: 300px;
            overflow-y: auto;
            border-top: 1px solid var(--gray);
            margin-top: 5px;
        }
        .log-table { width: 100%; font-size: 12px; }
        .log-table th { background: #f1f5f9; padding: 8px; position: sticky; top: 0; z-index: 1; }
        .log-table td { padding: 8px; border-bottom: 1px solid #f1f5f9; }
        .log-active { color: var(--success); font-weight: 600; }
        .log-inactive { color: #e53e3e; font-weight: 600; }

        /* Force Status */
        .force-status-box {
            background: #fffaf0;
            border: 1px solid #fbd38d;
            border-radius: var(--radius);
            padding: 15px;
            margin-bottom: 20px;
        }
        .force-status-box h4 {
            color: #c05621;
            margin-bottom: 12px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Bypass info tooltip */
        .bypass-cell {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .bypass-label {
            font-size: 10px;
            color: var(--bypass-color);
            font-weight: 700;
            opacity: 0;
            transition: opacity 0.2s;
        }
        tr.bypass-row .bypass-label { opacity: 1; }

        /* Responsive */
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .dashboard-container { padding: 15px; }
            .config-card { flex-direction: column; text-align: center; gap: 15px; }
            .config-controls { width: 100%; justify-content: center; }
            .status-text { text-align: left; }
            .workflow-container { flex-direction: column; gap: 15px; }
            .connector { display: none; }
            .step-box { width: 100%; }
            .bypass-connector { display: none; }
            .tabs { flex-direction: column; }
            .tab { border-bottom: 1px solid var(--gray); border-right: none; }
            .table-container { font-size: 12px; }
            th, td { padding: 6px 8px; }
            .filter-container { flex-direction: column; }
            .filter-select { width: 100%; }
            .update-row { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body class="<?php echo $validator_active ? '' : 'validator-inactive'; ?>">
    <div class="container">
        <header>
            <div class="logo">
                <i class="fas fa-cogs logo-icon"></i>
                <div class="logo-text">Admin <span>Dashboard</span></div>
            </div>
            <p class="tagline">System administration and management</p>
            
            <div class="user-info">
                <div class="notification-container">
                    <button class="btn btn-outline" onclick="toggleNotifications()">
                        <i class="fas fa-bell"></i>
                        <?php if ($request_stats['pending_count'] > 0): ?>
                            <span class="notification-badge"><?php echo $request_stats['pending_count']; ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="notification-dropdown" id="notificationDropdown">
                        <?php if ($request_stats['pending_count'] > 0): ?>
                            <div class="notification-item">
                                <div class="notification-title">Pending Requests</div>
                                <div class="notification-message">You have <?php echo $request_stats['pending_count']; ?> pending cashback requests that need your attention.</div>
                                <div class="notification-time">Just now</div>
                            </div>
                        <?php else: ?>
                            <div class="notification-item">
                                <div class="notification-title">No Pending Requests</div>
                                <div class="notification-message">All requests have been processed.</div>
                                <div class="notification-time">Just now</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
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
            <h2 class="section-title">System Overview</h2>
            
            <!-- Validator Configuration Card -->
            <div class="config-card">
                <div class="config-info">
                    <h4><i class="fas fa-shield-alt"></i> Validator Configuration</h4>
                    <p>If inactive, requests bypass the Validator step and go directly to Finance.</p>
                </div>
                <div class="config-controls">
                    <span id="validatorStatusText" class="status-text <?php echo $validator_active ? 'status-active' : 'status-inactive'; ?>">
                        <?php echo $validator_active ? 'Active' : 'Bypassed'; ?>
                    </span>
                    <label class="switch">
                        <input type="checkbox" id="validatorToggle" <?php echo $validator_active ? 'checked' : ''; ?>>
                        <span class="slider"></span>
                    </label>
                </div>
            </div>

            <!-- Current Request Flow & History -->
            <div class="workflow-wrapper">
                <h3 class="section-title" style="border:none; margin-bottom:10px;">Current Request Flow</h3>
                <div class="workflow-container">
                    <div class="step active"><div class="step-box">User / RM</div></div>
                    <div class="connector"></div>
                    <div class="step active"><div class="step-box">Manager</div></div>
                    <div class="connector"></div>
                    <div class="step active"><div class="step-box">Head</div></div>
                    <div class="connector connector-validator"></div>
                    <div class="step active step-validator"><div class="step-box">Validator</div></div>
                    <div class="connector"></div>
                    <div class="step active"><div class="step-box">Finance</div></div>
                    <div class="bypass-connector"></div>
                </div>
                
                <div class="log-section">
                    <div class="accordion-header" onclick="toggleHistory()">
                        <div class="accordion-title">Validator Change History</div>
                        <i class="fas fa-chevron-down accordion-icon"></i>
                    </div>
                    <div class="accordion-content" id="historyContent">
                        <table class="log-table">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Changed By</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (mysqli_num_rows($log_result) > 0): ?>
                                    <?php while ($log = mysqli_fetch_assoc($log_result)): ?>
                                        <tr>
                                            <td><?php echo date('d M H:i', strtotime($log['created_at'])); ?></td>
                                            <td><?php echo htmlspecialchars($log['full_name']); ?></td>
                                            <td class="<?php echo ($log['setting_value'] == '1') ? 'log-active' : 'log-inactive'; ?>">
                                                <?php echo ($log['setting_value'] == '1') ? 'Activated' : 'Bypassed'; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="3" style="text-align:center; color:#999;">No history found</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['total_users']; ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['manager_count']; ?></div>
                    <div class="stat-label">Managers</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['head_count']; ?></div>
                    <div class="stat-label">Heads</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['validator_count']; ?></div>
                    <div class="stat-label">Validators</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['finance_count']; ?></div>
                    <div class="stat-label">Finance</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['user_count']; ?></div>
                    <div class="stat-label">Regular Users</div>
                </div>
                <div class="stat-card stat-bypass">
                    <div class="stat-value"><?php echo $stats['bypass_count']; ?></div>
                    <div class="stat-label">Manager Bypass ON</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $request_stats['total_requests']; ?></div>
                    <div class="stat-label">Total Requests</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $request_stats['pending_count']; ?></div>
                    <div class="stat-label">Pending Requests</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $request_stats['finance_approved_count']; ?></div>
                    <div class="stat-label">Approved Requests</div>
                </div>
            </div>
            
            <div class="tabs">
                <div class="tab active" onclick="openTab(event, 'users-tab')">Users Management</div>
                <div class="tab" onclick="openTab(event, 'requests-tab')">Cashback Requests</div>
            </div>
            
            <div id="users-tab" class="tab-content active">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3>Users Management</h3>
                    <div class="action-buttons">
                        <button class="btn btn-success" onclick="exportUsers()">
                            <i class="fas fa-download"></i> Export
                        </button>
                        <button class="btn btn-primary" onclick="document.getElementById('addUserModal').style.display='flex'">
                            <i class="fas fa-plus"></i> Add User
                        </button>
                    </div>
                </div>
                
                <div class="search-container">
                    <input type="text" id="userSearch" class="search-input" placeholder="Search users by name, email, or employee ID...">
                    <button class="btn btn-info" onclick="searchUsers()">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <button class="btn btn-outline" onclick="resetUserSearch()">
                        <i class="fas fa-times"></i> Reset
                    </button>
                </div>
                
                <div class="filter-container">
                    <select id="roleFilter" class="filter-select" onchange="filterUsers()">
                        <option value="">All Roles</option>
                        <option value="Admin">Admin</option>
                        <option value="Head">Head</option>
                        <option value="Manager">Manager</option>
                        <option value="User">User</option>
                        <option value="Finance">Finance</option>
                        <option value="Validator">Validator</option>
                    </select>
                    <select id="departmentFilter" class="filter-select" onchange="filterUsers()">
                        <option value="">All Departments</option>
                        <?php
                        $departments_sql = "SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department";
                        $departments_result = mysqli_query($conn, $departments_sql);
                        while ($department = mysqli_fetch_assoc($departments_result)) {
                            echo "<option value='{$department['department']}'>{$department['department']}</option>";
                        }
                        ?>
                    </select>
                    <select id="bypassFilter" class="filter-select" onchange="filterUsers()">
                        <option value="">Bypass Manager</option>
                        <option value="1">Bypass ON</option>
                        <option value="0">Bypass OFF</option>
                    </select>
                </div>
                
                <?php if (mysqli_num_rows($users_result) > 0): ?>
                    <div class="table-container">
                        <table id="usersTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>EMP ID</th>
                                    <th>Full Name</th>
                                    <th>Department</th>
                                    <th>Role</th>
                                    <th>Manager</th>
                                    <th>Head</th>
                                    <th>Validator</th>
                                    <th>Finance</th> <!-- NEW -->
                                    <th>Bypass Mgr</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($user = mysqli_fetch_assoc($users_result)): ?>
                                    <tr data-user-id="<?php echo $user['id']; ?>" class="<?php echo ($user['bypass_manager'] == 1) ? 'bypass-row' : ''; ?>">
                                        <td><?php echo $user['id']; ?></td>
                                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                                        <td><?php echo htmlspecialchars($user['emp_id']); ?></td>
                                        <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($user['department']); ?></td>
                                        <td>
                                            <select class="inline-edit" id="role-<?php echo $user['id']; ?>" data-field="role">
                                                <option value="Admin" <?php echo $user['role'] == 'Admin' ? 'selected' : ''; ?>>Admin</option>
                                                <option value="Head" <?php echo $user['role'] == 'Head' ? 'selected' : ''; ?>>Head</option>
                                                <option value="Manager" <?php echo $user['role'] == 'Manager' ? 'selected' : ''; ?>>Manager</option>
                                                <option value="User" <?php echo $user['role'] == 'User' ? 'selected' : ''; ?>>User</option>
                                                <option value="Finance" <?php echo $user['role'] == 'Finance' ? 'selected' : ''; ?>>Finance</option>
                                                <option value="Validator" <?php echo $user['role'] == 'Validator' ? 'selected' : ''; ?>>Validator</option>
                                            </select>
                                        </td>
                                        <td>
                                            <select class="inline-edit" id="manager-<?php echo $user['id']; ?>" data-field="manager_id">
                                                <option value="">None</option>
                                                <?php foreach ($managers as $manager): ?>
                                                    <option value="<?php echo $manager['id']; ?>" <?php echo $user['manager_id'] == $manager['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($manager['full_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select class="inline-edit" id="head-<?php echo $user['id']; ?>" data-field="head_id">
                                                <option value="">None</option>
                                                <?php foreach ($heads as $head): ?>
                                                    <option value="<?php echo $head['id']; ?>" <?php echo $user['head_id'] == $head['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($head['full_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select class="inline-edit" id="validator-<?php echo $user['id']; ?>" data-field="validator_id">
                                                <option value="">None</option>
                                                <?php foreach ($validators as $validator): ?>
                                                    <option value="<?php echo $validator['id']; ?>" <?php echo $user['validator_id'] == $validator['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($validator['full_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <!-- NEW: Finance Dropdown -->
                                        <td>
                                            <select class="inline-edit" id="finance-<?php echo $user['id']; ?>" data-field="finance_id">
                                                <option value="">None</option>
                                                <?php foreach ($finances as $finance): ?>
                                                    <option value="<?php echo $finance['id']; ?>" <?php echo $user['finance_id'] == $finance['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($finance['full_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <div class="bypass-cell">
                                                <label class="switch-sm" title="<?php echo ($user['bypass_manager'] == 1) ? 'Manager Bypass: ON - Requests go directly to Head' : 'Manager Bypass: OFF - Normal flow'; ?>">
                                                    <input type="checkbox" 
                                                           id="bypass-<?php echo $user['id']; ?>" 
                                                           <?php echo ($user['bypass_manager'] == 1) ? 'checked' : ''; ?>
                                                           onchange="toggleBypassManager(<?php echo $user['id']; ?>, this)">
                                                    <span class="slider-sm"></span>
                                                </label>
                                                <span class="bypass-label">BYPASS</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="update-row">
                                                <button class="btn btn-success btn-sm" onclick="updateUserRoleAndReporting(<?php echo $user['id']; ?>)">
                                                    <i class="fas fa-check"></i> Update
                                                </button>
                                                <button class="btn btn-info btn-sm" onclick="editUser(<?php echo $user['id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-danger btn-sm" onclick="confirmDelete(<?php echo $user['id']; ?>)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-users"></i>
                        <p>No users found</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <div id="requests-tab" class="tab-content">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3>Cashback Requests</h3>
                </div>
                
                <div class="search-container">
                    <input type="text" id="requestSearch" class="search-input" placeholder="Search requests by reference number, customer name, or user...">
                    <button class="btn btn-info" onclick="searchRequests()">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <button class="btn btn-outline" onclick="resetRequestSearch()">
                        <i class="fas fa-times"></i> Reset
                    </button>
                </div>
                
                <div class="filter-container">
                    <select id="statusFilter" class="filter-select" onchange="filterRequests()">
                        <option value="">All Statuses</option>
                        <option value="Pending">Pending</option>
                        <option value="Manager Approved">Manager Approved</option>
                        <option value="Head Approved">Head Approved</option>
                        <option value="Validator Approved">Validator Approved</option>
                        <option value="Finance Approved">Finance Approved</option>
                        <option value="Rejected">Rejected</option>
                        <option value="Validator Rejected">Validator Rejected</option>
                    </select>
                    <select id="monthFilter" class="filter-select" onchange="filterRequests()">
                        <option value="">All Months</option>
                        <option value="January">January</option>
                        <option value="February">February</option>
                        <option value="March">March</option>
                        <option value="April">April</option>
                        <option value="May">May</option>
                        <option value="June">June</option>
                        <option value="July">July</option>
                        <option value="August">August</option>
                        <option value="September">September</option>
                        <option value="October">October</option>
                        <option value="November">November</option>
                        <option value="December">December</option>
                    </select>
                    <select id="yearFilter" class="filter-select" onchange="filterRequests()">
                        <option value="">All Years</option>
                        <?php
                        $currentYear = date('Y');
                        for ($year = $currentYear; $year >= $currentYear - 5; $year--) {
                            echo "<option value='$year'>$year</option>";
                        }
                        ?>
                    </select>
                    <select id="bypassRequestFilter" class="filter-select" onchange="filterRequests()">
                        <option value="">Bypass Status</option>
                        <option value="bypass">Bypassed Manager</option>
                        <option value="normal">Normal Flow</option>
                    </select>
                </div>
                
                <?php if (mysqli_num_rows($requests_result) > 0): ?>
                    <div class="table-container">
                        <table id="requestsTable">
                            <thead>
                                <tr>
                                    <th>Reference #</th>
                                    <th>User</th>
                                    <th>Reporting Manager</th>
                                    <th>Customer</th>
                                    <th>Department</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                mysqli_data_seek($requests_result, 0);
                                while ($request = mysqli_fetch_assoc($requests_result)): 
                                ?>
                                    <tr data-request-id="<?php echo $request['id']; ?>" data-bypass="<?php echo $request['bypass_manager']; ?>" class="<?php echo ($request['bypass_manager'] == 1) ? 'bypass-row' : ''; ?>">
                                        <td>
                                            <?php echo htmlspecialchars($request['reference_number']); ?>
                                            <?php if ($request['bypass_manager'] == 1): ?>
                                                <span class="bypass-badge">BYPASS</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($request['user_name']); ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($request['manager_name']); ?>
                                            <?php if ($request['bypass_manager'] == 1): ?>
                                                <span class="bypass-badge">SKIPPED</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($request['customer_name']); ?></td>
                                        <td><?php echo htmlspecialchars($request['department']); ?></td>
                                        <td>₹<?php echo number_format($request['referral_amount'], 2); ?></td>
                                        <td>
                                            <?php
                                            $status_class = '';
                                            switch ($request['status']) {
                                                case 'Pending': $status_class = 'status-pending'; break;
                                                case 'Manager Approved': $status_class = 'status-manager-approved'; break;
                                                case 'Head Approved': $status_class = 'status-head-approved'; break;
                                                case 'Validator Approved': $status_class = 'status-validator-approved'; break;
                                                case 'Finance Approved': $status_class = 'status-finance-approved'; break;
                                                case 'Rejected': $status_class = 'status-rejected'; break;
                                                case 'Validator Rejected': $status_class = 'status-validator-rejected'; break;
                                            }
                                            ?>
                                            <span class="status-badge <?php echo $status_class; ?>">
                                                <?php echo htmlspecialchars($request['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('d M Y', strtotime($request['created_at'])); ?></td>
                                        <td>
                                            <button class="btn btn-info btn-sm" onclick="viewRequest(<?php echo $request['id']; ?>)">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                            <button class="btn btn-primary btn-sm" onclick="editRequest(<?php echo $request['id']; ?>)">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="export-container">
                        <button class="btn btn-success" onclick="exportRequests()">
                            <i class="fas fa-download"></i> Export Cashback Requests
                        </button>
                    </div>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-file-invoice"></i>
                        <p>No cashback requests found</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Add User Modal -->
    <div id="addUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Add New User</h3>
                <button class="modal-close" onclick="document.getElementById('addUserModal').style.display='none'">&times;</button>
            </div>
            <form action="add_user.php" method="post">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="emp_id">Employee ID</label>
                        <input type="text" id="emp_id" name="emp_id" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="full_name">Full Name</label>
                        <input type="text" id="full_name" name="full_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="department">Department</label>
                        <input type="text" id="department" name="department" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="role">Role</label>
                        <select id="role" name="role" class="form-control" required>
                            <option value="">Select Role</option>
                            <option value="Admin">Admin</option>
                            <option value="Head">Head</option>
                            <option value="Manager">Manager</option>
                            <option value="User">User</option>
                            <option value="Finance">Finance</option>
                            <option value="Validator">Validator</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="manager_id">Manager</label>
                        <select id="manager_id" name="manager_id" class="form-control">
                            <option value="">None</option>
                            <?php foreach ($managers as $manager): ?>
                                <option value="<?php echo $manager['id']; ?>"><?php echo htmlspecialchars($manager['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="head_id">Head</label>
                        <select id="head_id" name="head_id" class="form-control">
                            <option value="">None</option>
                            <?php foreach ($heads as $head): ?>
                                <option value="<?php echo $head['id']; ?>"><?php echo htmlspecialchars($head['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="validator_id">Validator</label>
                        <select id="validator_id" name="validator_id" class="form-control">
                            <option value="">None</option>
                            <?php foreach ($validators as $validator): ?>
                                <option value="<?php echo $validator['id']; ?>"><?php echo htmlspecialchars($validator['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- NEW: Finance Dropdown -->
                    <div class="form-group">
                        <label for="finance_id">Finance</label>
                        <select id="finance_id" name="finance_id" class="form-control">
                            <option value="">None</option>
                            <?php foreach ($finances as $finance): ?>
                                <option value="<?php echo $finance['id']; ?>"><?php echo htmlspecialchars($finance['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="document.getElementById('addUserModal').style.display='none'">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add User</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Edit Request Modal -->
    <div id="editRequestModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Edit Cashback Request</h3>
                <button class="modal-close" onclick="document.getElementById('editRequestModal').style.display='none'">&times;</button>
            </div>
            <form id="editRequestForm">
                <div class="modal-body">
                    <input type="hidden" id="editRequestId" name="request_id">
                    
                    <!-- FORCE UPDATE STATUS SECTION -->
                    <div class="force-status-box">
                        <h4><i class="fas fa-bolt"></i> Force Update Status (Admin Override)</h4>
                        <div class="form-group" style="margin-bottom: 10px;">
                            <label>Current Status</label>
                            <input type="text" id="editCurrentStatus" class="form-control" readonly style="background: #edf2f7; cursor: not-allowed;">
                        </div>
                        <div class="form-group" style="margin-bottom: 10px;">
                            <label for="forceNewStatus">Move Forward To</label>
                            <select id="forceNewStatus" class="form-control">
                                <option value="">-- Select Status to Force Move --</option>
                            </select>
                        </div>
                        <button type="button" class="btn btn-warning" onclick="forceAdvanceRequest()" style="width: 100%;">
                            <i class="fas fa-bolt"></i> Force Advance Request
                        </button>
                    </div>
                    <!-- END FORCE UPDATE STATUS SECTION -->

                    <div class="form-group">
                        <label for="editRmEmpId">RM Employee ID</label>
                        <input type="text" id="editRmEmpId" name="rm_emp_id" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="editRmName">Reporting Manager Name</label>
                        <input type="text" id="editRmName" name="rm_name" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="editDepartment">Department</label>
                        <input type="text" id="editDepartment" name="department" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="editCustomerName">Customer Name</label>
                        <input type="text" id="editCustomerName" name="customer_name" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="editMobileNumber">Mobile Number</label>
                        <input type="text" id="editMobileNumber" name="mobile_number" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="editMonth">Month</label>
                        <select id="editMonth" name="month" class="form-control">
                            <option value="January">January</option>
                            <option value="February">February</option>
                            <option value="March">March</option>
                            <option value="April">April</option>
                            <option value="May">May</option>
                            <option value="June">June</option>
                            <option value="July">July</option>
                            <option value="August">August</option>
                            <option value="September">September</option>
                            <option value="October">October</option>
                            <option value="November">November</option>
                            <option value="December">December</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="editYear">Year</label>
                        <input type="text" id="editYear" name="year" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="editInsuranceCompany">Insurance Company</label>
                        <input type="text" id="editInsuranceCompany" name="insurance_company" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="editPolicyType">Policy Type</label>
                        <input type="text" id="editPolicyType" name="policy_type" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="editPremiumWithGst">Premium with GST</label>
                        <input type="number" id="editPremiumWithGst" name="premium_with_gst" class="form-control" step="0.01">
                    </div>
                    <div class="form-group">
                        <label for="editWithoutGst">Without GST</label>
                        <input type="number" id="editWithoutGst" name="without_gst" class="form-control" step="0.01">
                    </div>
                    <div class="form-group">
                        <label for="editReferralAmount">Referral Amount</label>
                        <input type="number" id="editReferralAmount" name="referral_amount" class="form-control" step="0.01">
                    </div>
                    <div class="form-group">
                        <label for="editReason">Reason</label>
                        <textarea id="editReason" name="reason" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="editUtrNumber">UTR Number</label>
                        <input type="text" id="editUtrNumber" name="utr_number" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="document.getElementById('editRequestModal').style.display='none'">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Request</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Accordion Logic
        function toggleHistory() {
            const header = document.querySelector('.accordion-header');
            const content = document.getElementById('historyContent');
            header.classList.toggle('active');
            if (content.style.maxHeight) {
                content.style.maxHeight = null;
                content.classList.remove('open');
            } else {
                content.style.maxHeight = content.scrollHeight + "px";
                content.classList.add('open');
            }
        }

        // --- BYPASS MANAGER TOGGLE ---
        function toggleBypassManager(userId, checkbox) {
            const newValue = checkbox.checked ? 1 : 0;
            const row = checkbox.closest('tr');
            const label = row.querySelector('.bypass-label');
            
            // Optimistic UI update
            if (newValue === 1) {
                row.classList.add('bypass-row');
                if (label) label.style.opacity = '1';
            } else {
                row.classList.remove('bypass-row');
                if (label) label.style.opacity = '0';
            }
            
            // Update tooltip
            checkbox.closest('label').title = newValue === 1 
                ? 'Manager Bypass: ON - Requests go directly to Head' 
                : 'Manager Bypass: OFF - Normal flow';
            
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=toggleBypassManager&user_id=${userId}&bypass_value=${newValue}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, newValue ? 'warning' : 'success');
                } else {
                    // Revert on failure
                    checkbox.checked = !checkbox.checked;
                    if (checkbox.checked) {
                        row.classList.add('bypass-row');
                        if (label) label.style.opacity = '1';
                    } else {
                        row.classList.remove('bypass-row');
                        if (label) label.style.opacity = '0';
                    }
                    showNotification(data.message || 'Failed to update bypass setting', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                checkbox.checked = !checkbox.checked;
                if (checkbox.checked) {
                    row.classList.add('bypass-row');
                    if (label) label.style.opacity = '1';
                } else {
                    row.classList.remove('bypass-row');
                    if (label) label.style.opacity = '0';
                }
                showNotification('Network error occurred', 'error');
            });
        }

        // Validator Toggle Logic
        document.addEventListener('DOMContentLoaded', function() {
            const toggle = document.getElementById('validatorToggle');
            const statusText = document.getElementById('validatorStatusText');
            const body = document.body;

            if(toggle) {
                toggle.addEventListener('change', function() {
                    const newStatus = this.checked ? 'active' : 'inactive';
                    
                    if(newStatus === 'inactive') {
                        body.classList.add('validator-inactive');
                        statusText.textContent = 'Bypassed';
                        statusText.className = 'status-text status-inactive';
                        showNotification('Validator Step Bypassed', 'warning');
                    } else {
                        body.classList.remove('validator-inactive');
                        statusText.textContent = 'Active';
                        statusText.className = 'status-text status-active';
                        showNotification('Validator Step Activated', 'success');
                    }
                    
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=toggleValidator&status=${newStatus}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            this.checked = !this.checked;
                            if(this.checked) {
                                body.classList.remove('validator-inactive');
                                statusText.textContent = 'Active';
                                statusText.className = 'status-text status-active';
                            } else {
                                body.classList.add('validator-inactive');
                                statusText.textContent = 'Bypassed';
                                statusText.className = 'status-text status-inactive';
                            }
                            showNotification('Failed to update setting', 'error');
                        } else {
                            setTimeout(() => location.reload(), 1500);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        this.checked = !this.checked;
                        showNotification('An error occurred', 'error');
                    });
                });
            }
        });

        // Tab functionality
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
        
        // User management functions
        function editUser(userId) {
            window.location.href = 'edit_user.php?id=' + userId;
        }
        
        function confirmDelete(userId) {
            if (confirm('Are you sure you want to delete this user?')) {
                window.location.href = 'delete_user.php?id=' + userId;
            }
        }
        
        function updateUserRoleAndReporting(userId) {
            const roleSelect = document.getElementById('role-' + userId);
            const managerSelect = document.getElementById('manager-' + userId);
            const headSelect = document.getElementById('head-' + userId);
            const validatorSelect = document.getElementById('validator-' + userId);
            const financeSelect = document.getElementById('finance-' + userId); // NEW
            
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=updateUserRoleAndReporting&user_id=${userId}&role=${roleSelect.value}&manager_id=${managerSelect.value}&head_id=${headSelect.value}&validator_id=${validatorSelect.value}&finance_id=${financeSelect.value}` // NEW finance_id
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred while updating the user', 'error');
            });
        }
        
        // Request management functions
        function viewRequest(requestId) {
            window.location.href = 'view_request.php?id=' + requestId;
        }
        
        function editRequest(requestId) {
            fetch('get_request.php?id=' + requestId)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('editRequestId').value = data.request.id;
                    document.getElementById('editRmEmpId').value = data.request.rm_emp_id;
                    document.getElementById('editRmName').value = data.request.rm_name;
                    document.getElementById('editDepartment').value = data.request.department;
                    document.getElementById('editCustomerName').value = data.request.customer_name;
                    document.getElementById('editMobileNumber').value = data.request.mobile_number;
                    document.getElementById('editMonth').value = data.request.month;
                    document.getElementById('editYear').value = data.request.year;
                    document.getElementById('editInsuranceCompany').value = data.request.insurance_company;
                    document.getElementById('editPolicyType').value = data.request.policy_type;
                    document.getElementById('editPremiumWithGst').value = data.request.premium_with_gst;
                    document.getElementById('editWithoutGst').value = data.request.without_gst;
                    document.getElementById('editReferralAmount').value = data.request.referral_amount;
                    document.getElementById('editReason').value = data.request.reason;
                    document.getElementById('editUtrNumber').value = data.request.utr_number;
                    
                    // Force Status Update Logic
                    const currentStatus = data.request.status;
                    document.getElementById('editCurrentStatus').value = currentStatus;
                    
                    const statusFlow = [
                        'Pending', 
                        'Manager Approved', 
                        'Head Approved', 
                        'Validator Approved', 
                        'Finance Approved'
                    ];
                    
                    const forceSelect = document.getElementById('forceNewStatus');
                    forceSelect.innerHTML = '<option value="">-- Select Status to Force Move --</option>';
                    
                    let currentIndex = statusFlow.indexOf(currentStatus);
                    
                    if (currentIndex !== -1) {
                        for (let i = currentIndex + 1; i < statusFlow.length; i++) {
                            let opt = document.createElement('option');
                            opt.value = statusFlow[i];
                            opt.textContent = "👉 " + statusFlow[i];
                            forceSelect.appendChild(opt);
                        }
                    }
                    
                    if (currentStatus !== 'Rejected' && currentStatus !== 'Validator Rejected' && currentStatus !== 'Finance Approved') {
                        const rejectOptions = ['Rejected', 'Validator Rejected'];
                        rejectOptions.forEach(rejStatus => {
                            let opt = document.createElement('option');
                            opt.value = rejStatus;
                            opt.textContent = "🚫 " + rejStatus;
                            forceSelect.appendChild(opt);
                        });
                    }
                    
                    document.getElementById('editRequestModal').style.display = 'flex';
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred while fetching request data', 'error');
            });
        }
        
        // Force Advance Request AJAX
        function forceAdvanceRequest() {
            const requestId = document.getElementById('editRequestId').value;
            const newStatus = document.getElementById('forceNewStatus').value;
            
            if (!newStatus) {
                showNotification('Please select a status to move forward to', 'error');
                return;
            }
            
            if (!confirm(`Are you sure you want to forcefully move this request to "${newStatus}"? This action cannot be undone.`)) {
                return;
            }
            
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=forceUpdateStatus&request_id=${requestId}&new_status=${encodeURIComponent(newStatus)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    document.getElementById('editRequestModal').style.display = 'none';
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred while forcefully updating the request', 'error');
            });
        }
        
        // Form submission for edit request
        document.getElementById('editRequestForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'updateCashbackRequest');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    document.getElementById('editRequestModal').style.display = 'none';
                    location.reload();
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred while updating the request', 'error');
            });
        });
        
        // Search and filter functions
        function searchUsers() {
            const searchTerm = document.getElementById('userSearch').value.toLowerCase();
            const rows = document.querySelectorAll('#usersTable tbody tr');
            rows.forEach(row => {
                row.style.display = row.textContent.toLowerCase().includes(searchTerm) ? '' : 'none';
            });
        }
        
        function resetUserSearch() {
            document.getElementById('userSearch').value = '';
            document.getElementById('roleFilter').value = '';
            document.getElementById('departmentFilter').value = '';
            document.getElementById('bypassFilter').value = '';
            document.querySelectorAll('#usersTable tbody tr').forEach(row => row.style.display = '');
        }
        
        function filterUsers() {
            const roleFilter = document.getElementById('roleFilter').value;
            const departmentFilter = document.getElementById('departmentFilter').value;
            const bypassFilter = document.getElementById('bypassFilter').value;
            document.querySelectorAll('#usersTable tbody tr').forEach(row => {
                let showRow = true;
                if (roleFilter) {
                    const roleSelect = row.querySelector('select[data-field="role"]');
                    if (roleSelect && roleSelect.value !== roleFilter) showRow = false;
                }
                if (departmentFilter) {
                    const departmentCell = row.cells[4];
                    if (departmentCell && departmentCell.textContent !== departmentFilter) showRow = false;
                }
                if (bypassFilter !== '') {
                    const bypassCheckbox = row.querySelector('input[type="checkbox"]');
                    const bypassVal = bypassCheckbox && bypassCheckbox.checked ? '1' : '0';
                    if (bypassVal !== bypassFilter) showRow = false;
                }
                row.style.display = showRow ? '' : 'none';
            });
        }
        
        function searchRequests() {
            const searchTerm = document.getElementById('requestSearch').value.toLowerCase();
            document.querySelectorAll('#requestsTable tbody tr').forEach(row => {
                row.style.display = row.textContent.toLowerCase().includes(searchTerm) ? '' : 'none';
            });
        }
        
        function resetRequestSearch() {
            document.getElementById('requestSearch').value = '';
            document.getElementById('statusFilter').value = '';
            document.getElementById('monthFilter').value = '';
            document.getElementById('yearFilter').value = '';
            document.getElementById('bypassRequestFilter').value = '';
            document.querySelectorAll('#requestsTable tbody tr').forEach(row => row.style.display = '');
        }
        
        function filterRequests() {
            const statusFilter = document.getElementById('statusFilter').value;
            const bypassFilter = document.getElementById('bypassRequestFilter').value;
            document.querySelectorAll('#requestsTable tbody tr').forEach(row => {
                let showRow = true;
                if (statusFilter) {
                    const statusBadge = row.querySelector('.status-badge');
                    if (statusBadge && statusBadge.textContent !== statusFilter) showRow = false;
                }
                if (bypassFilter) {
                    const bypassVal = row.getAttribute('data-bypass');
                    if (bypassFilter === 'bypass' && bypassVal !== '1') showRow = false;
                    if (bypassFilter === 'normal' && bypassVal === '1') showRow = false;
                }
                row.style.display = showRow ? '' : 'none';
            });
        }
        
        // Export functions
        function exportUsers() {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'export_users.php';
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }
        
        function exportRequests() {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'export_requests.php';
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }
        
        // Notification functions
        function toggleNotifications() {
            const dropdown = document.getElementById('notificationDropdown');
            dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
        }
        
        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `alert alert-${type}`;
            notification.textContent = message;
            const header = document.querySelector('header');
            header.parentNode.insertBefore(notification, header.nextSibling);
            setTimeout(() => { if(notification.parentNode) notification.parentNode.removeChild(notification); }, 5000);
        }
        
        document.addEventListener('click', function(event) {
            const notificationContainer = document.querySelector('.notification-container');
            const dropdown = document.getElementById('notificationDropdown');
            if (!notificationContainer.contains(event.target)) {
                dropdown.style.display = 'none';
            }
        });
    </script>
</body>
</html>