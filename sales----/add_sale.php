<?php
// /var/www/html/cb_new_uat/sales/add_sale.php

require_once '../config.php';

// Check if user is logged in
if (!is_logged_in()) {
    redirect('../login.php');
}

// Check if user has the right role (User, Manager, Head can also add)
if (!has_role('User') && !has_role('Manager') && !has_role('Head')) {
    show_notification('You do not have permission to access this page', 'error');
    redirect('../dashboard_' . strtolower($_SESSION['role']) . '.php');
}

 $user = get_current_user_details(); // Aapke system mein ye function hona chahiye

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Generate unique reference number
    $reference_number = 'SA-' . date('dmy') . '-' . rand(1000, 9999);
    
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
        $upload_dir = '../uploads/sales_screenshots/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        $file_name = time() . '_' . basename($_FILES['payment_screenshot']['name']);
        $target_file = $upload_dir . $file_name;
        
        if (move_uploaded_file($_FILES['payment_screenshot']['tmp_name'], $target_file)) {
            $payment_screenshot_url = $target_file;
        } else {
            show_notification('Error uploading payment screenshot.', 'error');
            // Agar file upload fail ho jaye to form wapas dikha dena chahiye
        }
    }
    
    // Get user's manager and head IDs
    $manager_id = $user['manager_id'];
    $head_id = $user['head_id'];
    
    // Insert into sales_requests table
    $sql = "INSERT INTO sales_requests (
                reference_number, user_id, manager_id, head_id, submission_date, quotation_number, 
                ccs_lead_id, customer_name, mobile_number, vehicle_number, rm_name, leader_name, 
                premium, premium_without_gst, policy_type, vehicle_type, city, state, vehicle_cc, 
                registration_year, tp_status, tp_premium, od_single_year, od_multi_year, 
                category, fuel_type, insurance_company, deal_type, payment_screenshot_url, remarks
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = mysqli_prepare($conn, $sql);
    $stmt->bind_param(
        "siissssssssddssssssssddsssssss",
        $reference_number, $user['id'], $manager_id, $head_id, $data['submission_date'], $data['quotation_number'],
        $data['ccs_lead_id'], $data['customer_name'], $data['mobile_number'], $data['vehicle_number'], $data['rm_name'], $data['leader_name'],
        $data['premium'], $data['premium_without_gst'], $data['policy_type'], $data['vehicle_type'], $data['city'], $data['state'], $data['vehicle_cc'],
        $data['registration_year'], $data['tp_status'], $data['tp_premium'], $data['od_single_year'], $data['od_multi_year'],
        $data['category'], $data['fuel_type'], $data['insurance_company'], $data['deal_type'], $payment_screenshot_url, $data['remarks']
    );
    
    if (mysqli_stmt_execute($stmt)) {
        show_notification("Sale submitted successfully! Reference Number: $reference_number", 'success');
        redirect('index.php');
    } else {
        show_notification("Error submitting sale: " . mysqli_error($conn), 'error');
    }
    
    $stmt->close();
}

