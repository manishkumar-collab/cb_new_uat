<?php
require_once 'config.php';

// Check if user is logged in and has validator role
if (!is_logged_in() || !has_role('Validator')) {
    show_notification('You do not have permission to access this page', 'error');
    redirect('login.php');
}

// Get current validator's ID
 $validator_id = $_SESSION['user_id'];

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

// Get pending requests (approved by Head OR with user justification) - Only for this validator
// MODIFIED: Now includes 'Pending Validation' status
 $pending_sql = "SELECT cr.*, u.full_name, u.emp_id, u.department 
              FROM cashback_requests cr 
              JOIN users u ON cr.user_id = u.id 
              WHERE (cr.status = 'Head Approved' OR cr.status = 'Pending Validation') AND u.validator_id = $validator_id" . $filter_conditions . "
              ORDER BY cr.created_at DESC";
 $pending_result = mysqli_query($conn, $pending_sql);

// Get approved/rejected requests by this validator - Only for this validator
// Updated to include finance processed requests
 $processed_sql = "SELECT cr.*, u.full_name, u.emp_id, u.department 
                FROM cashback_requests cr 
                JOIN users u ON cr.user_id = u.id 
                WHERE cr.status IN ('Validator Approved', 'Validator Rejected', 'Finance Approved', 'Paid') 
                AND u.validator_id = $validator_id" . $filter_conditions . "
                ORDER BY cr.updated_at DESC";
 $processed_result = mysqli_query($conn, $processed_sql);

// Get statistics - Only for this validator
// MODIFIED: Now includes 'Pending Validation' status in pending count
 $stats_sql = "SELECT 
            COUNT(*) AS total_requests,
            SUM(CASE WHEN status IN ('Head Approved', 'Pending Validation') THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN status IN ('Validator Approved', 'Validator Rejected', 'Finance Approved', 'Paid') THEN 1 ELSE 0 END) AS processed_count,
            SUM(CASE WHEN status IN ('Validator Approved', 'Validator Rejected', 'Finance Approved', 'Paid') AND status NOT LIKE '%Rejected%' THEN 1 ELSE 0 END) AS approved_count,
            SUM(CASE WHEN status LIKE '%Rejected%' THEN 1 ELSE 0 END) AS rejected_count,
            SUM(CASE WHEN status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN premium_with_gst ELSE 0 END) AS total_premium_with_gst,
            SUM(CASE WHEN status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN without_gst ELSE 0 END) AS total_premium_without_gst,
            SUM(CASE WHEN status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN referral_amount ELSE 0 END) AS total_cashback,
            SUM(CASE WHEN cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN premium_with_gst ELSE 0 END) AS cb_premium_with_gst,
            SUM(CASE WHEN cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN without_gst ELSE 0 END) AS cb_premium_without_gst,
            SUM(CASE WHEN cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN referral_amount ELSE 0 END) AS cb_cashback,
            SUM(CASE WHEN cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN premium_with_gst ELSE 0 END) AS shortfall_premium_with_gst,
            SUM(CASE WHEN cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN without_gst ELSE 0 END) AS shortfall_premium_without_gst,
            SUM(CASE WHEN cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN referral_amount ELSE 0 END) AS shortfall_cashback
            FROM cashback_requests cr
            JOIN users u ON cr.user_id = u.id
            WHERE u.validator_id = $validator_id" . $filter_conditions;
 $stats_result = mysqli_query($conn, $stats_sql);
 $stats = mysqli_fetch_assoc($stats_result);

// Calculate ratios
 $ratio_with_gst = $stats['total_premium_with_gst'] > 0 ? ($stats['total_cashback'] / $stats['total_premium_with_gst']) * 100 : 0;
 $ratio_without_gst = $stats['total_premium_without_gst'] > 0 ? ($stats['total_cashback'] / $stats['total_premium_without_gst']) * 100 : 0;

// Calculate CB and Shortfall ratios
 $cb_ratio_with_gst = $stats['cb_premium_with_gst'] > 0 ? ($stats['cb_cashback'] / $stats['cb_premium_with_gst']) * 100 : 0;
 $cb_ratio_without_gst = $stats['cb_premium_without_gst'] > 0 ? ($stats['cb_cashback'] / $stats['cb_premium_without_gst']) * 100 : 0;

 $shortfall_ratio_with_gst = $stats['shortfall_premium_with_gst'] > 0 ? ($stats['shortfall_cashback'] / $stats['shortfall_premium_with_gst']) * 100 : 0;
 $shortfall_ratio_without_gst = $stats['shortfall_premium_without_gst'] > 0 ? ($stats['shortfall_cashback'] / $stats['shortfall_premium_without_gst']) * 100 : 0;

// Get department-wise statistics - Only for this validator
 $dept_sql = "SELECT 
           u.department,
           COUNT(*) AS total_requests,
           SUM(CASE WHEN cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.premium_with_gst ELSE 0 END) AS dept_premium_with_gst,
           SUM(CASE WHEN cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.without_gst ELSE 0 END) AS dept_premium_without_gst,
           SUM(CASE WHEN cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.referral_amount ELSE 0 END) AS dept_cashback,
           SUM(CASE WHEN cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.premium_with_gst ELSE 0 END) AS cb_premium_with_gst,
           SUM(CASE WHEN cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.without_gst ELSE 0 END) AS cb_premium_without_gst,
           SUM(CASE WHEN cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.referral_amount ELSE 0 END) AS cb_cashback,
           SUM(CASE WHEN cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.premium_with_gst ELSE 0 END) AS shortfall_premium_with_gst,
           SUM(CASE WHEN cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.without_gst ELSE 0 END) AS shortfall_premium_without_gst,
           SUM(CASE WHEN cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.referral_amount ELSE 0 END) AS shortfall_cashback
           FROM cashback_requests cr
           JOIN users u ON cr.user_id = u.id
           WHERE u.validator_id = $validator_id" . $filter_conditions . "
           GROUP BY u.department";
 $dept_result = mysqli_query($conn, $dept_sql);

// Get head-wise statistics - Only for this validator
 $head_sql = "SELECT 
           h.full_name AS head_name,
           h.department AS head_department,
           COUNT(*) AS total_requests,
           SUM(CASE WHEN cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.premium_with_gst ELSE 0 END) AS head_premium_with_gst,
           SUM(CASE WHEN cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.without_gst ELSE 0 END) AS head_premium_without_gst,
           SUM(CASE WHEN cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.referral_amount ELSE 0 END) AS head_cashback,
           SUM(CASE WHEN cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.premium_with_gst ELSE 0 END) AS cb_premium_with_gst,
           SUM(CASE WHEN cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.without_gst ELSE 0 END) AS cb_premium_without_gst,
           SUM(CASE WHEN cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.referral_amount ELSE 0 END) AS cb_cashback,
           SUM(CASE WHEN cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.premium_with_gst ELSE 0 END) AS shortfall_premium_with_gst,
           SUM(CASE WHEN cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.without_gst ELSE 0 END) AS shortfall_premium_without_gst,
           SUM(CASE WHEN cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.referral_amount ELSE 0 END) AS shortfall_cashback
           FROM cashback_requests cr
           JOIN users u ON cr.user_id = u.id
           JOIN users h ON u.head_id = h.id
           WHERE u.validator_id = $validator_id" . $filter_conditions . "
           GROUP BY h.id, h.full_name, h.department";
 $head_result = mysqli_query($conn, $head_sql);

