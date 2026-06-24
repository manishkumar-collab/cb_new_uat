<?php
require_once '../config.php';
require_once 'functions.php';

// Check if user is logged in and has user role
if (!is_logged_in() || !has_role('User')) {
    show_notification('You do not have permission to access this page', 'error');
    redirect('../login.php');
}

// Initialize filter variables
 $reference_filter = isset($_GET['reference_number']) ? $_GET['reference_number'] : '';
 $customer_filter = isset($_GET['customer_name']) ? $_GET['customer_name'] : '';
 $start_date_filter = isset($_GET['start_date']) ? $_GET['start_date'] : '';
 $end_date_filter = isset($_GET['end_date']) ? $_GET['end_date'] : '';

// Get current month and year for filtering
 $current_month = date('m');
 $current_year = date('Y');

// Build the base SQL query for current month paid requests
 $requests_sql = "SELECT * FROM sales_requests WHERE user_id = ? AND status = 'Head Paid' AND MONTH(created_at) = ? AND YEAR(created_at) = ?";
 $params = [];
 $types = "iii";
 $params[] = $_SESSION['user_id'];
 $params[] = $current_month;
 $params[] = $current_year;

// Dynamically add filter conditions to the SQL query
if (!empty($reference_filter)) {
    $requests_sql .= " AND reference_number LIKE ?";
    $types .= "s";
    $params[] = "%$reference_filter%";
}

if (!empty($customer_filter)) {
    $requests_sql .= " AND name LIKE ?";
    $types .= "s";
    $params[] = "%$customer_filter%";
}

if (!empty($start_date_filter)) {
    $requests_sql .= " AND created_at >= ?";
    $types .= "s";
    $params[] = $start_date_filter;
}

if (!empty($end_date_filter)) {
    // Add one day to the end_date to include the entire day
    $end_date_plus_one = date('Y-m-d', strtotime($end_date_filter . ' +1 day'));
    $requests_sql .= " AND created_at < ?";
    $types .= "s";
    $params[] = $end_date_plus_one;
}

 $requests_sql .= " ORDER BY created_at DESC";

// Prepare and execute the statement
 $stmt = mysqli_prepare($conn, $requests_sql);

// Create references for bind_param
 $bind_params = [];
 $bind_params[] = $types;
foreach ($params as $key => $value) {
    $bind_params[] = &$params[$key];
}

// Use call_user_func_array to bind parameters dynamically
call_user_func_array('mysqli_stmt_bind_param', array_merge([$stmt], $bind_params));

mysqli_stmt_execute($stmt);
 $requests_result = mysqli_stmt_get_result($stmt);

// Group requests by date
 $requests_by_date = [];
while ($request = mysqli_fetch_assoc($requests_result)) {
    $date = date('d M Y', strtotime($request['created_at']));
    if (!isset($requests_by_date[$date])) {
        $requests_by_date[$date] = [];
    }
    $requests_by_date[$date][] = $request;
}

// Get statistics for current month paid requests
 $stats_sql = "SELECT 
            COUNT(*) AS total_requests,
            SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN status = 'Manager Verified' THEN 1 ELSE 0 END) AS manager_verified_count,
            SUM(CASE WHEN status = 'Head Paid' THEN 1 ELSE 0 END) AS head_paid_count,
            SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) AS rejected_count,
            SUM(CASE WHEN status != 'Rejected' THEN premium ELSE 0 END) AS total_premium,
            SUM(CASE WHEN status != 'Rejected' THEN premium_wo_gst ELSE 0 END) AS total_premium_wo_gst,
            SUM(CASE WHEN status = 'Rejected' THEN premium ELSE 0 END) AS rejected_premium
            FROM sales_requests 
            WHERE user_id = ? AND status = 'Head Paid' AND MONTH(created_at) = ? AND YEAR(created_at) = ?";
 $stmt = mysqli_prepare($conn, $stats_sql);
