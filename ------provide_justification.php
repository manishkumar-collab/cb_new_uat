<?php
require_once 'config.php';

// Check if user is logged in and has user role
if (!is_logged_in() || !has_role('User')) {
    show_notification('You do not have permission to access this page', 'error');
    redirect('login.php');
}

// Get request ID
 $request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Validate request
if ($request_id <= 0) {
    show_notification('Invalid request ID', 'error');
    redirect('dashboard_user.php');
}

// Get request details
 $request_sql = "SELECT * FROM cashback_requests WHERE id = ? AND user_id = ?";
 $stmt = mysqli_prepare($conn, $request_sql);
mysqli_stmt_bind_param($stmt, "ii", $request_id, $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
 $request_result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($request_result) !== 1) {
    show_notification('Request not found', 'error');
    redirect('dashboard_user.php');
}

 $request = mysqli_fetch_assoc($request_result);

// Check if request is in Validator Rejected status
if ($request['status'] !== 'Validator Rejected') {
    show_notification('This request is not in Validator Rejected status', 'error');
    redirect('dashboard_user.php');
}

// Get validator's rejection reason
 $rejection_sql = "SELECT comments FROM approvals WHERE request_id = ? AND approver_role = 'Validator' AND status = 'Rejected'";
 $stmt = mysqli_prepare($conn, $rejection_sql);
mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
 $rejection_result = mysqli_stmt_get_result($stmt);

 $rejection_reason = '';
if (mysqli_num_rows($rejection_result) > 0) {
    $rejection = mysqli_fetch_assoc($rejection_result);
    $rejection_reason = $rejection['comments'];
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $justification = isset($_POST['justification']) ? trim($_POST['justification']) : '';
    
    if (empty($justification)) {
        show_notification('Please provide a justification', 'error');
    } else {
        // Update request with justification
        $update_sql = "UPDATE cashback_requests SET reason = CONCAT(reason, '\n\n || User Justification: ', ?) WHERE id = ?";
        $stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($stmt, "si", $justification, $request_id);
        
        if (mysqli_stmt_execute($stmt)) {
            // Reset status to Head Approved so it goes back to Validator
            $reset_sql = "UPDATE cashback_requests SET status = 'Head Approved' WHERE id = ?";
            $stmt = mysqli_prepare($conn, $reset_sql);
            mysqli_stmt_bind_param($stmt, "i", $request_id);
            mysqli_stmt_execute($stmt);
            
            show_notification('Justification submitted successfully. Your request will be reviewed again by the validator.', 'success');
            redirect('dashboard_user.php');
        } else {
            show_notification('Failed to submit justification. Please try again.', 'error');
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Provide Justification - Cashback System</title>
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
        }
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            color: var(--text);
            line-height: 1.5;
            min-height: 100vh;
            font-size: 14px;
            padding: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background-color: var(--light);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
        }
        header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--gray);
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
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark);
        }
        input, textarea, select {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--gray);
            border-radius: var(--radius);
            font-size: 14px;
        }
        textarea {
            min-height: 120px;
            resize: vertical;
        }
        .btn {
            padding: 10px 20px;
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
            background-color: var(--gray);
            color: var(--dark);
        }
        .btn-secondary:hover {
            background-color: #cbd5e0;
        }
        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        .alert {
            padding: 12px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-error {
            background-color: #fff2f0;
            border-left: 4px solid #cf1322;
            color: #cf1322;
        }
        .alert-success {
            background-color: #f6ffed;
            border-left: 4px solid #389e0d;
            color: #389e0d;
        }
        .request-details {
            background-color: #f8fafc;
            padding: 15px;
            border-radius: var(--radius);
            margin-bottom: 20px;
        }
        .request-details h3 {
            margin-bottom: 10px;
            color: var(--primary);
        }
        .request-details p {
            margin-bottom: 5px;
        }
        .rejection-reason {
            background-color: #fff1f0;
            padding: 15px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            border-left: 4px solid #f5222d;
        }
        .rejection-reason h3 {
            margin-bottom: 10px;
            color: #f5222d;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="logo">
                <i class="fas fa-comment-dots logo-icon"></i>
                <div class="logo-text">Provide <span>Justification</span></div>
            </div>
            <p>Your request has been rejected by the validator. Please provide a justification.</p>
        </header>
        
        <?php if (isset($_SESSION['notification'])): ?>
            <div class="alert alert-<?php echo $_SESSION['notification']['type']; ?>">
                <?php echo $_SESSION['notification']['message']; ?>
            </div>
            <?php unset($_SESSION['notification']); ?>
        <?php endif; ?>
        
        <div class="request-details">
            <h3>Request Details</h3>
            <p><strong>Reference Number:</strong> <?php echo htmlspecialchars($request['reference_number']); ?></p>
            <p><strong>Customer Name:</strong> <?php echo htmlspecialchars($request['customer_name']); ?></p>
            <p><strong>Insurance Company:</strong> <?php echo htmlspecialchars($request['insurance_company']); ?></p>
            <p><strong>Policy Type:</strong> <?php echo htmlspecialchars($request['policy_type']); ?></p>
            <p><strong>Premium With GST:</strong> ₹<?php echo number_format($request['premium_with_gst'], 2); ?></p>
            <p><strong>Referral Amount:</strong> ₹<?php echo number_format($request['referral_amount'], 2); ?></p>
            <p><strong>Original Reason:</strong> <?php echo htmlspecialchars($request['reason']); ?></p>
        </div>
        
        <?php if (!empty($rejection_reason)): ?>
        <div class="rejection-reason">
            <h3>Validator's Rejection Reason</h3>
            <p><?php echo htmlspecialchars($rejection_reason); ?></p>
        </div>
        <?php endif; ?>
        
        <form method="post" action="">
            <div class="form-group">
                <label for="justification">Your Justification</label>
                <textarea id="justification" name="justification" required><?php echo isset($_POST['justification']) ? htmlspecialchars($_POST['justification']) : ''; ?></textarea>
            </div>
            
            <div class="form-actions">
                <a href="dashboard_user.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-paper-plane"></i> Submit Justification
                </button>
            </div>
        </form>
    </div>
</body>
</html>