// Get manager-wise statistics - Only for this validator
 $manager_sql = "SELECT 
             m.full_name AS manager_name,
             m.department AS manager_department,
             h.full_name AS head_name,
             COUNT(*) AS total_requests,
             SUM(CASE WHEN cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.premium_with_gst ELSE 0 END) AS manager_premium_with_gst,
             SUM(CASE WHEN cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.without_gst ELSE 0 END) AS manager_premium_without_gst,
             SUM(CASE WHEN cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.referral_amount ELSE 0 END) AS manager_cashback,
             SUM(CASE WHEN cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.premium_with_gst ELSE 0 END) AS cb_premium_with_gst,
             SUM(CASE WHEN cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.without_gst ELSE 0 END) AS cb_premium_without_gst,
             SUM(CASE WHEN cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.referral_amount ELSE 0 END) AS cb_cashback,
             SUM(CASE WHEN cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.premium_with_gst ELSE 0 END) AS shortfall_premium_with_gst,
             SUM(CASE WHEN cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.without_gst ELSE 0 END) AS shortfall_premium_without_gst,
             SUM(CASE WHEN cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.referral_amount ELSE 0 END) AS shortfall_cashback
             FROM cashback_requests cr
             JOIN users u ON cr.user_id = u.id
             JOIN users m ON u.manager_id = m.id
             JOIN users h ON u.head_id = h.id
             WHERE u.validator_id = $validator_id" . $filter_conditions . "
             GROUP BY m.id, m.full_name, m.department, h.full_name";
 $manager_result = mysqli_query($conn, $manager_sql);

// Get team member-wise statistics - Only for this validator
 $member_sql = "SELECT 
             u.full_name AS member_name,
             u.department AS member_department,
             m.full_name AS manager_name,
             h.full_name AS head_name,
             COUNT(*) AS total_requests,
             SUM(CASE WHEN cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.premium_with_gst ELSE 0 END) AS member_premium_with_gst,
             SUM(CASE WHEN cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.without_gst ELSE 0 END) AS member_premium_without_gst,
             SUM(CASE WHEN cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.referral_amount ELSE 0 END) AS member_cashback,
             SUM(CASE WHEN cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.premium_with_gst ELSE 0 END) AS cb_premium_with_gst,
             SUM(CASE WHEN cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.without_gst ELSE 0 END) AS cb_premium_without_gst,
             SUM(CASE WHEN cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.referral_amount ELSE 0 END) AS cb_cashback,
             SUM(CASE WHEN cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.premium_with_gst ELSE 0 END) AS shortfall_premium_with_gst,
             SUM(CASE WHEN cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.without_gst ELSE 0 END) AS shortfall_premium_without_gst,
             SUM(CASE WHEN cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.referral_amount ELSE 0 END) AS shortfall_cashback
             FROM cashback_requests cr
             JOIN users u ON cr.user_id = u.id
             LEFT JOIN users m ON u.manager_id = m.id
             LEFT JOIN users h ON u.head_id = h.id
             WHERE u.validator_id = $validator_id" . $filter_conditions . "
             GROUP BY u.id, u.full_name, u.department, m.full_name, h.full_name";
 $member_result = mysqli_query($conn, $member_sql);

// Get departments for filter dropdown
 $departments_sql = "SELECT DISTINCT department FROM users WHERE department != '' ORDER BY department";
 $departments_result = mysqli_query($conn, $departments_sql);

// Get heads for filter dropdown
 $heads_sql = "SELECT id, full_name FROM users WHERE role = 'Head' ORDER BY full_name";
 $heads_result = mysqli_query($conn, $heads_sql);

// Get managers for filter dropdown
 $managers_sql = "SELECT id, full_name FROM users WHERE role = 'Manager' ORDER BY full_name";
 $managers_result = mysqli_query($conn, $managers_sql);

