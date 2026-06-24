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

// Get overall statistics with filters (updated for Validator Approved)
 $stats_sql = "SELECT 
              COUNT(*) AS total_requests,
              SUM(CASE WHEN cr.status = 'Validator Approved' THEN 1 ELSE 0 END) AS pending_count,
              SUM(CASE WHEN cr.status = 'Paid' THEN 1 ELSE 0 END) AS approved_count,
              SUM(CASE WHEN cr.status = 'Rejected' THEN 1 ELSE 0 END) AS rejected_count,
              SUM(CASE WHEN cr.status = 'Validator Approved' THEN cr.referral_amount ELSE 0 END) AS pending_amount,
              SUM(CASE WHEN cr.status = 'Paid' THEN cr.referral_amount ELSE 0 END) AS approved_amount,
              SUM(CASE WHEN cr.status = 'Rejected' THEN cr.referral_amount ELSE 0 END) AS rejected_amount,
              SUM(CASE WHEN cr.status = 'Paid' THEN cr.premium_with_gst ELSE 0 END) AS total_premium_with_gst,
              SUM(CASE WHEN cr.status = 'Paid' THEN cr.without_gst ELSE 0 END) AS total_without_gst,
              AVG(CASE WHEN cr.status = 'Paid' THEN cr.referral_amount ELSE NULL END) AS avg_cashback,
              AVG(CASE WHEN cr.status = 'Paid' THEN cr.premium_with_gst ELSE NULL END) AS avg_premium,
              SUM(CASE WHEN cr.status = 'Paid' THEN cr.referral_amount ELSE 0 END) / 
              NULLIF(SUM(CASE WHEN cr.status = 'Paid' THEN cr.premium_with_gst ELSE 0 END), 0) * 100 AS cashback_ratio
              FROM cashback_requests cr 
              JOIN users u ON cr.user_id = u.id 
              LEFT JOIN users m ON u.manager_id = m.id 
              LEFT JOIN users h ON m.head_id = h.id 
              LEFT JOIN users v ON u.validator_id = v.id 
              WHERE 1=1" . $filter_conditions;
 $stats_result = mysqli_query($conn, $stats_sql);
 $stats = mysqli_fetch_assoc($stats_result);

// Get monthly statistics for the last 12 months (updated for Validator Approved)
 $monthly_sql = "SELECT 
                MONTH(cr.created_at) AS month,
                YEAR(cr.created_at) AS year,
                COUNT(*) AS count,
                SUM(cr.referral_amount) AS amount,
                SUM(cr.premium_with_gst) AS premium_with_gst,
                SUM(cr.without_gst) AS without_gst,
                SUM(cr.referral_amount) / NULLIF(SUM(cr.premium_with_gst), 0) * 100 AS cashback_ratio
                FROM cashback_requests cr 
                WHERE (cr.status = 'Paid' OR cr.status = 'Validator Approved')
                AND cr.created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 12 MONTH)" . 
                (empty($department_filter) ? '' : " AND cr.department = '" . mysqli_real_escape_string($conn, $department_filter) . "'") .
                (empty($head_filter) ? '' : " AND cr.head_id = '" . mysqli_real_escape_string($conn, $head_filter) . "'") .
                (empty($manager_filter) ? '' : " AND cr.manager_id = '" . mysqli_real_escape_string($conn, $manager_filter) . "'") .
                " GROUP BY MONTH(cr.created_at), YEAR(cr.created_at)
                ORDER BY year DESC, month DESC";
 $monthly_result = mysqli_query($conn, $monthly_sql);

// Get department-wise statistics with filters
 $dept_sql = "SELECT 
               u.department,
               COUNT(*) AS count,
               SUM(cr.referral_amount) AS amount,
               SUM(cr.premium_with_gst) AS premium_with_gst,
               SUM(cr.without_gst) AS without_gst,
               AVG(cr.referral_amount) AS avg_amount,
               SUM(cr.referral_amount) / NULLIF(SUM(cr.premium_with_gst), 0) * 100 AS cashback_ratio
               FROM cashback_requests cr 
               JOIN users u ON cr.user_id = u.id 
               WHERE (cr.status = 'Paid' OR cr.status = 'Validator Approved')" . $filter_conditions . "
               GROUP BY u.department
               ORDER BY amount DESC";
 $dept_result = mysqli_query($conn, $dept_sql);