mysqli_stmt_bind_param($stmt, "iii", $_SESSION['user_id'], $current_month, $current_year);
mysqli_stmt_execute($stmt);
 $stats_result = mysqli_stmt_get_result($stmt);
 $stats = mysqli_fetch_assoc($stats_result);

// Get overall business statistics
 $overall_stats_sql = "SELECT 
            COUNT(*) AS total_requests,
            SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN status = 'Manager Verified' THEN 1 ELSE 0 END) AS manager_verified_count,
            SUM(CASE WHEN status = 'Head Paid' THEN 1 ELSE 0 END) AS head_paid_count,
            SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) AS rejected_count,
            SUM(CASE WHEN status != 'Rejected' THEN premium ELSE 0 END) AS total_premium,
            SUM(CASE WHEN status != 'Rejected' THEN premium_wo_gst ELSE 0 END) AS total_premium_wo_gst,
            SUM(CASE WHEN status = 'Rejected' THEN premium ELSE 0 END) AS rejected_premium
            FROM sales_requests 
            WHERE user_id = ?";
 $stmt = mysqli_prepare($conn, $overall_stats_sql);
mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
 $overall_stats_result = mysqli_stmt_get_result($stmt);
 $overall_stats = mysqli_fetch_assoc($overall_stats_result);

// Get all date-wise requests (not just paid ones)
 $all_requests_sql = "SELECT * FROM sales_requests WHERE user_id = ?";
 $all_requests_params = [$_SESSION['user_id']];
 $all_requests_types = "i";

// Apply date filters if provided
if (!empty($start_date_filter)) {
    $all_requests_sql .= " AND created_at >= ?";
    $all_requests_types .= "s";
    $all_requests_params[] = $start_date_filter;
}

if (!empty($end_date_filter)) {
    $end_date_plus_one = date('Y-m-d', strtotime($end_date_filter . ' +1 day'));
    $all_requests_sql .= " AND created_at < ?";
    $all_requests_types .= "s";
    $all_requests_params[] = $end_date_plus_one;
}

 $all_requests_sql .= " ORDER BY created_at DESC";

 $stmt = mysqli_prepare($conn, $all_requests_sql);
 $bind_params = [];
 $bind_params[] = $all_requests_types;
foreach ($all_requests_params as $key => $value) {
    $bind_params[] = &$all_requests_params[$key];
}

call_user_func_array('mysqli_stmt_bind_param', array_merge([$stmt], $bind_params));
mysqli_stmt_execute($stmt);
 $all_requests_result = mysqli_stmt_get_result($stmt);

// Group all requests by date
 $all_requests_by_date = [];
while ($request = mysqli_fetch_assoc($all_requests_result)) {
    $date = date('d M Y', strtotime($request['created_at']));
    if (!isset($all_requests_by_date[$date])) {
        $all_requests_by_date[$date] = [];
    }
    $all_requests_by_date[$date][] = $request;
}

// Calculate date-wise raised and paid amounts
 $datewise_data = [];
