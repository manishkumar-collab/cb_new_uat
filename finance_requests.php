<?php
require_once 'config.php';

// Check if user is logged in and has finance role
if (!is_logged_in() || !has_role('Finance')) {
    show_notification('You do not have permission to access this page', 'error');
    redirect('login.php');
}

// Set default date range (last 30 days)
 $end_date = date('Y-m-d');
 $start_date = date('Y-m-d', strtotime('-30 days'));

// Initialize filter variables
 $department_filter = '';
 $head_filter = '';
 $manager_filter = '';
 $request_type_filter = '';

// Check if date filters are applied
if (isset($_GET['start_date']) && !empty($_GET['start_date'])) {
    $start_date = $_GET['start_date'];
}
if (isset($_GET['end_date']) && !empty($_GET['end_date'])) {
    $end_date = $_GET['end_date'];
}
if (isset($_GET['department']) && !empty($_GET['department'])) {
    $department_filter = $_GET['department'];
}
if (isset($_GET['head']) && !empty($_GET['head'])) {
    $head_filter = $_GET['head'];
}
if (isset($_GET['manager']) && !empty($_GET['manager'])) {
    $manager_filter = $_GET['manager'];
}
if (isset($_GET['request_type']) && !empty($_GET['request_type'])) {
    $request_type_filter = $_GET['request_type'];
}

// Build filter conditions for SQL queries
 $filter_conditions = " AND cr.created_at BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59'";
if (!empty($department_filter)) {
    $filter_conditions .= " AND u.department = '" . mysqli_real_escape_string($conn, $department_filter) . "'";
}
if (!empty($head_filter)) {
    $filter_conditions .= " AND h.id = '" . mysqli_real_escape_string($conn, $head_filter) . "'";
}
if (!empty($manager_filter)) {
    $filter_conditions .= " AND m.id = '" . mysqli_real_escape_string($conn, $manager_filter) . "'";
}
if (!empty($request_type_filter)) {
    $filter_conditions .= " AND cr.form_type = '" . mysqli_real_escape_string($conn, $request_type_filter) . "'";
}

// Finance User Filtering Logic: Only show requests mapped to this finance user via Head mapping
 $current_finance_id = $_SESSION['user_id'];
 $finance_condition = " AND h.finance_id = '" . mysqli_real_escape_string($conn, $current_finance_id) . "'";

// Get all validator approved cashback requests with filters
 $pending_sql = "SELECT cr.*, u.full_name AS user_name, u.emp_id AS user_emp_id, u.department AS user_department,
                m.full_name AS manager_name, m.emp_id AS manager_emp_id,
                h.full_name AS head_name, h.emp_id AS head_emp_id,
                v.full_name AS validator_name, v.emp_id AS validator_emp_id
                FROM cashback_requests cr 
                JOIN users u ON cr.user_id = u.id 
                LEFT JOIN users m ON u.manager_id = m.id 
                LEFT JOIN users h ON u.head_id = h.id 
                LEFT JOIN users v ON u.validator_id = v.id 
                WHERE cr.status = 'Validator Approved'" . $finance_condition . $filter_conditions . "
                ORDER BY cr.created_at DESC";
 $pending_result = mysqli_query($conn, $pending_sql);

// Get finance approved cashback requests with filters
 $approved_sql = "SELECT cr.*, u.full_name AS user_name, u.emp_id AS user_emp_id, u.department AS user_department,
                m.full_name AS manager_name, m.emp_id AS manager_emp_id,
                h.full_name AS head_name, h.emp_id AS head_emp_id,
                v.full_name AS validator_name, v.emp_id AS validator_emp_id
                FROM cashback_requests cr 
                JOIN users u ON cr.user_id = u.id 
                LEFT JOIN users m ON u.manager_id = m.id 
                LEFT JOIN users h ON u.head_id = h.id 
                LEFT JOIN users v ON u.validator_id = v.id 
                WHERE cr.status = 'Finance Approved'" . $finance_condition . $filter_conditions . "
                ORDER BY cr.updated_at DESC";
 $approved_result = mysqli_query($conn, $approved_sql);

// Get rejected requests with filters
 $rejected_sql = "SELECT cr.*, u.full_name AS user_name, u.emp_id AS user_emp_id, u.department AS user_department,
                m.full_name AS manager_name, m.emp_id AS manager_emp_id,
                h.full_name AS head_name, h.emp_id AS head_emp_id,
                v.full_name AS validator_name, v.emp_id AS validator_emp_id
                FROM cashback_requests cr 
                JOIN users u ON cr.user_id = u.id 
                LEFT JOIN users m ON u.manager_id = m.id 
                LEFT JOIN users h ON u.head_id = h.id 
                LEFT JOIN users v ON u.validator_id = v.id 
                WHERE cr.status = 'Rejected'" . $finance_condition . $filter_conditions . "
                ORDER BY cr.created_at DESC";
 $rejected_result = mysqli_query($conn, $rejected_sql);

// NEW QUERY: Data for the Table View (Month/Department Wise)
// This query fetches data specifically for the new table format requested
 $table_bifurcation_sql = "SELECT 
    u.department,
    DATE_FORMAT(cr.created_at, '%Y-%m') AS month_year,
    SUM(cr.premium_with_gst) AS total_premium_with_gst,
    SUM(cr.without_gst) AS total_premium_without_gst,
    SUM(CASE WHEN cr.form_type = 'CB' THEN cr.referral_amount ELSE 0 END) AS total_cb_amount,
    SUM(CASE WHEN cr.form_type = 'Shortfall' THEN cr.referral_amount ELSE 0 END) AS total_shortfall_amount
