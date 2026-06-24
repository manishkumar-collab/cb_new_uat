<?php
// /var/www/html/cb_new_uat/sales/edit_sale.php

session_start();
require_once '../config.php';

// Function to get current user details (agar pehle se defined nahi hai)
function get_current_user_details() {
    global $conn;
    if (isset($_SESSION['user_id'])) {
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

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['notification'] = ['message' => 'Please login to access this page.', 'type' => 'error'];
    header('Location: ../login.php');
    exit();
}

// Check if sale ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['notification'] = ['message' => 'Sale ID not provided.', 'type' => 'error'];
    header('Location: index.php');
    exit();
}

 $sale_id = $_GET['id'];
 $user = get_current_user_details();
 $user_id = $user['id'];

// Fetch sale details
 $sql = "SELECT * FROM sales_requests WHERE id = ? AND user_id = ?";
 $stmt = $conn->prepare($sql);
 $stmt->bind_param("ii", $sale_id, $user_id);
 $stmt->execute();
 $result = $stmt->get_result();
 $sale = $result->fetch_assoc();

// Check if sale exists and belongs to the user
if (!$sale) {
    $_SESSION['notification'] = ['message' => 'Sale request not found or you do not have permission to edit it.', 'type' => 'error'];
    header('Location: index.php');
    exit();
}