// Get head-wise statistics with filters
 $head_sql = "SELECT 
              h.id AS head_id,
              h.full_name AS head_name,
              h.department AS head_department,
              COUNT(*) AS count,
              SUM(cr.referral_amount) AS amount,
              SUM(cr.premium_with_gst) AS premium_with_gst,
              SUM(cr.without_gst) AS without_gst,
              SUM(cr.referral_amount) / NULLIF(SUM(cr.premium_with_gst), 0) * 100 AS cashback_ratio
              FROM cashback_requests cr 
              JOIN users u ON cr.user_id = u.id 
              LEFT JOIN users m ON u.manager_id = m.id 
              LEFT JOIN users h ON m.head_id = h.id 
              WHERE (cr.status = 'Paid' OR cr.status = 'Validator Approved')" . $filter_conditions . "
              GROUP BY h.id
              ORDER BY amount DESC";
 $head_result = mysqli_query($conn, $head_sql);

// Get manager-wise statistics with filters
 $manager_sql = "SELECT 
                 m.id AS manager_id,
                 m.full_name AS manager_name,
                 m.department AS manager_department,
                 COUNT(*) AS count,
                 SUM(cr.referral_amount) AS amount,
                 SUM(cr.premium_with_gst) AS premium_with_gst,
                 SUM(cr.without_gst) AS without_gst,
                 SUM(cr.referral_amount) / NULLIF(SUM(cr.premium_with_gst), 0) * 100 AS cashback_ratio
                 FROM cashback_requests cr 
                 JOIN users u ON cr.user_id = u.id 
                 LEFT JOIN users m ON u.manager_id = m.id 
                 WHERE (cr.status = 'Paid' OR cr.status = 'Validator Approved')" . $filter_conditions . "
                 GROUP BY m.id
                 ORDER BY amount DESC
                 LIMIT 10";
 $manager_result = mysqli_query($conn, $manager_sql);

// Get RM-wise statistics with filters
 $rm_sql = "SELECT 
            cr.rm_name,
            cr.department,
            COUNT(*) AS count,
            SUM(cr.referral_amount) AS amount,
            SUM(cr.premium_with_gst) AS premium_with_gst,
            SUM(cr.without_gst) AS without_gst,
            SUM(cr.referral_amount) / NULLIF(SUM(cr.premium_with_gst), 0) * 100 AS cashback_ratio
            FROM cashback_requests cr 
            WHERE (cr.status = 'Paid' OR cr.status = 'Validator Approved')" . $filter_conditions . "
            GROUP BY cr.rm_name
            ORDER BY amount DESC
            LIMIT 10";
 $rm_result = mysqli_query($conn, $rm_sql);

// Get day-wise statistics for the last 30 days
 $day_sql = "SELECT 
             DAY(cr.created_at) AS day,
             MONTH(cr.created_at) AS month,
             YEAR(cr.created_at) AS year,
             COUNT(*) AS count,
             SUM(cr.referral_amount) AS amount,
             SUM(cr.premium_with_gst) AS premium_with_gst,
             SUM(cr.without_gst) AS without_gst,
             SUM(cr.referral_amount) / NULLIF(SUM(cr.premium_with_gst), 0) * 100 AS cashback_ratio
             FROM cashback_requests cr 
             WHERE (cr.status = 'Paid' OR cr.status = 'Validator Approved')
             AND cr.created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)" . 
             (empty($department_filter) ? '' : " AND cr.department = '" . mysqli_real_escape_string($conn, $department_filter) . "'") .
             (empty($head_filter) ? '' : " AND cr.head_id = '" . mysqli_real_escape_string($conn, $head_filter) . "'") .
             (empty($manager_filter) ? '' : " AND cr.manager_id = '" . mysqli_real_escape_string($conn, $manager_filter) . "'") .
             " GROUP BY DAY(cr.created_at), MONTH(cr.created_at), YEAR(cr.created_at)
             ORDER BY year DESC, month DESC, day DESC";
 $day_result = mysqli_query($conn, $day_sql);

// Get approval time metrics (updated for Validator)
 $approval_time_sql = "SELECT 
                      cr.id,
                      cr.reference_number,
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
                      WHERE cr.status = 'Paid'" . $filter_conditions . "
                      GROUP BY cr.id
                      ORDER BY finance_approval_hours DESC
                      LIMIT 20";
 $approval_time_result = mysqli_query($conn, $approval_time_sql);

// Get insurance company-wise statistics
 $insurance_sql = "SELECT 
                 cr.insurance_company,
                 COUNT(*) AS count,
                 SUM(cr.referral_amount) AS amount,
                 SUM(cr.premium_with_gst) AS premium_with_gst,
                 SUM(cr.without_gst) AS without_gst,
                 SUM(cr.referral_amount) / NULLIF(SUM(cr.premium_with_gst), 0) * 100 AS cashback_ratio
                 FROM cashback_requests cr 
                 WHERE (cr.status = 'Paid' OR cr.status = 'Validator Approved')" . $filter_conditions . "
                 GROUP BY cr.insurance_company
                 ORDER BY amount DESC";
 $insurance_result = mysqli_query($conn, $insurance_sql);