FROM cashback_requests cr
JOIN users u ON cr.user_id = u.id
LEFT JOIN users h ON u.head_id = h.id
WHERE cr.status IN ('Validator Approved', 'Finance Approved')" . $finance_condition . $filter_conditions . "
GROUP BY u.department, DATE_FORMAT(cr.created_at, '%Y-%m')
ORDER BY DATE_FORMAT(cr.created_at, '%Y-%m') DESC, u.department ASC";
 $table_bifurcation_result = mysqli_query($conn, $table_bifurcation_sql);

// Get approval time metrics
 $approval_time_sql = "SELECT 
                      cr.id,
                      cr.reference_number,
                      cr.form_type,
                      cr.created_at AS request_date,
                      MIN(CASE WHEN a.approver_role = 'Manager' THEN a.created_at END) AS manager_approval_date,
                      MIN(CASE WHEN a.approver_role = 'Head' THEN a.created_at END) AS head_approval_date,
                      MIN(CASE WHEN a.approver_role = 'Validator' THEN a.created_at END) AS validator_approval_date,
                      MIN(CASE WHEN a.approver_role = 'Finance' THEN a.created_at END) AS finance_approval_date,
                      TIMESTAMPDIFF(HOUR, cr.created_at, MIN(CASE WHEN a.approver_role = 'Manager' THEN a.created_at END)) AS manager_approval_hours,
                      TIMESTAMPDIFF(HOUR, cr.created_at, MIN(CASE WHEN a.approver_role = 'Head' THEN a.created_at END)) AS head_approval_hours,
                      TIMESTAMPDIFF(HOUR, cr.created_at, MIN(CASE WHEN a.approver_role = 'Validator' THEN a.created_at END)) AS validator_approval_hours,
                      TIMESTAMPDIFF(HOUR, cr.created_at, MIN(CASE WHEN a.approver_role = 'Finance' THEN a.created_at END)) AS finance_approval_hours
                      FROM cashback_requests cr
                      JOIN approvals a ON cr.id = a.request_id
                      JOIN users u ON cr.user_id = u.id
                      LEFT JOIN users h ON u.head_id = h.id
                      WHERE cr.status = 'Finance Approved'" . $finance_condition . $filter_conditions . "
                      GROUP BY cr.id
                      ORDER BY finance_approval_hours DESC
                      LIMIT 20";
 $approval_time_result = mysqli_query($conn, $approval_time_sql);