foreach ($all_requests_by_date as $date => $date_requests) {
    $raised_amount = 0;
    $paid_amount = 0;
    
    foreach ($date_requests as $request) {
        $raised_amount += $request['premium'];
        if ($request['status'] === 'Head Paid') {
            $paid_amount += $request['premium'];
        }
    }
    
    $datewise_data[$date] = [
        'raised_amount' => $raised_amount,
        'paid_amount' => $paid_amount,
        'requests' => $date_requests
    ];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // --- Get form data safely ---
    $isEdit = isset($_POST['request_id']) && !empty($_POST['request_id']);
    $requestId = $isEdit ? (int)$_POST['request_id'] : null;
    
    $uniqueCode = $isEdit ? $_POST['unique_code'] : generateUniqueSalesCode($_SESSION['user_id']);
    $date = $_POST['date'];
    $quotationNumber = $_POST['quotation_number'];
    $ccsLeadId = $_POST['ccs_lead_id'];
    $name = $_POST['name'];
    $mobileNo = $_POST['mobile_no'];
    $vehicleNumber = $_POST['vehicle_number'];
    $rmName = $_POST['rm_name'];
    $leaderName = $_POST['leader_name'];
    $premium = $_POST['premium'];
    $premiumWoGst = $_POST['premium_wo_gst'];
    $multiSingle = $_POST['multi_single'];
    $wheeler = $_POST['wheeler'];
    $city = $_POST['city'];
    $state = $_POST['state'];
    $cc = $_POST['cc'];
    $registerYear = $_POST['register_year'];
    $tpStatus = $_POST['tp_status'];
    $tpPremium = !empty($_POST['tp_premium']) ? $_POST['tp_premium'] : null;
    $odsy = $_POST['odsy'];
    $odmy = $_POST['odmy'];
    $category = $_POST['category'];
    $fuelType = $_POST['fuel_type'];
    $insuranceCompany = $_POST['insurance_company'];
    $dealType = $_POST['deal_type'];
    $paymentScreenshotAttached = $_POST['payment_screenshot_attached'];
    $remarks = $_POST['remarks'];

    // Get manager and head IDs from the users table
    $managerId = $userDetails['manager_id'];
    $headId = $userDetails['head_id'];

    try {
        if ($isEdit) {
            // If editing, check for justification first
            if (empty($_POST['justification'])) {
                show_notification('Justification is required when editing a rejected request', 'error');
                redirect('new_sale.php?edit_id=' . $requestId);
            }
            // Save justification
            $justificationText = $_POST['justification'];
            $sqlJustification = "INSERT INTO sales_justifications (sales_request_id, user_id, justification_text) VALUES (?, ?, ?)";
            $stmtJust = $conn->prepare($sqlJustification);
            if ($stmtJust === false) {
                throw new Exception("Error preparing justification query: " . $conn->error);
            }
            mysqli_stmt_bind_param($stmtJust, "iis", $requestId, $_SESSION['user_id'], $justificationText);
            if (!mysqli_stmt_execute($stmtJust)) {
                throw new Exception("Error executing justification query: " . mysqli_stmt_error($stmtJust));
            }

            // --- Prepare UPDATE query ---
            $status = 'Pending';
            $sql = "UPDATE sales_requests SET unique_code=?, date=?, quotation_number=?, ccs_lead_id=?, name=?, mobile_no=?, vehicle_number=?, rm_name=?, leader_name=?, premium=?, premium_wo_gst=?, multi_single=?, wheeler=?, city=?, state=?, cc=?, register_year=?, tp_status=?, tp_premium=?, odsy=?, odmy=?, category=?, fuel_type=?, insurance_company=?, deal_type=?, payment_screenshot_attached=?, remarks=?, status=? WHERE id=? AND user_id=?";
            $stmt = $conn->prepare($sql);
            if ($stmt === false) {
                throw new Exception("Prepare failed: " . $conn->error . " (SQL: " . $sql . ")");
            }
            mysqli_stmt_bind_param($stmt, "ssssssssssddsssssssdsssssssii", $uniqueCode, $date, $quotationNumber, $ccsLeadId, $name, $mobileNo, $vehicleNumber, $rmName, $leaderName, $premium, $premiumWoGst, $multiSingle, $wheeler, $city, $state, $cc, $registerYear, $tpStatus, $tpPremium, $odsy, $odmy, $category, $fuelType, $insuranceCompany, $dealType, $paymentScreenshotAttached, $remarks, $status, $requestId, $_SESSION['user_id']);
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Execute failed: " . mysqli_stmt_error($stmt));
            }
        } else {
            // --- Prepare INSERT query ---
            $referenceNumber = generateReferenceNumber();
            $status = 'Pending';
            
            // Create a simple array with column names and values
            $columns = [
                'reference_number', 'user_id', 'manager_id', 'head_id', 'unique_code', 
                'date', 'quotation_number', 'ccs_lead_id', 'name', 'mobile_no', 
                'vehicle_number', 'rm_name', 'leader_name', 'premium', 'premium_wo_gst', 
                'multi_single', 'wheeler', 'city', 'state', 'cc', 'register_year', 
                'tp_status', 'tp_premium', 'odsy', 'odmy', 'category', 'fuel_type', 
                'insurance_company', 'deal_type', 'payment_screenshot_attached', 'remarks', 'status'
            ];
            
            $values = [
                $referenceNumber, $_SESSION['user_id'], $managerId, $headId, $uniqueCode, 
                $date, $quotationNumber, $ccsLeadId, $name, $mobileNo, 
                $vehicleNumber, $rmName, $leaderName, $premium, $premiumWoGst, 
                $multiSingle, $wheeler, $city, $state, $cc, $registerYear, 
                $tpStatus, $tpPremium, $odsy, $odmy, $category, $fuelType, 
                $insuranceCompany, $dealType, $paymentScreenshotAttached, $remarks, $status
            ];
            
            // Create the SQL query dynamically
            $columnList = implode(', ', $columns);
            $valuePlaceholders = implode(', ', array_fill(0, count($columns), '?'));
            $sql = "INSERT INTO sales_requests ($columnList) VALUES ($valuePlaceholders)";
            
            $stmt = $conn->prepare($sql);
            if ($stmt === false) {
                throw new Exception("Prepare failed: " . $conn->error . " (SQL: " . $sql . ")");
            }
            
            // Create the types string dynamically
            $types = "";
            foreach ($values as $value) {
                if (is_int($value)) {
                    $types .= "i";
                } elseif (is_float($value)) {
                    $types .= "d";
                } else {
                    $types .= "s";
                }
            }
            
            // Use call_user_func_array to bind parameters dynamically
            $bind_params = array_merge([$types], $values);
            if (!call_user_func_array(array($stmt, 'bind_param'), $bind_params)) {
                throw new Exception("Bind param failed: " . $stmt->error);
            }
            
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Execute failed: " . mysqli_stmt_error($stmt));
            }
        }

        // --- Success ---
        $message = $isEdit ? 'Sales request updated and resubmitted successfully!' : 'Sales request submitted successfully!';
        show_notification($message, 'success');
        redirect('index.php');

    } catch (Exception $e) {
        // --- Catch and display the error ---
        show_notification('Error: ' . $e->getMessage(), 'error');
        redirect('new_sale.php' . ($isEdit ? '?edit_id=' . $requestId : ''));
    }
}

