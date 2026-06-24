<?php
// /var/www/html/cb_new_uat/sales/process_edit_sale.php

// FOR DEBUGGING ONLY - REMOVE IN PRODUCTION
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

include_once 'functions.php';

// Check if user is logged in
if (!is_logged_in()) {
    redirect_with_message('../login.php', 'error', 'Please login to access this page.');
}

// Check if form is submitted for resubmission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['is_resubmission'])) {
    $user = get_current_user();
    $user_id = $user['id'];
    $sale_id = isset($_POST['sale_id']) ? (int)$_POST['sale_id'] : 0;

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

    // Handle file upload
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
            redirect_with_message('edit_sale.php?id=' . $sale_id, 'error', 'Error uploading payment screenshot.');
        }
    }

    // Start transaction for data integrity
    $conn->begin_transaction();

    try {
        // Get the original data to store in resubmissions table
        $sql_original = "SELECT * FROM sales_requests WHERE id = ?";
        $stmt = $conn->prepare($sql_original);
        $stmt->bind_param("i", $sale_id);
        $stmt->execute();
        $original_data = $stmt->get_result()->fetch_assoc();
        
        if (!$original_data) {
            throw new Exception("Original sale request not found for resubmission.");
        }
        
        // Store original data in resubmissions table
        $sql_resub = "INSERT INTO sales_resubmissions (sales_request_id, resubmitted_by, original_data) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql_resub);
        $original_json = json_encode($original_data);
        $stmt->bind_param("iis", $sale_id, $user_id, $original_json);
        $stmt->execute();
        
        // If a new file is not uploaded, keep the old one
        if (!$payment_screenshot_url) {
            $payment_screenshot_url = $original_data['payment_screenshot_url'];
        }

        // Update the existing sales request
        $sql = "UPDATE sales_requests SET 
                submission_date=?, quotation_number=?, ccs_lead_id=?, customer_name=?, 
                mobile_number=?, vehicle_number=?, rm_name=?, leader_name=?, premium=?, 
                premium_without_gst=?, policy_type=?, vehicle_type=?, city=?, state=?, 
                vehicle_cc=?, registration_year=?, tp_status=?, tp_premium=?, od_single_year=?, 
                od_multi_year=?, category=?, fuel_type=?, insurance_company=?, deal_type=?, 
                payment_screenshot_url=?, remarks=?, status='Pending', updated_at=CURRENT_TIMESTAMP
                WHERE id=?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "ssssssssddsssssssddssssssssi",
            $data['submission_date'], $data['quotation_number'], $data['ccs_lead_id'], $data['customer_name'],
            $data['mobile_number'], $data['vehicle_number'], $data['rm_name'], $data['leader_name'], $data['premium'],
            $data['premium_without_gst'], $data['policy_type'], $data['vehicle_type'], $data['city'], $data['state'],
            $data['vehicle_cc'], $data['registration_year'], $data['tp_status'], $data['tp_premium'], $data['od_single_year'],
            $data['od_multi_year'], $data['category'], $data['fuel_type'], $data['insurance_company'], $data['deal_type'],
            $payment_screenshot_url, $data['remarks'], $sale_id
        );
        
        $stmt->execute();
        $conn->commit();
        
        redirect_with_message('index.php', 'success', "Sale request resubmitted successfully!");

    } catch (Exception $e) {
        $conn->rollback();
        redirect_with_message('edit_sale.php?id=' . $sale_id, 'error', 'An error occurred: ' . $e->getMessage());
    }

    $stmt->close();
} else {
    redirect_with_message('index.php', 'error', 'Invalid request.');
}
?>