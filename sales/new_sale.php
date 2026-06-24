<?php
require_once '../config.php';
require_once 'functions.php';

// Check if user is logged in and has user role
if (!is_logged_in() || !has_role('User')) {
    show_notification('You do not have permission to access this page', 'error');
    redirect('../login.php');
}

// Get user details
 $user_id = $_SESSION['user_id'];
 $userDetails = getUserDetails($user_id);

// Get RM Name (logged-in user's name)
 $rmName = $userDetails['full_name'];

// Get Leader Name (manager's name)
 $leaderName = '';
if (!empty($userDetails['manager_id'])) {
    $managerSql = "SELECT full_name FROM users WHERE id = ?";
    $managerStmt = $conn->prepare($managerSql);
    mysqli_stmt_bind_param($managerStmt, "i", $userDetails['manager_id']);
    mysqli_stmt_execute($managerStmt);
    $managerResult = mysqli_stmt_get_result($managerStmt);
    $managerData = mysqli_fetch_assoc($managerResult);
    if ($managerData) {
        $leaderName = $managerData['full_name'];
    }
}

// Define and check the payment directory
 $paymentDir = '/var/www/html/cb_new_uat/sales/payment_screenshots';

// Create directory if it doesn't exist
if (!file_exists($paymentDir)) {
    if (!mkdir($paymentDir, 0755, true)) {
        error_log("Failed to create directory: " . $paymentDir);
        show_notification('Server configuration error: Could not create the upload directory. Please contact support.', 'error');
    }
}

// Check if the directory is writable
if (!is_writable($paymentDir)) {
    $webUser = 'unknown';
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $userInfo = posix_getpwuid(posix_geteuid());
        $webUser = $userInfo['name'] ?? 'unknown';
    } else {
        $webUser = get_current_user();
    }

    $errorMessage = sprintf(
        'Server Error: The upload directory <code>%s</code> is not writable by the web server user (<strong>%s</strong>).<br><br>Please run the following commands in your server terminal:<br><br><code>sudo chown %s:%s %s</code><br><code>sudo chmod -R 755 %s</code>',
        htmlspecialchars($paymentDir),
        htmlspecialchars($webUser),
        htmlspecialchars($webUser),
        htmlspecialchars($webUser),
        htmlspecialchars($paymentDir),
        htmlspecialchars($paymentDir)
    );
    
    show_notification($errorMessage, 'error');
    exit();
}

// Check if we are editing a request
 $editMode = false;
 $editData = null;
