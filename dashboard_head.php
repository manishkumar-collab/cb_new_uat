<?php
require_once 'config.php';

// Check if user is logged in and has head role
if (!is_logged_in() || !has_role('Head')) {
    show_notification('You do not have permission to access this page', 'error');
    redirect('login.php');
}

// Handle export request
if (isset($_GET['export']) && $_GET['export'] == 'all') {
    // Create a subquery to determine the actual status of each request
    $status_subquery = "SELECT 
                       cr.id,
                       CASE 
                           WHEN EXISTS (SELECT 1 FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Finance' AND a.status IN ('Approved', 'Paid')) THEN 'Finance Approved'
                           WHEN EXISTS (SELECT 1 FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Validator' AND a.status = 'Approved') THEN 'Validator Approved'
                           WHEN EXISTS (SELECT 1 FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Head' AND a.status = 'Approved') THEN 'Head Approved'
                           WHEN EXISTS (SELECT 1 FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Head' AND a.status = 'Rejected') THEN 'Rejected'
                           WHEN EXISTS (SELECT 1 FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Finance' AND a.status = 'Rejected') THEN 'Rejected'
                           WHEN EXISTS (SELECT 1 FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Validator' AND a.status = 'Rejected') THEN 'Rejected'
                           WHEN EXISTS (SELECT 1 FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Manager' AND a.status = 'Approved') THEN 'Manager Approved'
                           ELSE cr.status
                       END AS actual_status,
                       CASE 
                           WHEN EXISTS (SELECT 1 FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Head' AND a.status = 'Approved') THEN 1 ELSE 0 END AS is_head_approved,
                       CASE 
                           WHEN EXISTS (SELECT 1 FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Validator' AND a.status = 'Approved') THEN 1 ELSE 0 END AS is_validator_approved,
                       CASE 
                           WHEN EXISTS (SELECT 1 FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Finance' AND a.status IN ('Approved', 'Paid')) THEN 1 ELSE 0 END AS is_finance_approved,
                       CASE 
                           WHEN EXISTS (SELECT 1 FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Finance' AND a.status = 'Rejected') THEN 1 ELSE 0 END AS is_finance_rejected,
                       CASE 
                           WHEN EXISTS (SELECT 1 FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Validator' AND a.status = 'Rejected') THEN 1 ELSE 0 END AS is_validator_rejected
                       FROM cashback_requests cr";
    
    // Get all requests for this head with complete details
    $export_sql = "SELECT cr.*, u.full_name AS user_name, u.emp_id AS user_emp_id, u.department AS user_department,
                  m.full_name AS manager_name, m.emp_id AS manager_emp_id,
                  s.actual_status,
                  CASE WHEN s.is_head_approved = 1 THEN 'Yes' ELSE 'No' END AS head_approved,
                  CASE WHEN s.is_validator_approved = 1 THEN 'Yes' ELSE 'No' END AS validator_approved,
                  CASE WHEN s.is_finance_approved = 1 THEN 'Yes' ELSE 'No' END AS finance_approved,
                  CASE WHEN s.is_finance_rejected = 1 THEN 'Yes' ELSE 'No' END AS finance_rejected,
                  CASE WHEN s.is_validator_rejected = 1 THEN 'Yes' ELSE 'No' END AS validator_rejected
                  FROM cashback_requests cr 
                  JOIN users u ON cr.user_id = u.id 
                  JOIN users m ON u.manager_id = m.id 
                  JOIN ($status_subquery) s ON cr.id = s.id
                  WHERE m.head_id = ?
                  ORDER BY cr.created_at DESC";
    $stmt = mysqli_prepare($conn, $export_sql);
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    $export_result = mysqli_stmt_get_result($stmt);
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cashback_requests_' . date('Y-m-d') . '.csv"');
    
    // Create a file pointer
    $output = fopen('php://output', 'w');
    
    // Add BOM to fix Excel UTF-8 issues
    fputs($output, "\xEF\xBB\xBF");
    
    // Set column headers with all details
    fputcsv($output, array(
        'Reference #', 'Form Type', 'User Name', 'User Emp ID', 'Department', 
        'Manager Name', 'Manager Emp ID', 'Customer Name', 'Mobile Number', 
        'Month', 'Year', 'Insurance Company', 'Policy Type', 
        'Premium (with GST)', 'Premium (without GST)', 'Referral Amount', 
        'Status', 'Head Approved', 'Validator Approved', 'Finance Approved', 'Finance Rejected', 'Validator Rejected',
        'Reason', 'UTR Number', 'Attachment URL', 'Policy Copy URL', 'Payment Link',
        'Created At', 'Updated At'
    ));
    
    // Output each row of the data
    while ($row = mysqli_fetch_assoc($export_result)) {
        fputcsv($output, array(
            $row['reference_number'],
            $row['form_type'],
            $row['user_name'],
            $row['user_emp_id'],
            $row['user_department'],
            $row['manager_name'],
            $row['manager_emp_id'],
            $row['customer_name'],
            $row['mobile_number'],
            $row['month'],
            $row['year'],
            $row['insurance_company'],
            $row['policy_type'],
            $row['premium_with_gst'],
            $row['without_gst'],
            $row['referral_amount'],
            $row['actual_status'],
            $row['head_approved'],
            $row['validator_approved'],
            $row['finance_approved'],
            $row['finance_rejected'],
            $row['validator_rejected'],
            $row['reason'],
            $row['utr_number'],
            $row['attachment_url'],
            $row['policy_copy_url'],
            $row['payment_link'],
            $row['created_at'],
            $row['updated_at']
        ));
    }
    
    fclose($output);
    exit;
}

// Initialize filter variables
 $selected_month = isset($_GET['month']) ? $_GET['month'] : '';
 $selected_year = isset($_GET['year']) ? $_GET['year'] : '';
 $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
 $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
 $form_type_filter = isset($_GET['form_type']) ? $_GET['form_type'] : '';
 $manager_filter = isset($_GET['manager']) ? $_GET['manager'] : '';
 $team_filter = isset($_GET['team']) ? $_GET['team'] : '';
 $policy_type_filter = isset($_GET['policy_type']) ? $_GET['policy_type'] : '';
 $insurance_company_filter = isset($_GET['insurance_company']) ? $_GET['insurance_company'] : '';
 $search_query = isset($_GET['search']) ? $_GET['search'] : ''; // Capture search query

// Create a subquery to determine the actual status of each request
 $status_subquery = "SELECT 
                   cr.id,
                   CASE 
                       WHEN EXISTS (SELECT 1 FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Finance' AND a.status IN ('Approved', 'Paid')) THEN 'Finance Approved'
                       WHEN EXISTS (SELECT 1 FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Validator' AND a.status = 'Approved') THEN 'Validator Approved'
                       WHEN EXISTS (SELECT 1 FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Head' AND a.status = 'Approved') THEN 'Head Approved'
                       WHEN EXISTS (SELECT 1 FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Head' AND a.status = 'Rejected') THEN 'Rejected'
                       WHEN EXISTS (SELECT 1 FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Finance' AND a.status = 'Rejected') THEN 'Rejected'
                       WHEN EXISTS (SELECT 1 FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Validator' AND a.status = 'Rejected') THEN 'Rejected'
                       WHEN EXISTS (SELECT 1 FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Manager' AND a.status = 'Approved') THEN 'Manager Approved'
                       ELSE cr.status
                   END AS actual_status,
                   CASE 
                       WHEN EXISTS (SELECT 1 FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Head' AND a.status = 'Approved') THEN 1 ELSE 0 END AS is_head_approved,
                   CASE 
                       WHEN EXISTS (SELECT 1 FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Validator' AND a.status = 'Approved') THEN 1 ELSE 0 END AS is_validator_approved,
                   CASE 
                       WHEN EXISTS (SELECT 1 FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Finance' AND a.status IN ('Approved', 'Paid')) THEN 1 ELSE 0 END AS is_finance_approved,
                   CASE 
                       WHEN EXISTS (SELECT 1 FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Finance' AND a.status = 'Rejected') THEN 1 ELSE 0 END AS is_finance_rejected,
                   CASE 
                       WHEN EXISTS (SELECT 1 FROM approvals a WHERE a.request_id = cr.id AND a.approver_role = 'Validator' AND a.status = 'Rejected') THEN 1 ELSE 0 END AS is_validator_rejected
                   FROM cashback_requests cr";

// Get managers under this head - Modified to only get users with role 'manager'
 $managers_sql = "SELECT * FROM users WHERE head_id = ? AND role = 'manager'";
 $stmt = mysqli_prepare($conn, $managers_sql);
if (!$stmt) {
    show_notification('Database error: ' . mysqli_error($conn), 'error');
    redirect('login.php');
}
mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
 $managers_result = mysqli_stmt_get_result($stmt);

// Get all users under managers who report to this head
 $users_sql = "SELECT u.* FROM users u JOIN users m ON u.manager_id = m.id WHERE m.head_id = ?";
 $stmt = mysqli_prepare($conn, $users_sql);
if (!$stmt) {
    show_notification('Database error: ' . mysqli_error($conn), 'error');
    redirect('login.php');
}
mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
 $users_result = mysqli_stmt_get_result($stmt);

// NEW: Get Finances list for mapping display
 $finances_list_sql = "SELECT id, full_name FROM users WHERE role = 'Finance'";
 $finances_list_result = mysqli_query($conn, $finances_list_sql);
 $finances_list = [];
while ($finance = mysqli_fetch_assoc($finances_list_result)) {
    $finances_list[] = $finance;
}

// Get unique policy types for filter
 $policy_types_sql = "SELECT DISTINCT policy_type FROM cashback_requests";
 $stmt = mysqli_prepare($conn, $policy_types_sql);
if (!$stmt) {
    show_notification('Database error: ' . mysqli_error($conn), 'error');
    redirect('login.php');
}
mysqli_stmt_execute($stmt);
 $policy_types_result = mysqli_stmt_get_result($stmt);

// Get unique insurance companies for filter
 $insurance_companies_sql = "SELECT DISTINCT insurance_company FROM cashback_requests";
 $stmt = mysqli_prepare($conn, $insurance_companies_sql);
if (!$stmt) {
    show_notification('Database error: ' . mysqli_error($conn), 'error');
    redirect('login.php');
}
mysqli_stmt_execute($stmt);
 $insurance_companies_result = mysqli_stmt_get_result($stmt);

// Build the WHERE clause for filtering
 $where_clause = "WHERE m.head_id = ?";
 $params = array($_SESSION['user_id']);
 $types = "i";

// Add search query to where clause (Reference, Customer, User)
if (!empty($search_query)) {
    $where_clause .= " AND (cr.reference_number LIKE ? OR cr.customer_name LIKE ? OR u.full_name LIKE ?)";
    $search_term = "%$search_query%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "sss";
}

// Add date range filter if provided
if (!empty($start_date) && !empty($end_date)) {
    $where_clause .= " AND cr.created_at BETWEEN ? AND ?";
    $params[] = $start_date . " 00:00:00";
    $params[] = $end_date . " 23:59:59";
    $types .= "ss";
}

// Add form type filter if provided
if (!empty($form_type_filter)) {
    $where_clause .= " AND cr.form_type = ?";
    $params[] = $form_type_filter;
    $types .= "s";
}

// Add manager filter if provided
if (!empty($manager_filter)) {
    $where_clause .= " AND m.id = ?";
    $params[] = $manager_filter;
    $types .= "i";
}

// Add team filter if provided
if (!empty($team_filter)) {
    $where_clause .= " AND u.id = ?";
    $params[] = $team_filter;
    $types .= "i";
}

// Add policy type filter if provided
if (!empty($policy_type_filter)) {
    $where_clause .= " AND cr.policy_type = ?";
    $params[] = $policy_type_filter;
    $types .= "s";
}

// Add insurance company filter if provided
if (!empty($insurance_company_filter)) {
    $where_clause .= " AND cr.insurance_company = ?";
    $params[] = $insurance_company_filter;
    $types .= "s";
}

// Get pending cashback requests from users under this head
// UPDATED: Added u.finance_id in SELECT
 $pending_sql = "SELECT cr.*, u.full_name AS user_name, u.emp_id AS user_emp_id, u.department AS user_department, u.finance_id AS user_finance_id,
            m.full_name AS manager_name, m.emp_id AS manager_emp_id,
            s.actual_status,
            ma.created_at AS manager_approval_date,
            ha.created_at AS head_approval_date,
            va.created_at AS validator_approval_date,
            fa.created_at AS finance_approval_date,
            TIMESTAMPDIFF(HOUR, cr.created_at, ma.created_at) AS manager_time_taken,
            TIMESTAMPDIFF(HOUR, ma.created_at, ha.created_at) AS head_time_taken,
            TIMESTAMPDIFF(HOUR, ha.created_at, va.created_at) AS validator_time_taken,
            TIMESTAMPDIFF(HOUR, va.created_at, fa.created_at) AS finance_time_taken
            FROM cashback_requests cr 
            JOIN users u ON cr.user_id = u.id 
            JOIN users m ON u.manager_id = m.id 
            JOIN ($status_subquery) s ON cr.id = s.id
            LEFT JOIN approvals ma ON cr.id = ma.request_id AND ma.approver_role = 'Manager' AND ma.status = 'Approved'
            LEFT JOIN approvals ha ON cr.id = ha.request_id AND ha.approver_role = 'Head' AND ha.status = 'Approved'
            LEFT JOIN approvals va ON cr.id = va.request_id AND va.approver_role = 'Validator' AND va.status = 'Approved'
            LEFT JOIN approvals fa ON cr.id = fa.request_id AND fa.approver_role = 'Finance' AND fa.status IN ('Approved', 'Paid')
            $where_clause AND s.actual_status = 'Manager Approved'
            ORDER BY cr.created_at DESC";
 $stmt = mysqli_prepare($conn, $pending_sql);
if (!$stmt) {
    show_notification('Database error: ' . mysqli_error($conn), 'error');
    redirect('login.php');
}
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
 $pending_result = mysqli_stmt_get_result($stmt);

// Get approved cashback requests by this head
 $approved_sql = "SELECT cr.*, u.full_name AS user_name, u.emp_id AS user_emp_id, u.department AS user_department, u.finance_id AS user_finance_id,
            m.full_name AS manager_name, m.emp_id AS manager_emp_id,
            s.actual_status,
            ma.created_at AS manager_approval_date,
            ha.created_at AS head_approval_date,
            va.created_at AS validator_approval_date,
            fa.created_at AS finance_approval_date,
            TIMESTAMPDIFF(HOUR, cr.created_at, ma.created_at) AS manager_time_taken,
            TIMESTAMPDIFF(HOUR, ma.created_at, ha.created_at) AS head_time_taken,
            TIMESTAMPDIFF(HOUR, ha.created_at, va.created_at) AS validator_time_taken,
            TIMESTAMPDIFF(HOUR, va.created_at, fa.created_at) AS finance_time_taken
            FROM cashback_requests cr 
            JOIN users u ON cr.user_id = u.id 
            JOIN users m ON u.manager_id = m.id 
            JOIN ($status_subquery) s ON cr.id = s.id
            LEFT JOIN approvals ma ON cr.id = ma.request_id AND ma.approver_role = 'Manager' AND ma.status = 'Approved'
            LEFT JOIN approvals ha ON cr.id = ha.request_id AND ha.approver_role = 'Head' AND ha.status = 'Approved'
            LEFT JOIN approvals va ON cr.id = va.request_id AND va.approver_role = 'Validator' AND va.status = 'Approved'
            LEFT JOIN approvals fa ON cr.id = fa.request_id AND fa.approver_role = 'Finance' AND fa.status IN ('Approved', 'Paid')
            $where_clause AND (s.actual_status = 'Head Approved' OR s.actual_status = 'Validator Approved' OR s.actual_status = 'Finance Approved')
            ORDER BY cr.created_at DESC";
 $stmt = mysqli_prepare($conn, $approved_sql);
if (!$stmt) {
    show_notification('Database error: ' . mysqli_error($conn), 'error');
    redirect('login.php');
}
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
 $approved_result = mysqli_stmt_get_result($stmt);

// Get rejected cashback requests
 $rejected_sql = "SELECT cr.*, u.full_name AS user_name, u.emp_id AS user_emp_id, u.department AS user_department, u.finance_id AS user_finance_id,
            m.full_name AS manager_name, m.emp_id AS manager_emp_id,
            s.actual_status,
            ma.created_at AS manager_approval_date,
            ha.created_at AS head_approval_date,
            va.created_at AS validator_approval_date,
            fa.created_at AS finance_approval_date,
            ra.created_at AS rejection_date,
            ra.comments AS rejection_reason,
            ra.approver_role AS rejected_by_role,
            TIMESTAMPDIFF(HOUR, cr.created_at, ma.created_at) AS manager_time_taken,
            TIMESTAMPDIFF(HOUR, ma.created_at, ha.created_at) AS head_time_taken,
            TIMESTAMPDIFF(HOUR, ha.created_at, va.created_at) AS validator_time_taken,
            TIMESTAMPDIFF(HOUR, va.created_at, fa.created_at) AS finance_time_taken
            FROM cashback_requests cr 
            JOIN users u ON cr.user_id = u.id 
            JOIN users m ON u.manager_id = m.id 
            JOIN ($status_subquery) s ON cr.id = s.id
            LEFT JOIN approvals ma ON cr.id = ma.request_id AND ma.approver_role = 'Manager' AND ma.status = 'Approved'
            LEFT JOIN approvals ha ON cr.id = ha.request_id AND ha.approver_role = 'Head' AND ha.status = 'Approved'
            LEFT JOIN approvals va ON cr.id = va.request_id AND va.approver_role = 'Validator' AND va.status = 'Approved'
            LEFT JOIN approvals fa ON cr.id = fa.request_id AND fa.approver_role = 'Finance' AND fa.status IN ('Approved', 'Paid')
            LEFT JOIN approvals ra ON cr.id = ra.request_id AND ra.status = 'Rejected'
            $where_clause AND s.actual_status = 'Rejected'
            ORDER BY cr.created_at DESC";
 $stmt = mysqli_prepare($conn, $rejected_sql);
if (!$stmt) {
    show_notification('Database error: ' . mysqli_error($conn), 'error');
    redirect('login.php');
}
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
 $rejected_result = mysqli_stmt_get_result($stmt);

// Get statistics - UPDATED LOGIC FOR PREMIUM RATIO
 $stats_sql = "SELECT 
        COUNT(*) AS total_requests,
        SUM(CASE WHEN s.actual_status = 'Manager Approved' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN s.is_head_approved = 1 THEN 1 ELSE 0 END) AS approved_count,
        SUM(CASE WHEN s.is_validator_approved = 1 THEN 1 ELSE 0 END) AS validator_approved_count,
        SUM(CASE WHEN s.is_finance_approved = 1 THEN 1 ELSE 0 END) AS finance_approved_count,
        SUM(CASE WHEN s.is_finance_rejected = 1 THEN 1 ELSE 0 END) AS finance_rejected_count,
        SUM(CASE WHEN s.is_validator_rejected = 1 THEN 1 ELSE 0 END) AS validator_rejected_count,
        SUM(CASE WHEN s.actual_status = 'Manager Approved' THEN cr.referral_amount ELSE 0 END) AS pending_amount,
        SUM(CASE WHEN s.is_head_approved = 1 THEN cr.referral_amount ELSE 0 END) AS approved_amount,
        SUM(CASE WHEN s.is_validator_approved = 1 THEN cr.referral_amount ELSE 0 END) AS validator_approved_amount,
        SUM(CASE WHEN s.is_finance_approved = 1 THEN cr.referral_amount ELSE 0 END) AS finance_approved_amount,
        SUM(CASE WHEN s.is_finance_rejected = 1 THEN cr.referral_amount ELSE 0 END) AS finance_rejected_amount,
        SUM(CASE WHEN s.is_validator_rejected = 1 THEN cr.referral_amount ELSE 0 END) AS validator_rejected_amount,
        
        -- Premium Sums based on Approved Status logic
        SUM(CASE WHEN s.is_head_approved = 1 THEN cr.premium_with_gst ELSE 0 END) AS head_premium_with_gst,
        SUM(CASE WHEN s.is_validator_approved = 1 THEN cr.premium_with_gst ELSE 0 END) AS validator_premium_with_gst,
        SUM(CASE WHEN s.is_finance_approved = 1 THEN cr.premium_with_gst ELSE 0 END) AS finance_premium_with_gst,
        SUM(CASE WHEN s.is_finance_rejected = 1 THEN cr.premium_with_gst ELSE 0 END) AS finance_rejected_premium_with_gst,
        SUM(CASE WHEN s.is_validator_rejected = 1 THEN cr.premium_with_gst ELSE 0 END) AS validator_rejected_premium_with_gst,
        
        SUM(CASE WHEN s.is_head_approved = 1 THEN cr.without_gst ELSE 0 END) AS head_without_gst,
        SUM(CASE WHEN s.is_validator_approved = 1 THEN cr.without_gst ELSE 0 END) AS validator_without_gst,
        SUM(CASE WHEN s.is_finance_approved = 1 THEN cr.without_gst ELSE 0 END) AS finance_without_gst,
        SUM(CASE WHEN s.is_finance_rejected = 1 THEN cr.without_gst ELSE 0 END) AS finance_rejected_without_gst,
        SUM(CASE WHEN s.is_validator_rejected = 1 THEN cr.without_gst ELSE 0 END) AS validator_rejected_without_gst,
        
        -- Total Premium for Ratio Calculation (All Approved Requests)
        SUM(CASE WHEN (s.is_head_approved = 1 OR s.is_validator_approved = 1 OR s.is_finance_approved = 1) THEN cr.premium_with_gst ELSE 0 END) AS total_premium_all_requests,
        SUM(CASE WHEN (s.is_head_approved = 1 OR s.is_validator_approved = 1 OR s.is_finance_approved = 1) THEN cr.without_gst ELSE 0 END) AS total_without_gst_all_requests,
        
        -- CB and Shortfall Totals
        SUM(CASE WHEN (s.is_head_approved = 1 OR s.is_validator_approved = 1 OR s.is_finance_approved = 1) AND cr.form_type = 'CB' THEN cr.referral_amount ELSE 0 END) AS total_cb_amount,
        SUM(CASE WHEN (s.is_head_approved = 1 OR s.is_validator_approved = 1 OR s.is_finance_approved = 1) AND cr.form_type = 'Shortfall' THEN cr.referral_amount ELSE 0 END) AS total_shortfall_amount
        FROM cashback_requests cr 
        JOIN users u ON cr.user_id = u.id 
        JOIN users m ON u.manager_id = m.id 
        JOIN ($status_subquery) s ON cr.id = s.id
        WHERE m.head_id = ?";
        
 $stmt = mysqli_prepare($conn, $stats_sql);
if (!$stmt) {
    show_notification('Database error: ' . mysqli_error($conn), 'error');
    redirect('login.php');
}
mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
 $stats_result = mysqli_stmt_get_result($stmt);
 $stats = mysqli_fetch_assoc($stats_result);

// --- CALCULATION LOGIC UPDATE START ---

// 1. ROUND THE TOTAL AMOUNTS FIRST (To match displayed figures)
 $total_premium_with_gst_rounded = round($stats['total_premium_all_requests']);
 $total_premium_without_gst_rounded = round($stats['total_without_gst_all_requests']);
 $total_cb_amount_rounded = round($stats['total_cb_amount']);
 $total_shortfall_amount_rounded = round($stats['total_shortfall_amount']);

// 2. Calculate CB % using ROUNDED figures to ensure exact match with display
 $cashback_percentage_with_gst_display = "0.00%";
if ($total_premium_with_gst_rounded > 0) {
    $cashback_percentage_with_gst = ($total_cb_amount_rounded / $total_premium_with_gst_rounded) * 100;
    // Changed to 2 decimal places for Round Figure
    $cashback_percentage_with_gst_display = number_format($cashback_percentage_with_gst, 2) . "%";
}

// 3. Calculate CB % using ROUNDED figures (Without GST)
 $cashback_percentage_without_gst_display = "0.00%";
if ($total_premium_without_gst_rounded > 0) {
    $cashback_percentage_without_gst = ($total_cb_amount_rounded / $total_premium_without_gst_rounded) * 100;
    // Changed to 2 decimal places for Round Figure
    $cashback_percentage_without_gst_display = number_format($cashback_percentage_without_gst, 2) . "%";
}

// 4. Calculate Shortfall % using ROUNDED figures
 $shortfall_percentage_with_gst_display = "0.00%";
if ($total_premium_with_gst_rounded > 0) {
    $shortfall_percentage_with_gst = ($total_shortfall_amount_rounded / $total_premium_with_gst_rounded) * 100;
    // Changed to 2 decimal places for Round Figure
    $shortfall_percentage_with_gst_display = number_format($shortfall_percentage_with_gst, 2) . "%";
}

// 5. Calculate Shortfall % using ROUNDED figures (Without GST)
 $shortfall_percentage_without_gst_display = "0.00%";
if ($total_premium_without_gst_rounded > 0) {
    $shortfall_percentage_without_gst = ($total_shortfall_amount_rounded / $total_premium_without_gst_rounded) * 100;
    // Changed to 2 decimal places for Round Figure
    $shortfall_percentage_without_gst_display = number_format($shortfall_percentage_without_gst, 2) . "%";
}

// --- CALCULATION LOGIC UPDATE END ---

// Prepare Month Filter for Analytics Queries
 $analytics_where_filter = $where_clause; // Start with existing filters
 $analytics_params = $params;
 $analytics_types = $types;

if (!empty($selected_month) && !empty($selected_year)) {
    $analytics_where_filter .= " AND MONTH(cr.created_at) = ? AND YEAR(cr.created_at) = ?";
    $analytics_params[] = $selected_month;
    $analytics_params[] = $selected_year;
    $analytics_types .= "ii";
}

// Get department-wise statistics
 $dept_sql = "SELECT 
        u.department,
        COUNT(*) AS count,
        SUM(cr.referral_amount) AS amount,
        SUM(cr.premium_with_gst) AS premium_with_gst,
        SUM(cr.without_gst) AS without_gst,
        SUM(CASE WHEN cr.form_type = 'CB' THEN cr.referral_amount ELSE 0 END) AS cb_amount,
        SUM(CASE WHEN cr.form_type = 'Shortfall' THEN cr.referral_amount ELSE 0 END) AS shortfall_amount,
        SUM(CASE WHEN cr.form_type = 'CB' THEN cr.premium_with_gst ELSE 0 END) AS cb_premium_with_gst,
        SUM(CASE WHEN cr.form_type = 'Shortfall' THEN cr.premium_with_gst ELSE 0 END) AS shortfall_premium_with_gst
        FROM cashback_requests cr 
        JOIN users u ON cr.user_id = u.id 
        JOIN users m ON u.manager_id = m.id 
        JOIN ($status_subquery) s ON cr.id = s.id
        $analytics_where_filter AND (s.actual_status = 'Head Approved' OR s.actual_status = 'Validator Approved' OR s.actual_status = 'Finance Approved')
        GROUP BY u.department
        ORDER BY amount DESC";
 $stmt = mysqli_prepare($conn, $dept_sql);
if (!$stmt) {
    show_notification('Database error: ' . mysqli_error($conn), 'error');
    redirect('login.php');
}
mysqli_stmt_bind_param($stmt, $analytics_types, ...$analytics_params);
mysqli_stmt_execute($stmt);
 $dept_result = mysqli_stmt_get_result($stmt);

// Get manager-wise statistics
 $manager_stats_sql = "SELECT 
                   m.full_name AS manager_name,
                   m.department AS manager_department,
                   COUNT(*) AS count,
                   SUM(cr.referral_amount) AS amount,
                   SUM(cr.premium_with_gst) AS premium_with_gst,
                   SUM(cr.without_gst) AS without_gst,
                   SUM(CASE WHEN cr.form_type = 'CB' THEN cr.referral_amount ELSE 0 END) AS cb_amount,
                   SUM(CASE WHEN cr.form_type = 'Shortfall' THEN cr.referral_amount ELSE 0 END) AS shortfall_amount,
                   SUM(CASE WHEN cr.form_type = 'CB' THEN cr.premium_with_gst ELSE 0 END) AS cb_premium_with_gst,
                   SUM(CASE WHEN cr.form_type = 'Shortfall' THEN cr.premium_with_gst ELSE 0 END) AS shortfall_premium_with_gst
                   FROM cashback_requests cr 
                   JOIN users u ON cr.user_id = u.id 
                   JOIN users m ON u.manager_id = m.id 
                   JOIN ($status_subquery) s ON cr.id = s.id
                   $analytics_where_filter AND (s.actual_status = 'Head Approved' OR s.actual_status = 'Validator Approved' OR s.actual_status = 'Finance Approved')
                   GROUP BY m.id
                   ORDER BY amount DESC";
 $stmt = mysqli_prepare($conn, $manager_stats_sql);
if (!$stmt) {
    show_notification('Database error: ' . mysqli_error($conn), 'error');
    redirect('login.php');
}
mysqli_stmt_bind_param($stmt, $analytics_types, ...$analytics_params);
mysqli_stmt_execute($stmt);
 $manager_stats_result = mysqli_stmt_get_result($stmt);

// Get monthly statistics
 $monthly_sql = "SELECT 
            MONTH(cr.created_at) AS month,
            YEAR(cr.created_at) AS year,
            COUNT(*) AS count,
            SUM(cr.referral_amount) AS amount,
            SUM(cr.premium_with_gst) AS premium_with_gst,
            SUM(cr.without_gst) AS without_gst,
            SUM(CASE WHEN cr.form_type = 'CB' THEN cr.referral_amount ELSE 0 END) AS cb_amount,
            SUM(CASE WHEN cr.form_type = 'Shortfall' THEN cr.referral_amount ELSE 0 END) AS shortfall_amount,
            SUM(CASE WHEN cr.form_type = 'CB' THEN cr.premium_with_gst ELSE 0 END) AS cb_premium_with_gst,
            SUM(CASE WHEN cr.form_type = 'Shortfall' THEN cr.premium_with_gst ELSE 0 END) AS shortfall_premium_with_gst
            FROM cashback_requests cr 
            JOIN users u ON cr.user_id = u.id 
            JOIN users m ON u.manager_id = m.id 
            JOIN ($status_subquery) s ON cr.id = s.id
            WHERE m.head_id = ? AND (s.actual_status = 'Head Approved' OR s.actual_status = 'Validator Approved' OR s.actual_status = 'Finance Approved')
            GROUP BY MONTH(cr.created_at), YEAR(cr.created_at)
            ORDER BY year DESC, month DESC
            LIMIT 6";
 $stmt = mysqli_prepare($conn, $monthly_sql);
if (!$stmt) {
    show_notification('Database error: ' . mysqli_error($conn), 'error');
    redirect('login.php');
}
mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
 $monthly_result = mysqli_stmt_get_result($stmt);

// Get team-wise statistics
 $team_stats_sql = "SELECT 
                u.full_name AS team_member_name,
                u.department AS team_member_department,
                m.full_name AS manager_name,
                COUNT(*) AS count,
                SUM(cr.referral_amount) AS amount,
                SUM(cr.premium_with_gst) AS premium_with_gst,
                SUM(cr.without_gst) AS without_gst,
                SUM(CASE WHEN cr.form_type = 'CB' THEN cr.referral_amount ELSE 0 END) AS cb_amount,
                SUM(CASE WHEN cr.form_type = 'Shortfall' THEN cr.referral_amount ELSE 0 END) AS shortfall_amount,
                SUM(CASE WHEN cr.form_type = 'CB' THEN cr.premium_with_gst ELSE 0 END) AS cb_premium_with_gst,
                SUM(CASE WHEN cr.form_type = 'Shortfall' THEN cr.premium_with_gst ELSE 0 END) AS shortfall_premium_with_gst
                FROM cashback_requests cr 
                JOIN users u ON cr.user_id = u.id 
                JOIN users m ON u.manager_id = m.id 
                JOIN ($status_subquery) s ON cr.id = s.id
                $analytics_where_filter AND (s.actual_status = 'Head Approved' OR s.actual_status = 'Validator Approved' OR s.actual_status = 'Finance Approved')
                GROUP BY u.id
                ORDER BY amount DESC
                LIMIT 10";
 $stmt = mysqli_prepare($conn, $team_stats_sql);
if (!$stmt) {
    show_notification('Database error: ' . mysqli_error($conn), 'error');
    redirect('login.php');
}
mysqli_stmt_bind_param($stmt, $analytics_types, ...$analytics_params);
mysqli_stmt_execute($stmt);
 $team_stats_result = mysqli_stmt_get_result($stmt);

// Get policy type-wise statistics
 $policy_type_stats_sql = "SELECT 
                        cr.policy_type,
                        COUNT(*) AS count,
                        SUM(cr.referral_amount) AS amount,
                        SUM(cr.premium_with_gst) AS premium_with_gst,
                        SUM(cr.without_gst) AS without_gst,
                        SUM(CASE WHEN cr.form_type = 'CB' THEN cr.referral_amount ELSE 0 END) AS cb_amount,
                        SUM(CASE WHEN cr.form_type = 'Shortfall' THEN cr.referral_amount ELSE 0 END) AS shortfall_amount,
                        SUM(CASE WHEN cr.form_type = 'CB' THEN cr.premium_with_gst ELSE 0 END) AS cb_premium_with_gst,
                        SUM(CASE WHEN cr.form_type = 'Shortfall' THEN cr.premium_with_gst ELSE 0 END) AS shortfall_premium_with_gst
                        FROM cashback_requests cr 
                        JOIN users u ON cr.user_id = u.id 
                        JOIN users m ON u.manager_id = m.id 
                        JOIN ($status_subquery) s ON cr.id = s.id
                        $analytics_where_filter AND (s.actual_status = 'Head Approved' OR s.actual_status = 'Validator Approved' OR s.actual_status = 'Finance Approved')
                        GROUP BY cr.policy_type
                        ORDER BY amount DESC";
 $stmt = mysqli_prepare($conn, $policy_type_stats_sql);
if (!$stmt) {
    show_notification('Database error: ' . mysqli_error($conn), 'error');
    redirect('login.php');
}
mysqli_stmt_bind_param($stmt, $analytics_types, ...$analytics_params);
mysqli_stmt_execute($stmt);
 $policy_type_stats_result = mysqli_stmt_get_result($stmt);

// Get insurance company-wise statistics
 $insurance_company_stats_sql = "SELECT 
                              cr.insurance_company,
                              COUNT(*) AS count,
                              SUM(cr.referral_amount) AS amount,
                              SUM(cr.premium_with_gst) AS premium_with_gst,
                              SUM(cr.without_gst) AS without_gst,
                              SUM(CASE WHEN cr.form_type = 'CB' THEN cr.referral_amount ELSE 0 END) AS cb_amount,
                              SUM(CASE WHEN cr.form_type = 'Shortfall' THEN cr.referral_amount ELSE 0 END) AS shortfall_amount,
                              SUM(CASE WHEN cr.form_type = 'CB' THEN cr.premium_with_gst ELSE 0 END) AS cb_premium_with_gst,
                              SUM(CASE WHEN cr.form_type = 'Shortfall' THEN cr.premium_with_gst ELSE 0 END) AS shortfall_premium_with_gst
                              FROM cashback_requests cr 
                              JOIN users u ON cr.user_id = u.id 
                              JOIN users m ON u.manager_id = m.id 
                              JOIN ($status_subquery) s ON cr.id = s.id
                              $analytics_where_filter AND (s.actual_status = 'Head Approved' OR s.actual_status = 'Validator Approved' OR s.actual_status = 'Finance Approved')
                              GROUP BY cr.insurance_company
                              ORDER BY amount DESC";
 $stmt = mysqli_prepare($conn, $insurance_company_stats_sql);
if (!$stmt) {
    show_notification('Database error: ' . mysqli_error($conn), 'error');
    redirect('login.php');
}
mysqli_stmt_bind_param($stmt, $analytics_types, ...$analytics_params);
mysqli_stmt_execute($stmt);
 $insurance_company_stats_result = mysqli_stmt_get_result($stmt);

// Get month-wise statistics for new dashboard card - Updated Logic
 $month_wise_sql = "SELECT 
                   cr.month,
                   cr.year,
                   -- Premium Totals for CB forms (Only Approved)
                   SUM(CASE WHEN cr.form_type = 'CB' AND (s.is_head_approved = 1 OR s.is_validator_approved = 1 OR s.is_finance_approved = 1) THEN cr.premium_with_gst ELSE 0 END) AS cb_premium_with_gst,
                   SUM(CASE WHEN cr.form_type = 'Shortfall' AND (s.is_head_approved = 1 OR s.is_validator_approved = 1 OR s.is_finance_approved = 1) THEN cr.premium_with_gst ELSE 0 END) AS shortfall_premium_with_gst,
                   SUM(CASE WHEN cr.form_type = 'CB' AND (s.is_head_approved = 1 OR s.is_validator_approved = 1 OR s.is_finance_approved = 1) THEN cr.without_gst ELSE 0 END) AS cb_premium_without_gst,
                   SUM(CASE WHEN cr.form_type = 'Shortfall' AND (s.is_head_approved = 1 OR s.is_validator_approved = 1 OR s.is_finance_approved = 1) THEN cr.without_gst ELSE 0 END) AS shortfall_premium_without_gst,
                   -- Amount Totals
                   SUM(CASE WHEN cr.form_type = 'CB' AND (s.is_head_approved = 1 OR s.is_validator_approved = 1 OR s.is_finance_approved = 1) THEN cr.referral_amount ELSE 0 END) AS cb_amount,
                   SUM(CASE WHEN cr.form_type = 'Shortfall' AND (s.is_head_approved = 1 OR s.is_validator_approved = 1 OR s.is_finance_approved = 1) THEN cr.referral_amount ELSE 0 END) AS shortfall_amount
                   FROM cashback_requests cr 
                   JOIN users u ON cr.user_id = u.id 
                   JOIN users m ON u.manager_id = m.id 
                   JOIN ($status_subquery) s ON cr.id = s.id
                   WHERE m.head_id = ? AND (s.is_head_approved = 1 OR s.is_validator_approved = 1 OR s.is_finance_approved = 1)
                   GROUP BY cr.month, cr.year
                   ORDER BY cr.year DESC, cr.month DESC";
 $stmt = mysqli_prepare($conn, $month_wise_sql);
if (!$stmt) {
    show_notification('Database error: ' . mysqli_error($conn), 'error');
    redirect('login.php');
}
mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
 $month_wise_result = mysqli_stmt_get_result($stmt);

// Get unique months for dropdown
 $months_sql = "SELECT DISTINCT cr.month, cr.year
               FROM cashback_requests cr 
               JOIN users u ON cr.user_id = u.id 
               JOIN users m ON u.manager_id = m.id 
               JOIN ($status_subquery) s ON cr.id = s.id
               WHERE m.head_id = ? AND (s.actual_status = 'Head Approved' OR s.actual_status = 'Validator Approved' OR s.actual_status = 'Finance Approved')
               ORDER BY cr.year DESC, cr.month DESC";
 $stmt = mysqli_prepare($conn, $months_sql);
if (!$stmt) {
    show_notification('Database error: ' . mysqli_error($conn), 'error');
    redirect('login.php');
}
mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
 $months_result = mysqli_stmt_get_result($stmt);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Head Dashboard - CB Account</title>
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
            max-width: 100%;
            overflow-x: hidden;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
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
            width: 100%;
            overflow: hidden;
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
            width: 100%;
            max-width: 100%;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            min-width: 800px;
        }
        th, td {
            padding: 8px 12px;
            text-align: left;
            border-bottom: 1px solid var(--gray);
            white-space: nowrap;
        }
        th {
            background-color: #f8fafc;
            font-weight: 600;
            color: var(--dark);
        }
        tr:hover {
            background-color: #f8fafc;
        }
        /* Footer row styling for analytics tables */
        tfoot tr {
            background-color: #f0f3f8;
            color: #f05d49;
            font-weight: bold;
        }
        tfoot td {
            border-top: 2px solid #f05d49;
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
        .status-validator-approved {
            background-color: #e6fffb;
            color: #13c2c2;
        }
        .status-finance-approved {
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
        .tabs {
            display: flex;
            border-bottom: 1px solid var(--gray);
            margin-bottom: 20px;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
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
            position: relative;
            overflow: hidden;
        }
        .hierarchy-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        .hierarchy-item::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            height: 3px;
            width: 100%;
            background: linear-gradient(90deg, var(--primary), var(--primary-light));
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }
        .hierarchy-item:hover::after {
            transform: scaleX(1);
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
        
        /* Analytics Grid Layout updated to single column for better table width */
        .analytics-grid {
            display: grid;
            grid-template-columns: 1fr; 
            gap: 20px;
            margin-bottom: 30px;
            width: 100%;
        }
        
        .analytics-card {
            background: var(--light);
            border-radius: var(--radius);
            padding: 15px;
            box-shadow: var(--shadow);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            width: 100%;
            overflow: hidden;
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
            min-width: 1000px; /* Ensure horizontal scroll if needed on very small screens */
        }
        .analytics-table th, .analytics-table td {
            padding: 6px 8px;
            text-align: left;
            border-bottom: 1px solid var(--gray);
            white-space: nowrap;
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
        .cashback-stats {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .cashback-stat-card {
            flex: 1;
            background: var(--light);
            border-radius: var(--radius);
            padding: 15px;
            box-shadow: var(--shadow);
            text-align: center;
            margin: 0 5px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            min-width: 180px;
        }
        .cashback-stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        .cashback-stat-value {
            font-size: 24px;
            font-weight: bold;
            color: var(--primary);
            margin-bottom: 5px;
        }
        .cashback-stat-label {
            font-size: 14px;
            color: var(--text-light);
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
        .form-type-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }
        .form-type-cb {
            background-color: #e6fffb;
            color: #13c2c2;
        }
        .form-type-shortfall {
            background-color: #fff7e6;
            color: #d46b08;
        }
        /* Floating button style */
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
        /* Month-wise dashboard card styles */
        .month-wise-card {
            background: var(--light);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            width: 100%;
            overflow: hidden;
        }
        .month-selector {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        .month-selector label {
            margin-right: 10px;
            font-weight: 500;
        }
        .month-selector select {
            padding: 8px 10px;
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            font-size: 14px;
            margin-right: 10px;
        }
        .month-data {
            display: flex;
            justify-content: space-around;
            flex-wrap: wrap;
        }
        .month-data-item {
            text-align: center;
            padding: 15px;
            min-width: 150px;
        }
        .month-data-value {
            font-size: 22px;
            font-weight: bold;
            color: var(--primary);
            margin-bottom: 5px;
        }
        .month-data-label {
            font-size: 14px;
            color: var(--text-light);
        }
        /* Filter styles */
        .filter-container {
            background-color: #f8fafc;
            border-radius: var(--radius);
            padding: 15px;
            margin-bottom: 15px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: end;
        }
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        .filter-group label {
            display: block;
            margin-bottom: 5px;
            color: var(--dark);
            font-weight: 500;
        }
        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            font-size: 14px;
        }
        .filter-actions {
            display: flex;
            gap: 10px;
        }
        .time-taken {
            font-size: 12px;
            color: var(--text-light);
        }
        .time-taken-positive {
            color: #38a169;
        }
        .time-taken-negative {
            color: #e53e3e;
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
            
            /* Analytics grid remains 1 column on mobile, which is handled by default now */
            
            .hierarchy-grid {
                grid-template-columns: 1fr;
            }
            
            .cashback-stats {
                flex-direction: column;
            }
            
            .cashback-stat-card {
                margin: 5px 0;
            }
            
            .month-data {
                flex-direction: column;
            }
            
            .month-data-item {
                margin-bottom: 10px;
            }
            
            .filter-container {
                flex-direction: column;
            }
            
            .filter-group {
                width: 100%;
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
                <a href="dashboard_head.php" class="sidebar-menu-item active">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>

                <a href="http://itsupport.coveryou.in/cb_new_uat/sales/head.php" class="sidebar-menu-item">
                    <i class="fas fa-briefcase"></i> Business
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
                        <i class="fas fa-user-shield logo-icon"></i>
                        <div class="logo-text">Head <span>Dashboard</span></div>
                    </div>
                    <p class="tagline">Department oversight and final approvals</p>
                    
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
                    <h2 class="section-title">Department Overview</h2>
                    
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-value"><?php echo mysqli_num_rows($managers_result); ?></div>
                            <div class="stat-label">Managers</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo mysqli_num_rows($users_result); ?></div>
                            <div class="stat-label">Team Members</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $stats['pending_count']; ?></div>
                            <div class="stat-label">Pending Approvals</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value">₹<?php echo number_format(round($stats['pending_amount']), 0); ?></div>
                            <div class="stat-label">Pending Amount</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $stats['approved_count']; ?></div>
                            <div class="stat-label">Head Approved Count</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value">₹<?php echo number_format(round($stats['approved_amount']), 0); ?></div>
                            <div class="stat-label">Head Approved Amount</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $stats['validator_approved_count']; ?></div>
                            <div class="stat-label">Validator Approved Count</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value">₹<?php echo number_format(round($stats['validator_approved_amount']), 0); ?></div>
                            <div class="stat-label">Validator Approved Amount</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $stats['finance_approved_count']; ?></div>
                            <div class="stat-label">Finance Approved Count</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value">₹<?php echo number_format(round($stats['finance_approved_amount']), 0); ?></div>
                            <div class="stat-label">Finance Approved Amount</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value">₹<?php echo number_format(round($stats['finance_rejected_amount']), 0); ?></div>
                            <div class="stat-label">Finance Rejected Amount</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value">₹<?php echo number_format(round($stats['validator_rejected_amount']), 0); ?></div>
                            <div class="stat-label">Validator Rejected Amount</div>
                        </div>
                    </div>
                    
                    <!-- Cashback Percentage Statistics -->
                    <h3 class="section-title">CB Percentage Statistics</h3>
                    <div class="cashback-stats">
                        <!-- Using ROUNDED variables for display -->
                        <div class="cashback-stat-card">
                            <div class="cashback-stat-value">₹<?php echo number_format($total_premium_with_gst_rounded, 0); ?></div>
                            <div class="cashback-stat-label">Total Premium (with GST)</div>
                        </div>
                        <div class="cashback-stat-card">
                            <div class="cashback-stat-value">₹<?php echo number_format($total_cb_amount_rounded, 0); ?></div>
                            <div class="cashback-stat-label">Total CB Amount</div>
                        </div>
                        <div class="cashback-stat-card">
                            <!-- Changed to 2 decimal places for Round Figure -->
                            <div class="cashback-stat-value"><?php echo $cashback_percentage_with_gst_display; ?></div>
                            <div class="cashback-stat-label">CB % of Premium (with GST)</div>
                        </div>
                        <div class="cashback-stat-card">
                            <div class="cashback-stat-value">₹<?php echo number_format($total_shortfall_amount_rounded, 0); ?></div>
                            <div class="cashback-stat-label">Total Shortfall Amount</div>
                        </div>
                        <div class="cashback-stat-card">
                            <!-- Changed to 2 decimal places for Round Figure -->
                            <div class="cashback-stat-value"><?php echo $shortfall_percentage_with_gst_display; ?></div>
                            <div class="cashback-stat-label">Shortfall % of Premium (with GST)</div>
                        </div>
                    </div>
                    <div class="cashback-stats">
                        <!-- Using ROUNDED variables for display -->
                        <div class="cashback-stat-card">
                            <div class="cashback-stat-value">₹<?php echo number_format($total_premium_without_gst_rounded, 0); ?></div>
                            <div class="cashback-stat-label">Total Premium (without GST)</div>
                        </div>
                        <div class="cashback-stat-card">
                            <div class="cashback-stat-value">₹<?php echo number_format($total_cb_amount_rounded, 0); ?></div>
                            <div class="cashback-stat-label">Total CB Amount</div>
                        </div>
                        <div class="cashback-stat-card">
                            <!-- Changed to 2 decimal places for Round Figure -->
                            <div class="cashback-stat-value"><?php echo $cashback_percentage_without_gst_display; ?></div>
                            <div class="cashback-stat-label">CB % of Premium (without GST)</div>
                        </div>
                        <div class="cashback-stat-card">
                            <div class="cashback-stat-value">₹<?php echo number_format($total_shortfall_amount_rounded, 0); ?></div>
                            <div class="cashback-stat-label">Total Shortfall Amount</div>
                        </div>
                        <div class="cashback-stat-card">
                            <!-- Changed to 2 decimal places for Round Figure -->
                            <div class="cashback-stat-value"><?php echo $shortfall_percentage_without_gst_display; ?></div>
                            <div class="cashback-stat-label">Shortfall % of Premium (without GST)</div>
                        </div>
                    </div>
                    
                    <!-- Month-wise Dashboard Card -->
                    <div class="month-wise-card">
                        <h3 class="section-title">Month-wise Premium Analysis</h3>
                        <div class="month-selector">
                            <label for="month-select">Select Month:</label>
                            <select id="month-select" onchange="updateMonthData()">
                                <option value="">-- Select Month --</option>
                                <?php 
                                // Reset result pointer to reuse it
                                mysqli_data_seek($months_result, 0);
                                
                                while ($month = mysqli_fetch_assoc($months_result)): ?>
                                    <option value="<?php echo $month['month'] . '-' . $month['year']; ?>">
                                        <?php echo $month['month'] . ' ' . $month['year']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <!-- First Section: CB Analysis -->
                        <h4 style="margin: 20px 0 15px; color: var(--primary); font-size: 15px;">CB Analysis</h4>
                        <div class="cashback-stats">
                            <div class="cashback-stat-card">
                                <div class="cashback-stat-value" id="cb-premium-with-gst">₹0</div>
                                <div class="cashback-stat-label">CB Premium (with GST)</div>
                            </div>
                            <div class="cashback-stat-card">
                                <div class="cashback-stat-value" id="cb-amount">₹0</div>
                                <div class="cashback-stat-label">CB Amount</div>
                            </div>
                            <div class="cashback-stat-card">
                                <div class="cashback-stat-value" id="cb-percentage-with-gst">0%</div>
                                <div class="cashback-stat-label">CB % of Premium (with GST)</div>
                            </div>
                        </div>
                        <div class="cashback-stats">
                            <div class="cashback-stat-card">
                                <div class="cashback-stat-value" id="cb-premium-without-gst">₹0</div>
                                <div class="cashback-stat-label">CB Premium (without GST)</div>
                            </div>
                            <div class="cashback-stat-card">
                                <div class="cashback-stat-value" id="cb-amount-without-gst">₹0</div>
                                <div class="cashback-stat-label">CB Amount</div>
                            </div>
                            <div class="cashback-stat-card">
                                <div class="cashback-stat-value" id="cb-percentage-without-gst">0%</div>
                                <div class="cashback-stat-label">CB % of Premium (without GST)</div>
                            </div>
                        </div>
                        
                        <!-- Second Section: Shortfall Analysis -->
                        <h4 style="margin: 20px 0 15px; color: var(--primary); font-size: 15px;">Shortfall Analysis</h4>
                        <div class="cashback-stats">
                            <div class="cashback-stat-card">
                                <div class="cashback-stat-value" id="shortfall-premium-with-gst">₹0</div>
                                <div class="cashback-stat-label">Shortfall Premium (with GST)</div>
                            </div>
                            <div class="cashback-stat-card">
                                <div class="cashback-stat-value" id="shortfall-amount">₹0</div>
                                <div class="cashback-stat-label">Shortfall Amount</div>
                            </div>
                            <div class="cashback-stat-card">
                                <div class="cashback-stat-value" id="shortfall-percentage-with-gst">0%</div>
                                <div class="cashback-stat-label">Shortfall % of Premium (with GST)</div>
                            </div>
                        </div>
                        <div class="cashback-stats">
                            <div class="cashback-stat-card">
                                <div class="cashback-stat-value" id="shortfall-premium-without-gst">₹0</div>
                                <div class="cashback-stat-label">Shortfall Premium (without GST)</div>
                            </div>
                            <div class="cashback-stat-card">
                                <div class="cashback-stat-value" id="shortfall-amount-without-gst">₹0</div>
                                <div class="cashback-stat-label">Shortfall Amount</div>
                            </div>
                            <div class="cashback-stat-card">
                                <div class="cashback-stat-value" id="shortfall-percentage-without-gst">0%</div>
                                <div class="cashback-stat-label">Shortfall % of Premium (without GST)</div>
                            </div>
                        </div>
                    </div> 
                    
                    <div class="hierarchy">
                        <h3 style="margin-bottom: 15px;">Reporting Structure</h3>
                        
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
                                    if (!$stmt) {
                                        echo "<div class='alert alert-error'>Database error: " . mysqli_error($conn) . "</div>";
                                        continue;
                                    }
                                    mysqli_stmt_bind_param($stmt, "i", $manager['id']);
                                    mysqli_stmt_execute($stmt);
                                    $team_result = mysqli_stmt_get_result($stmt);
                                    
                                    // Prepare filters for sub-queries (Reporting Structure)
                                    $hierarchy_where_clause = "WHERE u.manager_id = ?";
                                    $hierarchy_params = array($manager['id']);
                                    $hierarchy_types = "i";
                                    
                                    // Apply month filter to hierarchy stats if selected
                                    if (!empty($selected_month) && !empty($selected_year)) {
                                        $hierarchy_where_clause .= " AND MONTH(cr.created_at) = ? AND YEAR(cr.created_at) = ?";
                                        $hierarchy_params[] = $selected_month;
                                        $hierarchy_params[] = $selected_year;
                                        $hierarchy_types .= "ii";
                                    }
                                    
                                    // Get pending count for this manager
                                    $pending_count_sql = "SELECT COUNT(*) AS count 
                                                       FROM cashback_requests cr 
                                                       JOIN users u ON cr.user_id = u.id 
                                                       JOIN ($status_subquery) s ON cr.id = s.id
                                                       $hierarchy_where_clause AND s.actual_status = 'Manager Approved'";
                                    $stmt = mysqli_prepare($conn, $pending_count_sql);
                                    if (!$stmt) {
                                        echo "<div class='alert alert-error'>Database error: " . mysqli_error($conn) . "</div>";
                                        continue;
                                    }
                                    mysqli_stmt_bind_param($stmt, $hierarchy_types, ...$hierarchy_params);
                                    mysqli_stmt_execute($stmt);
                                    $pending_count_result = mysqli_stmt_get_result($stmt);
                                    $pending_count = mysqli_fetch_assoc($pending_count_result)['count'];
                                    
                                    // Get team amount for this manager
                                    $team_amount_sql = "SELECT SUM(cr.referral_amount) AS amount 
                                                     FROM cashback_requests cr 
                                                     JOIN users u ON cr.user_id = u.id 
                                                     JOIN ($status_subquery) s ON cr.id = s.id
                                                     $hierarchy_where_clause AND (s.actual_status = 'Head Approved' OR s.actual_status = 'Validator Approved' OR s.actual_status = 'Finance Approved')";
                                    $stmt = mysqli_prepare($conn, $team_amount_sql);
                                    if (!$stmt) {
                                        echo "<div class='alert alert-error'>Database error: " . mysqli_error($conn) . "</div>";
                                        continue;
                                    }
                                    mysqli_stmt_bind_param($stmt, $hierarchy_types, ...$hierarchy_params);
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
                                                <div class="hierarchy-stat-value">₹<?php echo number_format(round($team_amount), 0); ?></div>
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
                    
      <!-- Filter Section -->
                        <div class="filter-container">
                            <div class="filter-group">
                                <label for="start-date-approved">Start Date:</label>
                                <input type="date" id="start-date-approved" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                            </div>
                            <div class="filter-group">
                                <label for="end-date-approved">End Date:</label>
                                <input type="date" id="end-date-approved" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                            </div>
                            <div class="filter-group">
                                <label for="form-type-approved">Form Type:</label>
                                <select id="form-type-approved" name="form_type">
                                    <option value="">All Types</option>
                                    <option value="CB" <?php echo ($form_type_filter == 'CB') ? 'selected' : ''; ?>>CB</option>
                                    <option value="Shortfall" <?php echo ($form_type_filter == 'Shortfall') ? 'selected' : ''; ?>>Shortfall</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label for="manager-filter-approved">Manager:</label>
                                <select id="manager-filter-approved" name="manager">
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
                            <div class="filter-group">
                                <label for="team-filter-approved">Team Member:</label>
                                <select id="team-filter-approved" name="team">
                                    <option value="">All Team Members</option>
                                    <?php 
                                    // Reset result pointer to reuse it
                                    mysqli_data_seek($users_result, 0);
                                    
                                    while ($user = mysqli_fetch_assoc($users_result)): ?>
                                        <option value="<?php echo $user['id']; ?>" <?php echo ($team_filter == $user['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($user['full_name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label for="policy-type-filter-approved">Policy Type:</label>
                                <select id="policy-type-filter-approved" name="policy_type">
                                    <option value="">All Policy Types</option>
                                    <?php 
                                    // Reset result pointer to reuse it
                                    mysqli_data_seek($policy_types_result, 0);
                                    
                                    while ($policy_type = mysqli_fetch_assoc($policy_types_result)): ?>
                                        <option value="<?php echo $policy_type['policy_type']; ?>" <?php echo ($policy_type_filter == $policy_type['policy_type']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($policy_type['policy_type']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label for="insurance-company-filter-approved">Insurance Company:</label>
                                <select id="insurance-company-filter-approved" name="insurance_company">
                                    <option value="">All Insurance Companies</option>
                                    <?php 
                                    // Reset result pointer to reuse it
                                    mysqli_data_seek($insurance_companies_result, 0);
                                    
                                    while ($insurance_company = mysqli_fetch_assoc($insurance_companies_result)): ?>
                                        <option value="<?php echo $insurance_company['insurance_company']; ?>" <?php echo ($insurance_company_filter == $insurance_company['insurance_company']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($insurance_company['insurance_company']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <!-- Added Search Field -->
                            <div class="filter-group" style="flex: 2;">
                                <label for="search-query-approved">Search:</label>
                                <input type="text" id="search-query-approved" name="search" placeholder="Reference, Customer, User..." value="<?php echo htmlspecialchars($search_query); ?>">
                            </div>
                            <div class="filter-actions">
                                <button class="btn btn-primary" onclick="applyFiltersApproved()">
                                    <i class="fas fa-filter"></i> Apply Filters
                                </button>
                                <button class="btn btn-danger" onclick="clearFiltersApproved()">
                                    <i class="fas fa-times"></i> Clear
                                </button>
                            </div>
                        </div>
                    
                    <div class="analytics-grid">
                        <!-- Manager-wise Analytics -->
                        <div class="analytics-card">
                            <h3>Manager-wise Analytics</h3>
                            <div class="table-container">
                                <table class="analytics-table">
                                    <thead>
                                        <tr>
                                            <th>Manager</th>
                                            <th>Department</th>
                                            <th>Requests</th>
                                            <th>Premium (with GST)</th>
                                            <th>Premium (without GST)</th>
                                            <th>CB Amount</th>
                                            <th>Shortfall Amount</th>
                                            <th>CB Ratio</th>
                                            <th>Shortfall Ratio</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        // Initialize totals
                                        $total_requests_manager = 0;
                                        $total_premium_gst_manager = 0;
                                        $total_premium_no_gst_manager = 0;
                                        $total_cb_amount_manager = 0;
                                        $total_shortfall_amount_manager = 0;

                                        while ($manager = mysqli_fetch_assoc($manager_stats_result)): ?>
                                            <?php 
                                            // ROUND PREMIUM AND AMOUNT FOR CALCULATION
                                            $manager_premium_gst_rounded = round($manager['premium_with_gst']);
                                            $manager_cb_amount_rounded = round($manager['cb_amount']);
                                            $manager_shortfall_amount_rounded = round($manager['shortfall_amount']);

                                            // Calculate CB ratio based on ROUNDED figures - 2 decimal places
                                            $cb_ratio = 0;
                                            if ($manager_premium_gst_rounded > 0) {
                                                $cb_ratio = ($manager_cb_amount_rounded / $manager_premium_gst_rounded) * 100;
                                            }
                                            
                                            // Calculate Shortfall ratio based on ROUNDED figures - 2 decimal places
                                            $shortfall_ratio = 0;
                                            if ($manager['shortfall_premium_with_gst'] > 0) {
                                                $shortfall_ratio = ($manager_shortfall_amount_rounded / round($manager['shortfall_premium_with_gst'])) * 100;
                                            }

                                            // Accumulate totals (Use original for sum, display rounded)
                                            $total_requests_manager += $manager['count'];
                                            $total_premium_gst_manager += $manager['premium_with_gst'];
                                            $total_premium_no_gst_manager += $manager['without_gst'];
                                            $total_cb_amount_manager += $manager['cb_amount'];
                                            $total_shortfall_amount_manager += $manager['shortfall_amount'];
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($manager['manager_name']); ?></td>
                                                <td><?php echo htmlspecialchars($manager['manager_department']); ?></td>
                                                <td><?php echo $manager['count']; ?></td>
                                                <td>₹<?php echo number_format($manager_premium_gst_rounded, 0); ?></td>
                                                <td>₹<?php echo number_format(round($manager['without_gst']), 0); ?></td>
                                                <td>₹<?php echo number_format($manager_cb_amount_rounded, 0); ?></td>
                                                <td>₹<?php echo number_format($manager_shortfall_amount_rounded, 0); ?></td>
                                                <td><?php echo number_format($cb_ratio, 2); ?>%</td>
                                                <td><?php echo number_format($shortfall_ratio, 2); ?>%</td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="2" style="text-align:right;">Total:</td>
                                            <td><?php echo $total_requests_manager; ?></td>
                                            <td>₹<?php echo number_format(round($total_premium_gst_manager), 0); ?></td>
                                            <td>₹<?php echo number_format(round($total_premium_no_gst_manager), 0); ?></td>
                                            <td>₹<?php echo number_format(round($total_cb_amount_manager), 0); ?></td>
                                            <td>₹<?php echo number_format(round($total_shortfall_amount_manager), 0); ?></td>
                                            <td>-</td>
                                            <td>-</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Team-wise Analytics -->
                        <div class="analytics-card">
                            <h3>Team-wise Analytics</h3>
                            <div class="table-container">
                                <table class="analytics-table">
                                    <thead>
                                        <tr>
                                            <th>Team Member</th>
                                            <th>Manager</th>
                                            <th>Requests</th>
                                            <th>Premium (with GST)</th>
                                            <th>Premium (without GST)</th>
                                            <th>CB Amount</th>
                                            <th>Shortfall Amount</th>
                                            <th>CB Ratio</th>
                                            <th>Shortfall Ratio</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        // Initialize totals
                                        $total_requests_team = 0;
                                        $total_premium_gst_team = 0;
                                        $total_premium_no_gst_team = 0;
                                        $total_cb_amount_team = 0;
                                        $total_shortfall_amount_team = 0;

                                        while ($team = mysqli_fetch_assoc($team_stats_result)): ?>
                                            <?php 
                                            // ROUND PREMIUM AND AMOUNT FOR CALCULATION
                                            $team_premium_gst_rounded = round($team['premium_with_gst']);
                                            $team_cb_amount_rounded = round($team['cb_amount']);
                                            $team_shortfall_amount_rounded = round($team['shortfall_amount']);

                                            // Calculate CB ratio based on ROUNDED figures - 2 decimal places
                                            $cb_ratio = 0;
                                            if ($team_premium_gst_rounded > 0) {
                                                $cb_ratio = ($team_cb_amount_rounded / $team_premium_gst_rounded) * 100;
                                            }
                                            
                                            // Calculate Shortfall ratio based on ROUNDED figures - 2 decimal places
                                            $shortfall_ratio = 0;
                                            if ($team['shortfall_premium_with_gst'] > 0) {
                                                $shortfall_ratio = ($team_shortfall_amount_rounded / round($team['shortfall_premium_with_gst'])) * 100;
                                            }

                                            // Accumulate totals
                                            $total_requests_team += $team['count'];
                                            $total_premium_gst_team += $team['premium_with_gst'];
                                            $total_premium_no_gst_team += $team['without_gst'];
                                            $total_cb_amount_team += $team['cb_amount'];
                                            $total_shortfall_amount_team += $team['shortfall_amount'];
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($team['team_member_name']); ?></td>
                                                <td><?php echo htmlspecialchars($team['manager_name']); ?></td>
                                                <td><?php echo $team['count']; ?></td>
                                                <td>₹<?php echo number_format($team_premium_gst_rounded, 0); ?></td>
                                                <td>₹<?php echo number_format(round($team['without_gst']), 0); ?></td>
                                                <td>₹<?php echo number_format($team_cb_amount_rounded, 0); ?></td>
                                                <td>₹<?php echo number_format($team_shortfall_amount_rounded, 0); ?></td>
                                                <td><?php echo number_format($cb_ratio, 2); ?>%</td>
                                                <td><?php echo number_format($shortfall_ratio, 2); ?>%</td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="2" style="text-align:right;">Total:</td>
                                            <td><?php echo $total_requests_team; ?></td>
                                            <td>₹<?php echo number_format(round($total_premium_gst_team), 0); ?></td>
                                            <td>₹<?php echo number_format(round($total_premium_no_gst_team), 0); ?></td>
                                            <td>₹<?php echo number_format(round($total_cb_amount_team), 0); ?></td>
                                            <td>₹<?php echo number_format(round($total_shortfall_amount_team), 0); ?></td>
                                            <td>-</td>
                                            <td>-</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Policy Type Analytics -->
                        <div class="analytics-card">
                            <h3>Policy Type Analytics</h3>
                            <div class="table-container">
                                <table class="analytics-table">
                                    <thead>
                                        <tr>
                                            <th>Policy Type</th>
                                            <th>Requests</th>
                                            <th>Premium (with GST)</th>
                                            <th>Premium (without GST)</th>
                                            <th>CB Amount</th>
                                            <th>Shortfall Amount</th>
                                            <th>CB Ratio</th>
                                            <th>Shortfall Ratio</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        // Initialize totals
                                        $total_requests_policy = 0;
                                        $total_premium_gst_policy = 0;
                                        $total_premium_no_gst_policy = 0;
                                        $total_cb_amount_policy = 0;
                                        $total_shortfall_amount_policy = 0;

                                        while ($policy_type = mysqli_fetch_assoc($policy_type_stats_result)): ?>
                                            <?php 
                                            // ROUND PREMIUM AND AMOUNT FOR CALCULATION
                                            $policy_premium_gst_rounded = round($policy_type['premium_with_gst']);
                                            $policy_cb_amount_rounded = round($policy_type['cb_amount']);
                                            $policy_shortfall_amount_rounded = round($policy_type['shortfall_amount']);

                                            // Calculate CB ratio based on ROUNDED figures - 2 decimal places
                                            $cb_ratio = 0;
                                            if ($policy_premium_gst_rounded > 0) {
                                                $cb_ratio = ($policy_cb_amount_rounded / $policy_premium_gst_rounded) * 100;
                                            }
                                            
                                            // Calculate Shortfall ratio based on ROUNDED figures - 2 decimal places
                                            $shortfall_ratio = 0;
                                            if ($policy_type['shortfall_premium_with_gst'] > 0) {
                                                $shortfall_ratio = ($policy_shortfall_amount_rounded / round($policy_type['shortfall_premium_with_gst'])) * 100;
                                            }

                                            // Accumulate totals
                                            $total_requests_policy += $policy_type['count'];
                                            $total_premium_gst_policy += $policy_type['premium_with_gst'];
                                            $total_premium_no_gst_policy += $policy_type['without_gst'];
                                            $total_cb_amount_policy += $policy_type['cb_amount'];
                                            $total_shortfall_amount_policy += $policy_type['shortfall_amount'];
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($policy_type['policy_type']); ?></td>
                                                <td><?php echo $policy_type['count']; ?></td>
                                                <td>₹<?php echo number_format($policy_premium_gst_rounded, 0); ?></td>
                                                <td>₹<?php echo number_format(round($policy_type['without_gst']), 0); ?></td>
                                                <td>₹<?php echo number_format($policy_cb_amount_rounded, 0); ?></td>
                                                <td>₹<?php echo number_format($policy_shortfall_amount_rounded, 0); ?></td>
                                                <td><?php echo number_format($cb_ratio, 2); ?>%</td>
                                                <td><?php echo number_format($shortfall_ratio, 2); ?>%</td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td style="text-align:right;">Total:</td>
                                            <td><?php echo $total_requests_policy; ?></td>
                                            <td>₹<?php echo number_format(round($total_premium_gst_policy), 0); ?></td>
                                            <td>₹<?php echo number_format(round($total_premium_no_gst_policy), 0); ?></td>
                                            <td>₹<?php echo number_format(round($total_cb_amount_policy), 0); ?></td>
                                            <td>₹<?php echo number_format(round($total_shortfall_amount_policy), 0); ?></td>
                                            <td>-</td>
                                            <td>-</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Insurance Company Analytics -->
                        <div class="analytics-card">
                            <h3>Insurance Company Analytics</h3>
                            <div class="table-container">
                                <table class="analytics-table">
                                    <thead>
                                        <tr>
                                            <th>Insurance Company</th>
                                            <th>Requests</th>
                                            <th>Premium (with GST)</th>
                                            <th>Premium (without GST)</th>
                                            <th>CB Amount</th>
                                            <th>Shortfall Amount</th>
                                            <th>CB Ratio</th>
                                            <th>Shortfall Ratio</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        // Initialize totals
                                        $total_requests_insurance = 0;
                                        $total_premium_gst_insurance = 0;
                                        $total_premium_no_gst_insurance = 0;
                                        $total_cb_amount_insurance = 0;
                                        $total_shortfall_amount_insurance = 0;

                                        while ($insurance_company = mysqli_fetch_assoc($insurance_company_stats_result)): ?>
                                            <?php 
                                            // ROUND PREMIUM AND AMOUNT FOR CALCULATION
                                            $insurance_premium_gst_rounded = round($insurance_company['premium_with_gst']);
                                            $insurance_cb_amount_rounded = round($insurance_company['cb_amount']);
                                            $insurance_shortfall_amount_rounded = round($insurance_company['shortfall_amount']);

                                            // Calculate CB ratio based on ROUNDED figures - 2 decimal places
                                            $cb_ratio = 0;
                                            if ($insurance_premium_gst_rounded > 0) {
                                                $cb_ratio = ($insurance_cb_amount_rounded / $insurance_premium_gst_rounded) * 100;
                                            }
                                            
                                            // Calculate Shortfall ratio based on ROUNDED figures - 2 decimal places
                                            $shortfall_ratio = 0;
                                            if ($insurance_company['shortfall_premium_with_gst'] > 0) {
                                                $shortfall_ratio = ($insurance_shortfall_amount_rounded / round($insurance_company['shortfall_premium_with_gst'])) * 100;
                                            }

                                            // Accumulate totals
                                            $total_requests_insurance += $insurance_company['count'];
                                            $total_premium_gst_insurance += $insurance_company['premium_with_gst'];
                                            $total_premium_no_gst_insurance += $insurance_company['without_gst'];
                                            $total_cb_amount_insurance += $insurance_company['cb_amount'];
                                            $total_shortfall_amount_insurance += $insurance_company['shortfall_amount'];
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($insurance_company['insurance_company']); ?></td>
                                                <td><?php echo $insurance_company['count']; ?></td>
                                                <td>₹<?php echo number_format($insurance_premium_gst_rounded, 0); ?></td>
                                                <td>₹<?php echo number_format(round($insurance_company['without_gst']), 0); ?></td>
                                                <td>₹<?php echo number_format($insurance_cb_amount_rounded, 0); ?></td>
                                                <td>₹<?php echo number_format($insurance_shortfall_amount_rounded, 0); ?></td>
                                                <td><?php echo number_format($cb_ratio, 2); ?>%</td>
                                                <td><?php echo number_format($shortfall_ratio, 2); ?>%</td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td style="text-align:right;">Total:</td>
                                            <td><?php echo $total_requests_insurance; ?></td>
                                            <td>₹<?php echo number_format(round($total_premium_gst_insurance), 0); ?></td>
                                            <td>₹<?php echo number_format(round($total_premium_no_gst_insurance), 0); ?></td>
                                            <td>₹<?php echo number_format(round($total_cb_amount_insurance), 0); ?></td>
                                            <td>₹<?php echo number_format(round($total_shortfall_amount_insurance), 0); ?></td>
                                            <td>-</td>
                                            <td>-</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <div class="tabs" id="approval-tabs">
                        <div class="tab-buttons">
                            <div class="tab active" onclick="openTab(event, 'pending-tab')">Pending Approvals</div>
                            <div class="tab" onclick="openTab(event, 'approved-tab')">Approved Requests</div>
                            <div class="tab" onclick="openTab(event, 'rejected-tab')">Rejected Requests</div>
                        </div>
                        <a href="?export=all" class="btn btn-primary">
                            <i class="fas fa-download"></i> Export All Requests
                        </a>
                    </div>
                    
                    <!-- PENDING REQUESTS TAB -->
                    <div id="pending-tab" class="tab-content active">
                        <h3>Pending Approvals</h3>
                        
                        <?php if (mysqli_num_rows($pending_result) > 0): ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Reference #</th>
                                            <th>User</th>
                                            <th>Manager</th>
                                            <th>Assigned Finance</th> <!-- NEW COLUMN -->
                                            <th>Customer</th>
                                            <th>Premium (with GST)</th>
                                            <th>Premium (without GST)</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th>Request Raise Date</th>
                                            <th>Manager Approval Date</th>
                                            <th>Head Approval Date</th>
                                            <th>Manager Time Taken</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($request = mysqli_fetch_assoc($pending_result)): ?>
                                            <?php
                                            $status_class = '';
                                            switch ($request['actual_status']) {
                                                case 'Pending': $status_class = 'status-pending'; break;
                                                case 'Manager Approved': $status_class = 'status-manager-approved'; break;
                                                case 'Head Approved': $status_class = 'status-head-approved'; break;
                                                case 'Validator Approved': $status_class = 'status-validator-approved'; break;
                                                case 'Finance Approved': $status_class = 'status-finance-approved'; break;
                                                case 'Rejected': $status_class = 'status-rejected'; break;
                                            }
                                            
                                            $time_taken_class = ($request['manager_time_taken'] > 24) ? 'time-taken-negative' : 'time-taken-positive';
                                            
                                            // NEW: Find finance name from mapped finance_id
                                            $finance_name_display = 'Not Assigned';
                                            if (!empty($request['user_finance_id'])) {
                                                foreach ($finances_list as $fl) {
                                                    if ($fl['id'] == $request['user_finance_id']) {
                                                        $finance_name_display = htmlspecialchars($fl['full_name']);
                                                        break;
                                                    }
                                                }
                                            }
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($request['reference_number']); ?></td>
                                                <td><?php echo htmlspecialchars($request['user_name']); ?></td>
                                                <td><?php echo htmlspecialchars($request['manager_name']); ?></td>
                                                <!-- NEW: Assigned Finance Column -->
                                                <td><span class="status-badge status-head-approved"><?php echo $finance_name_display; ?></span></td>
                                                <td><?php echo htmlspecialchars($request['customer_name']); ?></td>
                                                <td>₹<?php echo number_format(round($request['premium_with_gst']), 0); ?></td>
                                                <td>₹<?php echo number_format(round($request['without_gst']), 0); ?></td>
                                                <td>₹<?php echo number_format(round($request['referral_amount']), 0); ?></td>
                                                <td>
                                                    <span class="status-badge <?php echo $status_class; ?>">
                                                        <?php echo htmlspecialchars($request['actual_status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('d M Y H:i', strtotime($request['created_at'])); ?></td>
                                                <td><?php echo $request['manager_approval_date'] ? date('d M Y H:i', strtotime($request['manager_approval_date'])) : 'N/A'; ?></td>
                                                <td><?php echo $request['head_approval_date'] ? date('d M Y H:i', strtotime($request['head_approval_date'])) : 'N/A'; ?></td>
                                                <td>
                                                    <?php if ($request['manager_time_taken']): ?>
                                                        <span class="time-taken <?php echo $time_taken_class; ?>">
                                                            <?php echo $request['manager_time_taken']; ?> hours
                                                        </span>
                                                    <?php else: ?>
                                                        N/A
                                                    <?php endif; ?>
                                                </td>
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
                                <p>No pending approvals</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- APPROVED REQUESTS TAB -->
                    <div id="approved-tab" class="tab-content">
                        <h3>Approved Requests</h3>
                        
                        <?php if (mysqli_num_rows($approved_result) > 0): ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Reference #</th>
                                            <th>User</th>
                                            <th>Manager</th>
                                            <th>Assigned Finance</th> <!-- NEW COLUMN -->
                                            <th>Customer</th>
                                            <th>Request Type</th>
                                            <th>Premium (with GST)</th>
                                            <th>Premium (without GST)</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th>Request Raise Date</th>
                                            <th>Manager Approval Date</th>
                                            <th>Head Approval Date</th>
                                            <th>Validator Approval Date</th>
                                            <th>Finance Approval Date</th>
                                            <th>Time Taken</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($request = mysqli_fetch_assoc($approved_result)): ?>
                                            <?php
                                            $status_class = '';
                                            switch ($request['actual_status']) {
                                                case 'Pending': $status_class = 'status-pending'; break;
                                                case 'Manager Approved': $status_class = 'status-manager-approved'; break;
                                                case 'Head Approved': $status_class = 'status-head-approved'; break;
                                                case 'Validator Approved': $status_class = 'status-validator-approved'; break;
                                                case 'Finance Approved': $status_class = 'status-finance-approved'; break;
                                                case 'Rejected': $status_class = 'status-rejected'; break;
                                            }
                                            
                                            $form_type_class = ($request['form_type'] == 'CB') ? 'form-type-cb' : 'form-type-shortfall';
                                            
                                            // Calculate total time taken
                                            $total_time_taken = 0;
                                            if ($request['manager_time_taken'] && $request['head_time_taken'] && $request['validator_time_taken'] && $request['finance_time_taken']) {
                                                $total_time_taken = $request['manager_time_taken'] + $request['head_time_taken'] + $request['validator_time_taken'] + $request['finance_time_taken'];
                                            }
                                            
                                            $time_taken_class = ($total_time_taken > 72) ? 'time-taken-negative' : 'time-taken-positive';
                                            
                                            // NEW: Find finance name
                                            $finance_name_display = 'Not Assigned';
                                            if (!empty($request['user_finance_id'])) {
                                                foreach ($finances_list as $fl) {
                                                    if ($fl['id'] == $request['user_finance_id']) {
                                                        $finance_name_display = htmlspecialchars($fl['full_name']);
                                                        break;
                                                    }
                                                }
                                            }
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($request['reference_number']); ?></td>
                                                <td><?php echo htmlspecialchars($request['user_name']); ?></td>
                                                <td><?php echo htmlspecialchars($request['manager_name']); ?></td>
                                                <!-- NEW: Assigned Finance Column -->
                                                <td><span class="status-badge status-head-approved"><?php echo $finance_name_display; ?></span></td>
                                                <td><?php echo htmlspecialchars($request['customer_name']); ?></td>
                                                <td>
                                                    <span class="form-type-badge <?php echo $form_type_class; ?>">
                                                        <?php echo htmlspecialchars($request['form_type']); ?>
                                                    </span>
                                                </td>
                                                <td>₹<?php echo number_format(round($request['premium_with_gst']), 0); ?></td>
                                                <td>₹<?php echo number_format(round($request['without_gst']), 0); ?></td>
                                                <td>₹<?php echo number_format(round($request['referral_amount']), 0); ?></td>
                                                <td>
                                                    <span class="status-badge <?php echo $status_class; ?>">
                                                        <?php echo htmlspecialchars($request['actual_status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('d M Y H:i', strtotime($request['created_at'])); ?></td>
                                                <td><?php echo $request['manager_approval_date'] ? date('d M Y H:i', strtotime($request['manager_approval_date'])) : 'N/A'; ?></td>
                                                <td><?php echo $request['head_approval_date'] ? date('d M Y H:i', strtotime($request['head_approval_date'])) : 'N/A'; ?></td>
                                                <td><?php echo $request['validator_approval_date'] ? date('d M Y H:i', strtotime($request['validator_approval_date'])) : 'N/A'; ?></td>
                                                <td><?php echo $request['finance_approval_date'] ? date('d M Y H:i', strtotime($request['finance_approval_date'])) : 'N/A'; ?></td>
                                                <td>
                                                    <?php if ($total_time_taken > 0): ?>
                                                        <span class="time-taken <?php echo $time_taken_class; ?>">
                                                            <?php echo $total_time_taken; ?> hours
                                                        </span>
                                                    <?php else: ?>
                                                        N/A
                                                    <?php endif; ?>
                                                </td>
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
                    
                    <!-- REJECTED REQUESTS TAB -->
                    <div id="rejected-tab" class="tab-content">
                        <h3>Rejected Requests</h3>
                        
                        <?php if (mysqli_num_rows($rejected_result) > 0): ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Reference #</th>
                                            <th>User</th>
                                            <th>Manager</th>
                                            <th>Customer</th>
                                            <th>Request Type</th>
                                            <th>Premium (with GST)</th>
                                            <th>Premium (without GST)</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th>Request Raise Date</th>
                                            <th>Rejection Date</th>
                                            <th>Rejected By</th>
                                            <th>Rejection Reason</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($request = mysqli_fetch_assoc($rejected_result)): ?>
                                            <?php
                                            $status_class = 'status-rejected';
                                            $form_type_class = ($request['form_type'] == 'CB') ? 'form-type-cb' : 'form-type-shortfall';
                                            
                                            // Get rejected by user name
                                            $rejected_by_name = 'N/A';
                                            if ($request['rejected_by_role']) {
                                                $rejected_by_name = $request['rejected_by_role'];
                                            }
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($request['reference_number']); ?></td>
                                                <td><?php echo htmlspecialchars($request['user_name']); ?></td>
                                                <td><?php echo htmlspecialchars($request['manager_name']); ?></td>
                                                <td><?php echo htmlspecialchars($request['customer_name']); ?></td>
                                                <td>
                                                    <span class="form-type-badge <?php echo $form_type_class; ?>">
                                                        <?php echo htmlspecialchars($request['form_type']); ?>
                                                    </span>
                                                </td>
                                                <td>₹<?php echo number_format(round($request['premium_with_gst']), 0); ?></td>
                                                <td>₹<?php echo number_format(round($request['without_gst']), 0); ?></td>
                                                <td>₹<?php echo number_format(round($request['referral_amount']), 0); ?></td>
                                                <td>
                                                    <span class="status-badge <?php echo $status_class; ?>">
                                                        <?php echo htmlspecialchars($request['actual_status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('d M Y H:i', strtotime($request['created_at'])); ?></td>
                                                <td><?php echo $request['rejection_date'] ? date('d M Y H:i', strtotime($request['rejection_date'])) : 'N/A'; ?></td>
                                                <td><?php echo htmlspecialchars($rejected_by_name); ?></td>
                                                <td><?php echo htmlspecialchars($request['rejection_reason']); ?></td>
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
                                <p>No rejected requests</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Floating button to scroll to approval sections -->
    <button class="floating-btn" onclick="scrollToApprovals()">
        <i class="fas fa-arrow-down"></i>
    </button>
    
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
        // Month-wise data object
        const monthWiseData = <?php 
            $data = array();
            mysqli_data_seek($month_wise_result, 0);
            while ($row = mysqli_fetch_assoc($month_wise_result)) {
                $key = $row['month'] . '-' . $row['year'];
                $data[$key] = array(
                    'cb_premium_with_gst' => $row['cb_premium_with_gst'],
                    'shortfall_premium_with_gst' => $row['shortfall_premium_with_gst'],
                    'cb_premium_without_gst' => $row['cb_premium_without_gst'],
                    'shortfall_premium_without_gst' => $row['shortfall_premium_without_gst'],
                    'cb_amount' => $row['cb_amount'],
                    'shortfall_amount' => $row['shortfall_amount']
                );
            }
            echo json_encode($data);
        ?>;
        
        // Function to update month data
        function updateMonthData() {
            const select = document.getElementById('month-select');
            const selectedMonth = select.value;
            
            if (selectedMonth && monthWiseData[selectedMonth]) {
                const data = monthWiseData[selectedMonth];
                
                // Helper to round and format
                const formatRound = (num) => '₹' + Math.round(num).toLocaleString('en-IN');
                
                // Helper to calc percentage with 2 decimals using rounded figures
                const calcPercent = (num, den) => {
                    if (Math.round(den) === 0) return '0.00%';
                    let p = (Math.round(num) / Math.round(den)) * 100;
                    return p.toFixed(2) + '%';
                };

                // Update CB Premium with GST
                document.getElementById('cb-premium-with-gst').textContent = formatRound(data.cb_premium_with_gst);
                
                // Update CB Amount
                document.getElementById('cb-amount').textContent = formatRound(data.cb_amount);
                
                // Calculate and update CB % of Premium with GST
                document.getElementById('cb-percentage-with-gst').textContent = calcPercent(data.cb_amount, data.cb_premium_with_gst);
                
                // Update CB Premium without GST
                document.getElementById('cb-premium-without-gst').textContent = formatRound(data.cb_premium_without_gst);
                
                // Update CB Amount (same as with GST)
                document.getElementById('cb-amount-without-gst').textContent = formatRound(data.cb_amount);
                
                // Calculate and update CB % of Premium without GST
                document.getElementById('cb-percentage-without-gst').textContent = calcPercent(data.cb_amount, data.cb_premium_without_gst);
                
                // Update Shortfall Premium with GST
                document.getElementById('shortfall-premium-with-gst').textContent = formatRound(data.shortfall_premium_with_gst);
                
                // Update Shortfall Amount
                document.getElementById('shortfall-amount').textContent = formatRound(data.shortfall_amount);
                
                // Calculate and update Shortfall % of Premium with GST
                document.getElementById('shortfall-percentage-with-gst').textContent = calcPercent(data.shortfall_amount, data.shortfall_premium_with_gst);
                
                // Update Shortfall Premium without GST
                document.getElementById('shortfall-premium-without-gst').textContent = formatRound(data.shortfall_premium_without_gst);
                
                // Update Shortfall Amount (same as with GST)
                document.getElementById('shortfall-amount-without-gst').textContent = formatRound(data.shortfall_amount);
                
                // Calculate and update Shortfall % of Premium without GST
                document.getElementById('shortfall-percentage-without-gst').textContent = calcPercent(data.shortfall_amount, data.shortfall_premium_without_gst);
            } else {
                // Reset all values to 0 if no month is selected
                document.getElementById('cb-premium-with-gst').textContent = '₹0';
                document.getElementById('cb-amount').textContent = '₹0';
                document.getElementById('cb-percentage-with-gst').textContent = '0%';
                document.getElementById('cb-premium-without-gst').textContent = '₹0';
                document.getElementById('cb-amount-without-gst').textContent = '₹0';
                document.getElementById('cb-percentage-without-gst').textContent = '0%';
                document.getElementById('shortfall-premium-with-gst').textContent = '₹0';
                document.getElementById('shortfall-amount').textContent = '₹0';
                document.getElementById('shortfall-percentage-with-gst').textContent = '0%';
                document.getElementById('shortfall-premium-without-gst').textContent = '₹0';
                document.getElementById('shortfall-amount-without-gst').textContent = '₹0';
                document.getElementById('shortfall-percentage-without-gst').textContent = '0%';
            }
        }
        
        // Function to apply filters for pending approvals
        function applyFilters() {
            const startDate = document.getElementById('start-date').value;
            const endDate = document.getElementById('end-date').value;
            const formType = document.getElementById('form-type').value;
            const manager = document.getElementById('manager-filter').value;
            const team = document.getElementById('team-filter').value;
            const policyType = document.getElementById('policy-type-filter').value;
            const insuranceCompany = document.getElementById('insurance-company-filter').value;
            const searchQuery = document.getElementById('search-query').value; // Get search value
            
            let url = 'dashboard_head.php?tab=pending';
            
            if (startDate) url += '&start_date=' + encodeURIComponent(startDate);
            if (endDate) url += '&end_date=' + encodeURIComponent(endDate);
            if (formType) url += '&form_type=' + encodeURIComponent(formType);
            if (manager) url += '&manager=' + encodeURIComponent(manager);
            if (team) url += '&team=' + encodeURIComponent(team);
            if (policyType) url += '&policy_type=' + encodeURIComponent(policyType);
            if (insuranceCompany) url += '&insurance_company=' + encodeURIComponent(insuranceCompany);
            if (searchQuery) url += '&search=' + encodeURIComponent(searchQuery);
            
            window.location.href = url;
        }
        
        // Function to clear filters for pending approvals
        function clearFilters() {
            document.getElementById('start-date').value = '';
            document.getElementById('end-date').value = '';
            document.getElementById('form-type').value = '';
            document.getElementById('manager-filter').value = '';
            document.getElementById('team-filter').value = '';
            document.getElementById('policy-type-filter').value = '';
            document.getElementById('insurance-company-filter').value = '';
            document.getElementById('search-query').value = '';
            
            window.location.href = 'dashboard_head.php?tab=pending';
        }
        
        // Function to apply filters for approved requests
        function applyFiltersApproved() {
            const startDate = document.getElementById('start-date-approved').value;
            const endDate = document.getElementById('end-date-approved').value;
            const formType = document.getElementById('form-type-approved').value;
            const manager = document.getElementById('manager-filter-approved').value;
            const team = document.getElementById('team-filter-approved').value;
            const policyType = document.getElementById('policy-type-filter-approved').value;
            const insuranceCompany = document.getElementById('insurance-company-filter-approved').value;
            const searchQuery = document.getElementById('search-query-approved').value; // Get search value
            
            let url = 'dashboard_head.php?tab=approved';
            
            if (startDate) url += '&start_date=' + encodeURIComponent(startDate);
            if (endDate) url += '&end_date=' + encodeURIComponent(endDate);
            if (formType) url += '&form_type=' + encodeURIComponent(formType);
            if (manager) url += '&manager=' + encodeURIComponent(manager);
            if (team) url += '&team=' + encodeURIComponent(team);
            if (policyType) url += '&policy_type=' + encodeURIComponent(policyType);
            if (insuranceCompany) url += '&insurance_company=' + encodeURIComponent(insuranceCompany);
            if (searchQuery) url += '&search=' + encodeURIComponent(searchQuery);
            
            window.location.href = url;
        }
        
        // Function to clear filters for approved requests
        function clearFiltersApproved() {
            document.getElementById('start-date-approved').value = '';
            document.getElementById('end-date-approved').value = '';
            document.getElementById('form-type-approved').value = '';
            document.getElementById('manager-filter-approved').value = '';
            document.getElementById('team-filter-approved').value = '';
            document.getElementById('policy-type-filter-approved').value = '';
            document.getElementById('insurance-company-filter-approved').value = '';
            document.getElementById('search-query-approved').value = '';
            
            window.location.href = 'dashboard_head.php?tab=approved';
        }
        
        // Function to apply filters for rejected requests
        function applyFiltersRejected() {
            const startDate = document.getElementById('start-date-rejected').value;
            const endDate = document.getElementById('end-date-rejected').value;
            const formType = document.getElementById('form-type-rejected').value;
            const manager = document.getElementById('manager-filter-rejected').value;
            const team = document.getElementById('team-filter-rejected').value;
            const policyType = document.getElementById('policy-type-filter-rejected').value;
            const insuranceCompany = document.getElementById('insurance-company-filter-rejected').value;
            const searchQuery = document.getElementById('search-query-rejected').value; // Get search value
            
            let url = 'dashboard_head.php?tab=rejected';
            
            if (startDate) url += '&start_date=' + encodeURIComponent(startDate);
            if (endDate) url += '&end_date=' + encodeURIComponent(endDate);
            if (formType) url += '&form_type=' + encodeURIComponent(formType);
            if (manager) url += '&manager=' + encodeURIComponent(manager);
            if (team) url += '&team=' + encodeURIComponent(team);
            if (policyType) url += '&policy_type=' + encodeURIComponent(policyType);
            if (insuranceCompany) url += '&insurance_company=' + encodeURIComponent(insuranceCompany);
            if (searchQuery) url += '&search=' + encodeURIComponent(searchQuery);
            
            window.location.href = url;
        }
        
        // Function to clear filters for rejected requests
        function clearFiltersRejected() {
            document.getElementById('start-date-rejected').value = '';
            document.getElementById('end-date-rejected').value = '';
            document.getElementById('form-type-rejected').value = '';
            document.getElementById('manager-filter-rejected').value = '';
            document.getElementById('team-filter-rejected').value = '';
            document.getElementById('policy-type-filter-rejected').value = '';
            document.getElementById('insurance-company-filter-rejected').value = '';
            document.getElementById('search-query-rejected').value = '';
            
            window.location.href = 'dashboard_head.php?tab=rejected';
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
            
            // Update URL to reflect the active tab
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName.replace('-tab', ''));
            window.history.replaceState({}, '', url);
        }
        
        // Set the active tab based on URL parameter
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const tab = urlParams.get('tab');
            
            if (tab === 'approved') {
                document.querySelector('.tab:nth-child(2)').click();
            } else if (tab === 'rejected') {
                document.querySelector('.tab:nth-child(3)').click();
            }
            
            // Set form values from URL parameters
            const startDate = urlParams.get('start_date');
            const endDate = urlParams.get('end_date');
            const formType = urlParams.get('form_type');
            const manager = urlParams.get('manager');
            const team = urlParams.get('team');
            const policyType = urlParams.get('policy_type');
            const insuranceCompany = urlParams.get('insurance_company');
            const searchQuery = urlParams.get('search');
            
            if (startDate) {
                document.getElementById('start-date').value = startDate;
                document.getElementById('start-date-approved').value = startDate;
                document.getElementById('start-date-rejected').value = startDate;
            }
            
            if (endDate) {
                document.getElementById('end-date').value = endDate;
                document.getElementById('end-date-approved').value = endDate;
                document.getElementById('end-date-rejected').value = endDate;
            }
            
            if (formType) {
                document.getElementById('form-type').value = formType;
                document.getElementById('form-type-approved').value = formType;
                document.getElementById('form-type-rejected').value = formType;
            }
            
            if (manager) {
                document.getElementById('manager-filter').value = manager;
                document.getElementById('manager-filter-approved').value = manager;
                document.getElementById('manager-filter-rejected').value = manager;
            }
            
            if (team) {
                document.getElementById('team-filter').value = team;
                document.getElementById('team-filter-approved').value = team;
                document.getElementById('team-filter-rejected').value = team;
            }
            
            if (policyType) {
                document.getElementById('policy-type-filter').value = policyType;
                document.getElementById('policy-type-filter-approved').value = policyType;
                document.getElementById('policy-type-filter-rejected').value = policyType;
            }
            
            if (insuranceCompany) {
                document.getElementById('insurance-company-filter').value = insuranceCompany;
                document.getElementById('insurance-company-filter-approved').value = insuranceCompany;
                document.getElementById('insurance-company-filter-rejected').value = insuranceCompany;
            }

            if (searchQuery) {
                document.getElementById('search-query').value = searchQuery;
                document.getElementById('search-query-approved').value = searchQuery;
                document.getElementById('search-query-rejected').value = searchQuery;
            }
        });
        
        let currentRequestId = null;
        
        function approveRequest(requestId) {
            currentRequestId = requestId;
            document.getElementById('approveModal').style.display = 'flex';
            document.getElementById('approveForm').action = 'head_approve_request.php?id=' + requestId;
        }
        
        function rejectRequest(requestId) {
            currentRequestId = requestId;
            document.getElementById('rejectModal').style.display = 'flex';
            document.getElementById('rejectForm').action = 'head_reject_request.php?id=' + requestId;
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
        
        // Scroll to approval sections
        function scrollToApprovals() {
            const approvalTabs = document.getElementById('approval-tabs');
            approvalTabs.scrollIntoView({ behavior: 'smooth' });
        }
    </script>
</body>
</html>