// Check if the status is 'Rejected' (only rejected requests can be edited)
if ($sale['status'] !== 'Rejected') {
    $_SESSION['notification'] = ['message' => 'You can only edit rejected requests.', 'type' => 'error'];
    header('Location: index.php');
    exit();
}

 $conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Sale Request - CoverYou</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            color: #4a5568;
            line-height: 1.5;
            min-height: 100vh;
        }
        .container {
            max-width: 900px;
            margin: 20px auto;
            padding: 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .card-header {
            background: #f05d49;
            color: white;
            padding: 15px 20px;
            border-radius: 8px 8px 0 0;
            margin: -20px -20px 20px -20px;
        }
        .card-header h2 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #2d3748;
        }
        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #cbd5e0;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        .form-control:focus {
            outline: none;
            border-color: #f05d49;
            box-shadow: 0 0 0 2px rgba(240, 93, 73, 0.2);
        }
        .form-control[readonly] {
            background-color: #e2e8f0;
            cursor: not-allowed;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            font-size: 16px;
            transition: background-color 0.2s;
        }
        .btn-primary {
            background: #f05d49;
            color: white;
        }
        .btn-primary:hover {
            background: #d84c38;
        }
        .btn-info {
            background: #17a2b8;
            color: white;
            text-decoration: none;
            display: inline-block;
            margin-right: 10px;
        }
        .btn-info:hover {
            background: #135a8f;
        }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        .form-grid-full {
            grid-column: span 2;
        }
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            .form-grid-full {
                grid-column: span 1;
            }
        }
        .alert {
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
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
    </style>
</head>
<body>
    <div class="container">
        <div class="card-header">
            <h2><i class="fas fa-edit"></i> Edit Sale Request</h2>
        </div>
        
        <?php if (isset($_SESSION['notification'])): ?>
            <div class="alert alert-<?php echo $_SESSION['notification']['type']; ?>">
                <?php echo $_SESSION['notification']['message']; ?>
            </div>
            <?php unset($_SESSION['notification']); ?>
        <?php endif; ?>

        <form action="process_edit_sale.php" method="post" enctype="multipart/form-data">
            <input type="hidden" name="sale_id" value="<?php echo $sale_id; ?>">
            <input type="hidden" name="is_resubmission" value="1">
            
            <div class="form-grid">
                <div class="form-group">
                    <label for="submission_date"><i class="fas fa-calendar"></i> Date</label>
                    <input type="date" id="submission_date" name="submission_date" class="form-control" value="<?php echo htmlspecialchars($sale['submission_date']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="quotation_number"><i class="fas fa-file-invoice"></i> Quotation Number</label>
                    <input type="text" id="quotation_number" name="quotation_number" class="form-control" value="<?php echo htmlspecialchars($sale['quotation_number']); ?>">
                </div>
                <div class="form-group">
                    <label for="ccs_lead_id"><i class="fas fa-id-card"></i> CCS LEAD ID</label>
                    <input type="text" id="ccs_lead_id" name="ccs_lead_id" class="form-control" value="<?php echo htmlspecialchars($sale['ccs_lead_id']); ?>">
                </div>
                <div class="form-group">
                    <label for="customer_name"><i class="fas fa-user"></i> Name</label>
                    <input type="text" id="customer_name" name="customer_name" class="form-control" value="<?php echo htmlspecialchars($sale['customer_name']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="mobile_number"><i class="fas fa-phone"></i> Mobile no</label>
                    <input type="text" id="mobile_number" name="mobile_number" class="form-control" value="<?php echo htmlspecialchars($sale['mobile_number']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="vehicle_number"><i class="fas fa-car"></i> Vehicle Number</label>
                    <input type="text" id="vehicle_number" name="vehicle_number" class="form-control" value="<?php echo htmlspecialchars($sale['vehicle_number']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="rm_name"><i class="fas fa-user-tie"></i> RM Name</label>
                    <input type="text" id="rm_name" name="rm_name" class="form-control" value="<?php echo htmlspecialchars($sale['rm_name']); ?>" readonly>
                </div>
                <div class="form-group">
                    <label for="leader_name"><i class="fas fa-user"></i> Leader Name</label>
                    <input type="text" id="leader_name" name="leader_name" class="form-control" value="<?php echo htmlspecialchars($sale['leader_name']); ?>">
                </div>
                <div class="form-group">
                    <label for="premium"><i class="fas fa-rupee-sign"></i> Premium</label>
                    <input type="number" id="premium" name="premium" class="form-control" step="0.01" value="<?php echo htmlspecialchars($sale['premium']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="premium_without_gst"><i class="fas fa-calculator"></i> Premium W/O Gst</label>
                    <input type="number" id="premium_without_gst" name="premium_without_gst" class="form-control" step="0.01" value="<?php echo htmlspecialchars($sale['premium_without_gst']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="policy_type"><i class="fas fa-file-contract"></i> Multi / Single</label>
                    <select id="policy_type" name="policy_type" class="form-control" required>
                        <option value="Single Year" <?php echo ($sale['policy_type'] == 'Single Year') ? 'selected' : ''; ?>>Single Year</option>
                        <option value="Multi Year" <?php echo ($sale['policy_type'] == 'Multi Year') ? 'selected' : ''; ?>>Multi Year</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="vehicle_type"><i class="fas fa-motorcycle"></i> 2/4 Wheeler</label>
                    <select id="vehicle_type" name="vehicle_type" class="form-control" required>
                        <option value="2 Wheeler" <?php echo ($sale['vehicle_type'] == '2 Wheeler') ? 'selected' : ''; ?>>2 Wheeler</option>
                        <option value="4 Wheeler" <?php echo ($sale['vehicle_type'] == '4 Wheeler') ? 'selected' : ''; ?>>4 Wheeler</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="city"><i class="fas fa-city"></i> City</label>
                    <input type="text" id="city" name="city" class="form-control" value="<?php echo htmlspecialchars($sale['city']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="state"><i class="fas fa-map-marker-alt"></i> State</label>
                    <input type="text" id="state" name="state" class="form-control" value="<?php echo htmlspecialchars($sale['state']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="vehicle_cc"><i class="fas fa-tachometer-alt"></i> CC</label>
                    <input type="text" id="vehicle_cc" name="vehicle_cc" class="form-control" value="<?php echo htmlspecialchars($sale['vehicle_cc']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="registration_year"><i class="fas fa-calendar-alt"></i> Register Year</label>
                    <input type="text" id="registration_year" name="registration_year" class="form-control" value="<?php echo htmlspecialchars($sale['registration_year']); ?>">
                </div>
                <div class="form-group">
                    <label for="tp_status"><i class="fas fa-check-circle"></i> TP Status</label>
                    <select id="tp_status" name="tp_status" class="form-control" required>
                        <option value="Yes" <?php echo ($sale['tp_status'] == 'Yes') ? 'selected' : ''; ?>>Yes</option>
                        <option value="No" <?php echo ($sale['tp_status'] == 'No') ? 'selected' : ''; ?>>No</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="tp_premium"><i class="fas fa-rupee-sign"></i> TP Premium</label>
                    <input type="number" id="tp_premium" name="tp_premium" class="form-control" step="0.01" value="<?php echo htmlspecialchars($sale['tp_premium']); ?>">
                </div>
                <div class="form-group">
                    <label for="od_single_year"><i class="fas fa-rupee-sign"></i> ODSY</label>
                    <input type="number" id="od_single_year" name="od_single_year" class="form-control" step="0.01" value="<?php echo htmlspecialchars($sale['od_single_year']); ?>">
                </div>
                <div class="form-group">
                    <label for="od_multi_year"><i class="fas fa-rupee-sign"></i> ODMY</label>
                    <input type="number" id="od_multi_year" name="od_multi_year" class="form-control" step="0.01" value="<?php echo htmlspecialchars($sale['od_multi_year']); ?>">
                </div>
                <div class="form-group">
                    <label for="category"><i class="fas fa-tag"></i> Category</label>
                    <input type="text" id="category" name="category" class="form-control" value="<?php echo htmlspecialchars($sale['category']); ?>">
                </div>
                <div class="form-group">
                    <label for="fuel_type"><i class="fas fa-gas-pump"></i> Fuel Type</label>
                    <input type="text" id="fuel_type" name="fuel_type" class="form-control" value="<?php echo htmlspecialchars($sale['fuel_type']); ?>">
                </div>
                <div class="form-group">
                    <label for="insurance_company"><i class="fas fa-shield-alt"></i> Insurance Company</label>
                    <input type="text" id="insurance_company" name="insurance_company" class="form-control" value="<?php echo htmlspecialchars($sale['insurance_company']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="deal_type"><i class="fas fa-handshake"></i> Deal Type</label>
                    <select id="deal_type" name="deal_type" class="form-control" required>
                        <option value="Doctor" <?php echo ($sale['deal_type'] == 'Doctor') ? 'selected' : ''; ?>>Doctor</option>
                        <option value="Non-Doctor" <?php echo ($sale['deal_type'] == 'Non-Doctor') ? 'selected' : ''; ?>>Non-Doctor</option>
                    </select>
                </div>
                <div class="form-group form-grid-full">
                    <label for="payment_screenshot"><i class="fas fa-image"></i> Payment Screenshot Attached</label>
                    <input type="file" id="payment_screenshot" name="payment_screenshot" class="form-control">
                    <?php if ($sale['payment_screenshot_url']): ?>
                        <p style="margin-top: 5px; font-size: 12px; color: #666;">
                            Current file: <a href="<?php echo '../' . htmlspecialchars($sale['payment_screenshot_url']); ?>" target="_blank" style="color: #f05d49;">View Screenshot</a>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="form-group form-grid-full">
                    <label for="remarks"><i class="fas fa-comment"></i> Remarks</label>
                    <textarea id="remarks" name="remarks" class="form-control" rows="3"><?php echo htmlspecialchars($sale['remarks']); ?></textarea>
                </div>
            </div>
            
            <div style="text-align: center; margin-top: 20px;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Resubmit Request</button>
            </div>
        </form>
        <a href="index.php" class="btn btn-info"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>
</body>
</html>