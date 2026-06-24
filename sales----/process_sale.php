<?php
// /var/www/html/cb_new_uat/sales/process_sale.php

include_once 'functions.php';

// Check if user is logged in
if (!is_logged_in()) {
    redirect_with_message('../login.php', 'error', 'Please login to access this page.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_sale'])) {
    $user = get_current_user();
    
    // Get user's manager and head IDs
    $manager_id = $user['manager_id'];
    $head_id = $user['head_id'];
    
    // Generate unique reference number
    $reference_number = generate_sales_reference_number();
    
    // File upload for payment screenshot
    $payment_screenshot_url = null;
    if (isset($_FILES['payment_screenshot']) && $_FILES['payment_screenshot']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/sales_screenshots/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        $file_name = time() . '_' . basename($_FILES['payment_screenshot']['name']);
        $target_file = $upload_dir . $file_name;
        
        if (move_uploaded_file($_FILES['payment_screenshot']['tmp_name'], $target_file)) {
            $payment_screenshot_url = $target_file;
        } else {
            redirect_with_message('add_sale.php', 'error', 'Error uploading payment screenshot.');
        }
    }
    
    // Get form data and sanitize
    $data = [
        'submission_date' => sanitize_input($_POST['submission_date']),
        'quotation_number' => sanitize_input($_POST['quotation_number']),
        'ccs_lead_id' => sanitize_input($_POST['ccs_lead_id']),
        'customer_name' => sanitize_input($_POST['customer_name']),
        'mobile_number' => sanitize_input($_POST['mobile_number']),
        'vehicle_number' => sanitize_input($_POST['vehicle_number']),
        'rm_name' => sanitize_input($_POST['rm_name']),
        'leader_name' => sanitize_input($_POST['leader_name']),
        'premium' => sanitize_input($_POST['premium']),
        'premium_without_gst' => sanitize_input($_POST['premium_without_gst']),
        'policy_type' => sanitize_input($_POST['policy_type']),
        'vehicle_type' => sanitize_input($_POST['vehicle_type']),
        'city' => sanitize_input($_POST['city']),
        'state' => sanitize_input($_POST['state']),
        'vehicle_cc' => sanitize_input($_POST['vehicle_cc']),
        'registration_year' => sanitize_input($_POST['registration_year']),
        'tp_status' => sanitize_input($_POST['tp_status']),
        'tp_premium' => sanitize_input($_POST['tp_premium']),
        'od_single_year' => sanitize_input($_POST['od_single_year']),
        'od_multi_year' => sanitize_input($_POST['od_multi_year']),
        'category' => sanitize_input($_POST['category']),
        'fuel_type' => sanitize_input($_POST['fuel_type']),
        'insurance_company' => sanitize_input($_POST['insurance_company']),
        'deal_type' => sanitize_input($_POST['deal_type']),
        'remarks' => sanitize_input($_POST['remarks'])
    ];
    
    // Prepare SQL statement
    $sql = "INSERT INTO sales_requests (
                reference_number, user_id, manager_id, head_id, submission_date, quotation_number, 
                ccs_lead_id, customer_name, mobile_number, vehicle_number, rm_name, leader_name, 
                premium, premium_without_gst, policy_type, vehicle_type, city, state, vehicle_cc, 
                registration_year, tp_status, tp_premium, od_single_year, od_multi_year, 
                category, fuel_type, insurance_company, deal_type, payment_screenshot_url, remarks
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    
    // Bind parameters
    $stmt->bind_param(
        "siiissssssssddssssssssddsssssss",
        $reference_number, $user['id'], $manager_id, $head_id, $data['submission_date'], $data['quotation_number'],
        $data['ccs_lead_id'], $data['customer_name'], $data['mobile_number'], $data['vehicle_number'], $data['rm_name'], $data['leader_name'],
        $data['premium'], $data['premium_without_gst'], $data['policy_type'], $data['vehicle_type'], $data['city'], $data['state'], $data['vehicle_cc'],
        $data['registration_year'], $data['tp_status'], $data['tp_premium'], $data['od_single_year'], $data['od_multi_year'],
        $data['category'], $data['fuel_type'], $data['insurance_company'], $data['deal_type'], $payment_screenshot_url, $data['remarks']
    );
    
    // Execute the statement
    if ($stmt->execute()) {
        redirect_with_message('index.php', 'success', 'Sale submitted successfully! Reference Number: ' . $reference_number);
    } else {
        redirect_with_message('add_sale.php', 'error', 'Error submitting sale. Please try again.');
    }
    
    $stmt->close();
} else {
    redirect_with_message('add_sale.php', 'error', 'Invalid request.');
}
?>