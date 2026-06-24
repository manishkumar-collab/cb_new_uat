<?php
require_once 'config.php';

// Check if user is logged in and has admin role
if (!is_logged_in() || !has_role('Admin')) {
    show_notification('You do not have permission to access this page', 'error');
    redirect('login.php');
}

// Get all cashback requests with manager information
 $requests_sql = "SELECT cr.*, u.full_name AS user_name, m.full_name AS manager_name
                 FROM cashback_requests cr 
                 JOIN users u ON cr.user_id = u.id
                 LEFT JOIN users m ON u.manager_id = m.id
                 ORDER BY cr.created_at DESC";
 $requests_result = mysqli_query($conn, $requests_sql);

// Create a file pointer
 $filename = "cashback_requests_" . date('Y-m-d') . ".csv";
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

// Open output stream
 $output = fopen('php://output', 'w');

// Add CSV headers - include all fields from database
fputcsv($output, [
    'ID', 
    'Form Type', 
    'Reference Number', 
    'User ID', 
    'User Name', 
    'Validator ID', 
    'RM Employee ID', 
    'RM Name', 
    'Department', 
    'Customer Name', 
    'Mobile Number', 
    'Month', 
    'Year', 
    'Insurance Company', 
    'Policy Type', 
    'Premium with GST', 
    'Without GST', 
    'Referral Amount', 
    'Attachment URL', 
    'Policy Copy URL', 
    'Payment Link', 
    'Reason', 
    'UTR Number', 
    'Payment Screenshot URL', 
    'Status', 
    'Created At', 
    'Updated At',
    'Manager Name'
]);

// Add data rows
while ($request = mysqli_fetch_assoc($requests_result)) {
    fputcsv($output, [
        $request['id'],
        $request['form_type'],
        $request['reference_number'],
        $request['user_id'],
        $request['user_name'],
        $request['validator_id'],
        $request['rm_emp_id'],
        $request['rm_name'],
        $request['department'],
        $request['customer_name'],
        $request['mobile_number'],
        $request['month'],
        $request['year'],
        $request['insurance_company'],
        $request['policy_type'],
        $request['premium_with_gst'],
        $request['without_gst'],
        number_format($request['referral_amount'], 2), // Removed ₹ symbol
        $request['attachment_url'],
        $request['policy_copy_url'],
        $request['payment_link'],
        $request['reason'],
        $request['utr_number'],
        $request['payment_screenshot_url'],
        $request['status'],
        $request['created_at'],
        $request['updated_at'],
        $request['manager_name'] // Added manager name
    ]);
}

// Close the output stream
fclose($output);
exit;
?>