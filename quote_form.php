<?php
require_once 'config.php';

// User must be logged in
if (!is_logged_in()) {
    redirect('login.php');
}

// Generate a unique reference number
 $ref_number = 'QO' . date('ymd') . strtoupper(uniqid());

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic validation
    $required_fields = ['department', 'rm_name', 'client_type', 'client_name', 'contact_number', 'vehicle_number', 'fuel_type', 'manufacturing_company', 'model_name'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            $error = "Please fill all required fields.";
            break;
        }
    }

    if (!isset($error)) {
        // Handle file upload
        $policy_doc_path = null;
        if (isset($_FILES['previous_policy_doc']) && $_FILES['previous_policy_doc']['error'] == UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/policy_docs/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $file_name = basename($_FILES["previous_policy_doc"]["name"]);
            $policy_doc_path = $upload_dir . uniqid() . '_' . $file_name;
            move_uploaded_file($_FILES["previous_policy_doc"]["tmp_name"], $policy_doc_path);
        }

        // Insert into database
        $sql = "INSERT INTO quote_requests (
                    reference_number, user_id, department, rm_name, team_leader_name, client_type, client_name, 
                    contact_number, email, vehicle_number, previous_policy_doc, fuel_type, current_ncb, claim_taken, 
                    registration_date, manufacturing_company, model_name, idv_value, manufacturing_year, 
                    current_addons, required_addons, expiry_date, previous_insurer, remarks, quotation_priority
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "sisssssssssssssssssssssss",
            $ref_number, $_SESSION['user_id'], $_POST['department'], $_POST['rm_name'], $_POST['team_leader_name'], $_POST['client_type'],
            $_POST['client_name'], $_POST['contact_number'], $_POST['email'], $_POST['vehicle_number'], $policy_doc_path,
            $_POST['fuel_type'], $_POST['current_ncb'], $_POST['claim_taken'], $_POST['registration_date'],
            $_POST['manufacturing_company'], $_POST['model_name'], $_POST['idv_value'], $_POST['manufacturing_year'],
            $_POST['current_addons'], $_POST['required_addons'], $_POST['expiry_date'], $_POST['previous_insurer'],
            $_POST['remarks'], $_POST['quotation_priority']
        );

        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['notification'] = ['message' => 'Quote request submitted successfully! Reference: ' . $ref_number, 'type' => 'success'];
            redirect('quote_form.php'); // Or wherever the user goes next
        } else {
            $error = "Database error: " . mysqli_error($conn);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Submit Quote Request</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Use the same base styles as admin.css for consistency */
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f7f6; color: #333; margin: 0; padding: 20px; }
        .container { max-width: 800px; margin: auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        h1 { color: #f05d49; text-align: center; margin-bottom: 20px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; }
        .form-control { width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
        .form-control:focus { border-color: #f05d49; outline: none; }
        .btn { background-color: #f05d49; color: white; padding: 12px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; width: 100%; }
        .btn:hover { background-color: #d84c38; }
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        .alert-error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .grid-full { grid-column: 1 / -1; }
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fas fa-file-contract"></i> Insurance Quote Request Form</h1>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <form action="quote_form.php" method="post" enctype="multipart/form-data">
            <div class="grid">
                <div class="form-group">
                    <label for="department">Department *</label>
                    <input type="text" id="department" name="department" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="rm_name">RM Name *</label>
                    <input type="text" id="rm_name" name="rm_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="team_leader_name">Team Leader Name</label>
                    <input type="text" id="team_leader_name" name="team_leader_name" class="form-control">
                </div>
                <div class="form-group">
                    <label for="client_type">Client Type *</label>
                    <select id="client_type" name="client_type" class="form-control" required>
                        <option value="">Select...</option>
                        <option value="Individual">Individual</option>
                        <option value="Corporate">Corporate</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="client_name">Client Name *</label>
                    <input type="text" id="client_name" name="client_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="contact_number">Contact Number *</label>
                    <input type="tel" id="contact_number" name="contact_number" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" class="form-control">
                </div>
                <div class="form-group">
                    <label for="vehicle_number">Vehicle Number *</label>
                    <input type="text" id="vehicle_number" name="vehicle_number" class="form-control" required>
                </div>
                <div class="form-group grid-full">
                    <label for="previous_policy_doc">Previous Policy/RC/Doc</label>
                    <input type="file" id="previous_policy_doc" name="previous_policy_doc" class="form-control">
                </div>
                <div class="form-group">
                    <label for="fuel_type">Fuel Type *</label>
                    <select id="fuel_type" name="fuel_type" class="form-control" required>
                        <option value="">Select...</option>
                        <option value="Petrol">Petrol</option>
                        <option value="Diesel">Diesel</option>
                        <option value="CNG">CNG</option>
                        <option value="Electric">Electric</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="current_ncb">Current NCB</label>
                    <input type="text" id="current_ncb" name="current_ncb" class="form-control">
                </div>
                <div class="form-group">
                    <label for="claim_taken">Claim Taken?</label>
                    <select id="claim_taken" name="claim_taken" class="form-control">
                        <option value="">Select...</option>
                        <option value="Yes">Yes</option>
                        <option value="No">No</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="registration_date">Registration Date</label>
                    <input type="date" id="registration_date" name="registration_date" class="form-control">
                </div>
                <div class="form-group">
                    <label for="manufacturing_company">Manufacturing Company (Maker) *</label>
                    <input type="text" id="manufacturing_company" name="manufacturing_company" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="model_name">Model Name (Variant) *</label>
                    <input type="text" id="model_name" name="model_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="idv_value">IDV Value (90% of Last Year)</label>
                    <input type="number" id="idv_value" name="idv_value" class="form-control" step="0.01">
                </div>
                <div class="form-group">
                    <label for="manufacturing_year">Manufacturing Year</label>
                    <input type="text" id="manufacturing_year" name="manufacturing_year" class="form-control">
                </div>
                <div class="form-group grid-full">
                    <label for="current_addons">Current Addon's</label>
                    <textarea id="current_addons" name="current_addons" class="form-control" rows="3"></textarea>
                </div>
                <div class="form-group grid-full">
                    <label for="required_addons">Required Addon's</label>
                    <textarea id="required_addons" name="required_addons" class="form-control" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label for="expiry_date">Expiry Date</label>
                    <input type="date" id="expiry_date" name="expiry_date" class="form-control">
                </div>
                <div class="form-group">
                    <label for="previous_insurer">Previous Insurer</label>
                    <input type="text" id="previous_insurer" name="previous_insurer" class="form-control">
                </div>
                <div class="form-group grid-full">
                    <label for="remarks">Remarks</label>
                    <textarea id="remarks" name="remarks" class="form-control" rows="3"></textarea>
                </div>
                 <div class="form-group">
                    <label for="quotation_priority">Quotation Priority</label>
                    <select id="quotation_priority" name="quotation_priority" class="form-control">
                        <option value="Normal">Normal</option>
                        <option value="High">High</option>
                        <option value="Urgent">Urgent</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn"><i class="fas fa-paper-plane"></i> Submit Request</button>
        </form>
    </div>
</body>
</html>