if (isset($_GET['edit_id'])) {
    $editMode = true;
    $editId = $_GET['edit_id'];
    
    $sql = "SELECT * FROM sales_requests WHERE id = ? AND user_id = ? AND status = 'Rejected'";
    $stmt = $conn->prepare($sql);
    mysqli_stmt_bind_param($stmt, "ii", $editId, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $editData = mysqli_fetch_assoc($result);
    
    if (!$editData) {
        show_notification('Invalid request or you do not have permission to edit this.', 'error');
        redirect('index.php');
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isEdit = isset($_POST['request_id']) && !empty($_POST['request_id']);
    $requestId = $isEdit ? (int)$_POST['request_id'] : null;
    
    $date = $_POST['date'];
    $quotationNumber = $_POST['quotation_number'];
    $ccsLeadId = $_POST['ccs_lead_id'];
    $name = $_POST['name'];
    $mobileNo = $_POST['mobile_no'];
    $vehicleNumber = $_POST['vehicle_number'];
    $rmName = $_POST['rm_name'];
    $leaderName = !empty($_POST['leader_name']) ? $_POST['leader_name'] : '';
    $premium = $_POST['premium'];
    $premiumWoGst = $_POST['premium_wo_gst'];
    $multiSingle = $_POST['multi_single'];
    $wheeler = $_POST['wheeler'];
    $city = $_POST['city'];
    $state = $_POST['state'];
    $cc = $_POST['cc'];
    $registerYear = $_POST['register_year'];
    // Validation: Agar Registration Date khali hai toh error dikhayein
if (empty($registerYear)) {
    show_notification('Error: Registration Date is required.', 'error');
    redirect('new_sale.php' . ($isEdit ? '?edit_id=' . $requestId : ''));
}
    $vehicleAge = $_POST['vehicle_age'];
    $tpStatus = $_POST['tp_status'];
    $tpPremium = !empty($_POST['tp_premium']) ? $_POST['tp_premium'] : null;
    $odsy = !empty($_POST['odsy']) ? $_POST['odsy'] : '';
    $odmy = !empty($_POST['odmy']) ? $_POST['odmy'] : '';
    $category = $_POST['category'];
    $fuelType = $_POST['fuel_type'];
    $make = $_POST['make'];
    $model = $_POST['model'];
    $insuranceCompany = $_POST['insurance_company'];
    $dealType = $_POST['deal_type'];
    
    // Handle payment screenshot upload
    $paymentScreenshotPath = '';
    $paymentScreenshotAttached = 'No';
    
    if (isset($_FILES['payment_screenshot']) && $_FILES['payment_screenshot']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['payment_screenshot']['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
                UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form',
                UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload'
            ];
            
            $errorMessage = isset($errorMessages[$_FILES['payment_screenshot']['error']]) 
                ? $errorMessages[$_FILES['payment_screenshot']['error']] 
                : 'Unknown upload error';
                
            show_notification('Error uploading payment screenshot: ' . $errorMessage, 'error');
            redirect('new_sale.php' . ($isEdit ? '?edit_id=' . $requestId : ''));
        }
        
        $fileTmpPath = $_FILES['payment_screenshot']['tmp_name'];
        $fileName = $_FILES['payment_screenshot']['name'];
        $fileSize = $_FILES['payment_screenshot']['size'];
        $fileType = $_FILES['payment_screenshot']['type'];
        
        $maxSize = 5 * 1024 * 1024; // 5MB
        if ($fileSize > $maxSize) {
            show_notification('File size too large. Maximum allowed size is 5MB', 'error');
            redirect('new_sale.php' . ($isEdit ? '?edit_id=' . $requestId : ''));
        }
        
        $fileInfo = pathinfo($fileName);
        $fileExtension = strtolower($fileInfo['extension']);
        $newFileName = 'payment_' . time() . '_' . mt_rand(1000, 9999) . '.' . $fileExtension;
        $destPath = $paymentDir . '/' . $newFileName;
        
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
        if (!in_array($fileType, $allowedTypes)) {
            show_notification('Invalid file type. Please upload an image file (JPG, PNG, GIF)', 'error');
            redirect('new_sale.php' . ($isEdit ? '?edit_id=' . $requestId : ''));
        }
        
        if (!move_uploaded_file($fileTmpPath, $destPath)) {
            error_log("Failed to move uploaded file from $fileTmpPath to $destPath");
            show_notification('Error uploading payment screenshot. Please try again.', 'error');
            redirect('new_sale.php' . ($isEdit ? '?edit_id=' . $requestId : ''));
        }
        
        $paymentScreenshotPath = 'sales/payment_screenshots/' . $newFileName;
        $paymentScreenshotAttached = 'Yes';
    }
    
    $remarks = !empty($_POST['remarks']) ? $_POST['remarks'] : '';
    $managerId = $userDetails['manager_id'];
    $headId = $userDetails['head_id'];

    try {
        if ($isEdit) {
            if (empty($_POST['justification'])) {
                show_notification('Justification is required when editing a rejected request', 'error');
                redirect('new_sale.php?edit_id=' . $requestId);
            }
            
            mysqli_begin_transaction($conn);
            
            try {
                $justificationText = $_POST['justification'];
                $sqlJustification = "INSERT INTO sales_justifications (sales_request_id, user_id, justification_text) VALUES (?, ?, ?)";
                $stmtJust = $conn->prepare($sqlJustification);
                if ($stmtJust === false) {
                    throw new Exception("Error preparing justification query: " . $conn->error);
                }
                mysqli_stmt_bind_param($stmtJust, "iis", $requestId, $user_id, $justificationText);
                if (!mysqli_stmt_execute($stmtJust)) {
                    throw new Exception("Error executing justification query: " . mysqli_stmt_error($stmtJust));
                }
                
                $updateFields = [
                    'date' => $date,
                    'quotation_number' => $quotationNumber,
                    'ccs_lead_id' => $ccsLeadId,
                    'name' => $name,
                    'mobile_no' => $mobileNo,
                    'vehicle_number' => $vehicleNumber,
                    'rm_name' => $rmName,
                    'leader_name' => $leaderName,
                    'premium' => $premium,
                    'premium_wo_gst' => $premiumWoGst,
                    'multi_single' => $multiSingle,
                    'wheeler' => $wheeler,
                    'city' => $city,
                    'state' => $state,
                    'cc' => $cc,
                    'register_year' => $registerYear,
                    'vehicle_age' => $vehicleAge,
                    'tp_status' => $tpStatus,
                    'tp_premium' => $tpPremium,
                    'odsy' => $odsy,
                    'odmy' => $odmy,
                    'category' => $category,
                    'fuel_type' => $fuelType,
                    'make' => $make,
                    'model' => $model,
                    'insurance_company' => $insuranceCompany,
                    'deal_type' => $dealType,
                    'payment_screenshot_attached' => $paymentScreenshotAttached,
                    'remarks' => $remarks,
                    'status' => 'Pending',
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                
                if (!empty($paymentScreenshotPath)) {
                    $updateFields['payment_screenshot_url'] = $paymentScreenshotPath;
                }
                
                $setClause = [];
                $types = "";
                $values = [];
                
                foreach ($updateFields as $field => $value) {
                    $setClause[] = "$field = ?";
                    if (is_int($value)) {
                        $types .= "i";
                    } elseif (is_float($value)) {
                        $types .= "d";
                    } else {
                        $types .= "s";
                    }
                    $values[] = $value;
                }
                
                $types .= "ii";
                $values[] = $requestId;
                $values[] = $user_id;
                
                $sql = "UPDATE sales_requests SET " . implode(', ', $setClause) . " WHERE id = ? AND user_id = ?";
                $stmt = $conn->prepare($sql);
                if ($stmt === false) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                
                mysqli_stmt_bind_param($stmt, $types, ...$values);
                
                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception("Execute failed: " . mysqli_stmt_error($stmt));
                }
                
                $sqlApproval = "INSERT INTO approvals_sales (sales_request_id, approver_id, approver_role, status, comments) VALUES (?, ?, 'User', 'Resubmitted', ?)";
                $stmtApproval = $conn->prepare($sqlApproval);
                mysqli_stmt_bind_param($stmtApproval, "iis", $requestId, $user_id, $justificationText);
                mysqli_stmt_execute($stmtApproval);
                
                mysqli_commit($conn);
                
                $message = 'Sales request updated and resubmitted successfully!';
            } catch (Exception $e) {
                mysqli_rollback($conn);
                throw $e;
            }
        } else {
            $referenceNumber = generateReferenceNumber();
            $uniqueCode = generateUniqueSalesCode($user_id);
            $status = 'Pending';
            
            mysqli_begin_transaction($conn);
            
            try {
                $insertFields = [
                    'reference_number' => $referenceNumber,
                    'user_id' => $user_id,
                    'manager_id' => $managerId,
                    'head_id' => $headId,
                    'unique_code' => $uniqueCode,
                    'date' => $date,
                    'quotation_number' => $quotationNumber,
                    'ccs_lead_id' => $ccsLeadId,
                    'name' => $name,
                    'mobile_no' => $mobileNo,
                    'vehicle_number' => $vehicleNumber,
                    'rm_name' => $rmName,
                    'leader_name' => $leaderName,
                    'premium' => $premium,
                    'premium_wo_gst' => $premiumWoGst,
                    'multi_single' => $multiSingle,
                    'wheeler' => $wheeler,
                    'city' => $city,
                    'state' => $state,
                    'cc' => $cc,
                    'register_year' => $registerYear,
                    'vehicle_age' => $vehicleAge,
                    'tp_status' => $tpStatus,
                    'tp_premium' => $tpPremium,
                    'odsy' => $odsy,
                    'odmy' => $odmy,
                    'category' => $category,
                    'fuel_type' => $fuelType,
                    'make' => $make,
                    'model' => $model,
                    'insurance_company' => $insuranceCompany,
                    'deal_type' => $dealType,
                    'payment_screenshot_attached' => $paymentScreenshotAttached,
                    'remarks' => $remarks,
                    'status' => $status
                ];
                
                if (!empty($paymentScreenshotPath)) {
                    $insertFields['payment_screenshot_url'] = $paymentScreenshotPath;
                }
                
                $columns = array_keys($insertFields);
                $values = array_values($insertFields);
                
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
                
                $columnList = implode(', ', $columns);
                $valuePlaceholders = implode(', ', array_fill(0, count($values), '?'));
                $sql = "INSERT INTO sales_requests ($columnList) VALUES ($valuePlaceholders)";
                
                $stmt = $conn->prepare($sql);
                if ($stmt === false) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                
                mysqli_stmt_bind_param($stmt, $types, ...$values);
                
                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception("Execute failed: " . mysqli_stmt_error($stmt));
                }
                
                mysqli_commit($conn);
                
                $message = 'Sales request submitted successfully!';
            } catch (Exception $e) {
                mysqli_rollback($conn);
                throw $e;
            }
        }

        show_notification($message, 'success');
        redirect('index.php');

    } catch (Exception $e) {
        show_notification('Error: ' . $e->getMessage(), 'error');
        redirect('new_sale.php' . ($isEdit ? '?edit_id=' . $requestId : ''));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $editMode ? 'Edit' : 'New'; ?> Sale - Sales System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="https://www.coveryou.in/images/favicon.png" type="image/png">
    <style>
        /* Your existing CSS styles */
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
        .form-container {
            background-color: var(--light);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
        }
        .form-title {
            font-size: 18px;
            color: var(--primary);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--gray);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 15px;
        }
        .form-group {
            flex: 1;
            min-width: 250px;
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
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(240, 93, 73, 0.2);
        }
        .form-control:disabled {
            background-color: #f8f9fa;
            cursor: not-allowed;
        }
        .btn {
            padding: 8px 16px;
            border-radius: var(--radius);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 14px;
            text-decoration: none;
        }
        .btn-primary {
            background-color: var(--primary);
            color: white;
        }
        .btn-primary:hover {
            background-color: var(--primary-dark);
        }
        .btn-secondary {
            background-color: #718096;
            color: white;
        }
        .btn-secondary:hover {
            background-color: #4a5568;
        }
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
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
        .alert-info {
            background-color: #e6f7ff;
            border-left: 4px solid #096dd9;
            color: #096dd9;
        }
        .required {
            color: #e53e3e;
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
            padding: 8px 12px;
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            background-color: var(--light);
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .file-upload label:hover {
            border-color: var(--primary);
        }
        .file-upload-label {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .file-name {
            margin-left: auto;
            color: var(--text-light);
            font-size: 12px;
            max-width: 150px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .file-preview {
            margin-top: 10px;
            max-width: 200px;
            max-height: 200px;
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            overflow: hidden;
            display: none;
        }
        .file-preview img {
            width: 100%;
            height: auto;
            display: block;
        }
        .age-display {
            margin-top: 5px;
            font-size: 12px;
            color: var(--text-light);
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
            .form-group {
                min-width: 100%;
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
                <a href="index.php" class="sidebar-menu-item">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="new_sale.php" class="sidebar-menu-item active">
                    <i class="fas fa-plus-circle"></i> <?php echo $editMode ? 'Edit' : 'New'; ?> Sale
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
                        <div class="logo-text">Sales <span>System</span></div>
                    </div>
                    <p class="tagline"><?php echo $editMode ? 'Edit and resubmit rejected sales request' : 'Submit a new sales request'; ?></p>
                    
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
                
                <?php if ($editMode): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> You are editing a rejected request. Please provide a justification for the changes and resubmit for approval.
                    </div>
                <?php endif; ?>
                
                <div class="form-container">
                    <h2 class="form-title">
                        <i class="fas fa-<?php echo $editMode ? 'edit' : 'plus-circle'; ?>"></i>
                        <?php echo $editMode ? 'Edit Sales Request' : 'New Sales Request'; ?>
                    </h2>
                    
                    <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?><?php echo $editMode ? '?edit_id=' . $editId : ''; ?>" enctype="multipart/form-data">
                        <?php if ($editMode): ?>
                            <input type="hidden" name="request_id" value="<?php echo $editId; ?>">
                        <?php endif; ?>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="date">Payment Date <span class="required">*</span></label>
                                <input type="date" id="date" name="date" class="form-control" value="<?php echo $editMode ? htmlspecialchars($editData['date']) : date('Y-m-d'); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="quotation_number">Quotation Number <span class="required">*</span></label>
                                <input type="text" id="quotation_number" name="quotation_number" class="form-control" value="<?php echo $editMode ? htmlspecialchars($editData['quotation_number']) : ''; ?>" required>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="ccs_lead_id">CCS Lead ID <span class="required">*</span></label>
                                <input type="text" id="ccs_lead_id" name="ccs_lead_id" class="form-control" value="<?php echo $editMode ? htmlspecialchars($editData['ccs_lead_id']) : ''; ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="name">Customer Name <span class="required">*</span></label>
                                <input type="text" id="name" name="name" class="form-control" value="<?php echo $editMode ? htmlspecialchars($editData['name']) : ''; ?>" required>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="mobile_no">Mobile Number <span class="required">*</span></label>
                                <input type="text" id="mobile_no" name="mobile_no" class="form-control" value="<?php echo $editMode ? htmlspecialchars($editData['mobile_no']) : ''; ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="vehicle_number">Vehicle Number <span class="required">*</span></label>
                                <input type="text" id="vehicle_number" name="vehicle_number" class="form-control" value="<?php echo $editMode ? htmlspecialchars($editData['vehicle_number']) : ''; ?>" required>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="rm_name">RM Name <span class="required">*</span></label>
                                <input type="text" id="rm_name" name="rm_name" class="form-control" value="<?php echo $editMode ? htmlspecialchars($editData['rm_name']) : htmlspecialchars($rmName); ?>" required disabled>
                                <input type="hidden" name="rm_name" value="<?php echo $editMode ? htmlspecialchars($editData['rm_name']) : htmlspecialchars($rmName); ?>">
                            </div>
                            <div class="form-group">
                                <label for="leader_name">Leader Name</label>
                                <input type="text" id="leader_name" name="leader_name" class="form-control" value="<?php echo $editMode ? htmlspecialchars($editData['leader_name']) : htmlspecialchars($leaderName); ?>" disabled>
                                <input type="hidden" name="leader_name" value="<?php echo $editMode ? htmlspecialchars($editData['leader_name']) : htmlspecialchars($leaderName); ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="premium">Premium <span class="required">*</span></label>
                                <input type="number" id="premium" name="premium" class="form-control" step="0.01" value="<?php echo $editMode ? htmlspecialchars($editData['premium']) : ''; ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="premium_wo_gst">Premium (w/o GST) <span class="required">*</span></label>
                                <input type="number" id="premium_wo_gst" name="premium_wo_gst" class="form-control" step="0.01" value="<?php echo $editMode ? htmlspecialchars($editData['premium_wo_gst']) : ''; ?>" required>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="multi_single">Multi/Single <span class="required">*</span></label>
                                <select id="multi_single" name="multi_single" class="form-control" required>
                                    <option value="Multi" <?php echo ($editMode && $editData['multi_single'] === 'Multi') ? 'selected' : ''; ?>>Multi</option>
                                    <option value="Single" <?php echo ($editMode && $editData['multi_single'] === 'Single') ? 'selected' : ''; ?>>Single</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="wheeler">Wheeler <span class="required">*</span></label>
                                <select id="wheeler" name="wheeler" class="form-control" required>
                                    <option value="2" <?php echo ($editMode && $editData['wheeler'] === '2') ? 'selected' : ''; ?>>2</option>
                                    <option value="4" <?php echo ($editMode && $editData['wheeler'] === '4') ? 'selected' : ''; ?>>4</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="city">City <span class="required">*</span></label>
                                <input type="text" id="city" name="city" class="form-control" value="<?php echo $editMode ? htmlspecialchars($editData['city']) : ''; ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="state">State <span class="required">*</span></label>
                                <!-- Updated to Dropdown -->
                                <select id="state" name="state" class="form-control" required>
                                    <option value="">Select State</option>
                                   <option value="Andhra Pradesh">Andhra Pradesh</option>
        <option value="Arunachal Pradesh">Arunachal Pradesh</option>
        <option value="Assam">Assam</option>
        <option value="Bihar">Bihar</option>
        <option value="Chhattisgarh">Chhattisgarh</option>
        <option value="Goa">Goa</option>
        <option value="Gujarat">Gujarat</option>
        <option value="Haryana">Haryana</option>
        <option value="Himachal Pradesh">Himachal Pradesh</option>
        <option value="Jharkhand">Jharkhand</option>
        <option value="Karnataka">Karnataka</option>
        <option value="Kerala">Kerala</option>
        <option value="Madhya Pradesh">Madhya Pradesh</option>
        <option value="Maharashtra">Maharashtra</option>
        <option value="Manipur">Manipur</option>
        <option value="Meghalaya">Meghalaya</option>
        <option value="Mizoram">Mizoram</option>
        <option value="Nagaland">Nagaland</option>
        <option value="Odisha">Odisha</option>
        <option value="Punjab">Punjab</option>
        <option value="Rajasthan">Rajasthan</option>
        <option value="Sikkim">Sikkim</option>
        <option value="Tamil Nadu">Tamil Nadu</option>
        <option value="Telangana">Telangana</option>
        <option value="Tripura">Tripura</option>
        <option value="Uttar Pradesh">Uttar Pradesh</option>
        <option value="Uttarakhand">Uttarakhand</option>
        <option value="West Bengal">West Bengal</option>

        <!-- Union Territories -->
        <option value="Andaman and Nicobar Islands (UT)">Andaman and Nicobar Islands (UT)</option>
        <option value="Chandigarh (UT)">Chandigarh (UT)</option>
        <option value="Dadra and Nagar Haveli and Daman and Diu (UT)">Dadra and Nagar Haveli and Daman and Diu (UT)</option>
        <option value="Delhi - National Capital Territory (UT)">Delhi - National Capital Territory (UT)</option>
        <option value="Jammu and Kashmir (UT)">Jammu and Kashmir (UT)</option>
        <option value="Ladakh (UT)">Ladakh (UT)</option>
        <option value="Lakshadweep (UT)">Lakshadweep (UT)</option>
        <option value="Puducherry (UT)">Puducherry (UT)</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="cc">CC <span class="required">*</span></label>
                                <input type="text" id="cc" name="cc" class="form-control" value="<?php echo $editMode ? htmlspecialchars($editData['cc']) : ''; ?>" required>
                            </div>
                            <div class="form-group">
    <label for="register_year">Registration Date <span class="required">*</span></label>
    <!-- Type change kar diya 'date' kiya taaki calendar khule -->
    <input type="date" id="register_year" name="register_year" class="form-control" value="<?php echo $editMode ? htmlspecialchars($editData['register_year']) : ''; ?>" required>
    <div id="vehicle_age_display" class="age-display"></div>
    <input type="hidden" id="vehicle_age" name="vehicle_age" value="<?php echo $editMode ? htmlspecialchars($editData['vehicle_age']) : ''; ?>">
</div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="tp_status">TP Status <span class="required">*</span></label>
                                <!-- Updated to Dropdown -->
                                <select id="tp_status" name="tp_status" class="form-control" required>
                                    <option value="">Select TP Status</option>
                                    <option value="Yes" <?php echo ($editMode && $editData['tp_status'] === 'Yes') ? 'selected' : ''; ?>>Yes</option>
                                    <option value="No" <?php echo ($editMode && $editData['tp_status'] === 'No') ? 'selected' : ''; ?>>No</option>
                                    <option value="Not Available" <?php echo ($editMode && $editData['tp_status'] === 'Not Available') ? 'selected' : ''; ?>>Not Available</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="tp_premium">TP Premium</label>
                                <input type="number" id="tp_premium" name="tp_premium" class="form-control" step="0.01" value="<?php echo $editMode ? htmlspecialchars($editData['tp_premium']) : ''; ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="odsy">ODSY</label>
                                <input type="text" id="odsy" name="odsy" class="form-control" value="<?php echo $editMode ? htmlspecialchars($editData['odsy']) : ''; ?>">
                            </div>
                            <div class="form-group">
                                <label for="odmy">ODMY</label>
                                <input type="text" id="odmy" name="odmy" class="form-control" value="<?php echo $editMode ? htmlspecialchars($editData['odmy']) : ''; ?>">
                            </div>
                        </div>
                        
                       <div class="form-row">
    <div class="form-group">
        <label for="category">Category <span class="required">*</span></label>
        <select id="category" name="category" class="form-control" required>
            <option value="">Select Category</option>
            
             <option value="L1-A">L1-A</option>
        <option value="L1-B">L1-B</option>
        <option value="L1-C">L1-C</option>

        <option value="L2-A">L2-A</option>
        <option value="L2-B">L2-B</option>
        <option value="L2-C">L2-C</option>

        <option value="L3-A">L3-A</option>
        <option value="L3-B">L3-B</option>
        <option value="L3-C">L3-C</option>

        <option value="L4-A">L4-A</option>
        <option value="L4-B">L4-B</option>
        <option value="L4-C">L4-C</option>
        </select>
    </div>
                            
                            
                            <div class="form-group">
                                <label for="fuel_type">Fuel Type <span class="required">*</span></label>
                                <!-- Updated to Dropdown -->
                                <select id="fuel_type" name="fuel_type" class="form-control" required>
                                    <option value="">Select Fuel Type</option>
                                    <option value="Petrol">Petrol</option>
                                    <option value="Petrol + CNG">Petrol + CNG</option>
                                    <option value="Petrol + LPG">Petrol + LPG</option>
                                    <option value="Diesel">Diesel</option>
                                    <option value="Electric">Electric</option>
                                    <option value="Hybrid">Hybrid</option>
                                    <option value="CNG">CNG</option>
                                    <option value="LPG">LPG</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- New fields: Make and Model -->
                        <div class="form-row">
                            <div class="form-group">
                                <label for="make">Make <span class="required">*</span></label>
                                <input type="text" id="make" name="make" class="form-control" value="<?php echo $editMode ? htmlspecialchars($editData['make']) : ''; ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="model">Model <span class="required">*</span></label>
                                <input type="text" id="model" name="model" class="form-control" value="<?php echo $editMode ? htmlspecialchars($editData['model']) : ''; ?>" required>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="insurance_company">Insurance Company <span class="required">*</span></label>
                                <!-- Updated to Dropdown -->
                                <select id="insurance_company" name="insurance_company" class="form-control" required>
                                    <option value="">Select Company</option>
                                    <option value="Acko General Insurance Limited">Acko General Insurance Limited</option>
        <option value="Agriculture Insurance Company of India Limited">Agriculture Insurance Company of India Limited</option>
        <option value="Bajaj General Insurance Limited">Bajaj General Insurance Limited</option>
        <option value="Bharti Axa General Insurance Co. Ltd.">Bharti Axa General Insurance Co. Ltd.</option>
        <option value="Cholamandalam MS General Insurance Company Limited">Cholamandalam MS General Insurance Company Limited</option>
        <option value="ECGC Limited">ECGC Limited</option>
        <option value="Generali Central Insurance Company Limited">Generali Central Insurance Company Limited</option>
        <option value="Go Digit General Insurance Limited">Go Digit General Insurance Limited</option>
        <option value="HDFC ERGO General Insurance Company Limited">HDFC ERGO General Insurance Company Limited</option>
        <option value="ICICI LOMBARD General Insurance Company Limited">ICICI LOMBARD General Insurance Company Limited</option>
        <option value="IFFCO TOKIO General Insurance Company Limited">IFFCO TOKIO General Insurance Company Limited</option>
        <option value="Zurich Kotak General Insurance Company">Zurich Kotak General Insurance Company</option>
        <option value="Kshema General Insurance Limited">Kshema General Insurance Limited</option>
        <option value="Liberty General Insurance Limited">Liberty General Insurance Limited</option>
        <option value="Magma General Insurance Limited">Magma General Insurance Limited</option>
        <option value="National Insurance Company Limited">National Insurance Company Limited</option>
        <option value="Navi General Insurance Limited">Navi General Insurance Limited</option>
        <option value="Raheja QBE General Insurance Co. Ltd.">Raheja QBE General Insurance Co. Ltd.</option>
        <option value="Reliance General Insurance Company Limited">Reliance General Insurance Company Limited</option>
        <option value="Royal Sundaram General Insurance Company Limited">Royal Sundaram General Insurance Company Limited</option>
        <option value="SBI General Insurance Company Limited">SBI General Insurance Company Limited</option>
        <option value="Shriram General Insurance Company Limited">Shriram General Insurance Company Limited</option>
        <option value="Tata AIG General Insurance Company Limited">Tata AIG General Insurance Company Limited</option>
        <option value="The New India Assurance Company Limited">The New India Assurance Company Limited</option>
        <option value="The Oriental Insurance Company Limited">The Oriental Insurance Company Limited</option>
        <option value="United India Insurance Company Limited">United India Insurance Company Limited</option>
        <option value="Universal Sompo General Insurance Company Limited">Universal Sompo General Insurance Company Limited</option>
        <option value="Zuno General Insurance Ltd.">Zuno General Insurance Ltd.</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="deal_type">Deal Type <span class="required">*</span></label>
                                <!-- Updated to Dropdown -->
                                <select id="deal_type" name="deal_type" class="form-control" required>
                                    <option value="">Select Deal Type</option>
                                  <option value="Fleet">Fleet</option>
            <option value="Non-Fleet">Non-Fleet</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="payment_screenshot">Payment Screenshot Attached <span class="required">*</span></label>
                                <div class="file-upload">
                                    <input type="file" id="payment_screenshot" name="payment_screenshot" accept="image/*">
                                    <label for="payment_screenshot">
                                        <div class="file-upload-label">
                                            <i class="fas fa-cloud-upload-alt"></i>
                                            <span>Choose file</span>
                                            <span class="file-name" id="file-name">No file selected</span>
                                        </div>
                                    </label>
                                </div>
                                <div class="file-preview" id="file-preview">
                                    <img id="preview-image" src="" alt="Payment screenshot preview">
                                </div>
                                <small>Accepted formats: JPG, PNG, GIF. Max size: 5MB</small>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="remarks">Remarks</label>
                            <textarea id="remarks" name="remarks" class="form-control" rows="3"><?php echo $editMode ? htmlspecialchars($editData['remarks']) : ''; ?></textarea>
                        </div>
                        
                        <?php if ($editMode): ?>
                            <div class="form-group">
                                <label for="justification">Justification for Changes <span class="required">*</span></label>
                                <textarea id="justification" name="justification" class="form-control" rows="4" required placeholder="Please explain why you are making these changes to the rejected request"></textarea>
                            </div>
                        <?php endif; ?>
                        
                        <div class="form-actions">
                            <a href="index.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> <?php echo $editMode ? 'Resubmit Request' : 'Submit Request'; ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
    
    <script>
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
        
        // File upload preview
        document.getElementById('payment_screenshot').addEventListener('change', function() {
            const fileName = this.files[0] ? this.files[0].name : 'No file selected';
            document.getElementById('file-name').textContent = fileName;
            
            // Show image preview
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    document.getElementById('preview-image').setAttribute('src', e.target.result);
                    document.getElementById('file-preview').style.display = 'block';
                }
                
                reader.readAsDataURL(this.files[0]);
            } else {
                document.getElementById('file-preview').style.display = 'none';
            }
        });
        
        // Calculate Premium (w/o GST) when Premium changes
        document.getElementById('premium').addEventListener('input', function() {
            const premium = parseFloat(this.value) || 0;
            const gstRate = 0.18; // 18% GST
            const premiumWoGst = premium / (1 + gstRate);
            document.getElementById('premium_wo_gst').value = premiumWoGst.toFixed(2);
        });
        
                // --- Vehicle Age Calculation (Years + Months + Days) ---
        
        function calculateExactAge(regDateStr) {
            const regDate = new Date(regDateStr);
            const today = new Date();

            let years = today.getFullYear() - regDate.getFullYear();
            let months = today.getMonth() - regDate.getMonth();
            let days = today.getDate() - regDate.getDate();

            // Agar days negative hai, toh pichle month se din lekar aao
            if (days < 0) {
                const prevMonth = new Date(today.getFullYear(), today.getMonth(), 0);
                days += prevMonth.getDate();
                months--;
            }

            // Agar months negative hai, toh year se lekar aao
            if (months < 0) {
                months += 12;
                years--;
            }

            return { years, months, days };
        }

        function updateAgeDisplay(regDateStr) {
            if (regDateStr) {
                const age = calculateExactAge(regDateStr);
                
                // Screen par display karne ke liye text
                const ageText = `${age.years} Years, ${age.months} Months, ${age.days} Days`;
                document.getElementById('vehicle_age_display').textContent = `Vehicle Age: ${ageText}`;
                
                // Database ke liye approx value (agar zaroorat ho)
                const totalAge = age.years + (age.months / 12);
                document.getElementById('vehicle_age').value = totalAge.toFixed(2);
            } else {
                document.getElementById('vehicle_age_display').textContent = '';
                document.getElementById('vehicle_age').value = '';
            }
        }

        // Event Listener: Jab user date select kare
        document.getElementById('register_year').addEventListener('change', function() {
            updateAgeDisplay(this.value);
        });

        // Initialization: Agar page load ho raha hai aur edit mode hai
        <?php if ($editMode && !empty($editData['register_year'])): ?>
            document.addEventListener('DOMContentLoaded', function() {
                const regDateStr = "<?php echo $editData['register_year']; ?>";
                updateAgeDisplay(regDateStr);
            });
        <?php endif; ?>
    </script>
</body>
</html>