// Get policy type-wise statistics
 $policy_sql = "SELECT 
              cr.policy_type,
              COUNT(*) AS count,
              SUM(cr.referral_amount) AS amount,
              SUM(cr.premium_with_gst) AS premium_with_gst,
              SUM(cr.without_gst) AS without_gst,
              SUM(cr.referral_amount) / NULLIF(SUM(cr.premium_with_gst), 0) * 100 AS cashback_ratio
              FROM cashback_requests cr 
              WHERE (cr.status = 'Paid' OR cr.status = 'Validator Approved')" . $filter_conditions . "
              GROUP BY cr.policy_type
              ORDER BY amount DESC";
 $policy_result = mysqli_query($conn, $policy_sql);

// Calculate average approval times (updated for Validator)
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
                    WHERE cr.status = 'Paid'" . $filter_conditions;
 $avg_approval_result = mysqli_query($conn, $avg_approval_sql);
 $avg_approval = mysqli_fetch_assoc($avg_approval_result);

// Get departments for filter dropdown
 $departments_sql = "SELECT DISTINCT department FROM users WHERE department != '' ORDER BY department";
 $departments_result = mysqli_query($conn, $departments_sql);

// Get heads for filter dropdown
 $heads_sql = "SELECT id, full_name FROM users WHERE role = 'Head' ORDER BY full_name";
 $heads_result = mysqli_query($conn, $heads_sql);

// Get managers for filter dropdown
 $managers_sql = "SELECT id, full_name FROM users WHERE role = 'Manager' ORDER BY full_name";
 $managers_result = mysqli_query($conn, $managers_sql);