// Calculate average approval times
 $avg_approval_sql = "SELECT 
                    AVG(TIMESTAMPDIFF(HOUR, cr.created_at, 
                      (SELECT MIN(a.created_at) FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Manager')
                    )) AS avg_manager_hours,
                    AVG(TIMESTAMPDIFF(HOUR, cr.created_at, 
                      (SELECT MIN(a.created_at) FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Head')
                    )) AS avg_head_hours,
                    AVG(TIMESTAMPDIFF(HOUR, cr.created_at, 
                      (SELECT MIN(a.created_at) FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Validator')
                    )) AS avg_validator_hours,
                    AVG(TIMESTAMPDIFF(HOUR, cr.created_at, 
                      (SELECT MIN(a.created_at) FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Finance')
                    )) AS avg_finance_hours
                    FROM cashback_requests cr
                    JOIN users u ON cr.user_id = u.id
                    LEFT JOIN users h ON u.head_id = h.id
                    WHERE cr.status = 'Finance Approved'" . $finance_condition . $filter_conditions;
 $avg_approval_result = mysqli_query($conn, $avg_approval_sql);
 $avg_approval = mysqli_fetch_assoc($avg_approval_result);

// Get departments for filter dropdown
 $departments_sql = "SELECT DISTINCT department FROM users WHERE department != '' ORDER BY department";
 $departments_result = mysqli_query($conn, $departments_sql);

// Get heads for filter dropdown mapped to this finance user
 $heads_sql = "SELECT id, full_name FROM users WHERE role = 'Head' AND finance_id = '" . mysqli_real_escape_string($conn, $current_finance_id) . "' ORDER BY full_name";
 $heads_result = mysqli_query($conn, $heads_sql);

// Get managers for filter dropdown mapped under those heads
 $managers_sql = "SELECT DISTINCT m.id, m.full_name FROM users m JOIN users h ON m.head_id = h.id WHERE m.role = 'Manager' AND h.finance_id = '" . mysqli_real_escape_string($conn, $current_finance_id) . "' ORDER BY m.full_name";
 $managers_result = mysqli_query($conn, $managers_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finance Requests - CB Account</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/datepicker/0.6.5/datepicker.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/datepicker/0.6.5/datepicker.min.js"></script>
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
            --secondary: #4a6fa5;
            --secondary-dark: #3a5a8a;
            --success: #38a169;
            --success-dark: #2f855a;
            --danger: #e53e3e;
            --danger-dark: #c53030;
            --warning: #d69e2e;
            --warning-dark: #b7791f;
            --dark: #2d3748;
            --light: #ffffff;
            --gray: #e2e8f0;
            --text: #4a5568;
            --text-light: #718096;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px rgba(0, 0, 0, 0.1);
            --radius: 8px;
        }
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            color: var(--text);
            line-height: 1.6;
            min-height: 100vh;
            padding: 15px;
            font-size: 14px;
        }
        .container {
            max-width: 1600px;
            margin: 0 auto;
        }
        header {
            text-align: center;
            margin-bottom: 25px;
            padding: 20px;
            background: var(--light);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            position: relative;
        }
        .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 12px;
        }
        .logo-icon {
            font-size: 28px;
            color: var(--primary);
        }
        .logo-text {
            font-size: 26px;
            font-weight: 700;
            color: var(--dark);
        }
        .logo-text span {
            color: var(--primary);
        }
        .tagline {
            color: var(--text-light);
            font-size: 15px;
            margin-bottom: 8px;
        }
        .user-info {
            position: absolute;
            top: 20px;
            right: 20px;
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
            background-color: var(--danger);
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
            background-color: var(--danger-dark);
        }
        .dashboard-container {
            background-color: var(--light);
            border-radius: var(--radius);
            padding: 25px;
            box-shadow: var(--shadow);
            margin-bottom: 25px;
        }
        .section-title {
            font-size: 20px;
            color: var(--primary);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .filter-container {
            display: flex;
            gap: 15px;
            align-items: flex-end;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
            min-width: 150px;
        }
        .filter-label {
            font-size: 12px;
            color: var(--text-light);
            font-weight: 500;
        }
        .filter-input, .filter-select {
            padding: 8px 10px;
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            font-size: 13px;
            width: 100%;
        }
        .filter-btn {
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
        .filter-btn:hover {
            background-color: var(--primary-dark);
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
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid var(--gray);
        }
        th {
            background-color: #f8fafc;
            font-weight: 600;
            color: var(--dark);
            position: sticky;
            top: 0;
        }
        tr:hover {
            background-color: #f8fafc;
        }
        .btn {
            padding: 8px 12px;
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
            background-color: var(--success);
            color: white;
        }
        .btn-success:hover {
            background-color: var(--success-dark);
        }
        .btn-danger {
            background-color: var(--danger);
            color: white;
        }
        .btn-danger:hover {
            background-color: var(--danger-dark);
        }
        .btn-info {
            background-color: var(--secondary);
            color: white;
        }
        .btn-info:hover {
            background-color: var(--secondary-dark);
        }
        .btn-warning {
            background-color: var(--warning);
            color: white;
        }
        .btn-warning:hover {
            background-color: var(--warning-dark);
        }
        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--gray);
            color: var(--text);
        }
        .btn-outline:hover {
            background-color: var(--gray);
        }
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }
        .status-pending {
            background-color: #fff7e6;
            color: #d46b08;
        }
        .status-approved {
            background-color: #f6ffed;
            color: #389e0d;
        }
        .status-rejected {
            background-color: #fff2f0;
            color: #cf1322;
        }
        .alert {
            padding: 15px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert i {
            font-size: 18px;
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
        .alert-warning {
            background-color: #fffbe6;
            border-left: 4px solid #d46b08;
            color: #d46b08;
        }
        .tabs {
            display: flex;
            border-bottom: 2px solid var(--gray);
            margin-bottom: 25px;
            overflow-x: auto;
        }
        .tab {
            padding: 12px 20px;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            font-weight: 500;
            white-space: nowrap;
            transition: all 0.3s ease;
        }
        .tab:hover {
            color: var(--primary);
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
            max-width: 550px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.15);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            position: sticky;
            top: 0;
            background-color: var(--light);
            z-index: 10;
            padding-bottom: 10px;
        }
        .modal-title {
            font-size: 20px;
            color: var(--dark);
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text);
        }
        .modal-body {
            margin-bottom: 20px;
        }
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            position: sticky;
            bottom: 0;
            background-color: var(--light);
            padding-top: 10px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--dark);
            font-weight: 500;
        }
        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            font-size: 14px;
        }
        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }
        .no-data {
            text-align: center;
            padding: 40px;
            color: var(--text-light);
        }
        .no-data i {
            font-size: 48px;
            margin-bottom: 15px;
            color: var(--gray);
        }
        .export-btn {
            background-color: var(--success);
            color: white;
            border: none;
            border-radius: var(--radius);
            padding: 8px 12px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 13px;
            margin-left: 10px;
        }
        .export-btn:hover {
            background-color: var(--success-dark);
        }
        .search-box {
            position: relative;
            margin-bottom: 20px;
        }
        .search-input {
            width: 100%;
            padding: 10px 40px 10px 15px;
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            font-size: 14px;
        }
        .search-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
        }
        .action-group {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        .approval-time {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .approval-time i {
            color: var(--text-light);
        }
        .time-badge {
            background-color: #f8fafc;
            padding: 3px 6px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
        }
        .time-badge.high {
            background-color: #fff2f0;
            color: #cf1322;
        }
        .time-badge.medium {
            background-color: #fff7e6;
            color: #d46b08;
        }
        .time-badge.low {
            background-color: #f6ffed;
            color: #389e0d;
        }
        .dashboard-btn {
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: var(--radius);
            padding: 10px 20px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 500;
            transition: background-color 0.3s ease;
            margin-bottom: 20px;
        }
        .dashboard-btn:hover {
            background-color: var(--primary-dark);
        }
        .utr-info {
            background-color: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: var(--radius);
            padding: 8px 12px;
            margin-top: 5px;
            font-size: 12px;
            color: #0369a1;
        }
        .utr-number {
            font-weight: 600;
            color: #0c4a6e;
        }
        .file-upload {
            position: relative;
            display: inline-block;
            cursor: pointer;
            width: 100%;
        }
        .file-upload input[type=file] {
            position: absolute;
            left: -9999px;
        }
        .file-upload label {
            display: block;
            padding: 10px 12px;
            border: 1px dashed var(--gray);
            border-radius: var(--radius);
            background-color: #f8fafc;
            cursor: pointer;
            text-align: center;
            transition: all 0.3s ease;
        }
        .file-upload label:hover {
            border-color: var(--primary);
            background-color: #f0f9ff;
        }
        .file-upload label i {
            margin-right: 8px;
            color: var(--primary);
        }
        .file-info {
            margin-top: 8px;
            font-size: 12px;
            color: var(--text-light);
        }
        .file-preview {
            margin-top: 10px;
            max-width: 100%;
            border-radius: var(--radius);
            display: none;
            height: 200px;
            overflow: hidden;
            position: relative;
        }
        .file-preview img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }
        .loader {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(255, 255, 255, 0.8);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }
        .loader-content {
            text-align: center;
        }
        .spinner {
            border: 5px solid rgba(0, 0, 0, 0.1);
            width: 50px;
            height: 50px;
            border-radius: 50%;
            border-left-color: var(--primary);
            animation: spin 1s ease infinite;
            margin: 0 auto 15px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .loader-text {
            color: var(--dark);
            font-weight: 500;
        }
        .request-type-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            margin-right: 5px;
        }
        .request-type-cb {
            background-color: #e6f7ff;
            color: #0958d9;
        }
        .request-type-shortfall {
            background-color: #fff7e6;
            color: #d46b08;
        }
        .amount {
            font-weight: 600;
            color: var(--primary);
        }
        
        @media (max-width: 768px) {
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
                padding: 8px 10px;
            }
            .filter-container {
                flex-direction: column;
                align-items: stretch;
            }
            .filter-btn {
                align-self: stretch;
                justify-content: center;
            }
            .modal-content {
                max-height: 95vh;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="logo">
                <i class="fas fa-file-invoice-dollar logo-icon"></i>
                <div class="logo-text">Finance <span>Requests</span></div>
            </div>
            <p class="tagline">Manage CB requests and payments</p>
            
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
        
        <div class="dashboard-container">
            <a href="dashboard_finance.php" class="dashboard-btn">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
            
            <h2 class="section-title">
                CB Requests Management
            </h2>
            
            <div class="filter-container">
                <div class="filter-group">
                    <label class="filter-label">From Date</label>
                    <input type="text" id="start_date" class="filter-input datepicker" value="<?php echo $start_date; ?>" readonly>
                </div>
                <div class="filter-group">
                    <label class="filter-label">To Date</label>
                    <input type="text" id="end_date" class="filter-input datepicker" value="<?php echo $end_date; ?>" readonly>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Request Type</label>
                    <select id="request_type_filter" class="filter-select">
                        <option value="">All Types</option>
                        <option value="CB" <?php echo $request_type_filter === 'CB' ? 'selected' : ''; ?>>CB</option>
                        <option value="Shortfall" <?php echo $request_type_filter === 'Shortfall' ? 'selected' : ''; ?>>Shortfall</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Department</label>
                    <select id="department_filter" class="filter-select">
                        <option value="">All Departments</option>
                        <?php while ($dept = mysqli_fetch_assoc($departments_result)): ?>
                            <option value="<?php echo htmlspecialchars($dept['department']); ?>" <?php echo $department_filter === $dept['department'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['department']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Head</label>
                    <select id="head_filter" class="filter-select">
                        <option value="">All Heads</option>
                        <?php mysqli_data_seek($heads_result, 0); ?>
                        <?php while ($head = mysqli_fetch_assoc($heads_result)): ?>
                            <option value="<?php echo $head['id']; ?>" <?php echo $head_filter === $head['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($head['full_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Manager</label>
                    <select id="manager_filter" class="filter-select">
                        <option value="">All Managers</option>
                        <?php mysqli_data_seek($managers_result, 0); ?>
                        <?php while ($manager = mysqli_fetch_assoc($managers_result)): ?>
                            <option value="<?php echo $manager['id']; ?>" <?php echo $manager_filter === $manager['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($manager['full_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <button class="filter-btn" onclick="applyFilters()">
                    <i class="fas fa-filter"></i> Apply Filters
                </button>
                <button class="btn btn-outline" onclick="resetFilters()">
                    <i class="fas fa-redo"></i> Reset
                </button>
            </div>
            
            <div class="tabs">
                <div class="tab active" onclick="openTab(event, 'pending-tab')">Pending Approvals</div>
                <div class="tab" onclick="openTab(event, 'approved-tab')">Paid Requests</div>
                <div class="tab" onclick="openTab(event, 'rejected-tab')">Rejected Requests</div>
                <div class="tab" onclick="openTab(event, 'approval-time-tab')">Approval Time Analysis</div>
                <div class="tab" onclick="openTab(event, 'aggregated-tab')">CB/Shortfall Bifurcation</div>
            </div>
            
            <!-- Pending Tab -->
            <div id="pending-tab" class="tab-content active">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3>Pending Approvals</h3>
                    <div class="export-options">
                        <button class="export-btn" onclick="exportData('pending')">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                </div>
                
                <div class="search-box">
                    <input type="text" id="pendingSearch" class="search-input" placeholder="Search by reference, user, customer...">
                    <i class="fas fa-search search-icon"></i>
                </div>
                
                <?php if (mysqli_num_rows($pending_result) > 0): ?>
                    <div class="table-container">
                        <table id="pendingTable" class="data-table">
                            <thead>
                                <tr>
                                    <th>Reference #</th>
                                    <th>Type</th>
                                    <th>User</th>
                                    <th>Manager</th>
                                    <th>Head</th>
                                    <th>Validator</th>
                                    <th>Customer</th>
                                    <th>Premium (with GST)</th>
                                    <th>Premium (without GST)</th>
                                    <th class="amount">Amount</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($request = mysqli_fetch_assoc($pending_result)): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($request['reference_number']); ?></td>
                                        <td>
                                            <span class="request-type-badge <?php echo $request['form_type'] === 'CB' ? 'request-type-cb' : 'request-type-shortfall'; ?>">
                                                <?php echo htmlspecialchars($request['form_type']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($request['user_name']); ?></td>
                                        <td><?php echo htmlspecialchars($request['manager_name']); ?></td>
                                        <td><?php echo htmlspecialchars($request['head_name']); ?></td>
                                        <td><?php echo htmlspecialchars($request['validator_name']); ?></td>
                                        <td><?php echo htmlspecialchars($request['customer_name']); ?></td>
                                        <td>₹<?php echo number_format($request['premium_with_gst'], 2); ?></td>
                                        <td>₹<?php echo number_format($request['without_gst'], 2); ?></td>
                                        <td class="amount">₹<?php echo number_format($request['referral_amount'], 2); ?></td>
                                        <td><?php echo date('d M Y', strtotime($request['created_at'])); ?></td>
                                        <td>
                                            <div class="action-group">
                                                <button class="btn btn-info" onclick="viewRequest(<?php echo $request['id']; ?>)">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                                <button class="btn btn-success" onclick="payRequest(<?php echo $request['id']; ?>)">
                                                    <i class="fas fa-money-check"></i> Pay
                                                </button>
                                                <button class="btn btn-danger" onclick="rejectRequest(<?php echo $request['id']; ?>)">
                                                    <i class="fas fa-times"></i> Reject
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
                        <i class="fas fa-file-invoice"></i>
                        <p>No pending approvals</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Approved Tab -->
            <div id="approved-tab" class="tab-content">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3>Paid Requests</h3>
                    <div class="export-options">
                        <button class="export-btn" onclick="exportData('approved')">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                </div>
                
                <div class="search-box">
                    <input type="text" id="approvedSearch" class="search-input" placeholder="Search by reference, user, customer...">
                    <i class="fas fa-search search-icon"></i>
                </div>
                
                <?php if (mysqli_num_rows($approved_result) > 0): ?>
                    <div class="table-container">
                        <table id="approvedTable" class="data-table">
                            <thead>
                                <tr>
                                    <th>Reference #</th>
                                    <th>Type</th>
                                    <th>User</th>
                                    <th>Manager</th>
                                    <th>Head</th>
                                    <th>Validator</th>
                                    <th>Customer</th>
                                    <th>Premium (with GST)</th>
                                    <th>Premium (without GST)</th>
                                    <th class="amount">Amount</th>
                                    <th>Payment Date</th>
                                    <th>UTR Number</th>
                                    <th>Payment Proof</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($request = mysqli_fetch_assoc($approved_result)): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($request['reference_number']); ?></td>
                                        <td>
                                            <span class="request-type-badge <?php echo $request['form_type'] === 'CB' ? 'request-type-cb' : 'request-type-shortfall'; ?>">
                                                <?php echo htmlspecialchars($request['form_type']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($request['user_name']); ?></td>
                                        <td><?php echo htmlspecialchars($request['manager_name']); ?></td>
                                        <td><?php echo htmlspecialchars($request['head_name']); ?></td>
                                        <td><?php echo htmlspecialchars($request['validator_name']); ?></td>
                                        <td><?php echo htmlspecialchars($request['customer_name']); ?></td>
                                        <td>₹<?php echo number_format($request['premium_with_gst'], 2); ?></td>
                                        <td>₹<?php echo number_format($request['without_gst'], 2); ?></td>
                                        <td class="amount">₹<?php echo number_format($request['referral_amount'], 2); ?></td>
                                        <td><?php echo date('d M Y', strtotime($request['updated_at'])); ?></td>
                                        <td>
                                            <?php if (!empty($request['utr_number'])): ?>
                                                <span class="utr-number"><?php echo htmlspecialchars($request['utr_number']); ?></span>
                                            <?php else: ?>
                                                <span style="color: #999;">Not provided</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($request['payment_screenshot_url'])): ?>
                                                <a href="<?php echo htmlspecialchars($request['payment_screenshot_url']); ?>" target="_blank" class="btn btn-info btn-sm">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                            <?php else: ?>
                                                <span style="color: #999;">Not uploaded</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-group">
                                                <button class="btn btn-info" onclick="viewRequest(<?php echo $request['id']; ?>)">
                                                    <i class="fas fa-eye"></i> View
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
                        <i class="fas fa-file-invoice"></i>
                        <p>No paid requests</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Rejected Tab -->
            <div id="rejected-tab" class="tab-content">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3>Rejected Requests</h3>
                    <div class="export-options">
                        <button class="export-btn" onclick="exportData('rejected')">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                </div>
                
                <div class="search-box">
                    <input type="text" id="rejectedSearch" class="search-input" placeholder="Search by reference, user, customer...">
                    <i class="fas fa-search search-icon"></i>
                </div>
                
                <?php if (mysqli_num_rows($rejected_result) > 0): ?>
                    <div class="table-container">
                        <table id="rejectedTable" class="data-table">
                            <thead>
                                <tr>
                                    <th>Reference #</th>
                                    <th>Type</th>
                                    <th>User</th>
                                    <th>Manager</th>
                                    <th>Head</th>
                                    <th>Validator</th>
                                    <th>Customer</th>
                                    <th>Premium (with GST)</th>
                                    <th>Premium (without GST)</th>
                                    <th class="amount">Amount</th>
                                    <th>Rejection Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($request = mysqli_fetch_assoc($rejected_result)): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($request['reference_number']); ?></td>
                                        <td>
                                            <span class="request-type-badge <?php echo $request['form_type'] === 'CB' ? 'request-type-cb' : 'request-type-shortfall'; ?>">
                                                <?php echo htmlspecialchars($request['form_type']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($request['user_name']); ?></td>
                                        <td><?php echo htmlspecialchars($request['manager_name']); ?></td>
                                        <td><?php echo htmlspecialchars($request['head_name']); ?></td>
                                        <td><?php echo htmlspecialchars($request['validator_name']); ?></td>
                                        <td><?php echo htmlspecialchars($request['customer_name']); ?></td>
                                        <td>₹<?php echo number_format($request['premium_with_gst'], 2); ?></td>
                                        <td>₹<?php echo number_format($request['without_gst'], 2); ?></td>
                                        <td class="amount">₹<?php echo number_format($request['referral_amount'], 2); ?></td>
                                        <td><?php echo date('d M Y', strtotime($request['updated_at'])); ?></td>
                                        <td>
                                            <div class="action-group">
                                                <button class="btn btn-info" onclick="viewRequest(<?php echo $request['id']; ?>)">
                                                    <i class="fas fa-eye"></i> View
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
                        <i class="fas fa-file-invoice"></i>
                        <p>No rejected requests</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Approval Time Tab -->
            <div id="approval-time-tab" class="tab-content">
                <h3>Approval Time Analysis</h3>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
                    <div style="background: var(--light); padding: 15px; border-radius: var(--radius); text-align: center; box-shadow: var(--shadow);">
                        <div style="font-size: 24px; font-weight: bold; color: var(--primary);"><?php echo round($avg_approval['avg_manager_hours']); ?> hours</div>
                        <div style="font-size: 13px; color: var(--text-light); margin-top: 5px;">Avg. Manager Approval Time</div>
                    </div>
                    <div style="background: var(--light); padding: 15px; border-radius: var(--radius); text-align: center; box-shadow: var(--shadow);">
                        <div style="font-size: 24px; font-weight: bold; color: var(--primary);"><?php echo round($avg_approval['avg_head_hours']); ?> hours</div>
                        <div style="font-size: 13px; color: var(--text-light); margin-top: 5px;">Avg. Head Approval Time</div>
                    </div>
                    <div style="background: var(--light); padding: 15px; border-radius: var(--radius); text-align: center; box-shadow: var(--shadow);">
                        <div style="font-size: 24px; font-weight: bold; color: var(--primary);"><?php echo round($avg_approval['avg_validator_hours']); ?> hours</div>
                        <div style="font-size: 13px; color: var(--text-light); margin-top: 5px;">Avg. Validator Approval Time</div>
                    </div>
                    <div style="background: var(--light); padding: 15px; border-radius: var(--radius); text-align: center; box-shadow: var(--shadow);">
                        <div style="font-size: 24px; font-weight: bold; color: var(--primary);"><?php echo round($avg_approval['avg_finance_hours']); ?> hours</div>
                        <div style="font-size: 13px; color: var(--text-light); margin-top: 5px;">Avg. Finance Approval Time</div>
                    </div>
                </div>
                
                <?php if (mysqli_num_rows($approval_time_result) > 0): ?>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Reference #</th>
                                    <th>Type</th>
                                    <th>Request Date</th>
                                    <th>Manager Approval</th>
                                    <th>Head Approval</th>
                                    <th>Validator Approval</th>
                                    <th>Finance Approval</th>
                                    <th>Total Time</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($approval = mysqli_fetch_assoc($approval_time_result)): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($approval['reference_number']); ?></td>
                                        <td>
                                            <span class="request-type-badge <?php echo $approval['form_type'] === 'CB' ? 'request-type-cb' : 'request-type-shortfall'; ?>">
                                                <?php echo htmlspecialchars($approval['form_type']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('d M Y', strtotime($approval['request_date'])); ?></td>
                                        <td>
                                            <div class="approval-time">
                                                <span class="time-badge <?php echo $approval['manager_approval_hours'] > 48 ? 'high' : ($approval['manager_approval_hours'] > 24 ? 'medium' : 'low'); ?>">
                                                    <?php echo round($approval['manager_approval_hours']); ?> hrs
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="approval-time">
                                                <span class="time-badge <?php echo $approval['head_approval_hours'] > 72 ? 'high' : ($approval['head_approval_hours'] > 48 ? 'medium' : 'low'); ?>">
                                                    <?php echo round($approval['head_approval_hours']); ?> hrs
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="approval-time">
                                                <span class="time-badge <?php echo $approval['validator_approval_hours'] > 96 ? 'high' : ($approval['validator_approval_hours'] > 72 ? 'medium' : 'low'); ?>">
                                                    <?php echo round($approval['validator_approval_hours']); ?> hrs
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="approval-time">
                                                <span class="time-badge <?php echo $approval['finance_approval_hours'] > 120 ? 'high' : ($approval['finance_approval_hours'] > 96 ? 'medium' : 'low'); ?>">
                                                    <?php echo round($approval['finance_approval_hours']); ?> hrs
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="approval-time">
                                                <span class="time-badge <?php echo $approval['finance_approval_hours'] > 120 ? 'high' : ($approval['finance_approval_hours'] > 96 ? 'medium' : 'low'); ?>">
                                                    <?php echo round($approval['finance_approval_hours']); ?> hrs
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="action-group">
                                                <button class="btn btn-info" onclick="viewRequest(<?php echo $approval['id']; ?>)">
                                                    <i class="fas fa-eye"></i> View
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
                        <i class="fas fa-clock"></i>
                        <p>No approval time data available</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- NEW: CB/Shortfall Bifurcation Tab (Table Layout) -->
            <div id="aggregated-tab" class="tab-content">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3>CB/Shortfall Bifurcation</h3>
                    <div class="export-options">
                        <button class="export-btn" onclick="exportTableToCSV('bifurcationTable', 'cb_shortfall_bifurcation')">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                </div>

                <!-- New Table as per request -->
                <?php if (mysqli_num_rows($table_bifurcation_result) > 0): ?>
                    <div class="table-container">
                        <table id="bifurcationTable" class="data-table">
                            <thead>
                                <tr>
                                    <th>Department</th>
                                    <th>Month</th>
                                    <th>Premium with GST</th>
                                    <th>Without GST</th>
                                    <th>Referral Amount</th>
                                    <th>Shortfall AMT.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = mysqli_fetch_assoc($table_bifurcation_result)): 
                                    // Format Month (e.g., 2023-11 -> November 2023)
                                    $dateObj = DateTime::createFromFormat('Y-m', $row['month_year']);
                                    $monthName = $dateObj ? $dateObj->format('F Y') : $row['month_year'];
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['department']); ?></td>
                                        <td><?php echo htmlspecialchars($monthName); ?></td>
                                        <td><?php echo number_format($row['total_premium_with_gst'], 2); ?></td>
                                        <td><?php echo number_format($row['total_premium_without_gst'], 2); ?></td>
                                        <td style="color: var(--success); font-weight: 600;">
                                            <?php echo number_format($row['total_cb_amount'], 2); ?>
                                        </td>
                                        <td style="color: var(--danger); font-weight: 600;">
                                            <?php echo number_format($row['total_shortfall_amount'], 2); ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-table"></i>
                        <p>No data available for selected filters.</p>
                    </div>
                <?php endif; ?>
            </div>
            
        </div>
    </div>
    
    <!-- Pay Request Modal -->
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
                        <div class="file-upload">
                            <input type="file" id="paymentScreenshot" name="payment_screenshot" accept="image/*" onchange="previewFile(this)">
                            <label for="paymentScreenshot">
                                <i class="fas fa-cloud-upload-alt"></i> Click to upload payment screenshot
                            </label>
                        </div>
                        <div class="file-info">Accepted formats: JPG, PNG, GIF. Max size: 5MB</div>
                        <div id="filePreview" class="file-preview">
                            <img id="previewImg" src="" alt="Payment Screenshot">
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
    
    <!-- Loader -->
    <div id="loader" class="loader">
        <div class="loader-content">
            <div class="spinner"></div>
            <div class="loader-text">Processing payment...</div>
        </div>
    </div>
    
    <script>
        // Initialize date pickers
        $(document).ready(function() {
            $('.datepicker').datepicker({
                format: 'yyyy-mm-dd',
                autoclose: true,
                todayHighlight: true
            });
            
            // Setup search functionality for tables
            setupSearch('pendingSearch', 'pendingTable');
            setupSearch('approvedSearch', 'approvedTable');
            setupSearch('rejectedSearch', 'rejectedTable');
            
            // Handle form submission with loader
            $('#payForm').on('submit', function(e) {
                e.preventDefault();
                showLoader();
                
                var formData = new FormData(this);
                var actionUrl = this.action;
                
                $.ajax({
                    url: actionUrl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        hideLoader();
                        try {
                            var result = JSON.parse(response);
                            if (result.success) {
                                document.getElementById('payModal').style.display='none';
                                showNotification('Payment marked successfully', 'success');
                                setTimeout(function() {
                                    window.location.reload();
                                }, 1500);
                            } else {
                                showNotification(result.message || 'Error processing payment', 'error');
                            }
                        } catch (e) {
                            window.location.reload();
                        }
                    },
                    error: function() {
                        hideLoader();
                        showNotification('Error processing payment. Please try again.', 'error');
                    }
                });
            });
        });
        
        function setupSearch(searchId, tableId) {
            $('#' + searchId).on('keyup', function() {
                var value = $(this).val().toLowerCase();
                $('#' + tableId + ' tbody tr').filter(function() {
                    $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1);
                });
            });
        }
        
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
        
        function payRequest(requestId) {
            currentRequestId = requestId;
            document.getElementById('payModal').style.display = 'flex';
            document.getElementById('payForm').action = 'finance_pay_request.php?id=' + requestId;
        }
        
        function rejectRequest(requestId) {
            currentRequestId = requestId;
            document.getElementById('rejectModal').style.display = 'flex';
            document.getElementById('rejectForm').action = 'finance_reject_request.php?id=' + requestId;
        }
        
        function viewRequest(requestId) {
            window.location.href = 'view_request.php?id=' + requestId;
        }
        
        function applyFilters() {
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            const department = document.getElementById('department_filter').value;
            const head = document.getElementById('head_filter').value;
            const manager = document.getElementById('manager_filter').value;
            const requestType = document.getElementById('request_type_filter').value;
            
            const url = new URL(window.location.href);
            
            if (startDate) url.searchParams.set('start_date', startDate);
            else url.searchParams.delete('start_date');
            
            if (endDate) url.searchParams.set('end_date', endDate);
            else url.searchParams.delete('end_date');
            
            if (requestType) url.searchParams.set('request_type', requestType);
            else url.searchParams.delete('request_type');
            
            if (department) url.searchParams.set('department', department);
            else url.searchParams.delete('department');
            
            if (head) url.searchParams.set('head', head);
            else url.searchParams.delete('head');
            
            if (manager) url.searchParams.set('manager', manager);
            else url.searchParams.delete('manager');
            
            window.location.href = url.toString();
        }
        
        function resetFilters() {
            const url = new URL(window.location.href);
            url.searchParams.delete('start_date');
            url.searchParams.delete('end_date');
            url.searchParams.delete('department');
            url.searchParams.delete('head');
            url.searchParams.delete('manager');
            url.searchParams.delete('request_type');
            window.location.href = url.toString();
        }
        
        function exportData(type) {
            let tableId, fileName;
            
            switch(type) {
                case 'pending':
                    tableId = 'pendingTable';
                    fileName = 'pending_requests';
                    break;
                case 'approved':
                    tableId = 'approvedTable';
                    fileName = 'paid_requests';
                    break;
                case 'rejected':
                    tableId = 'rejectedTable';
                    fileName = 'rejected_requests';
                    break;
                default:
                    return; // Handle 'aggregated' separately or ignore
            }
            
            // Get table data
            let table = document.getElementById(tableId);
            let rows = [];
            
            // Get header row
            let headerRow = [];
            let headers = table.querySelectorAll('th');
            for (let i = 0; i < headers.length; i++) {
                headerRow.push(headers[i].innerText);
            }
            rows.push(headerRow.join(','));
            
            // Get data rows
            let dataRows = table.querySelectorAll('tbody tr');
            for (let i = 0; i < dataRows.length; i++) {
                let row = [];
                let cells = dataRows[i].querySelectorAll('td');
                
                // Skip last column (Actions)
                for (let j = 0; j < cells.length - 1; j++) {
                    // Remove currency symbols and clean the text
                    let cellText = cells[j].innerText.replace(/₹/g, '').replace(/â‚¹/g, '').trim();
                    row.push('"' + cellText + '"');
                }
                
                rows.push(row.join(','));
            }
            
            // Create CSV
            let csv = rows.join('\n');
            
            // Create download link
            let blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            let url = window.URL.createObjectURL(blob);
            let a = document.createElement('a');
            a.setAttribute('hidden', '');
            a.setAttribute('href', url);
            a.setAttribute('download', fileName + '_' + new Date().toISOString().slice(0, 10) + '.csv');
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }

        // NEW: Specific function to export the Bifurcation Table
        function exportTableToCSV(tableId, filename) {
            let table = document.getElementById(tableId);
            if(!table) return;
            
            let rows = [];
            
            // Get header row
            let headerRow = [];
            let headers = table.querySelectorAll('th');
            for (let i = 0; i < headers.length; i++) {
                headerRow.push(headers[i].innerText);
            }
            rows.push(headerRow.join(','));
            
            // Get data rows
            let dataRows = table.querySelectorAll('tbody tr');
            for (let i = 0; i < dataRows.length; i++) {
                let row = [];
                let cells = dataRows[i].querySelectorAll('td');
                
                for (let j = 0; j < cells.length; j++) {
                    // Remove currency symbols and clean text
                    let cellText = cells[j].innerText.replace(/₹/g, '').replace(/,/g, '').trim();
                    row.push('"' + cellText + '"');
                }
                
                rows.push(row.join(','));
            }
            
            let csv = rows.join('\n');
            let blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            let url = window.URL.createObjectURL(blob);
            let a = document.createElement('a');
            a.setAttribute('hidden', '');
            a.setAttribute('href', url);
            a.setAttribute('download', filename + '_' + new Date().toISOString().slice(0, 10) + '.csv');
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
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
        
        function showLoader() {
            document.getElementById('loader').style.display = 'flex';
        }
        
        function hideLoader() {
            document.getElementById('loader').style.display = 'none';
        }
        
        function showNotification(message, type) {
            var notification = document.createElement('div');
            notification.className = 'alert alert-' + type;
            notification.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'check-circle' : 'exclamation-circle') + '"></i> ' + message;
            
            var container = document.querySelector('.container');
            container.insertBefore(notification, container.firstChild);
            
            setTimeout(function() {
                notification.remove();
            }, 5000);
        }
    </script>
</body>
</html>