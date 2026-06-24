<?php
require_once 'config.php';

// Check if user is logged in
if (!is_logged_in()) {
    redirect('login.php');
}

// Check if user has the right role (User)
if (!has_role('User')) {
    show_notification('You do not have permission to access this page', 'error');
    redirect('dashboard_' . strtolower($_SESSION['role']) . '.php');
}

// ===== NEW: Fetch bypass_manager status for current user =====
 $bypass_manager = 0;
 $bypass_sql = "SELECT bypass_manager FROM users WHERE id = ?";
 $bypass_stmt = mysqli_prepare($conn, $bypass_sql);
mysqli_stmt_bind_param($bypass_stmt, "i", $_SESSION['user_id']);
mysqli_stmt_execute($bypass_stmt);
 $bypass_result = mysqli_stmt_get_result($bypass_stmt);
if ($bypass_result && mysqli_num_rows($bypass_result) > 0) {
    $bypass_data = mysqli_fetch_assoc($bypass_result);
    $bypass_manager = intval($bypass_data['bypass_manager']);
}
// =============================================================

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form type first
    $form_type = sanitize_input($_POST['form_type']);
    
    // Generate reference number based on form type
    if ($form_type === 'CB') {
        $reference_number = 'CB-' . date('Ymd') . '-' . rand(1000, 9999);
    } else if ($form_type === 'Shortfall') {
        $reference_number = 'SF-' . date('Ymd') . '-' . rand(1000, 9999);
    }
    
    // Get form data
    $rm_emp_id = sanitize_input($_POST['rmEmpId']);
    $rm_name = sanitize_input($_POST['rm']);
    $department = sanitize_input($_POST['department']);
    $customer_name = sanitize_input($_POST['name']);
    $mobile_number = sanitize_input($_POST['mobileNumber']); 
    $month = sanitize_input($_POST['month']);
    $year = sanitize_input($_POST['year']);
    $insurance_company = sanitize_input($_POST['insuranceCompany']);
    $policy_type = sanitize_input($_POST['policyType']);
    
    // --- HEALTH LOGIC ---
    $premium_with_gst = sanitize_input($_POST['premiumWithGST']);
    $without_gst = sanitize_input($_POST['withoutGST']);

    if ($department === 'Health_Fresh' || $department === 'Health_Renewal') {
        $premium_with_gst = $without_gst; 
        $without_gst = 0;
    }
    // --- CHANGE END ---

    $referral_amount = sanitize_input($_POST['referralAmount']);
    $approved_by = sanitize_input($_POST['approvedBy']);
    
    // --- NEW: Payment Type (Optional) ---
    $user_payment_type = isset($_POST['paymentType']) ? sanitize_input($_POST['paymentType']) : '';
    // -------------------------------------

    $reason = sanitize_input($_POST['reason']);
    
    // Define upload directory once at the beginning
    $upload_dir = 'uploads/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Handle file upload for CB type
    $attachment_url = '';
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $file_name = time() . '_' . basename($_FILES['attachment']['name']);
        $target_file = $upload_dir . $file_name;
        
        if (move_uploaded_file($_FILES['attachment']['tmp_name'], $target_file)) {
            $attachment_url = $target_file;
        }
    }
    
    // Handle additional files for Shortfall type
    $policy_copy_url = '';
    if (isset($_FILES['policyCopy']) && $_FILES['policyCopy']['error'] === UPLOAD_ERR_OK) {
        $file_name = time() . '_policy_' . basename($_FILES['policyCopy']['name']);
        $target_file = $upload_dir . $file_name;
        
        if (move_uploaded_file($_FILES['policyCopy']['tmp_name'], $target_file)) {
            $policy_copy_url = $target_file;
        }
    }

    // --- NEW: Handle User Payment Screenshot upload (Optional) ---
    $user_payment_screenshot = '';
    if (isset($_FILES['paymentScreenshot']) && $_FILES['paymentScreenshot']['error'] === UPLOAD_ERR_OK) {
        $file_name = time() . '_userpay_' . basename($_FILES['paymentScreenshot']['name']);
        $target_file = $upload_dir . $file_name;
        
        if (move_uploaded_file($_FILES['paymentScreenshot']['tmp_name'], $target_file)) {
            $user_payment_screenshot = $target_file;
        }
    }
    // ---------------------------------------------------------
    
    $payment_link = sanitize_input($_POST['paymentLink']);
    
    // ===== NEW: Determine initial status based on bypass_manager =====
    $initial_status = 'Pending';
    if ($bypass_manager == 1) {
        $initial_status = 'Manager Approved'; // Skips Manager, goes to Head directly
    }
    // ================================================================
    
    // Insert cashback request into database
    $sql = "INSERT INTO cashback_requests (form_type, reference_number, user_id, rm_emp_id, rm_name, department, customer_name, mobile_number, month, year, insurance_company, policy_type, premium_with_gst, without_gst, referral_amount, attachment_url, policy_copy_url, payment_link, user_payment_type, user_payment_screenshot, reason, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ssisssssssssssssssssss", 
        $form_type,
        $reference_number, 
        $_SESSION['user_id'], 
        $rm_emp_id, 
        $rm_name, 
        $department, 
        $customer_name, 
        $mobile_number, 
        $month, 
        $year, 
        $insurance_company, 
        $policy_type, 
        $premium_with_gst, 
        $without_gst, 
        $referral_amount, 
        $attachment_url,
        $policy_copy_url,
        $payment_link,
        $user_payment_type,
        $user_payment_screenshot,
        $reason,
        $initial_status
    );
    
    if (mysqli_stmt_execute($stmt)) {
        $message = ($form_type === 'CB') ? 
            "Cashback request submitted successfully. Reference number: $reference_number" : 
            "Shortfall request submitted successfully. Reference number: $reference_number";
        
        if ($bypass_manager == 1) {
            $message .= " (Manager Bypassed - Sent directly to Head)";
        }
        
        show_notification($message, 'success');
        redirect('index.php');
    } else {
        show_notification("Error submitting request: " . mysqli_error($conn), 'error');
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Organization CB Account Entry Form</title>
    <link rel="icon" href="https://www.coveryou.in/images/favicon.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            --bypass-color: #e53e3e;
        }
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            color: var(--text);
            line-height: 1.5;
            min-height: 100vh;
            font-size: 14px;
        }
        .app-container { display: flex; min-height: 100vh; }
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
        .sidebar-header { padding: 20px; text-align: center; border-bottom: 1px solid rgba(255, 255, 255, 0.1); }
        .sidebar-logo { display: flex; align-items: center; justify-content: center; gap: 8px; margin-bottom: 5px; }
        .sidebar-logo-icon { font-size: 24px; color: var(--primary); }
        .sidebar-logo-text { font-size: 20px; font-weight: 700; }
        .sidebar-user { padding: 15px; text-align: center; border-bottom: 1px solid rgba(255, 255, 255, 0.1); font-size: 13px; }
        .sidebar-user-name { font-weight: 600; margin-bottom: 3px; }
        .sidebar-user-role { color: var(--text-light); font-size: 12px; }
        .sidebar-menu { padding: 15px 0; }
        .sidebar-menu-item {
            display: block; padding: 12px 20px; color: var(--light); text-decoration: none;
            transition: background-color 0.2s ease; display: flex; align-items: center; gap: 10px;
        }
        .sidebar-menu-item:hover { background-color: rgba(255, 255, 255, 0.1); }
        .sidebar-menu-item.active { background-color: var(--primary); }
        .sidebar-menu-item i { width: 20px; text-align: center; }
        .sidebar-footer {
            padding: 15px; text-align: center; border-top: 1px solid rgba(255, 255, 255, 0.1);
            font-size: 12px; color: var(--text-light); position: absolute; bottom: 0; width: 100%;
        }
        .main-content { flex: 1; margin-left: var(--sidebar-width); padding: 15px; width: calc(100% - var(--sidebar-width)); }
        .container { max-width: 850px; margin: 0 auto; }
        header {
            text-align: center; margin-bottom: 20px; padding: 15px;
            background: var(--light); border-radius: var(--radius); box-shadow: var(--shadow); position: relative;
        }
        .logo { display: flex; align-items: center; justify-content: center; gap: 8px; margin-bottom: 10px; }
        .logo-icon { font-size: 24px; color: var(--primary); }
        .logo-text { font-size: 22px; font-weight: 700; color: var(--dark); }
        .logo-text span { color: var(--primary); }
        .tagline { color: var(--text-light); font-size: 14px; margin-bottom: 5px; }
        .user-info { position: absolute; top: 15px; right: 15px; display: flex; align-items: center; gap: 15px; }
        .user-details { display: flex; flex-direction: column; align-items: flex-end; }
        .username { font-weight: 600; color: var(--dark); }
        .user-role { font-size: 12px; color: var(--text-light); }
        .logout-btn {
            padding: 8px 15px; background-color: #e53e3e; color: white; border: none;
            border-radius: var(--radius); cursor: pointer; display: flex; align-items: center;
            gap: 5px; font-size: 13px; transition: background-color 0.3s ease;
        }
        .logout-btn:hover { background-color: #c53030; }
        .form-container {
            background-color: var(--light); border-radius: var(--radius);
            padding: 15px; box-shadow: var(--shadow); margin-bottom: 20px;
        }
        .section-title { font-size: 16px; color: var(--primary); margin-bottom: 15px; padding-bottom: 8px; border-bottom: 1px solid var(--gray); }
        .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
        .form-group { margin-bottom: 8px; }
        .form-group label { display: block; margin-bottom: 4px; color: var(--dark); font-weight: 500; font-size: 13px; }
        .form-control {
            width: 100%; padding: 8px 10px; border: 1px solid var(--gray);
            border-radius: var(--radius); font-size: 14px; transition: all 0.2s ease;
        }
        .form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px rgba(240, 93, 73, 0.2); }
        .form-control[readonly] { background-color: #f8f9fa; cursor: not-allowed; }
        textarea.form-control { min-height: 70px; resize: vertical; }
        .form-footer { margin-top: 15px; display: flex; justify-content: center; gap: 10px; }
        .btn {
            padding: 8px 16px; border-radius: var(--radius); font-weight: 500; cursor: pointer;
            transition: all 0.2s ease; border: none; display: inline-flex; align-items: center; gap: 5px; font-size: 14px;
        }
        .btn-primary { background-color: var(--primary); color: var(--light); }
        .btn-primary:hover { background-color: var(--primary-dark); transform: translateY(-1px); box-shadow: 0 2px 6px rgba(240, 93, 73, 0.3); }
        .btn-outline { background-color: transparent; border: 1px solid var(--primary); color: var(--primary); }
        .btn-outline:hover { background-color: var(--primary); color: var(--light); }
        .alert { padding: 12px; border-radius: var(--radius); margin-bottom: 20px; font-size: 14px; }
        .alert-success { background-color: #f6ffed; border-left: 4px solid #389e0d; color: #389e0d; }
        .alert-error { background-color: #fff2f0; border-left: 4px solid #cf1322; color: #cf1322; }
        .file-upload { position: relative; display: inline-block; width: 100%; }
        .file-upload input[type="file"] { position: absolute; opacity: 0; width: 100%; height: 100%; cursor: pointer; }
        .file-upload-label {
            display: flex; align-items: center; justify-content: center; gap: 8px; padding: 8px 10px;
            border: 1px dashed var(--gray); border-radius: var(--radius); background-color: var(--light);
            cursor: pointer; transition: all 0.2s ease;
        }
        .file-upload-label:hover { border-color: var(--primary); background-color: rgba(240, 93, 73, 0.05); }
        .file-name { margin-top: 5px; font-size: 12px; color: var(--text-light); font-style: italic; }
        .dashboard-link {
            position: fixed; bottom: 20px; right: 20px; background-color: var(--primary); color: white;
            border-radius: 50%; width: 60px; height: 60px; display: flex; align-items: center;
            justify-content: center; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2); cursor: pointer;
            z-index: 100; transition: all 0.3s ease;
        }
        .dashboard-link:hover { background-color: var(--primary-dark); transform: scale(1.1); }
        .dashboard-link i { font-size: 24px; }
        .menu-toggle {
            display: none; position: fixed; top: 15px; left: 15px; z-index: 1001;
            background-color: var(--dark); color: var(--light); border: none;
            border-radius: var(--radius); padding: 8px 12px; cursor: pointer;
        }
        .request-type-selector {
            display: flex; margin-bottom: 15px; background-color: #f8f9fa;
            border-radius: var(--radius); padding: 4px; box-shadow: inset 0 1px 3px rgba(0,0,0,0.1);
        }
        .request-type-btn {
            flex: 1; padding: 10px; text-align: center; cursor: pointer;
            border-radius: calc(var(--radius) - 2px); font-weight: 500; color: var(--text);
            transition: all 0.2s ease; border: none; background: transparent;
        }
        .request-type-btn.active { background-color: var(--light); color: var(--primary); box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .request-type-btn input[type="radio"] { display: none; }
        .hidden { display: none; }
        .referral-ratio { margin-top: 5px; font-size: 12px; color: var(--primary); font-weight: 600; }
        .required-star { color: #ff8c00; margin-left: 3px; }
        .validation-error { color: #e53e3e; font-size: 12px; margin-top: 3px; display: none; }

        .bypass-banner {
            background: linear-gradient(135deg, #fff5f5 0%, #fed7d7 100%);
            border: 1px solid #feb2b2;
            border-radius: var(--radius);
            padding: 12px 16px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: bypassPulse 2s ease-in-out infinite;
        }
        .bypass-banner-icon {
            width: 40px; height: 40px;
            background: var(--bypass-color);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .bypass-banner-text h4 {
            margin: 0 0 2px 0;
            font-size: 14px;
            color: var(--bypass-color);
        }
        .bypass-banner-text p {
            margin: 0;
            font-size: 12px;
            color: #9b2c2c;
        }
        @keyframes bypassPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(229, 62, 62, 0.2); }
            50% { box-shadow: 0 0 0 6px rgba(229, 62, 62, 0); }
        }

        .loader-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background-color: rgba(0, 0, 0, 0.5); display: flex; justify-content: center;
            align-items: center; z-index: 9999; opacity: 0; visibility: hidden; transition: opacity 0.3s, visibility 0.3s;
        }
        .loader-overlay.active { opacity: 1; visibility: visible; }
        .loader-container { background-color: var(--light); padding: 30px; border-radius: var(--radius); text-align: center; box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3); }
        .loader-spinner { border: 4px solid var(--gray); border-top: 4px solid var(--primary); border-radius: 50%; width: 50px; height: 50px; animation: spin 1s linear infinite; margin: 0 auto 15px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .loader-text { color: var(--dark); font-size: 16px; font-weight: 500; }
        
        .label-with-edit {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .edit-pencil {
            cursor: pointer;
            color: #ff7d6a; 
            font-size: 13px;
            transition: color 0.2s;
        }
        .edit-pencil:hover {
            color: var(--primary-dark);
        }

        /* ===== MANUAL GST % POPUP ===== */
        .manual-gst-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 10000;
            justify-content: center;
            align-items: center;
        }
        .manual-gst-box {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            text-align: center;
            width: 300px;
        }
        .manual-gst-box h4 {
            margin-bottom: 15px;
            color: var(--dark);
        }
        .manual-gst-input-group {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 15px;
        }
        .manual-gst-input-group input {
            width: 80px;
            padding: 8px;
            text-align: center;
            font-size: 16px;
            border: 1px solid var(--gray);
            border-radius: var(--radius);
        }
        .manual-gst-input-group span {
            font-size: 18px;
            font-weight: bold;
            color: var(--dark);
        }
        .manual-gst-actions {
            display: flex;
            justify-content: center;
            gap: 10px;
        }
        /* ================================== */
        
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.active { transform: translateX(0); }
            .main-content { margin-left: 0; width: 100%; padding: 60px 15px 15px; }
            .menu-toggle { display: block; }
            .form-grid { grid-template-columns: 1fr; }
            .form-container { padding: 15px; }
            .btn { width: 100%; justify-content: center; }
            .form-footer { flex-direction: column; }
            .bypass-banner { flex-direction: column; text-align: center; }
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
                <a href="dashboard_user.php" class="sidebar-menu-item">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="index.php" class="sidebar-menu-item active">
                    <i class="fas fa-plus-circle"></i> New Request
                </a>
                <a href="logout.php" class="sidebar-menu-item">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a> 
            </nav>
            
            <div class="sidebar-footer">
                &copy; <?php echo date('Y'); ?> Cashback System
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
                        <i class="fas fa-building logo-icon"></i>
                        <div class="logo-text">CB <span>Account</span></div>
                    </div>
                    <p class="tagline">Complete CB management system</p>
                    
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
                
                <div class="form-container">
                    <h2 class="section-title">Organization CB Account Entry Form</h2>
                    
                    <?php if (isset($_SESSION['notification'])): ?>
                        <div class="alert alert-<?php echo $_SESSION['notification']['type']; ?>">
                            <?php echo $_SESSION['notification']['message']; ?>
                        </div>
                        <?php unset($_SESSION['notification']); ?>
                    <?php endif; ?>
                    
                    <?php if ($bypass_manager == 1): ?>
                    <div class="bypass-banner">
                        <div class="bypass-banner-icon">
                            <i class="fas fa-forward"></i>
                        </div>
                        <div class="bypass-banner-text">
                            <h4><i class="fas fa-exclamation-triangle"></i> Manager Bypass Active</h4>
                            <p>Your requests will skip the Manager and go <strong>directly to Head</strong> for approval. This setting is controlled by Admin.</p>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <form id="cashbackForm" action="index.php" method="post" enctype="multipart/form-data">
                        <div class="request-type-selector">
                            <label class="request-type-btn active" for="formTypeCB">
                                <input type="radio" id="formTypeCB" name="form_type" value="CB" checked>
                                CB 
                            </label>
                            <label class="request-type-btn" for="formTypeShortfall">
                                <input type="radio" id="formTypeShortfall" name="form_type" value="Shortfall">
                                Shortfall
                            </label>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="rmEmpId"><i class="fas fa-id-badge"></i> RM EMP ID</label>
                                <input type="text" id="rmEmpId" name="rmEmpId" class="form-control" placeholder="Enter RM Employee ID" value="<?php echo isset($_SESSION['emp_id']) ? htmlspecialchars($_SESSION['emp_id']) : ''; ?>" readonly>
                            </div>
                            
                            <div class="form-group">
                                <label for="rm"><i class="fas fa-user"></i> RM Name</label>
                                <input type="text" id="rm" name="rm" class="form-control" placeholder="Enter RM name" value="<?php echo isset($_SESSION['full_name']) ? htmlspecialchars($_SESSION['full_name']) : ''; ?>" readonly>
                            </div>
                            
                            <div class="form-group">
                                <label for="department"><i class="fas fa-sitemap"></i> Department</label>
                                <input type="text" id="department" name="department" class="form-control" placeholder="Department" value="<?php echo isset($_SESSION['department']) ? htmlspecialchars($_SESSION['department']) : ''; ?>" readonly>
                            </div>
                            
                            <div class="form-group">
                                <label for="name"><i class="fas fa-user-tag"></i> Customer Name<span class="required-star">*</span></label>
                                <input type="text" id="name" name="name" class="form-control" placeholder="Enter customer name" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="mobileNumber"><i class="fas fa-phone"></i> Mobile Number<span class="required-star">*</span></label>
                                <input type="text" id="mobileNumber" name="mobileNumber" class="form-control" placeholder="Enter customer's mobile number" pattern="[0-9]{10}" maxlength="10" required>
                                <div id="mobileNumberError" class="validation-error">Please enter a valid 10-digit mobile number</div>
                            </div> 

                            <div class="form-group">
                                <label for="month"><i class="fas fa-calendar"></i> Month<span class="required-star">*</span></label>
                                <select id="month" name="month" class="form-control" required>
                                    <option value="">Select Month</option>
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
                                <label for="year"><i class="fas fa-calendar-alt"></i> Year<span class="required-star">*</span></label>
                                <select id="year" name="year" class="form-control" required>
                                    <option value="">Select Year</option>
                                    <option value="2024">2024</option>
                                    <option value="2025">2025</option>
                                    <option value="2026">2026</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="insuranceCompany"><i class="fas fa-building"></i> Insurance Company<span class="required-star">*</span></label>
                                <select id="insuranceCompany" name="insuranceCompany" class="form-control" required>
                                    <option value="">Select Insurance Company</option>
                                    <option value="Acko General Insurance Limited">Acko General Insurance Limited</option>
                                    <option value="Agriculture Insurance Company of India Limited">Agriculture Insurance Company of India Limited</option>
                                    <option value="Bajaj General Insurance Limited">Bajaj General Insurance Limited</option>
                                    <option value="Cholamandalam MS General Insurance Company Limited">Cholamandalam MS General Insurance Company Limited</option>
                                    <option value="Care Health Insurance">Care Health Insurance</option>
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
                                    <option value="Niva Bupa Health Insurance Company Limited">Niva Bupa Health Insurance Company Limited</option>
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
                                    <option value="Bharti Axa General Insurance Co. Ltd.">Bharti Axa General Insurance Co. Ltd.</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="policyType"><i class="fas fa-file-contract"></i> Policy Type<span class="required-star">*</span></label>
                                <select id="policyType" name="policyType" class="form-control" required>
                                    <option value="">Select Policy Type</option>
                                    <?php
                                    $userDepartment = isset($_SESSION['department']) ? $_SESSION['department'] : '';
                                    
                                    if ($userDepartment === 'Health_Fresh') {
                                        echo '<option value="Portability Policy">Portability Policy</option>';
                                        echo '<option value="Fresh Policy">Fresh Policy</option>';
                                        echo '<option value="Own Renewal Policy">Own Renewal Policy</option>';
                                        echo '<option value="Top Up Policy">Top Up Policy</option>';
                                        echo '<option value="Own Renewal Upsell Policy">Own Renewal Upsell Policy</option>';
                                    } else if ($userDepartment === 'Motor_Renewal' || $userDepartment === 'Motor_Fresh') {
                                        echo '<option value="Comprehensive Package">Comprehensive Package</option>';
                                        echo '<option value="Add Multiyear">Add Multiyear</option>';
                                        echo '<option value="Third Party">Third Party</option>';
                                    } else {
                                        echo '<option value="Comprehensive Package">Comprehensive Package</option>';
                                        echo '<option value="Add Multiyear">Add Multiyear</option>';
                                        echo '<option value="Third Party">Third Party</option>';
                                        echo '<option value="Portability Policy">Portability Policy</option>';
                                        echo '<option value="Fresh Policy">Fresh Policy</option>';
                                        echo '<option value="Own Renewal Policy">Own Renewal Policy</option>';
                                        echo '<option value="Top Up Policy">Top Up Policy</option>';
                                        echo '<option value="Own Renewal Upsell Policy">Own Renewal Upsell Policy</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                            
                            <!-- PREMIUM SECTION -->
                            <div class="form-group" id="premiumWithGstGroup">
                                <label for="premiumWithGST"><i class="fas fa-money-bill-wave"></i> Premium With GST<span class="required-star">*</span></label>
                                <input type="number" id="premiumWithGST" name="premiumWithGST" class="form-control" step="1" placeholder="0" required>
                            </div>
                            
                            <div class="form-group">
                                <div class="label-with-edit">
                                    <label for="withoutGST" id="withoutGstLabel"><i class="fas fa-calculator"></i> Net Premium (Auto @18%)</label>
                                    <i class="fas fa-pencil-alt edit-pencil" id="editNetPremiumBtn" title="Change GST % & Edit"></i>
                                </div>
                                <input type="number" id="withoutGST" name="withoutGST" class="form-control" step="1" placeholder="0" readonly required>
                            </div>
                            <!-- END PREMIUM SECTION -->
                            
                            <div class="form-group">
                                <label for="referralAmount"><i class="fas fa-hand-holding-usd"></i> Referral Amount<span class="required-star">*</span></label>
                                <input type="number" id="referralAmount" name="referralAmount" class="form-control" step="1" placeholder="0" required>
                                <div id="referralRatio" class="referral-ratio"></div>
                            </div>
                            
                            <div id="clientAccountDetails" class="form-group">
                                <label for="attachment"><i class="fas fa-paperclip"></i> Client Account Details<span id="attachmentStar" class="required-star">*</span></label>
                                <div class="file-upload">
                                    <input type="file" id="attachment" name="attachment" class="form-control" accept="image/*,.pdf" required>
                                    <label for="attachment" class="file-upload-label">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                        <span>Choose File</span>
                                    </label>
                                </div>
                                <div class="file-name" id="fileName">No file selected</div>
                            </div>
                            
                            <div id="policyCopyField" class="form-group hidden">
                                <label for="policyCopy"><i class="fas fa-file-pdf"></i> Upload Policy Copy<span id="policyCopyStar" class="required-star hidden">*</span></label>
                                <div class="file-upload">
                                    <input type="file" id="policyCopy" name="policyCopy" class="form-control" accept="image/*,.pdf">
                                    <label for="policyCopy" class="file-upload-label">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                        <span>Choose File</span>
                                    </label>
                                </div>
                                <div class="file-name" id="policyCopyName">No file selected</div>
                            </div>
                            
                            <div id="paymentLinkField" class="form-group hidden">
                                <label for="paymentLink"><i class="fas fa-link"></i> Payment Link<span id="paymentLinkStar" class="required-star hidden">*</span></label>
                                <input type="text" id="paymentLink" name="paymentLink" class="form-control" placeholder="Enter payment link">
                            </div>
                            
                            <!-- ===== NEW: PAYMENT TYPE & SCREENSHOT FIELDS ===== -->
                            <div class="form-group">
                                <label for="paymentType"><i class="fas fa-credit-card"></i> Payment Type</label>
                                <select id="paymentType" name="paymentType" class="form-control">
                                    <option value="">Select Payment Type</option>
                                    <option value="Customer Cheque">Customer Cheque</option>
                                    <option value="Online">Online</option>
                                    <option value="Razorpay">Razorpay</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="paymentScreenshot"><i class="fas fa-receipt"></i> Payment Screenshot/Receipt</label>
                                <div class="file-upload">
                                    <input type="file" id="paymentScreenshot" name="paymentScreenshot" class="form-control" accept="image/*,.pdf">
                                    <label for="paymentScreenshot" class="file-upload-label">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                        <span>Choose File</span>
                                    </label>
                                </div>
                                <div class="file-name" id="paymentScreenshotName">No file selected</div>
                            </div>
                            <!-- ================================================== -->
                            
                            <div class="form-group" style="grid-column: span 2;">
                                <label for="reason"><i class="fas fa-sticky-note"></i> Reason<span class="required-star">*</span></label>
                                <textarea id="reason" name="reason" class="form-control" placeholder="Enter reason for referral" required></textarea>
                            </div>
                        </div>
                        
                        <div class="form-footer">
                            <button type="reset" class="btn btn-outline"><i class="fas fa-redo"></i> Reset Form</button>
                            <button type="submit" class="btn btn-primary" id="submitBtn"><i class="fas fa-paper-plane"></i> Submit Data</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <a href="dashboard_user.php" class="dashboard-link" title="Go to Dashboard">
                <i class="fas fa-chart-bar"></i>
            </a>
        </main>
    </div>
    
    <!-- LOADER OVERLAY -->
    <div class="loader-overlay" id="loaderOverlay">
        <div class="loader-container">
            <div class="loader-spinner"></div>
            <div class="loader-text">Submitting your data, please wait...</div>
        </div>
    </div>

    <!-- ===== MANUAL GST % POPUP ===== -->
    <div class="manual-gst-overlay" id="manualGstOverlay">
        <div class="manual-gst-box">
            <h4><i class="fas fa-percentage"></i> Change GST Percentage</h4>
            <p style="font-size: 12px; color: var(--text-light); margin-bottom: 10px;">This will reverse-calculate Net Premium from Total and make it editable.</p>
            <div class="manual-gst-input-group">
                <input type="number" id="manualGstInput" step="1" min="0" max="100" value="18" placeholder="e.g. 5">
                <span>%</span>
            </div>
            <div class="manual-gst-actions">
                <button type="button" class="btn btn-outline" id="cancelManualGst">Cancel</button>
                <button type="button" class="btn btn-primary" id="applyManualGst">Apply & Edit</button>
            </div>
        </div>
    </div>
    <!-- ============================== -->

    <script>
        // Get Department from PHP
        const userDepartment = "<?php echo isset($_SESSION['department']) ? htmlspecialchars($_SESSION['department']) : ''; ?>";

        // DOM Elements
        const premiumWithGstGroup = document.getElementById('premiumWithGstGroup');
        const premiumWithGstInput = document.getElementById('premiumWithGST');
        const withoutGstLabel = document.getElementById('withoutGstLabel');
        const withoutGstInput = document.getElementById('withoutGST');
        const referralRatio = document.getElementById('referralRatio');
        const editNetPremiumBtn = document.getElementById('editNetPremiumBtn');
        
        // Popup DOM Elements
        const manualGstOverlay = document.getElementById('manualGstOverlay');
        const manualGstInput = document.getElementById('manualGstInput');
        const applyManualGst = document.getElementById('applyManualGst');
        const cancelManualGst = document.getElementById('cancelManualGst');

        let isNetPremiumManual = false;
        let manualGstPercent = 18; // Default

        // --- HEALTH LOGIC ---
        if (userDepartment === 'Health_Fresh' || userDepartment === 'Health_Renewal') {
            premiumWithGstGroup.classList.add('hidden');
            premiumWithGstInput.removeAttribute('required');
            withoutGstLabel.innerHTML = '<i class="fas fa-money-bill-wave"></i> Premium<span class="required-star">*</span>';
            withoutGstInput.removeAttribute('readonly');
            editNetPremiumBtn.style.display = 'none'; 
        } else {
            premiumWithGstGroup.classList.remove('hidden');
            premiumWithGstInput.setAttribute('required', 'required');
            withoutGstLabel.innerHTML = '<i class="fas fa-calculator"></i> Net Premium (Auto @18%)';
            withoutGstInput.setAttribute('readonly', 'true');
        }

        // Form type selection handler
        const formTypeButtons = document.querySelectorAll('.request-type-btn');
        const policyCopyField = document.getElementById('policyCopyField');
        const paymentLinkField = document.getElementById('paymentLinkField');
        const clientAccountDetails = document.getElementById('clientAccountDetails');
        const attachmentInput = document.getElementById('attachment');
        const attachmentStar = document.getElementById('attachmentStar');
        const policyCopyStar = document.getElementById('policyCopyStar');
        const paymentLinkStar = document.getElementById('paymentLinkStar');

        formTypeButtons.forEach(button => {
            button.addEventListener('click', function() {
                formTypeButtons.forEach(btn => btn.classList.remove('active'));
                this.classList.add('active');

                if (document.getElementById('formTypeShortfall').checked) {
                    clientAccountDetails.classList.add('hidden');
                    attachmentInput.removeAttribute('required');
                    policyCopyField.classList.remove('hidden');
                    paymentLinkField.classList.remove('hidden');
                    document.getElementById('policyCopy').setAttribute('required', 'required');
                    document.getElementById('paymentLink').setAttribute('required', 'required');
                    policyCopyStar.classList.remove('hidden');
                    paymentLinkStar.classList.remove('hidden');
                } else {
                    clientAccountDetails.classList.remove('hidden');
                    attachmentInput.setAttribute('required', 'required');
                    attachmentStar.classList.remove('hidden');
                    policyCopyField.classList.add('hidden');
                    paymentLinkField.classList.add('hidden');
                    document.getElementById('policyCopy').removeAttribute('required');
                    document.getElementById('paymentLink').removeAttribute('required');
                    policyCopyStar.classList.add('hidden');
                    paymentLinkStar.classList.add('hidden');
                }
            });
        });
        
        // File upload handling
        document.getElementById('attachment').addEventListener('change', function() {
            const file = this.files[0];
            document.getElementById('fileName').textContent = file ? file.name : 'No file selected';
        });
        document.getElementById('policyCopy').addEventListener('change', function() {
            const file = this.files[0];
            document.getElementById('policyCopyName').textContent = file ? file.name : 'No file selected';
        });
        
        // NEW: Payment Screenshot upload handling
        document.getElementById('paymentScreenshot').addEventListener('change', function() {
            const file = this.files[0];
            document.getElementById('paymentScreenshotName').textContent = file ? file.name : 'No file selected';
        });
        
        // ===== CORRECTED REVERSE GST CALCULATION LOGIC =====
        function calculateNetPremium() {
            const withGST = parseFloat(premiumWithGstInput.value);
            
            if (!isNaN(withGST) && withGST > 0) {
                const divisor = 1 + (manualGstPercent / 100);
                const netPremium = withGST / divisor;
                withoutGstInput.value = Math.round(netPremium); 
            } else {
                withoutGstInput.value = '';
            }
            calculateReferralRatio();
        }

        premiumWithGstInput.addEventListener('input', function() {
            if(!isNetPremiumManual) {
                calculateNetPremium();
            }
        });
        
        document.getElementById('referralAmount').addEventListener('input', calculateReferralRatio);
        withoutGstInput.addEventListener('input', calculateReferralRatio);
        
        function calculateReferralRatio() {
            const referralAmount = parseFloat(document.getElementById('referralAmount').value);
            const basePremium = parseFloat(withoutGstInput.value);
            if (!isNaN(referralAmount) && !isNaN(basePremium) && basePremium > 0) {
                const ratio = (referralAmount / basePremium * 100).toFixed(2);
                referralRatio.textContent = `Ratio: ${ratio}% of Premium`;
            } else {
                referralRatio.textContent = '';
            }
        }
        
        // ===== PENCIL, MANUAL GST & EDITABLE LOGIC =====
        editNetPremiumBtn.addEventListener('click', function() {
            manualGstOverlay.style.display = 'flex';
            manualGstInput.value = manualGstPercent; // set current % in popup
            manualGstInput.focus();
        });

        applyManualGst.addEventListener('click', function() {
            const enteredPercent = parseFloat(manualGstInput.value);
            if (!isNaN(enteredPercent) && enteredPercent >= 0) {
                manualGstPercent = enteredPercent;
                isNetPremiumManual = true;
                
                // 1. Make the field EDITABLE
                withoutGstInput.removeAttribute('readonly');
                withoutGstInput.style.backgroundColor = '#fff';
                withoutGstInput.style.borderColor = 'var(--primary)';
                
                // 2. Update Label to show custom %
                withoutGstLabel.innerHTML = `<i class="fas fa-edit"></i> Net Premium (Manual @${manualGstPercent}%)`;
                
                // 3. Recalculate automatically
                calculateNetPremium();
                
                // 4. Close popup
                manualGstOverlay.style.display = 'none';
            }
        });

        cancelManualGst.addEventListener('click', function() {
            manualGstOverlay.style.display = 'none';
        });
        
        // Loader
        document.getElementById('cashbackForm').addEventListener('submit', function() {
            document.getElementById('loaderOverlay').classList.add('active');
        });
        
        // Mobile menu toggle
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });
    </script>
</body>
</html>