// Export functionality - Enhanced to include all fields - Only for this validator
if (isset($_GET['export']) && $_GET['export'] == 'all') {
    // Set headers for download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="cashback_requests_' . date('Y-m-d') . '.csv"');
    
    // Create a file pointer connected to the output stream
    $output = fopen('php://output', 'w');
    
    // Set column headers - Including all fields from database
    fputcsv($output, array(
        'ID', 
        'Form Type',
        'Reference Number', 
        'User ID',
        'User Name', 
        'User Employee ID',
        'User Department',
        'RM Employee ID',
        'RM Name',
        'Customer Name', 
        'Mobile Number', 
        'Month', 
        'Year', 
        'Insurance Company', 
        'Policy Type', 
        'Premium With GST', 
        'Premium Without GST', 
        'Referral Amount',
        'Attachment URL',
        'Policy Copy URL',
        'Payment Link',
        'Reason',
        'UTR Number',
        'Status',
        'Created Date', 
        'Updated Date',
        'Manager Approval Date',
        'Manager Approval Comments',
        'Head Approval Date',
        'Head Approval Comments',
        'Validator Approval Date',
        'Validator Approval Comments',
        'Finance Approval Date',
        'Finance Approval Comments'
    ));
    
    // Get all requests data with approval history - Only for this validator
    $export_sql = "SELECT cr.*, u.full_name, u.emp_id, u.department,
                  (SELECT a.created_at FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Manager' LIMIT 1) AS manager_approval_date,
                  (SELECT a.comments FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Manager' LIMIT 1) AS manager_approval_comments,
                  (SELECT a.created_at FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Head' LIMIT 1) AS head_approval_date,
                  (SELECT a.comments FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Head' LIMIT 1) AS head_approval_comments,
                  (SELECT a.created_at FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Validator' LIMIT 1) AS validator_approval_date,
                  (SELECT a.comments FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Validator' LIMIT 1) AS validator_approval_comments,
                  (SELECT a.created_at FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Finance' LIMIT 1) AS finance_approval_date,
                  (SELECT a.comments FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Finance' LIMIT 1) AS finance_approval_comments
                  FROM cashback_requests cr 
                  JOIN users u ON cr.user_id = u.id
                  WHERE u.validator_id = $validator_id" . $filter_conditions . "
                  ORDER BY cr.created_at DESC";
    $export_result = mysqli_query($conn, $export_sql);
    
    // Output each row of data
    while ($row = mysqli_fetch_assoc($export_result)) {
        fputcsv($output, array(
            $row['id'],
            $row['form_type'],
            $row['reference_number'],
            $row['user_id'],
            $row['full_name'],
            $row['emp_id'],
            $row['department'],
            $row['rm_emp_id'],
            $row['rm_name'],
            $row['customer_name'],
            $row['mobile_number'],
            $row['month'],
            $row['year'],
            $row['insurance_company'],
            $row['policy_type'],
            $row['premium_with_gst'],
            $row['without_gst'],
            $row['referral_amount'],
            $row['attachment_url'],
            $row['policy_copy_url'],
            $row['payment_link'],
            $row['reason'],
            $row['utr_number'],
            $row['status'],
            $row['created_at'],
            $row['updated_at'],
            $row['manager_approval_date'],
            $row['manager_approval_comments'],
            $row['head_approval_date'],
            $row['head_approval_comments'],
            $row['validator_approval_date'],
            $row['validator_approval_comments'],
            $row['finance_approval_date'],
            $row['finance_approval_comments']
        ));
    }
    
    fclose($output);
    exit;
}

// Export department-wise data - Only for this validator
if (isset($_GET['export']) && $_GET['export'] == 'department') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="department_wise_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    fputcsv($output, array(
        'Department',
        'Total Requests',
        'CB Premium With GST',
        'CB Premium Without GST',
        'CB Cashback',
        'CB Ratio (With GST)',
        'CB Ratio (Without GST)',
        'Shortfall Premium With GST',
        'Shortfall Premium Without GST',
        'Shortfall Cashback',
        'Shortfall Ratio (With GST)',
        'Shortfall Ratio (Without GST)'
    ));
    
    $export_dept_sql = "SELECT 
                       u.department,
                       COUNT(*) AS total_requests,
                       SUM(CASE WHEN cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.premium_with_gst ELSE 0 END) AS dept_premium_with_gst,
                       SUM(CASE WHEN cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.without_gst ELSE 0 END) AS dept_premium_without_gst,
                       SUM(CASE WHEN cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.referral_amount ELSE 0 END) AS dept_cashback,
                       SUM(CASE WHEN cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.premium_with_gst ELSE 0 END) AS cb_premium_with_gst,
                       SUM(CASE WHEN cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.without_gst ELSE 0 END) AS cb_premium_without_gst,
                       SUM(CASE WHEN cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.referral_amount ELSE 0 END) AS cb_cashback,
                       SUM(CASE WHEN cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.premium_with_gst ELSE 0 END) AS shortfall_premium_with_gst,
                       SUM(CASE WHEN cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.without_gst ELSE 0 END) AS shortfall_premium_without_gst,
                       SUM(CASE WHEN cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.referral_amount ELSE 0 END) AS shortfall_cashback
                       FROM cashback_requests cr
                       JOIN users u ON cr.user_id = u.id
                       WHERE u.validator_id = $validator_id" . $filter_conditions . "
                       GROUP BY u.department";
    $export_dept_result = mysqli_query($conn, $export_dept_sql);
    
    while ($dept = mysqli_fetch_assoc($export_dept_result)) {
        $cb_ratio_with_gst = $dept['cb_premium_with_gst'] > 0 ? ($dept['cb_cashback'] / $dept['cb_premium_with_gst']) * 100 : 0;
        $cb_ratio_without_gst = $dept['cb_premium_without_gst'] > 0 ? ($dept['cb_cashback'] / $dept['cb_premium_without_gst']) * 100 : 0;
        $shortfall_ratio_with_gst = $dept['shortfall_premium_with_gst'] > 0 ? ($dept['shortfall_cashback'] / $dept['shortfall_premium_with_gst']) * 100 : 0;
        $shortfall_ratio_without_gst = $dept['shortfall_premium_without_gst'] > 0 ? ($dept['shortfall_cashback'] / $dept['shortfall_premium_without_gst']) * 100 : 0;
        
        fputcsv($output, array(
            $dept['department'],
            $dept['total_requests'],
            $dept['cb_premium_with_gst'],
            $dept['cb_premium_without_gst'],
            $dept['cb_cashback'],
            number_format($cb_ratio_with_gst, 2) . '%',
            number_format($cb_ratio_without_gst, 2) . '%',
            $dept['shortfall_premium_with_gst'],
            $dept['shortfall_premium_without_gst'],
            $dept['shortfall_cashback'],
            number_format($shortfall_ratio_with_gst, 2) . '%',
            number_format($shortfall_ratio_without_gst, 2) . '%'
        ));
    }
    
    fclose($output);
    exit;
}

// Export head-wise data - Only for this validator
if (isset($_GET['export']) && $_GET['export'] == 'head') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="head_wise_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    fputcsv($output, array(
        'Head Name',
        'Head Department',
        'Total Requests',
        'CB Premium With GST',
        'CB Premium Without GST',
        'CB Cashback',
        'CB Ratio (With GST)',
        'CB Ratio (Without GST)',
        'Shortfall Premium With GST',
        'Shortfall Premium Without GST',
        'Shortfall Cashback',
        'Shortfall Ratio (With GST)',
        'Shortfall Ratio (Without GST)'
    ));
    
    $export_head_sql = "SELECT 
                       h.full_name AS head_name,
                       h.department AS head_department,
                       COUNT(*) AS total_requests,
                       SUM(CASE WHEN cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.premium_with_gst ELSE 0 END) AS head_premium_with_gst,
                       SUM(CASE WHEN cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.without_gst ELSE 0 END) AS head_premium_without_gst,
                       SUM(CASE WHEN cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.referral_amount ELSE 0 END) AS head_cashback,
                       SUM(CASE WHEN cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.premium_with_gst ELSE 0 END) AS cb_premium_with_gst,
                       SUM(CASE WHEN cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.without_gst ELSE 0 END) AS cb_premium_without_gst,
                       SUM(CASE WHEN cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.referral_amount ELSE 0 END) AS cb_cashback,
                       SUM(CASE WHEN cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.premium_with_gst ELSE 0 END) AS shortfall_premium_with_gst,
                       SUM(CASE WHEN cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.without_gst ELSE 0 END) AS shortfall_premium_without_gst,
                       SUM(CASE WHEN cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.referral_amount ELSE 0 END) AS shortfall_cashback
                       FROM cashback_requests cr
                       JOIN users u ON cr.user_id = u.id
                       JOIN users h ON u.head_id = h.id
                       WHERE u.validator_id = $validator_id" . $filter_conditions . "
                       GROUP BY h.id, h.full_name, h.department";
    $export_head_result = mysqli_query($conn, $export_head_sql);
    
    while ($head = mysqli_fetch_assoc($export_head_result)) {
        $cb_ratio_with_gst = $head['cb_premium_with_gst'] > 0 ? ($head['cb_cashback'] / $head['cb_premium_with_gst']) * 100 : 0;
        $cb_ratio_without_gst = $head['cb_premium_without_gst'] > 0 ? ($head['cb_cashback'] / $head['cb_premium_without_gst']) * 100 : 0;
        $shortfall_ratio_with_gst = $head['shortfall_premium_with_gst'] > 0 ? ($head['shortfall_cashback'] / $head['shortfall_premium_with_gst']) * 100 : 0;
        $shortfall_ratio_without_gst = $head['shortfall_premium_without_gst'] > 0 ? ($head['shortfall_cashback'] / $head['shortfall_premium_without_gst']) * 100 : 0;
        
        fputcsv($output, array(
            $head['head_name'],
            $head['head_department'],
            $head['total_requests'],
            $head['cb_premium_with_gst'],
            $head['cb_premium_without_gst'],
            $head['cb_cashback'],
            number_format($cb_ratio_with_gst, 2) . '%',
            number_format($cb_ratio_without_gst, 2) . '%',
            $head['shortfall_premium_with_gst'],
            $head['shortfall_premium_without_gst'],
            $head['shortfall_cashback'],
            number_format($shortfall_ratio_with_gst, 2) . '%',
            number_format($shortfall_ratio_without_gst, 2) . '%'
        ));
    }
    
    fclose($output);
    exit;
}

// Export manager-wise data - Only for this validator
if (isset($_GET['export']) && $_GET['export'] == 'manager') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="manager_wise_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    fputcsv($output, array(
        'Manager Name',
        'Manager Department',
        'Head Name',
        'Total Requests',
        'CB Premium With GST',
        'CB Premium Without GST',
        'CB Cashback',
        'CB Ratio (With GST)',
        'CB Ratio (Without GST)',
        'Shortfall Premium With GST',
        'Shortfall Premium Without GST',
        'Shortfall Cashback',
        'Shortfall Ratio (With GST)',
        'Shortfall Ratio (Without GST)'
    ));
    
    $export_manager_sql = "SELECT 
                          m.full_name AS manager_name,
                          m.department AS manager_department,
                          h.full_name AS head_name,
                          COUNT(*) AS total_requests,
                          SUM(CASE WHEN cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.premium_with_gst ELSE 0 END) AS manager_premium_with_gst,
                          SUM(CASE WHEN cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.without_gst ELSE 0 END) AS manager_premium_without_gst,
                          SUM(CASE WHEN cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.referral_amount ELSE 0 END) AS manager_cashback,
                          SUM(CASE WHEN cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.premium_with_gst ELSE 0 END) AS cb_premium_with_gst,
                          SUM(CASE WHEN cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.without_gst ELSE 0 END) AS cb_premium_without_gst,
                          SUM(CASE WHEN cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.referral_amount ELSE 0 END) AS cb_cashback,
                          SUM(CASE WHEN cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.premium_with_gst ELSE 0 END) AS shortfall_premium_with_gst,
                          SUM(CASE WHEN cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.without_gst ELSE 0 END) AS shortfall_premium_without_gst,
                          SUM(CASE WHEN cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.referral_amount ELSE 0 END) AS shortfall_cashback
                          FROM cashback_requests cr
                          JOIN users u ON cr.user_id = u.id
                          JOIN users m ON u.manager_id = m.id
                          JOIN users h ON u.head_id = h.id
                          WHERE u.validator_id = $validator_id" . $filter_conditions . "
                          GROUP BY m.id, m.full_name, m.department, h.full_name";
    $export_manager_result = mysqli_query($conn, $export_manager_sql);
    
    while ($manager = mysqli_fetch_assoc($export_manager_result)) {
        $cb_ratio_with_gst = $manager['cb_premium_with_gst'] > 0 ? ($manager['cb_cashback'] / $manager['cb_premium_with_gst']) * 100 : 0;
        $cb_ratio_without_gst = $manager['cb_premium_without_gst'] > 0 ? ($manager['cb_cashback'] / $manager['cb_premium_without_gst']) * 100 : 0;
        $shortfall_ratio_with_gst = $manager['shortfall_premium_with_gst'] > 0 ? ($manager['shortfall_cashback'] / $manager['shortfall_premium_with_gst']) * 100 : 0;
        $shortfall_ratio_without_gst = $manager['shortfall_premium_without_gst'] > 0 ? ($manager['shortfall_cashback'] / $manager['shortfall_premium_without_gst']) * 100 : 0;
        
        fputcsv($output, array(
            $manager['manager_name'],
            $manager['manager_department'],
            $manager['head_name'],
            $manager['total_requests'],
            $manager['cb_premium_with_gst'],
            $manager['cb_premium_without_gst'],
            $manager['cb_cashback'],
            number_format($cb_ratio_with_gst, 2) . '%',
            number_format($cb_ratio_without_gst, 2) . '%',
            $manager['shortfall_premium_with_gst'],
            $manager['shortfall_premium_without_gst'],
            $manager['shortfall_cashback'],
            number_format($shortfall_ratio_with_gst, 2) . '%',
            number_format($shortfall_ratio_without_gst, 2) . '%'
        ));
    }
    
    fclose($output);
    exit;
}