// Get validators for filter dropdown
 $validators_sql = "SELECT id, full_name FROM users WHERE role = 'Validator' ORDER BY full_name";
 $validators_result = mysqli_query($conn, $validators_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finance Dashboard - Cashback System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/datepicker/0.6.5/datepicker.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/datepicker/0.6.5/datepicker.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link rel="icon" href="https://www.coveryou.in/images/favicon.png" type="image/png">
    <style>
        /* Same CSS as before */
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
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: var(--light);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
            text-align: center;
            position: relative;
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background-color: var(--primary);
        }
        .stat-card.primary::before {
            background-color: var(--primary);
        }
        .stat-card.success::before {
            background-color: var(--success);
        }
        .stat-card.warning::before {
            background-color: var(--warning);
        }
        .stat-card.danger::before {
            background-color: var(--danger);
        }
        .stat-card.secondary::before {
            background-color: var(--secondary);
        }
        .stat-card.info::before {
            background-color: #3182ce;
        }
        .stat-icon {
            font-size: 24px;
            margin-bottom: 10px;
            color: var(--primary);
        }
        .stat-card.success .stat-icon {
            color: var(--success);
        }
        .stat-card.warning .stat-icon {
            color: var(--warning);
        }
        .stat-card.danger .stat-icon {
            color: var(--danger);
        }
        .stat-card.secondary .stat-icon {
            color: var(--secondary);
        }
        .stat-card.info .stat-icon {
            color: #3182ce;
        }
        .stat-value {
            font-size: 28px;
            font-weight: bold;
            color: var(--dark);
            margin-bottom: 5px;
        }
        .stat-label {
            font-size: 14px;
            color: var(--text-light);
        }
        .stat-change {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 12px;
            font-weight: 500;
        }
        .stat-change.positive {
            color: var(--success);
        }
        .stat-change.negative {
            color: var(--danger);
        }
        .chart-container {
            height: 350px;
            margin-bottom: 30px;
            background: var(--light);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
        }
        .chart-title {
            font-size: 16px;
            color: var(--dark);
            margin-bottom: 15px;
            text-align: center;
        }
        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        .analytics-card {
            background: var(--light);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .analytics-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }
        .analytics-card h3 {
            font-size: 18px;
            color: var(--dark);
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .analytics-card h3 i {
            color: var(--primary);
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
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.15);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
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
        .dashboard-summary {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        .summary-card {
            flex: 1;
            min-width: 200px;
            background: var(--light);
            border-radius: var(--radius);
            padding: 15px;
            box-shadow: var(--shadow);
            text-align: center;
        }
        .summary-value {
            font-size: 22px;
            font-weight: bold;
            color: var(--primary);
        }
        .summary-label {
            font-size: 13px;
            color: var(--text-light);
            margin-top: 5px;
        }
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .kpi-card {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: white;
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }
        .kpi-card.success {
            background: linear-gradient(135deg, var(--success), #4caf50);
        }
        .kpi-card.warning {
            background: linear-gradient(135deg, var(--warning), #ffc107);
        }
        .kpi-card.danger {
            background: linear-gradient(135deg, var(--danger), #ff4d4d);
        }
        .kpi-card.secondary {
            background: linear-gradient(135deg, var(--secondary), #5b8def);
        }
        .kpi-card.info {
            background: linear-gradient(135deg, #3182ce, #63b3ed);
        }
        .kpi-icon {
            position: absolute;
            right: 20px;
            top: 20px;
            font-size: 36px;
            opacity: 0.3;
        }
        .kpi-value {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .kpi-label {
            font-size: 14px;
            opacity: 0.9;
        }
        .kpi-change {
            position: absolute;
            bottom: 15px;
            right: 15px;
            font-size: 12px;
            background: rgba(255, 255, 255, 0.2);
            padding: 3px 8px;
            border-radius: 12px;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 13px;
        }
        .data-table th, .data-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--gray);
        }
        .data-table th {
            background-color: #f8fafc;
            font-weight: 600;
            color: var(--dark);
        }
        .data-table tr:hover {
            background-color: #f8fafc;
        }
        .data-table .amount {
            text-align: right;
            font-weight: 500;
        }
        .data-table .count {
            text-align: center;
        }
        .data-table .ratio {
            text-align: center;
            font-weight: 500;
        }
        .chart-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        .chart-card {
            background: var(--light);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
        }
        .chart-card h3 {
            font-size: 16px;
            color: var(--dark);
            margin-bottom: 15px;
            text-align: center;
        }
        .mini-chart {
            height: 200px;
        }
        .export-options {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        .export-btn-group {
            position: relative;
            display: inline-block;
        }
        .export-dropdown {
            display: none;
            position: absolute;
            background-color: var(--light);
            min-width: 120px;
            box-shadow: var(--shadow);
            z-index: 1;
            border-radius: var(--radius);
            overflow: hidden;
            top: 100%;
            right: 0;
        }
        .export-dropdown a {
            color: var(--text);
            padding: 8px 12px;
            text-decoration: none;
            display: block;
            font-size: 13px;
        }
        .export-dropdown a:hover {
            background-color: var(--gray);
        }
        .export-btn-group:hover .export-dropdown {
            display: block;
        }
        .ratio-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
            background-color: #e6f7ff;
            color: #0050b3;
        }
        .ratio-badge.high {
            background-color: #fff2f0;
            color: #cf1322;
        }
        .ratio-badge.medium {
            background-color: #fff7e6;
            color: #d46b08;
        }
        .ratio-badge.low {
            background-color: #f6ffed;
            color: #389e0d;
        }
        .requests-btn {
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
            margin-top: 15px;
        }
        .requests-btn:hover {
            background-color: var(--primary-dark);
        }
        /* Responsive Styles */
        @media (max-width: 768px) {
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
                padding: 8px 10px;
            }
            
            .analytics-grid {
                grid-template-columns: 1fr;
            }
            
            .chart-grid {
                grid-template-columns: 1fr;
            }
            
            .kpi-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filter-container {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-btn {
                align-self: stretch;
                justify-content: center;
            }
            
            .dashboard-summary {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="logo">
                <i class="fas fa-chart-line logo-icon"></i>
                <div class="logo-text">Finance <span>Dashboard</span></div>
            </div>
            <p class="tagline">Comprehensive cashback analytics and approval system</p>
            
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
            <h2 class="section-title">
                Financial Overview
                
                <div style="text-align: center; margin-top: 30px;">
                    <a href="finance_requests.php" class="requests-btn">
                        <i class="fas fa-list"></i> View All Requests
                    </a>
                </div>

                <div class="export-options">
                    <div class="export-btn-group">
                        <button class="export-btn">
                            <i class="fas fa-download"></i> Export Report
                        </button>
                        <div class="export-dropdown">
                            <a href="#" onclick="exportReport('csv')">CSV</a>
                            <a href="#" onclick="exportReport('excel')">Excel</a>
                            <a href="#" onclick="exportReport('pdf')">PDF</a>
                        </div>
                        
                    </div>
                </div>
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
            
            <div class="kpi-grid">
                <div class="kpi-card primary">
                    <i class="fas fa-file-invoice-dollar kpi-icon"></i>
                    <div class="kpi-value"><?php echo $stats['total_requests']; ?></div>
                    <div class="kpi-label">Total Requests</div>
                    <div class="kpi-change">
                        <?php
                        // Calculate change from previous period
                        $prev_start_date = date('Y-m-d', strtotime("$start_date -30 days"));
                        $prev_end_date = date('Y-m-d', strtotime("$start_date -1 day"));
                        $prev_sql = "SELECT COUNT(*) AS count FROM cashback_requests 
                                     WHERE created_at BETWEEN '$prev_start_date 00:00:00' AND '$prev_end_date 23:59:59'";
                        $prev_result = mysqli_query($conn, $prev_sql);
                        $prev_data = mysqli_fetch_assoc($prev_result);
                        $change = $stats['total_requests'] - $prev_data['count'];
                        $change_percent = $prev_data['count'] > 0 ? round(($change / $prev_data['count']) * 100, 1) : 0;
                        echo $change >= 0 ? '+' : '';
                        echo $change_percent . '%';
                        ?>
                    </div>
                </div>
                
                <div class="kpi-card warning">
                    <i class="fas fa-hourglass-half kpi-icon"></i>
                    <div class="kpi-value"><?php echo $stats['pending_count']; ?></div>
                    <div class="kpi-label">Pending Approvals</div>
                    <div class="kpi-change">
                        <?php
                        $prev_pending_sql = "SELECT COUNT(*) AS count FROM cashback_requests 
                                            WHERE status = 'Validator Approved'
                                            AND created_at BETWEEN '$prev_start_date 00:00:00' AND '$prev_end_date 23:59:59'";
                        $prev_pending_result = mysqli_query($conn, $prev_pending_sql);
                        $prev_pending_data = mysqli_fetch_assoc($prev_pending_result);
                        $pending_change = $stats['pending_count'] - $prev_pending_data['count'];
                        $pending_change_percent = $prev_pending_data['count'] > 0 ? round(($pending_change / $prev_pending_data['count']) * 100, 1) : 0;
                        echo $pending_change >= 0 ? '+' : '';
                        echo $pending_change_percent . '%';
                        ?>
                    </div>
                </div>
                
                <div class="kpi-card success">
                    <i class="fas fa-check-circle kpi-icon"></i>
                    <div class="kpi-value"><?php echo $stats['approved_count']; ?></div>
                    <div class="kpi-label">Paid Requests</div>
                    <div class="kpi-change">
                        <?php
                        $prev_approved_sql = "SELECT COUNT(*) AS count FROM cashback_requests 
                                             WHERE status = 'Paid'
                                             AND created_at BETWEEN '$prev_start_date 00:00:00' AND '$prev_end_date 23:59:59'";
                        $prev_approved_result = mysqli_query($conn, $prev_approved_sql);
                        $prev_approved_data = mysqli_fetch_assoc($prev_approved_result);
                        $approved_change = $stats['approved_count'] - $prev_approved_data['count'];
                        $approved_change_percent = $prev_approved_data['count'] > 0 ? round(($approved_change / $prev_approved_data['count']) * 100, 1) : 0;
                        echo $approved_change >= 0 ? '+' : '';
                        echo $approved_change_percent . '%';
                        ?>
                    </div>
                </div>
                
                <div class="kpi-card danger">
                    <i class="fas fa-times-circle kpi-icon"></i>
                    <div class="kpi-value"><?php echo $stats['rejected_count']; ?></div>
                    <div class="kpi-label">Rejected Requests</div>
                    <div class="kpi-change">
                        <?php
                        $prev_rejected_sql = "SELECT COUNT(*) AS count FROM cashback_requests 
                                             WHERE status = 'Rejected'
                                             AND created_at BETWEEN '$prev_start_date 00:00:00' AND '$prev_end_date 23:59:59'";
                        $prev_rejected_result = mysqli_query($conn, $prev_rejected_sql);
                        $prev_rejected_data = mysqli_fetch_assoc($prev_rejected_result);
                        $rejected_change = $stats['rejected_count'] - $prev_rejected_data['count'];
                        $rejected_change_percent = $prev_rejected_data['count'] > 0 ? round(($rejected_change / $prev_rejected_data['count']) * 100, 1) : 0;
                        echo $rejected_change >= 0 ? '+' : '';
                        echo $rejected_change_percent . '%';
                        ?>
                    </div>
                </div>
                
                <div class="kpi-card info">
                    <i class="fas fa-percentage kpi-icon"></i>
                    <div class="kpi-value"><?php echo number_format($stats['cashback_ratio'], 2); ?>%</div>
                    <div class="kpi-label">Cashback Ratio</div>
                    <div class="kpi-change">
                        <?php
                        $prev_ratio_sql = "SELECT 
                                          SUM(cr.referral_amount) / NULLIF(SUM(cr.premium_with_gst), 0) * 100 AS ratio
                                          FROM cashback_requests cr
                                          WHERE cr.status = 'Paid'
                                          AND cr.created_at BETWEEN '$prev_start_date 00:00:00' AND '$prev_end_date 23:59:59'";
                        $prev_ratio_result = mysqli_query($conn, $prev_ratio_sql);
                        $prev_ratio_data = mysqli_fetch_assoc($prev_ratio_result);
                        $ratio_change = $stats['cashback_ratio'] - $prev_ratio_data['ratio'];
                        $ratio_change_percent = $prev_ratio_data['ratio'] > 0 ? round(($ratio_change / $prev_ratio_data['ratio']) * 100, 1) : 0;
                        echo $ratio_change >= 0 ? '+' : '';
                        echo $ratio_change_percent . '%';
                        ?>
                    </div>
                </div>
            </div>
            
            <div class="dashboard-summary">
                <div class="summary-card">
                    <div class="summary-value">₹<?php echo number_format($stats['pending_amount'], 2); ?></div>
                    <div class="summary-label">Pending Amount</div>
                </div>
                <div class="summary-card">
                    <div class="summary-value">₹<?php echo number_format($stats['approved_amount'], 2); ?></div>
                    <div class="summary-label">Paid Amount</div>
                </div>
                <div class="summary-card">
                    <div class="summary-value">₹<?php echo number_format($stats['rejected_amount'], 2); ?></div>
                    <div class="summary-label">Rejected Amount</div>
                </div>
                <div class="summary-card">
                    <div class="summary-value">₹<?php echo number_format($stats['total_premium_with_gst'], 2); ?></div>
                    <div class="summary-label">Total Premium (with GST)</div>
                </div>
                <div class="summary-card">
                    <div class="summary-value">₹<?php echo number_format($stats['avg_cashback'], 2); ?></div>
                    <div class="summary-label">Avg. Cashback Amount</div>
                </div>
            </div>
            
            <div class="chart-grid">
                <div class="chart-card">
                    <h3>Monthly Trend Analysis</h3>
                    <div class="mini-chart">
                        <canvas id="monthlyChart"></canvas>
                    </div>
                </div>
                
                <div class="chart-card">
                    <h3>Department-wise Distribution</h3>
                    <div class="mini-chart">
                        <canvas id="departmentChart"></canvas>
                    </div>
                </div>
                
                <div class="chart-card">
                    <h3>Approval Time Analysis</h3>
                    <div class="mini-chart">
                        <canvas id="approvalTimeChart"></canvas>
                    </div>
                </div>
                
                <div class="chart-card">
                    <h3>Cashback Ratio Trend</h3>
                    <div class="mini-chart">
                        <canvas id="ratioChart"></canvas>
                    </div>
                </div>
            </div>
            
            <div class="analytics-grid">
                <div class="analytics-card">
                    <h3>
                        Department-wise Analytics
                        <i class="fas fa-building"></i>
                    </h3>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Department</th>
                                    <th class="count">Requests</th>
                                    <th class="amount">Premium (with GST)</th>
                                    <th class="ratio">Cashback Ratio</th>
                                    <th class="amount">Total Cashback</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php mysqli_data_seek($dept_result, 0); ?>
                                <?php while ($dept = mysqli_fetch_assoc($dept_result)): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($dept['department']); ?></td>
                                        <td class="count"><?php echo $dept['count']; ?></td>
                                        <td class="amount">₹<?php echo number_format($dept['premium_with_gst'], 2); ?></td>
                                        <td class="ratio">
                                            <span class="ratio-badge <?php 
                                                echo $dept['cashback_ratio'] > 15 ? 'high' : 
                                                     ($dept['cashback_ratio'] > 10 ? 'medium' : 'low'); 
                                            ?>">
                                                <?php echo number_format($dept['cashback_ratio'], 2); ?>%
                                            </span>
                                        </td>
                                        <td class="amount">₹<?php echo number_format($dept['amount'], 2); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="analytics-card">
                    <h3>
                        Head-wise Analytics
                        <i class="fas fa-user-tie"></i>
                    </h3>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Head</th>
                                    <th>Department</th>
                                    <th class="count">Requests</th>
                                    <th class="ratio">Cashback Ratio</th>
                                    <th class="amount">Cashback Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php mysqli_data_seek($head_result, 0); ?>
                                <?php while ($head = mysqli_fetch_assoc($head_result)): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($head['head_name']); ?></td>
                                        <td><?php echo htmlspecialchars($head['head_department']); ?></td>
                                        <td class="count"><?php echo $head['count']; ?></td>
                                        <td class="ratio">
                                            <span class="ratio-badge <?php 
                                                echo $head['cashback_ratio'] > 15 ? 'high' : 
                                                     ($head['cashback_ratio'] > 10 ? 'medium' : 'low'); 
                                            ?>">
                                                <?php echo number_format($head['cashback_ratio'], 2); ?>%
                                            </span>
                                        </td>
                                        <td class="amount">₹<?php echo number_format($head['amount'], 2); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="analytics-card">
                    <h3>
                        Manager-wise Analytics
                        <i class="fas fa-users"></i>
                    </h3>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Manager</th>
                                    <th>Department</th>
                                    <th class="count">Requests</th>
                                    <th class="ratio">Cashback Ratio</th>
                                    <th class="amount">Cashback Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php mysqli_data_seek($manager_result, 0); ?>
                                <?php while ($manager = mysqli_fetch_assoc($manager_result)): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($manager['manager_name']); ?></td>
                                        <td><?php echo htmlspecialchars($manager['manager_department']); ?></td>
                                        <td class="count"><?php echo $manager['count']; ?></td>
                                        <td class="ratio">
                                            <span class="ratio-badge <?php 
                                                echo $manager['cashback_ratio'] > 15 ? 'high' : 
                                                     ($manager['cashback_ratio'] > 10 ? 'medium' : 'low'); 
                                            ?>">
                                                <?php echo number_format($manager['cashback_ratio'], 2); ?>%
                                            </span>
                                        </td>
                                        <td class="amount">₹<?php echo number_format($manager['amount'], 2); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="analytics-card">
                    <h3>
                        RM-wise Analytics
                        <i class="fas fa-user"></i>
                    </h3>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>RM Name</th>
                                    <th>Department</th>
                                    <th class="count">Requests</th>
                                    <th class="ratio">Cashback Ratio</th>
                                    <th class="amount">Cashback Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php mysqli_data_seek($rm_result, 0); ?>
                                <?php while ($rm = mysqli_fetch_assoc($rm_result)): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($rm['rm_name']); ?></td>
                                        <td><?php echo htmlspecialchars($rm['department']); ?></td>
                                        <td class="count"><?php echo $rm['count']; ?></td>
                                        <td class="ratio">
                                            <span class="ratio-badge <?php 
                                                echo $rm['cashback_ratio'] > 15 ? 'high' : 
                                                     ($rm['cashback_ratio'] > 10 ? 'medium' : 'low'); 
                                            ?>">
                                                <?php echo number_format($rm['cashback_ratio'], 2); ?>%
                                            </span>
                                        </td>
                                        <td class="amount">₹<?php echo number_format($rm['amount'], 2); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="analytics-card">
                    <h3>
                        Insurance Company Analytics
                        <i class="fas fa-shield-alt"></i>
                    </h3>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Insurance Company</th>
                                    <th class="count">Requests</th>
                                    <th class="ratio">Cashback Ratio</th>
                                    <th class="amount">Cashback Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php mysqli_data_seek($insurance_result, 0); ?>
                                <?php while ($insurance = mysqli_fetch_assoc($insurance_result)): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($insurance['insurance_company']); ?></td>
                                        <td class="count"><?php echo $insurance['count']; ?></td>
                                        <td class="ratio">
                                            <span class="ratio-badge <?php 
                                                echo $insurance['cashback_ratio'] > 15 ? 'high' : 
                                                     ($insurance['cashback_ratio'] > 10 ? 'medium' : 'low'); 
                                            ?>">
                                                <?php echo number_format($insurance['cashback_ratio'], 2); ?>%
                                            </span>
                                        </td>
                                        <td class="amount">₹<?php echo number_format($insurance['amount'], 2); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="analytics-card">
                    <h3>
                        Policy Type Analytics
                        <i class="fas fa-file-contract"></i>
                    </h3>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Policy Type</th>
                                    <th class="count">Requests</th>
                                    <th class="ratio">Cashback Ratio</th>
                                    <th class="amount">Cashback Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php mysqli_data_seek($policy_result, 0); ?>
                                <?php while ($policy = mysqli_fetch_assoc($policy_result)): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($policy['policy_type']); ?></td>
                                        <td class="count"><?php echo $policy['count']; ?></td>
                                        <td class="ratio">
                                            <span class="ratio-badge <?php 
                                                echo $policy['cashback_ratio'] > 15 ? 'high' : 
                                                     ($policy['cashback_ratio'] > 10 ? 'medium' : 'low'); 
                                            ?>">
                                                <?php echo number_format($policy['cashback_ratio'], 2); ?>%
                                            </span>
                                        </td>
                                        <td class="amount">₹<?php echo number_format($policy['amount'], 2); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="dashboard-summary">
                <div class="summary-card">
                    <div class="summary-value"><?php echo round($avg_approval['avg_manager_hours']); ?> hours</div>
                    <div class="summary-label">Avg. Manager Approval Time</div>
                </div>
                <div class="summary-card">
                    <div class="summary-value"><?php echo round($avg_approval['avg_head_hours']); ?> hours</div>
                    <div class="summary-label">Avg. Head Approval Time</div>
                </div>
                <div class="summary-card">
                    <div class="summary-value"><?php echo round($avg_approval['avg_validator_hours']); ?> hours</div>
                    <div class="summary-label">Avg. Validator Approval Time</div>
                </div>
                <div class="summary-card">
                    <div class="summary-value"><?php echo round($avg_approval['avg_finance_hours']); ?> hours</div>
                    <div class="summary-label">Avg. Finance Approval Time</div>
                </div>
            </div>
            
            <div style="text-align: center; margin-top: 30px;">
                <a href="finance_requests.php" class="requests-btn">
                    <i class="fas fa-list"></i> View All Requests
                </a>
            </div>
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
        });
        
        function applyFilters() {
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            const department = document.getElementById('department_filter').value;
            const head = document.getElementById('head_filter').value;
            const manager = document.getElementById('manager_filter').value;
            
            const url = new URL(window.location.href);
            
            if (startDate) url.searchParams.set('start_date', startDate);
            else url.searchParams.delete('start_date');
            
            if (endDate) url.searchParams.set('end_date', endDate);
            else url.searchParams.delete('end_date');
            
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
            window.location.href = url.toString();
        }
        
        function exportReport(format) {
            // This would typically make an AJAX call to a server-side script
            // that generates the report in the requested format
            alert('Export to ' + format.toUpperCase() + ' would be implemented here. This would typically involve a server-side script that generates the report.');
        }
        
        // Render monthly chart
        document.addEventListener('DOMContentLoaded', function() {
            const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
            
            const monthlyData = <?php 
                mysqli_data_seek($monthly_result, 0);
                $data = [];
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
            const ratios = monthlyData.map(item => item.cashback_ratio);
            
            new Chart(monthlyCtx, {
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
            
            // Render department chart
            const departmentCtx = document.getElementById('departmentChart').getContext('2d');
            
            const departmentData = <?php 
                mysqli_data_seek($dept_result, 0);
                $data = [];
                while ($row = mysqli_fetch_assoc($dept_result)) {
                    $data[] = $row;
                }
                echo json_encode($data);
            ?>;
            
            const departmentLabels = departmentData.map(item => item.department);
            const departmentAmounts = departmentData.map(item => item.amount);
            
            new Chart(departmentCtx, {
                type: 'doughnut',
                data: {
                    labels: departmentLabels,
                    datasets: [{
                        data: departmentAmounts,
                        backgroundColor: [
                            'rgba(240, 93, 73, 0.8)',
                            'rgba(75, 192, 192, 0.8)',
                            'rgba(153, 102, 255, 0.8)',
                            'rgba(255, 159, 64, 0.8)',
                            'rgba(54, 162, 235, 0.8)',
                            'rgba(255, 99, 132, 0.8)'
                        ],
                        borderColor: [
                            'rgba(240, 93, 73, 1)',
                            'rgba(75, 192, 192, 1)',
                            'rgba(153, 102, 255, 1)',
                            'rgba(255, 159, 64, 1)',
                            'rgba(54, 162, 235, 1)',
                            'rgba(255, 99, 132, 1)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right'
                        }
                    }
                }
            });
            
            // Render approval time chart
            const approvalTimeCtx = document.getElementById('approvalTimeChart').getContext('2d');
            
            new Chart(approvalTimeCtx, {
                type: 'bar',
                data: {
                    labels: ['Manager', 'Head', 'Finance'],
                    datasets: [{
                        label: 'Average Approval Time (hours)',
                        data: [
                            <?php echo round($avg_approval['avg_manager_hours']); ?>,
                            <?php echo round($avg_approval['avg_head_hours']); ?>,
                            <?php echo round($avg_approval['avg_finance_hours']); ?>
                        ],
                        backgroundColor: [
                            'rgba(54, 162, 235, 0.8)',
                            'rgba(255, 159, 64, 0.8)',
                            'rgba(75, 192, 192, 0.8)'
                        ],
                        borderColor: [
                            'rgba(54, 162, 235, 1)',
                            'rgba(255, 159, 64, 1)',
                            'rgba(75, 192, 192, 1)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Hours'
                            }
                        }
                    }
                }
            });
            
            // Render cashback ratio chart
            const ratioCtx = document.getElementById('ratioChart').getContext('2d');
            
            new Chart(ratioCtx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Cashback Ratio (%)',
                        data: ratios,
                        backgroundColor: 'rgba(75, 192, 192, 0.2)',
                        borderColor: 'rgba(75, 192, 192, 1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Ratio (%)'
                            }
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>