// Check if we are editing a request
 $editMode = false;
 $editData = null;
if (isset($_GET['edit_id'])) {
    $editMode = true;
    $editId = $_GET['edit_id'];
    $sql = "SELECT * FROM sales_requests WHERE id = ? AND user_id = ? AND status = 'Rejected'";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $editId, $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $editData = mysqli_fetch_assoc($result);
    if (!$editData) {
        show_notification('Invalid request or you do not have permission to edit this.', 'error');
        redirect('index.php');
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Dashboard - CB Account</title>
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
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th, td {
            padding: 8px 12px;
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
        .form-link {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background-color: var(--primary);
            color: white;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            cursor: pointer;
            z-index: 100;
            transition: all 0.3s ease;
        }
        .form-link:hover {
            background-color: var(--primary-dark);
            transform: scale(1.1);
        }
        .form-link i {
            font-size: 24px;
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
        /* Datewise Request Styles */
        .date-container {
            margin-bottom: 20px;
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            overflow: hidden;
        }
        .date-header {
            background-color: var(--dark);
            color: var(--light);
            padding: 2px 15px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .date-header:hover {
            background-color: #4a5568;
        }
        .date-header i {
            transition: transform 0.3s ease;
        }
        .date-header.collapsed i {
            transform: rotate(180deg);
        }
        .date-content {
            max-height: 2000px;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }
        .date-content.collapsed {
            max-height: 0;
        }
        .date-table-container {
            padding: 0;
        }
        .date-summary {
            font-size: 12px;
            color: var(--text-light);
        }
        /* Filter Section Styles */
        .filter-section {
            background-color: #f8fafc;
            border-radius: var(--radius);
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
        }
        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--dark);
        }
        .filter-group input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            font-size: 14px;
        }
        .filter-actions {
            display: flex;
            gap: 10px;
        }
        /* Form Styles */
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
        .form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
        }
        .form-row .form-group {
            flex: 1;
            min-width: 200px;
        }
        /* New styles for datewise summary */
        .datewise-summary {
            display: flex;
            justify-content: space-between;
            padding: 8px 15px;
            background-color: #f8fafc;
            border-bottom: 1px solid var(--gray);
            font-size: 12px;
        }
        .datewise-summary-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .datewise-summary-item i {
            color: var(--primary);
        }
        /* New styles for request tabs */
        .request-tabs {
            display: flex;
            border-bottom: 1px solid var(--gray);
            background-color: #f8fafc;
        }
        .request-tab {
            padding: 8px 15px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            color: var(--text);
            border-bottom: 2px solid transparent;
            transition: all 0.2s ease;
        }
        .request-tab:hover {
            background-color: #e2e8f0;
        }
        .request-tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }
        .request-tab-content {
            display: none;
        }
        .request-tab-content.active {
            display: block;
        }
        /* NEW: Style for rejected requests card */
        .stat-card.rejected {
            border-left: 3px solid #cf1322;
        }
        .stat-card.rejected .stat-value {
            color: #cf1322;
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
            .filter-row {
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
                    <i class="fas fa-chart-line sidebar-logo-icon"></i>
                    <div class="sidebar-logo-text">Sales Account</div>
                </div>
            </div>
            
            <div class="sidebar-user">
                <div class="sidebar-user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                <div class="sidebar-user-role"><?php echo htmlspecialchars($_SESSION['role']); ?></div>
            </div>
            
            <nav class="sidebar-menu">
                <a href="index.php" class="sidebar-menu-item active">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="new_sale.php" class="sidebar-menu-item">
                    <i class="fas fa-plus-circle"></i> New Sale
                </a>
                <a href="../dashboard_user.php" class="sidebar-menu-item">
                    <i class="fas fa-coins"></i> CB Account
                </a>
                <a href="../quote" class="sidebar-menu-item">
                    <i class="fas fa-file-invoice"></i> Quotation Section
                </a>
                <a href="../logout.php" class="sidebar-menu-item">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a> 
            </nav>
            
            <div class="sidebar-footer">
                &copy; <?php echo date('Y'); ?> Sales Account System
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
                        <div class="logo-text">Sales <span>Dashboard</span></div>
                    </div>
                    <p class="tagline">Track your sales performance</p>
                    
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
                    <h2 class="section-title">My Sales Requests (Current Month - Paid)</h2>
                    
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $stats['total_requests']; ?></div>
                            <div class="stat-label">Total Requests</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $stats['pending_count']; ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $stats['manager_verified_count']; ?></div>
                            <div class="stat-label">Manager Verified</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $stats['head_paid_count']; ?></div>
                            <div class="stat-label">Head Paid</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $stats['rejected_count']; ?></div>
                            <div class="stat-label">Rejected</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value">₹<?php echo number_format($stats['total_premium'], 2); ?></div>
                            <div class="stat-label">Total Premium</div>
                        </div>
                        <!-- NEW: Rejected Requests Card 
                        <div class="stat-card rejected">
                            <div class="stat-value">₹<?php echo number_format($stats['rejected_premium'], 2); ?></div>
                            <div class="stat-label">Rejected Premium (<?php echo $stats['rejected_count']; ?> requests)</div>
                        </div>-->
                    </div>
                    
                    <!-- Overall Business Stats -->
                    <h2 class="section-title">Overall Business</h2>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $overall_stats['total_requests']; ?></div>
                            <div class="stat-label">Total Requests</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $overall_stats['head_paid_count']; ?></div>
                            <div class="stat-label">Head Paid</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value">₹<?php echo number_format($overall_stats['total_premium'], 2); ?></div>
                            <div class="stat-label">Total Premium (Excluding Rejected)</div>
                        </div>
                        <!-- NEW: Rejected Requests Card -->
                        <div class="stat-card rejected">
                            <div class="stat-value">₹<?php echo number_format($overall_stats['rejected_premium'], 2); ?></div>
                            <div class="stat-label">Rejected Premium (<?php echo $overall_stats['rejected_count']; ?> requests)</div>
                        </div>
                    </div>
                    
                    <!-- Filter Section -->
                    <div class="filter-section">
                        <form id="filterForm" method="GET" action="index.php">
                            <div class="filter-row">
                                <div class="filter-group">
                                    <label for="reference_number">Reference #</label>
                                    <input type="text" id="reference_number" name="reference_number" value="<?php echo htmlspecialchars($reference_filter); ?>" placeholder="Enter reference number">
                                </div>
                                <div class="filter-group">
                                    <label for="customer_name">Customer</label>
                                    <input type="text" id="customer_name" name="customer_name" value="<?php echo htmlspecialchars($customer_filter); ?>" placeholder="Enter customer name">
                                </div>
                                <div class="filter-group">
                                    <label for="start_date">Start Date</label>
                                    <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date_filter); ?>">
                                </div>
                                <div class="filter-group">
                                    <label for="end_date">End Date</label>
                                    <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date_filter); ?>">
                                </div>
                                <div class="filter-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-filter"></i> Filter
                                    </button>
                                    <button type="button" class="btn btn-primary" onclick="resetFilters()">
                                        <i class="fas fa-redo"></i> Reset
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                    
                    <?php if (!empty($datewise_data)): ?>
                        <div class="datewise-requests">
                            <?php foreach ($datewise_data as $date => $date_info): ?>
                                <div class="date-container">
                                    <div class="date-header" onclick="toggleDateSection(this)">
                                        <span><?php echo $date; ?></span>
                                        <span class="date-summary">
                                            <?php echo count($date_info['requests']); ?> requests | 
                                            ₹<?php echo number_format($date_info['raised_amount'], 2); ?> raised | 
                                            ₹<?php echo number_format($date_info['paid_amount'], 2); ?> paid
                                        </span>
                                        <i class="fas fa-chevron-up"></i>
                                    </div>
                                    <div class="date-content">
                                        <!-- Datewise Summary with Icons -->
                                        <div class="datewise-summary">
                                            <div class="datewise-summary-item">
                                                <i class="fas fa-arrow-up"></i>
                                                <span>Raised: ₹<?php echo number_format($date_info['raised_amount'], 2); ?></span>
                                            </div>
                                            <div class="datewise-summary-item">
                                                <i class="fas fa-check-circle"></i>
                                                <span>Paid: ₹<?php echo number_format($date_info['paid_amount'], 2); ?></span>
                                            </div>
                                        </div>
                                        
                                        <!-- Request Tabs -->
                                        <div class="request-tabs">
                                            <div class="request-tab active" onclick="showTab(this, 'all-requests-<?php echo str_replace([' ', '-'], ['', ''], $date); ?>')">
                                                All Requests (<?php echo count($date_info['requests']); ?>)
                                            </div>
                                            <div class="request-tab" onclick="showTab(this, 'paid-requests-<?php echo str_replace([' ', '-'], ['', ''], $date); ?>')">
                                                Paid (<?php echo count(array_filter($date_info['requests'], function($r) { return $r['status'] === 'Head Paid'; })); ?>)
                                            </div>
                                        </div>
                                        
                                        <!-- All Requests Tab Content -->
                                        <div id="all-requests-<?php echo str_replace([' ', '-'], ['', ''], $date); ?>" class="request-tab-content active">
                                            <div class="date-table-container">
                                                <table>
                                                    <thead>
                                                        <tr>
                                                            <th>Reference #</th>
                                                            <th>Customer</th>
                                                            <th>Vehicle</th>
                                                            <th>Premium</th>
                                                            <th>Status</th>
                                                            <th>Date</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($date_info['requests'] as $request): ?>
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
                                                            <tr>
                                                                <td><?php echo htmlspecialchars($request['reference_number']); ?></td>
                                                                <td><?php echo htmlspecialchars($request['name']); ?></td>
                                                                <td><?php echo htmlspecialchars($request['vehicle_number']); ?></td>
                                                                <td>₹<?php echo number_format($request['premium'], 2); ?></td>
                                                                <td>
                                                                    <span class="status-badge <?php echo $status_class; ?>">
                                                                        <?php echo htmlspecialchars($request['status']); ?>
                                                                    </span>
                                                                </td>
                                                                <td><?php echo date('d M Y', strtotime($request['created_at'])); ?></td>
                                                                <td>
                                                                    <button class="btn btn-primary" onclick="viewRequest(<?php echo $request['id']; ?>)">
                                                                        <i class="fas fa-eye"></i> View
                                                                    </button>
                                                                    <?php if ($request['status'] === 'Rejected'): ?>
                                                                        <button class="btn btn-primary" onclick="editRequest(<?php echo $request['id']; ?>)">
                                                                            <i class="fas fa-edit"></i> Edit
                                                                        </button>
                                                                    <?php endif; ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        
                                        <!-- Paid Requests Tab Content -->
                                        <div id="paid-requests-<?php echo str_replace([' ', '-'], ['', ''], $date); ?>" class="request-tab-content">
                                            <div class="date-table-container">
                                                <table>
                                                    <thead>
                                                        <tr>
                                                            <th>Reference #</th>
                                                            <th>Customer</th>
                                                            <th>Vehicle</th>
                                                            <th>Premium</th>
                                                            <th>Status</th>
                                                            <th>Date</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($date_info['requests'] as $request): ?>
                                                            <?php if ($request['status'] === 'Head Paid'): ?>
                                                            <?php
                                                            $status_class = 'status-head-paid';
                                                            ?>
                                                            <tr>
                                                                <td><?php echo htmlspecialchars($request['reference_number']); ?></td>
                                                                <td><?php echo htmlspecialchars($request['name']); ?></td>
                                                                <td><?php echo htmlspecialchars($request['vehicle_number']); ?></td>
                                                                <td>₹<?php echo number_format($request['premium'], 2); ?></td>
                                                                <td>
                                                                    <span class="status-badge <?php echo $status_class; ?>">
                                                                        <?php echo htmlspecialchars($request['status']); ?>
                                                                    </span>
                                                                </td>
                                                                <td><?php echo date('d M Y', strtotime($request['created_at'])); ?></td>
                                                                <td>
                                                                    <button class="btn btn-primary" onclick="viewRequest(<?php echo $request['id']; ?>)">
                                                                        <i class="fas fa-eye"></i> View
                                                                    </button>
                                                                </td>
                                                            </tr>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-chart-line"></i>
                            <p>No sales requests found</p>
                            <p style="margin-top: 10px;">Click button below to submit a new sales request.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Form Link Button -->
            <a href="new_sale.php" class="form-link" title="Submit New Sale">
                <i class="fas fa-plus"></i>
            </a>
        </main>
    </div>
    
    <script>
        function viewRequest(requestId) {
            window.location.href = 'view_sale.php?id=' + requestId;
        }
        
        function editRequest(requestId) {
            window.location.href = 'new_sale.php?edit_id=' + requestId;
        }
        
        function toggleDateSection(element) {
            element.classList.toggle('collapsed');
            element.nextElementSibling.classList.toggle('collapsed');
        }
        
        function showTab(tabElement, tabContentId) {
            // Hide all tab contents
            const tabContents = tabElement.parentElement.parentElement.querySelectorAll('.request-tab-content');
            tabContents.forEach(content => {
                content.classList.remove('active');
            });
            
            // Remove active class from all tabs
            const tabs = tabElement.parentElement.querySelectorAll('.request-tab');
            tabs.forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show the selected tab content
            document.getElementById(tabContentId).classList.add('active');
            
            // Add active class to the selected tab
            tabElement.classList.add('active');
        }
        
        function resetFilters() {
            window.location.href = 'index.php';
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