// Export member-wise data - Only for this validator
if (isset($_GET['export']) && $_GET['export'] == 'member') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="member_wise_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    fputcsv($output, array(
        'Member Name',
        'Member Department',
        'Member Employee ID',
        'Manager Name',
        'Head Name',
        'Total Requests',
        'CB Premium With GST',
        'CB Premium Without GST',
        'CB Cashback',
        'CB Ratio (With GST)',
        'CB Ratio (Without GST)',
        'Shortfall Premium With GST',
        'Shortfall Premium Without GST',
        'Shortfall Cashback',
        'Shortfall Ratio (With GST)',
        'Shortfall Ratio (Without GST)'
    ));
    
    $export_member_sql = "SELECT 
                         u.full_name AS member_name,
                         u.department AS member_department,
                         u.emp_id AS member_emp_id,
                         m.full_name AS manager_name,
                         h.full_name AS head_name,
                         COUNT(*) AS total_requests,
                         SUM(CASE WHEN cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.premium_with_gst ELSE 0 END) AS member_premium_with_gst,
                         SUM(CASE WHEN cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.without_gst ELSE 0 END) AS member_premium_without_gst,
                         SUM(CASE WHEN cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.referral_amount ELSE 0 END) AS member_cashback,
                         SUM(CASE WHEN cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.premium_with_gst ELSE 0 END) AS cb_premium_with_gst,
                         SUM(CASE WHEN cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.without_gst ELSE 0 END) AS cb_premium_without_gst,
                         SUM(CASE WHEN cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.referral_amount ELSE 0 END) AS cb_cashback,
                         SUM(CASE WHEN cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.premium_with_gst ELSE 0 END) AS shortfall_premium_with_gst,
                         SUM(CASE WHEN cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.without_gst ELSE 0 END) AS shortfall_premium_without_gst,
                         SUM(CASE WHEN cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid') THEN cr.referral_amount ELSE 0 END) AS shortfall_cashback
                         FROM cashback_requests cr
                         JOIN users u ON cr.user_id = u.id
                         LEFT JOIN users m ON u.manager_id = m.id
                         LEFT JOIN users h ON u.head_id = h.id
                         WHERE u.validator_id = $validator_id" . $filter_conditions . "
                         GROUP BY u.id, u.full_name, u.department, u.emp_id, m.full_name, h.full_name";
    $export_member_result = mysqli_query($conn, $export_member_sql);
    
    while ($member = mysqli_fetch_assoc($export_member_result)) {
        $cb_ratio_with_gst = $member['cb_premium_with_gst'] > 0 ? ($member['cb_cashback'] / $member['cb_premium_with_gst']) * 100 : 0;
        $cb_ratio_without_gst = $member['cb_premium_without_gst'] > 0 ? ($member['cb_cashback'] / $member['cb_premium_without_gst']) * 100 : 0;
        $shortfall_ratio_with_gst = $member['shortfall_premium_with_gst'] > 0 ? ($member['shortfall_cashback'] / $member['shortfall_premium_with_gst']) * 100 : 0;
        $shortfall_ratio_without_gst = $member['shortfall_premium_without_gst'] > 0 ? ($member['shortfall_cashback'] / $member['shortfall_premium_without_gst']) * 100 : 0;
        
        fputcsv($output, array(
            $member['member_name'],
            $member['member_department'],
            $member['member_emp_id'],
            $member['manager_name'],
            $member['head_name'],
            $member['total_requests'],
            $member['cb_premium_with_gst'],
            $member['cb_premium_without_gst'],
            $member['cb_cashback'],
            number_format($cb_ratio_with_gst, 2) . '%',
            number_format($cb_ratio_without_gst, 2) . '%',
            $member['shortfall_premium_with_gst'],
            $member['shortfall_premium_without_gst'],
            $member['shortfall_cashback'],
            number_format($shortfall_ratio_with_gst, 2) . '%',
            number_format($shortfall_ratio_without_gst, 2) . '%'
        ));
    }
    
    fclose($output);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Validator Dashboard - CB Account</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/datepicker/0.6.5/datepicker.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/datepicker/0.6.5/datepicker.min.js"></script>
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
            --sidebar-width: 250px;
        }
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            color: var(--text);
            line-height: 1.6;
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
            padding: 10px 12px;
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
        .btn-export {
            background-color: var(--success);
            color: white;
            margin-bottom: 15px;
        }
        .btn-export:hover {
            background-color: var(--success-dark);
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
        .status-paid {
            background-color: #e6f7ff;
            color: #1890ff;
        }
        .status-pending-validation {
            background-color: #f3e8ff;
            color: #9254de;
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
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
        }
        .modal-content {
            background-color: var(--light);
            margin: 10% auto;
            padding: 20px;
            border-radius: var(--radius);
            width: 80%;
            max-width: 500px;
            box-shadow: var(--shadow);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
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
        }
        .close:hover {
            color: var(--dark);
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--dark);
        }
        textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            font-size: 14px;
            min-height: 100px;
            resize: vertical;
        }
        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 15px;
        }
        /* Tabs Styles */
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
        /* Chart container */
        .chart-container {
            height: 300px;
            margin-bottom: 20px;
        }
        /* Ratio card */
        .ratio-card {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: white;
            padding: 20px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            text-align: center;
        }
        .ratio-value {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .ratio-label {
            font-size: 16px;
            opacity: 0.9;
        }
        /* Request Type Badge Styles */
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
        /* Premium Cards */
        .premium-card {
            background: linear-gradient(135deg, #f8fafc, #e2e8f0);
            border-radius: var(--radius);
            padding: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            border-left: 4px solid var(--primary);
        }
        .premium-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .premium-title i {
            color: var(--primary);
        }
        .premium-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        .premium-item {
            display: flex;
            flex-direction: column;
        }
        .premium-label {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 4px;
            font-weight: 500;
        }
        .premium-value {
            font-weight: 600;
            color: var(--dark);
            font-size: 14px;
        }
        /* Justification Badge Styles */
        .justification-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            margin-right: 5px;
            background-color: #f0f9ff;
            color: #096dd9;
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
            
            .filter-container {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-btn {
                align-self: stretch;
                justify-content: center;
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
                    <i class="fas fa-check-circle sidebar-logo-icon"></i>
                    <div class="sidebar-logo-text">Validator</div>
                </div>
            </div>
            
            <div class="sidebar-user">
                <div class="sidebar-user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                <div class="sidebar-user-role"><?php echo htmlspecialchars($_SESSION['role']); ?></div>
            </div>
            
            <nav class="sidebar-menu">
                <a href="dashboard_validator.php" class="sidebar-menu-item active">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>

                <a href="quote" class="sidebar-menu-item">
                    <i class="fas fa-file-invoice"></i> Quotation Section
                </a>
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
                        <i class="fas fa-check-circle logo-icon"></i>
                        <div class="logo-text">Validator <span>Dashboard</span></div>
                    </div>
                    <p class="tagline">Validate CB requests</p>
                    
                    <div class="user-info">
                        <div class="user-details">
                            <div class="username"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                            <div class="user-role"><?php echo htmlspecialchars($_SESSION['role']); ?> - <?php echo htmlspecialchars($_SESSION['department']); ?></div>
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
                    <h2 class="section-title">CB & Shortfall Ratio Analysis</h2>
                    
                    <div class="stats-grid">
                        <div class="ratio-card">
                            <div class="ratio-value"><?php echo number_format($ratio_with_gst, 2); ?>%</div>
                            <div class="ratio-label">Total CB Ratio (With GST)</div>
                        </div>
                        <div class="ratio-card">
                            <div class="ratio-value"><?php echo number_format($ratio_without_gst, 2); ?>%</div>
                            <div class="ratio-label">Total CB Ratio (Without GST)</div>
                        </div>
                        <div class="ratio-card">
                            <div class="ratio-value"><?php echo number_format($cb_ratio_with_gst, 2); ?>%</div>
                            <div class="ratio-label">CB Ratio (With GST)</div>
                        </div>
                        <div class="ratio-card">
                            <div class="ratio-value"><?php echo number_format($cb_ratio_without_gst, 2); ?>%</div>
                            <div class="ratio-label">CB Ratio (Without GST)</div>
                        </div>
                        <div class="ratio-card">
                            <div class="ratio-value"><?php echo number_format($shortfall_ratio_with_gst, 2); ?>%</div>
                            <div class="ratio-label">Shortfall Ratio (With GST)</div>
                        </div>
                        <div class="ratio-card">
                            <div class="ratio-value"><?php echo number_format($shortfall_ratio_without_gst, 2); ?>%</div>
                            <div class="ratio-label">Shortfall Ratio (Without GST)</div>
                        </div>
                    </div>
                    
                    <!-- Premium Cards -->
                    <div class="premium-card">
                        <div class="premium-title">
                            <i class="fas fa-money-bill-wave"></i>
                            Premium Details
                        </div>
                        <div class="premium-details">
                            <div class="premium-item">
                                <div class="premium-label">Total Premium (With GST)</div>
                                <div class="premium-value">₹<?php echo number_format($stats['total_premium_with_gst'], 2); ?></div>
                            </div>
                            <div class="premium-item">
                                <div class="premium-label">Total Premium (Without GST)</div>
                                <div class="premium-value">₹<?php echo number_format($stats['total_premium_without_gst'], 2); ?></div>
                            </div>
                            <div class="premium-item">
                                <div class="premium-label">CB Premium (With GST)</div>
                                <div class="premium-value">₹<?php echo number_format($stats['cb_premium_with_gst'], 2); ?></div>
                            </div>
                            <div class="premium-item">
                                <div class="premium-label">CB Premium (Without GST)</div>
                                <div class="premium-value">₹<?php echo number_format($stats['cb_premium_without_gst'], 2); ?></div>
                            </div>
                            <div class="premium-item">
                                <div class="premium-label">Shortfall Premium (With GST)</div>
                                <div class="premium-value">₹<?php echo number_format($stats['shortfall_premium_with_gst'], 2); ?></div>
                            </div>
                            <div class="premium-item">
                                <div class="premium-label">Shortfall Premium (Without GST)</div>
                                <div class="premium-value">₹<?php echo number_format($stats['shortfall_premium_without_gst'], 2); ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="chart-container">
                        <canvas id="ratioChart"></canvas>
                    </div>
                    
                    <h2 class="section-title">Validation Statistics</h2>
                    
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $stats['total_requests']; ?></div>
                            <div class="stat-label">Total Requests</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $stats['pending_count']; ?></div>
                            <div class="stat-label">Pending Validation</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $stats['processed_count']; ?></div>
                            <div class="stat-label">Processed Requests</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $stats['rejected_count']; ?></div>
                            <div class="stat-label">Rejected Requests</div>
                        </div>
                    </div>
                    
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
                        <div class="tab active" onclick="openTab(event, 'pending-tab')">Pending Validation</div>
                        <div class="tab" onclick="openTab(event, 'processed-tab')">Processed Requests</div>
                        <div class="tab" onclick="openTab(event, 'department-tab')">Department-wise</div>
                        <div class="tab" onclick="openTab(event, 'head-tab')">Head-wise</div>
                        <div class="tab" onclick="openTab(event, 'manager-tab')">Manager-wise</div>
                        <div class="tab" onclick="openTab(event, 'member-tab')">Team Member-wise</div>
                    </div>
                    
                    <div id="pending-tab" class="tab-content active">
                        <h2 class="section-title">Requests Pending Validation</h2>
                        
                        <?php if (mysqli_num_rows($pending_result) > 0): ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Reference #</th>
                                            <th>Type</th>
                                            <th>User</th>
                                            <th>Customer</th>
                                            <th>Month</th>
                                            <th>Premium With GST</th>
                                            <th>Referral Amount</th>
                                            <th>Reason</th>
                                            <th>Attachment</th>
                                            <th>Status</th>
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
                                                <td><?php echo htmlspecialchars($request['full_name']); ?></td>
                                                <td><?php echo htmlspecialchars($request['customer_name']); ?></td>
                                                <td><?php echo htmlspecialchars($request['month'] . ' ' . $request['year']); ?></td>
                                                <td>₹<?php echo number_format($request['premium_with_gst'], 2); ?></td>
                                                <td>₹<?php echo number_format($request['referral_amount'], 2); ?></td>
                                                <td><?php echo htmlspecialchars(substr($request['reason'], 0, 50) . (strlen($request['reason']) > 50 ? '...' : '')); ?></td>
                                                <td>
                                                    <?php if (!empty($request['attachment_url'])): ?>
                                                        <img src="<?php echo htmlspecialchars($request['attachment_url']); ?>" alt="Attachment" class="attachment-preview" onclick="viewAttachment('<?php echo htmlspecialchars($request['attachment_url']); ?>')">
                                                    <?php else: ?>
                                                        <span style="color: var(--text-light);">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($request['status'] === 'Pending Validation'): ?>
                                                        <span class="justification-badge">User Justification</span>
                                                    <?php else: ?>
                                                        <span class="status-badge status-pending">Head Approved</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <button class="btn btn-primary" onclick="viewRequest(<?php echo $request['id']; ?>)">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                    <button class="btn btn-success" onclick="validateRequest(<?php echo $request['id']; ?>, 'approve')">
                                                        <i class="fas fa-check"></i> Approve
                                                    </button>
                                                    <button class="btn btn-danger" onclick="validateRequest(<?php echo $request['id']; ?>, 'reject')">
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
                                <i class="fas fa-check-circle"></i>
                                <p>No requests pending validation</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div id="processed-tab" class="tab-content">
                        <h2 class="section-title">Processed Requests</h2>
                        
                        <?php if (mysqli_num_rows($processed_result) > 0): ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Reference #</th>
                                            <th>Type</th>
                                            <th>User</th>
                                            <th>Customer</th>
                                            <th>Month</th>
                                            <th>Premium With GST</th>
                                            <th>Referral Amount</th>
                                            <th>Status</th>
                                            <th>Processed Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($request = mysqli_fetch_assoc($processed_result)): ?>
                                            <?php
                                            $status_class = '';
                                            switch ($request['status']) {
                                                case 'Validator Approved':
                                                case 'Finance Approved':
                                                    $status_class = 'status-approved';
                                                    break;
                                                case 'Validator Rejected':
                                                    $status_class = 'status-rejected';
                                                    break;
                                                case 'Paid':
                                                    $status_class = 'status-paid';
                                                    break;
                                            }
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($request['reference_number']); ?></td>
                                                <td>
                                                    <span class="request-type-badge <?php echo $request['form_type'] === 'CB' ? 'request-type-cb' : 'request-type-shortfall'; ?>">
                                                        <?php echo htmlspecialchars($request['form_type']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($request['full_name']); ?></td>
                                                <td><?php echo htmlspecialchars($request['customer_name']); ?></td>
                                                <td><?php echo htmlspecialchars($request['month'] . ' ' . $request['year']); ?></td>
                                                <td>₹<?php echo number_format($request['premium_with_gst'], 2); ?></td>
                                                <td>₹<?php echo number_format($request['referral_amount'], 2); ?></td>
                                                <td>
                                                    <span class="status-badge <?php echo $status_class; ?>">
                                                        <?php echo htmlspecialchars($request['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('d M Y', strtotime($request['updated_at'])); ?></td>
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
                                <i class="fas fa-history"></i>
                                <p>No processed requests found</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div id="department-tab" class="tab-content">
                        <h2 class="section-title">Department-wise Statistics</h2>
                        
                        <a href="dashboard_validator.php?export=department" class="btn btn-export">
                            <i class="fas fa-download"></i> Export Department Data
                        </a>
                        
                        <?php if (mysqli_num_rows($dept_result) > 0): ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Department</th>
                                            <th>Total Requests</th>
                                            <th>CB</th>
                                            <th>CB Premium With GST</th>
                                            <th>CB Premium Without GST</th>
                                            <th>CB Cashback</th>
                                            <th>CB Ratio (With GST)</th>
                                            <th>CB Ratio (Without GST)</th>
                                            <th>Shortfall</th>
                                            <th>Shortfall Premium With GST</th>
                                            <th>Shortfall Premium Without GST</th>
                                            <th>Shortfall Cashback</th>
                                            <th>Shortfall Ratio (With GST)</th>
                                            <th>Shortfall Ratio (Without GST)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($dept = mysqli_fetch_assoc($dept_result)): ?>
                                            <?php
                                            $dept_ratio_with_gst = $dept['dept_premium_with_gst'] > 0 ? ($dept['dept_cashback'] / $dept['dept_premium_with_gst']) * 100 : 0;
                                            $dept_ratio_without_gst = $dept['dept_premium_without_gst'] > 0 ? ($dept['dept_cashback'] / $dept['dept_premium_without_gst']) * 100 : 0;
                                            $cb_ratio_with_gst = $dept['cb_premium_with_gst'] > 0 ? ($dept['cb_cashback'] / $dept['cb_premium_with_gst']) * 100 : 0;
                                            $cb_ratio_without_gst = $dept['cb_premium_without_gst'] > 0 ? ($dept['cb_cashback'] / $dept['cb_premium_without_gst']) * 100 : 0;
                                            $shortfall_ratio_with_gst = $dept['shortfall_premium_with_gst'] > 0 ? ($dept['shortfall_cashback'] / $dept['shortfall_premium_with_gst']) * 100 : 0;
                                            $shortfall_ratio_without_gst = $dept['shortfall_premium_without_gst'] > 0 ? ($dept['shortfall_cashback'] / $dept['shortfall_premium_without_gst']) * 100 : 0;
                                            
                                            // Calculate counts for CB and Shortfall
                                            $cb_count = 0;
                                            $shortfall_count = 0;
                                            
                                            // Get counts from separate queries
                                            $cb_count_sql = "SELECT COUNT(*) AS count FROM cashback_requests cr 
                                                           JOIN users u ON cr.user_id = u.id 
                                                           WHERE u.validator_id = $validator_id AND u.department = '" . $dept['department'] . "' 
                                                           AND cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid')";
                                            $cb_count_result = mysqli_query($conn, $cb_count_sql);
                                            $cb_count_row = mysqli_fetch_assoc($cb_count_result);
                                            $cb_count = $cb_count_row['count'];
                                            
                                            $shortfall_count_sql = "SELECT COUNT(*) AS count FROM cashback_requests cr 
                                                                   JOIN users u ON cr.user_id = u.id 
                                                                   WHERE u.validator_id = $validator_id AND u.department = '" . $dept['department'] . "' 
                                                                   AND cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid')";
                                            $shortfall_count_result = mysqli_query($conn, $shortfall_count_sql);
                                            $shortfall_count_row = mysqli_fetch_assoc($shortfall_count_result);
                                            $shortfall_count = $shortfall_count_row['count'];
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($dept['department']); ?></td>
                                                <td><?php echo $dept['total_requests']; ?></td>
                                                <td><?php echo $cb_count; ?></td>
                                                <td>₹<?php echo number_format($dept['cb_premium_with_gst'], 2); ?></td>
                                                <td>₹<?php echo number_format($dept['cb_premium_without_gst'], 2); ?></td>
                                                <td>₹<?php echo number_format($dept['cb_cashback'], 2); ?></td>
                                                <td><?php echo number_format($cb_ratio_with_gst, 2); ?>%</td>
                                                <td><?php echo number_format($cb_ratio_without_gst, 2); ?>%</td>
                                                <td><?php echo $shortfall_count; ?></td>
                                                <td>₹<?php echo number_format($dept['shortfall_premium_with_gst'], 2); ?></td>
                                                <td>₹<?php echo number_format($dept['shortfall_premium_without_gst'], 2); ?></td>
                                                <td>₹<?php echo number_format($dept['shortfall_cashback'], 2); ?></td>
                                                <td><?php echo number_format($shortfall_ratio_with_gst, 2); ?>%</td>
                                                <td><?php echo number_format($shortfall_ratio_without_gst, 2); ?>%</td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="no-data">
                                <i class="fas fa-building"></i>
                                <p>No department data found</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div id="head-tab" class="tab-content">
                        <h2 class="section-title">Head-wise Statistics</h2>
                        
                        <a href="dashboard_validator.php?export=head" class="btn btn-export">
                            <i class="fas fa-download"></i> Export Head Data
                        </a>
                        
                        <?php if (mysqli_num_rows($head_result) > 0): ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Head Name</th>
                                            <th>Department</th>
                                            <th>Total Requests</th>
                                            <th>CB</th>
                                            <th>CB Premium With GST</th>
                                            <th>CB Premium Without GST</th>
                                            <th>CB Cashback</th>
                                            <th>CB Ratio (With GST)</th>
                                            <th>CB Ratio (Without GST)</th>
                                            <th>Shortfall</th>
                                            <th>Shortfall Premium With GST</th>
                                            <th>Shortfall Premium Without GST</th>
                                            <th>Shortfall Cashback</th>
                                            <th>Shortfall Ratio (With GST)</th>
                                            <th>Shortfall Ratio (Without GST)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($head = mysqli_fetch_assoc($head_result)): ?>
                                            <?php
                                            $head_ratio_with_gst = $head['head_premium_with_gst'] > 0 ? ($head['head_cashback'] / $head['head_premium_with_gst']) * 100 : 0;
                                            $head_ratio_without_gst = $head['head_premium_without_gst'] > 0 ? ($head['head_cashback'] / $head['head_premium_without_gst']) * 100 : 0;
                                            $cb_ratio_with_gst = $head['cb_premium_with_gst'] > 0 ? ($head['cb_cashback'] / $head['cb_premium_with_gst']) * 100 : 0;
                                            $cb_ratio_without_gst = $head['cb_premium_without_gst'] > 0 ? ($head['cb_cashback'] / $head['cb_premium_without_gst']) * 100 : 0;
                                            $shortfall_ratio_with_gst = $head['shortfall_premium_with_gst'] > 0 ? ($head['shortfall_cashback'] / $head['shortfall_premium_with_gst']) * 100 : 0;
                                            $shortfall_ratio_without_gst = $head['shortfall_premium_without_gst'] > 0 ? ($head['shortfall_cashback'] / $head['shortfall_premium_without_gst']) * 100 : 0;
                                            
                                            // Calculate counts for CB and Shortfall
                                            $cb_count = 0;
                                            $shortfall_count = 0;
                                            
                                            // Get counts from separate queries
                                            $cb_count_sql = "SELECT COUNT(*) AS count FROM cashback_requests cr 
                                                           JOIN users u ON cr.user_id = u.id 
                                                           WHERE u.validator_id = $validator_id AND u.head_id = " . $head['id'] . " 
                                                           AND cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid')";
                                            $cb_count_result = mysqli_query($conn, $cb_count_sql);
                                            $cb_count_row = mysqli_fetch_assoc($cb_count_result);
                                            $cb_count = $cb_count_row['count'];
                                            
                                            $shortfall_count_sql = "SELECT COUNT(*) AS count FROM cashback_requests cr 
                                                                   JOIN users u ON cr.user_id = u.id 
                                                                   WHERE u.validator_id = $validator_id AND u.head_id = " . $head['id'] . " 
                                                                   AND cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid')";
                                            $shortfall_count_result = mysqli_query($conn, $shortfall_count_sql);
                                            $shortfall_count_row = mysqli_fetch_assoc($shortfall_count_result);
                                            $shortfall_count = $shortfall_count_row['count'];
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($head['head_name']); ?></td>
                                                <td><?php echo htmlspecialchars($head['head_department']); ?></td>
                                                <td><?php echo $head['total_requests']; ?></td>
                                                <td><?php echo $cb_count; ?></td>
                                                <td>₹<?php echo number_format($head['cb_premium_with_gst'], 2); ?></td>
                                                <td>₹<?php echo number_format($head['cb_premium_without_gst'], 2); ?></td>
                                                <td>₹<?php echo number_format($head['cb_cashback'], 2); ?></td>
                                                <td><?php echo number_format($cb_ratio_with_gst, 2); ?>%</td>
                                                <td><?php echo number_format($cb_ratio_without_gst, 2); ?>%</td>
                                                <td><?php echo $shortfall_count; ?></td>
                                                <td>₹<?php echo number_format($head['shortfall_premium_with_gst'], 2); ?></td>
                                                <td>₹<?php echo number_format($head['shortfall_premium_without_gst'], 2); ?></td>
                                                <td>₹<?php echo number_format($head['shortfall_cashback'], 2); ?></td>
                                                <td><?php echo number_format($shortfall_ratio_with_gst, 2); ?>%</td>
                                                <td><?php echo number_format($shortfall_ratio_without_gst, 2); ?>%</td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="no-data">
                                <i class="fas fa-user-tie"></i>
                                <p>No head data found</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div id="manager-tab" class="tab-content">
                        <h2 class="section-title">Manager-wise Statistics</h2>
                        
                        <a href="dashboard_validator.php?export=manager" class="btn btn-export">
                            <i class="fas fa-download"></i> Export Manager Data
                        </a>
                        
                        <?php if (mysqli_num_rows($manager_result) > 0): ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Manager Name</th>
                                            <th>Department</th>
                                            <th>Head Name</th>
                                            <th>Total Requests</th>
                                            <th>CB</th>
                                            <th>CB Premium With GST</th>
                                            <th>CB Premium Without GST</th>
                                            <th>CB Cashback</th>
                                            <th>CB Ratio (With GST)</th>
                                            <th>CB Ratio (Without GST)</th>
                                            <th>Shortfall</th>
                                            <th>Shortfall Premium With GST</th>
                                            <th>Shortfall Premium Without GST</th>
                                            <th>Shortfall Cashback</th>
                                            <th>Shortfall Ratio (With GST)</th>
                                            <th>Shortfall Ratio (Without GST)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($manager = mysqli_fetch_assoc($manager_result)): ?>
                                            <?php
                                            $manager_ratio_with_gst = $manager['manager_premium_with_gst'] > 0 ? ($manager['manager_cashback'] / $manager['manager_premium_with_gst']) * 100 : 0;
                                            $manager_ratio_without_gst = $manager['manager_premium_without_gst'] > 0 ? ($manager['manager_cashback'] / $manager['manager_premium_without_gst']) * 100 : 0;
                                            $cb_ratio_with_gst = $manager['cb_premium_with_gst'] > 0 ? ($manager['cb_cashback'] / $manager['cb_premium_with_gst']) * 100 : 0;
                                            $cb_ratio_without_gst = $manager['cb_premium_without_gst'] > 0 ? ($manager['cb_cashback'] / $manager['cb_premium_without_gst']) * 100 : 0;
                                            $shortfall_ratio_with_gst = $manager['shortfall_premium_with_gst'] > 0 ? ($manager['shortfall_cashback'] / $manager['shortfall_premium_with_gst']) * 100 : 0;
                                            $shortfall_ratio_without_gst = $manager['shortfall_premium_without_gst'] > 0 ? ($manager['shortfall_cashback'] / $manager['shortfall_premium_without_gst']) * 100 : 0;
                                            
                                            // Calculate counts for CB and Shortfall
                                            $cb_count = 0;
                                            $shortfall_count = 0;
                                            
                                            // Get counts from separate queries
                                            $cb_count_sql = "SELECT COUNT(*) AS count FROM cashback_requests cr 
                                                           JOIN users u ON cr.user_id = u.id 
                                                           WHERE u.validator_id = $validator_id AND u.manager_id = " . $manager['id'] . " 
                                                           AND cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid')";
                                            $cb_count_result = mysqli_query($conn, $cb_count_sql);
                                            $cb_count_row = mysqli_fetch_assoc($cb_count_result);
                                            $cb_count = $cb_count_row['count'];
                                            
                                            $shortfall_count_sql = "SELECT COUNT(*) AS count FROM cashback_requests cr 
                                                                   JOIN users u ON cr.user_id = u.id 
                                                                   WHERE u.validator_id = $validator_id AND u.manager_id = " . $manager['id'] . " 
                                                                   AND cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid')";
                                            $shortfall_count_result = mysqli_query($conn, $shortfall_count_sql);
                                            $shortfall_count_row = mysqli_fetch_assoc($shortfall_count_result);
                                            $shortfall_count = $shortfall_count_row['count'];
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($manager['manager_name']); ?></td>
                                                <td><?php echo htmlspecialchars($manager['manager_department']); ?></td>
                                                <td><?php echo htmlspecialchars($manager['head_name']); ?></td>
                                                <td><?php echo $manager['total_requests']; ?></td>
                                                <td><?php echo $cb_count; ?></td>
                                                <td>₹<?php echo number_format($manager['cb_premium_with_gst'], 2); ?></td>
                                                <td>₹<?php echo number_format($manager['cb_premium_without_gst'], 2); ?></td>
                                                <td>₹<?php echo number_format($manager['cb_cashback'], 2); ?></td>
                                                <td><?php echo number_format($cb_ratio_with_gst, 2); ?>%</td>
                                                <td><?php echo number_format($cb_ratio_without_gst, 2); ?>%</td>
                                                <td><?php echo $shortfall_count; ?></td>
                                                <td>₹<?php echo number_format($manager['shortfall_premium_with_gst'], 2); ?></td>
                                                <td>₹<?php echo number_format($manager['shortfall_premium_without_gst'], 2); ?></td>
                                                <td>₹<?php echo number_format($manager['shortfall_cashback'], 2); ?></td>
                                                <td><?php echo number_format($shortfall_ratio_with_gst, 2); ?>%</td>
                                                <td><?php echo number_format($shortfall_ratio_without_gst, 2); ?>%</td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="no-data">
                                <i class="fas fa-users-cog"></i>
                                <p>No manager data found</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div id="member-tab" class="tab-content">
                        <h2 class="section-title">Team Member-wise Statistics</h2>
                        
                        <a href="dashboard_validator.php?export=member" class="btn btn-export">
                            <i class="fas fa-download"></i> Export Team Member Data
                        </a>
                        
                        <?php if (mysqli_num_rows($member_result) > 0): ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Member Name</th>
                                            <th>Department</th>
                                            <th>Manager Name</th>
                                            <th>Head Name</th>
                                            <th>Total Requests</th>
                                            <th>CB</th>
                                            <th>CB Premium With GST</th>
                                            <th>CB Premium Without GST</th>
                                            <th>CB Cashback</th>
                                            <th>CB Ratio (With GST)</th>
                                            <th>CB Ratio (Without GST)</th>
                                            <th>Shortfall</th>
                                            <th>Shortfall Premium With GST</th>
                                            <th>Shortfall Premium Without GST</th>
                                            <th>Shortfall Cashback</th>
                                            <th>Shortfall Ratio (With GST)</th>
                                            <th>Shortfall Ratio (Without GST)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($member = mysqli_fetch_assoc($member_result)): ?>
                                            <?php
                                            $member_ratio_with_gst = $member['member_premium_with_gst'] > 0 ? ($member['member_cashback'] / $member['member_premium_with_gst']) * 100 : 0;
                                            $member_ratio_without_gst = $member['member_premium_without_gst'] > 0 ? ($member['member_cashback'] / $member['member_premium_without_gst']) * 100 : 0;
                                            $cb_ratio_with_gst = $member['cb_premium_with_gst'] > 0 ? ($member['cb_cashback'] / $member['cb_premium_with_gst']) * 100 : 0;
                                            $cb_ratio_without_gst = $member['cb_premium_without_gst'] > 0 ? ($member['cb_cashback'] / $member['cb_premium_without_gst']) * 100 : 0;
                                            $shortfall_ratio_with_gst = $member['shortfall_premium_with_gst'] > 0 ? ($member['shortfall_cashback'] / $member['shortfall_premium_with_gst']) * 100 : 0;
                                            $shortfall_ratio_without_gst = $member['shortfall_premium_without_gst'] > 0 ? ($member['shortfall_cashback'] / $member['shortfall_premium_without_gst']) * 100 : 0;
                                            
                                            // Calculate counts for CB and Shortfall
                                            $cb_count = 0;
                                            $shortfall_count = 0;
                                            
                                            // Get counts from separate queries
                                            $cb_count_sql = "SELECT COUNT(*) AS count FROM cashback_requests cr 
                                                           JOIN users u ON cr.user_id = u.id 
                                                           WHERE u.validator_id = $validator_id AND u.id = " . $member['id'] . " 
                                                           AND cr.form_type = 'CB' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid')";
                                            $cb_count_result = mysqli_query($conn, $cb_count_sql);
                                            $cb_count_row = mysqli_fetch_assoc($cb_count_result);
                                            $cb_count = $cb_count_row['count'];
                                            
                                            $shortfall_count_sql = "SELECT COUNT(*) AS count FROM cashback_requests cr 
                                                                   JOIN users u ON cr.user_id = u.id 
                                                                   WHERE u.validator_id = $validator_id AND u.id = " . $member['id'] . " 
                                                                   AND cr.form_type = 'Shortfall' AND cr.status IN ('Validator Approved', 'Finance Approved', 'Paid')";
                                            $shortfall_count_result = mysqli_query($conn, $shortfall_count_sql);
                                            $shortfall_count_row = mysqli_fetch_assoc($shortfall_count_result);
                                            $shortfall_count = $shortfall_count_row['count'];
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($member['member_name']); ?></td>
                                                <td><?php echo htmlspecialchars($member['member_department']); ?></td>
                                                <td><?php echo htmlspecialchars($member['manager_name']); ?></td>
                                                <td><?php echo htmlspecialchars($member['head_name']); ?></td>
                                                <td><?php echo $member['total_requests']; ?></td>
                                                <td><?php echo $cb_count; ?></td>
                                                <td>₹<?php echo number_format($member['cb_premium_with_gst'], 2); ?></td>
                                                <td>₹<?php echo number_format($member['cb_premium_without_gst'], 2); ?></td>
                                                <td>₹<?php echo number_format($member['cb_cashback'], 2); ?></td>
                                                <td><?php echo number_format($cb_ratio_with_gst, 2); ?>%</td>
                                                <td><?php echo number_format($cb_ratio_without_gst, 2); ?>%</td>
                                                <td><?php echo $shortfall_count; ?></td>
                                                <td>₹<?php echo number_format($member['shortfall_premium_with_gst'], 2); ?></td>
                                                <td>₹<?php echo number_format($member['shortfall_premium_without_gst'], 2); ?></td>
                                                <td>₹<?php echo number_format($member['shortfall_cashback'], 2); ?></td>
                                                <td><?php echo number_format($shortfall_ratio_with_gst, 2); ?>%</td>
                                                <td><?php echo number_format($shortfall_ratio_without_gst, 2); ?>%</td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="no-data">
                                <i class="fas fa-user"></i>
                                <p>No team member data found</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="dashboard-container">
                    <h2 class="section-title">Export All Requests</h2>
                    <p>You can export all CB requests data in CSV format for further analysis.</p>
                    <a href="dashboard_validator.php?export=all" class="btn btn-export">
                        <i class="fas fa-download"></i> Export All Requests
                    </a>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Validation Modal -->
    <div id="validationModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">Validate Request</h3>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="validationForm">
                    <input type="hidden" id="requestId" name="requestId">
                    <input type="hidden" id="validationAction" name="validationAction">
                    
                    <div class="form-group">
                        <label for="comments">Comments <span style="color:red;">*</span></label>
                        <textarea id="comments" name="comments" placeholder="Enter your comments here..." required></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="submitBtn">Submit</button>
                    </div>
                </form>
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
        
        // Create ratio chart
        const ctx = document.getElementById('ratioChart').getContext('2d');
        const ratioChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['CB', 'Shortfall', 'Remaining Premium'],
                datasets: [{
                    data: [<?php echo $stats['cb_cashback']; ?>, <?php echo $stats['shortfall_cashback']; ?>, <?php echo $stats['total_premium_with_gst'] - $stats['cb_cashback'] - $stats['shortfall_cashback']; ?>],
                    backgroundColor: [
                        '#f05d49',
                        '#d69e2e',
                        '#e2e8f0'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                    },
                    title: {
                        display: true,
                        text: 'CB vs Shortfall Distribution'
                    }
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
        }
        
        function viewRequest(requestId) {
            window.location.href = 'view_request.php?id=' + requestId;
        }
        
        function viewAttachment(url) {
            window.open(url, '_blank');
        }
        
        function validateRequest(requestId, action) {
            document.getElementById('requestId').value = requestId;
            document.getElementById('validationAction').value = action;
            
            const modalTitle = document.getElementById('modalTitle');
            const submitBtn = document.getElementById('submitBtn');
            
            if (action === 'approve') {
                modalTitle.textContent = 'Approve Request';
                submitBtn.textContent = 'Approve';
                submitBtn.className = 'btn btn-success';
            } else {
                modalTitle.textContent = 'Reject Request';
                submitBtn.textContent = 'Reject';
                submitBtn.className = 'btn btn-danger';
            }
            
            document.getElementById('validationModal').style.display = 'block';
        }
        
        function closeModal() {
            document.getElementById('validationModal').style.display = 'none';
            document.getElementById('comments').value = '';
        }
        
        // Handle form submission
        document.getElementById('validationForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const requestId = document.getElementById('requestId').value;
            const action = document.getElementById('validationAction').value;
            const comments = document.getElementById('comments').value;
            
            // Create form data
            const formData = new FormData();
            formData.append('requestId', requestId);
            formData.append('action', action);
            formData.append('comments', comments);
            
            // Send AJAX request
            fetch('process_validation.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeModal();
                    location.reload();
                } else {
                    alert(data.message || 'An error occurred');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred');
            });
        });
        
        // Apply filters
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
        
        // Reset filters
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
            const modal = document.getElementById('validationModal');
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>