// Function to get current user details (agar pehle se defined nahi hai)
function get_current_user_details() {
    global $conn;
    if (is_logged_in()) {
        $user_id = $_SESSION['user_id'];
        $sql = "SELECT * FROM users WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    return null;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Sale - CoverYou</title>
    <!-- Aapke existing CSS ka path yahan use karein -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Aapke existing styles ko yahan copy-paste karein ya link karein */
        /* Main aapko specific styles nahi de raha, kyunki aapne pura code diya hai */
        /* Main sirf form-specific styles add kar raha hun */
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f8fafc; }
        .container { max-width: 900px; margin: 20px auto; padding: 20px; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-control { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        .btn { padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; }
        .btn-primary { background: #f05d49; color: white; }
        .btn-primary:hover { background: #d84c38; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-grid-full { grid-column: span 2; }
        @media (max-width: 768px) { .form-grid { grid-template-columns: 1fr; } .form-grid-full { grid-column: span 1; } }
    </style>
</head>
<body>
    <!-- Aapka existing header/sidebar code yahan aa sakta hai -->
    <div class="container">
        <h2>Add New Sale</h2>
        
        <?php if (isset($_SESSION['notification'])): ?>
            <div class="alert alert-<?php echo $_SESSION['notification']['type']; ?>">
                <?php echo $_SESSION['notification']['message']; ?>
            </div>
            <?php unset($_SESSION['notification']); ?>
        <?php endif; ?>
        
        <form action="add_sale.php" method="post" enctype="multipart/form-data">
            <div class="form-grid">
                <div class="form-group">
                    <label for="submission_date">Date</label>
                    <input type="date" id="submission_date" name="submission_date" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="quotation_number">Quotation Number</label>
                    <input type="text" id="quotation_number" name="quotation_number" class="form-control">
                </div>
                <div class="form-group">
                    <label for="ccs_lead_id">CCS LEAD ID</label>
                    <input type="text" id="ccs_lead_id" name="ccs_lead_id" class="form-control">
                </div>
                <div class="form-group">
                    <label for="customer_name">Name</label>
                    <input type="text" id="customer_name" name="customer_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="mobile_number">Mobile no</label>
                    <input type="text" id="mobile_number" name="mobile_number" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="vehicle_number">Vehicle Number</label>
                    <input type="text" id="vehicle_number" name="vehicle_number" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="rm_name">RM Name</label>
                    <input type="text" id="rm_name" name="rm_name" class="form-control" value="<?php echo htmlspecialchars($user['full_name']); ?>" readonly>
                </div>
                <div class="form-group">
                    <label for="leader_name">Leader Name</label>
                    <input type="text" id="leader_name" name="leader_name" class="form-control">
                </div>
                <div class="form-group">
                    <label for="premium">Premium</label>
                    <input type="number" id="premium" name="premium" class="form-control" step="0.01" required>
                </div>
                <div class="form-group">
                    <label for="premium_without_gst">Premium W/O Gst</label>
                    <input type="number" id="premium_without_gst" name="premium_without_gst" class="form-control" step="0.01" required>
                </div>
                <div class="form-group">
                    <label for="policy_type">Multi / Single</label>
                    <select id="policy_type" name="policy_type" class="form-control" required>
                        <option value="Single Year">Single Year</option>
                        <option value="Multi Year">Multi Year</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="vehicle_type">2/4 Wheeler</label>
                    <select id="vehicle_type" name="vehicle_type" class="form-control" required>
                        <option value="2 Wheeler">2 Wheeler</option>
                        <option value="4 Wheeler">4 Wheeler</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="city">City</label>
                    <input type="text" id="city" name="city" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="state">State</label>
                    <input type="text" id="state" name="state" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="vehicle_cc">CC</label>
                    <input type="text" id="vehicle_cc" name="vehicle_cc" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="registration_year">Register Year</label>
                    <input type="text" id="registration_year" name="registration_year" class="form-control">
                </div>
                <div class="form-group">
                    <label for="tp_status">TP Status</label>
                    <select id="tp_status" name="tp_status" class="form-control" required>
                        <option value="Yes">Yes</option>
                        <option value="No">No</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="tp_premium">TP Premium</label>
                    <input type="number" id="tp_premium" name="tp_premium" class="form-control" step="0.01">
                </div>
                <div class="form-group">
                    <label for="od_single_year">ODSY</label>
                    <input type="number" id="od_single_year" name="od_single_year" class="form-control" step="0.01">
                </div>
                <div class="form-group">
                    <label for="od_multi_year">ODMY</label>
                    <input type="number" id="od_multi_year" name="od_multi_year" class="form-control" step="0.01">
                </div>
                <div class="form-group">
                    <label for="category">Category</label>
                    <input type="text" id="category" name="category" class="form-control">
                </div>
                <div class="form-group">
                    <label for="fuel_type">Fuel Type</label>
                    <input type="text" id="fuel_type" name="fuel_type" class="form-control">
                </div>
                <div class="form-group">
                    <label for="insurance_company">Insurance Company</label>
                    <input type="text" id="insurance_company" name="insurance_company" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="deal_type">Deal Type</label>
                    <select id="deal_type" name="deal_type" class="form-control" required>
                        <option value="Doctor">Doctor</option>
                        <option value="Non-Doctor">Non-Doctor</option>
                    </select>
                </div>
                <div class="form-group form-grid-full">
                    <label for="payment_screenshot">Payment Screenshot Attached</label>
                    <input type="file" id="payment_screenshot" name="payment_screenshot" class="form-control">
                </div>
                <div class="form-group form-grid-full">
                    <label for="remarks">Remarks</label>
                    <textarea id="remarks" name="remarks" class="form-control" rows="3"></textarea>
                </div>
            </div>
            <div class="form-group">
                <button type="submit" class="btn btn-primary">Submit Sale</button>
            </div>
        </form>
    </div>
</body>
</html>