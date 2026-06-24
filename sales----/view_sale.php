<?php
// /var/www/html/cb_new_uat/sales/view_sale.php

session_start();
require_once '../config.php';

// Function to get current user details
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
 $user_role = $user['role'];
 $user_id = $user['id'];

// Fetch sale details along with the user's assigned manager/head from the users table
 $sql = "SELECT sr.*, u.full_name as user_name, u.department as user_department, 
               u.manager_id as user_manager_id, u.head_id as user_head_id 
        FROM sales_requests sr 
        JOIN users u ON sr.user_id = u.id 
        WHERE sr.id = ?";
 $stmt = $conn->prepare($sql);
 $stmt->bind_param("i", $sale_id);
 $stmt->execute();
 $result = $stmt->get_result();
 $sale = $result->fetch_assoc();

// Check if sale exists
if (!$sale) {
    $_SESSION['notification'] = ['message' => 'Sale request not found.', 'type' => 'error'];
    header('Location: index.php');
    exit();
}

// Fetch approval chain and remarks
 $sql_approvals = "SELECT sa.*, u.full_name as approver_name 
                  FROM sales_approvals sa 
                  JOIN users u ON sa.approver_id = u.id 
                  WHERE sa.sales_request_id = ? 
                  ORDER BY sa.created_at ASC";
 $stmt = $conn->prepare($sql_approvals);
 $stmt->bind_param("i", $sale_id);
 $stmt->execute();
 $approval_chain = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Role-based access check (REVISED LOGIC)
 $can_view = false;
 $can_edit = false;
 $show_verify_reject_buttons = false;
 $show_paid_reject_buttons = false;

if ($user_role === 'Admin' || $user_role === 'Finance') {
    $can_view = true; // Can see everything
} elseif ($user_role === 'Head' && ($sale['user_head_id'] == $user_id || $sale['head_id'] == $user_id)) {
    // Head can see if they are the assigned head of the user or the request
    $can_view = true;
    if ($sale['status'] === 'Manager Verified') {
        $show_paid_reject_buttons = true;
    }
} elseif ($user_role === 'Manager' && ($sale['user_manager_id'] == $user_id || $sale['manager_id'] == $user_id)) {
    // Manager can see if they are the assigned manager of the user or the request
    $can_view = true;
    if ($sale['status'] === 'Pending') {
        $show_verify_reject_buttons = true;
    }
} elseif ($user_role === 'User' && $sale['user_id'] == $user_id) {
    // User can see their own requests
    $can_view = true;
    if ($sale['status'] === 'Pending' || $sale['status'] === 'Rejected') {
        $can_edit = true; // Can edit if pending or rejected
    }
}

if (!$can_view) {
    $_SESSION['notification'] = ['message' => 'You do not have permission to view this request.', 'type' => 'error'];
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
    <title>View Sale Details - CoverYou</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f8fafc; }
        .container { max-width: 1200px; margin: 20px auto; padding: 20px; }
        .card { background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .card-header { background: #f05d49; color: white; padding: 15px; border-radius: 8px 8px 0 0; }
        .card-body { padding: 20px; }
        .detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px; }
        .detail-item { margin-bottom: 10px; }
        .detail-label { font-weight: bold; color: #555; }
        .detail-value { color: #333; }
        .status-badge { padding: 5px 10px; border-radius: 12px; font-size: 12px; font-weight: bold; }
        .bg-pending { background-color: #ffc107; color: #212529; }
        .bg-verified { background-color: #17a2b8; color: white; }
        .bg-paid { background-color: #28a745; color: white; }
        .bg-rejected { background-color: #dc3545; color: white; }
        .btn { padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; color: white; margin-right: 10px; display: inline-block; }
        .btn-primary { background: #f05d49; }
        .btn-success { background: #28a745; }
        .btn-danger { background: #dc3545; }
        .btn-info { background: #17a2b8; }
        .btn-warning { background: #ffc107; color: #212529; }
        .chain-timeline { position: relative; padding-left: 30px; margin-top: 20px; }
        .chain-timeline::before { content: ''; position: absolute; left: 10px; top: 0; height: 100%; width: 2px; background: #e0e0e0; }
        .chain-item { position: relative; margin-bottom: 20px; }
        .chain-item::before { content: ''; position: absolute; left: -24px; top: 5px; width: 12px; height: 12px; border-radius: 50%; background: #f05d49; border: 2px solid white; }
        .chain-content { background: #f9f9f9; padding: 10px; border-radius: 5px; }
        .chain-actor { font-weight: bold; }
        .chain-action { color: #666; font-size: 12px; }
        .chain-comment { margin-top: 5px; font-style: italic; color: #333; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .modal-content { background-color: white; margin: 10% auto; padding: 20px; border-radius: 8px; width: 50%; max-width: 500px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; }
        .form-control { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
        .close { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
        .close:hover { color: black; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <h3>Sale Request Details: <?php echo htmlspecialchars($sale['reference_number']); ?></h3>
            </div>
            <div class="card-body">
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-label">Reference Number:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($sale['reference_number']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Status:</span>
                        <span class="status-badge bg-<?php echo strtolower(str_replace(' ', '-', $sale['status'])); ?>"><?php echo htmlspecialchars($sale['status']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Submitted By:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($sale['user_name']); ?> (<?php echo htmlspecialchars($sale['user_department']); ?>)</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Submission Date:</span>
                        <span class="detail-value"><?php echo date('d-M-Y', strtotime($sale['submission_date'])); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Quotation Number:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($sale['quotation_number']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">CCS LEAD ID:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($sale['ccs_lead_id']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Customer Name:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($sale['customer_name']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Mobile Number:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($sale['mobile_number']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Vehicle Number:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($sale['vehicle_number']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">RM Name:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($sale['rm_name']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Leader Name:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($sale['leader_name']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Premium (with GST):</span>
                        <span class="detail-value">₹<?php echo number_format($sale['premium'], 2); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Premium (without GST):</span>
                        <span class="detail-value">₹<?php echo number_format($sale['premium_without_gst'], 2); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Policy Type:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($sale['policy_type']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Vehicle Type:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($sale['vehicle_type']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">City:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($sale['city']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">State:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($sale['state']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Vehicle CC:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($sale['vehicle_cc']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Register Year:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($sale['registration_year']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">TP Status:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($sale['tp_status']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">TP Premium:</span>
                        <span class="detail-value">₹<?php echo number_format($sale['tp_premium'], 2); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">ODSY:</span>
                        <span class="detail-value">₹<?php echo number_format($sale['od_single_year'], 2); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">ODMY:</span>
                        <span class="detail-value">₹<?php echo number_format($sale['od_multi_year'], 2); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Category:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($sale['category']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Fuel Type:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($sale['fuel_type']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Insurance Company:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($sale['insurance_company']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Deal Type:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($sale['deal_type']); ?></span>
                    </div>
                    <?php if ($sale['payment_screenshot_url']): ?>
                    <div class="detail-item" style="grid-column: span 2;">
                        <span class="detail-label">Payment Screenshot:</span><br>
                        <a href="<?php echo '../' . htmlspecialchars($sale['payment_screenshot_url']); ?>" target="_blank">
                            <img src="<?php echo '../' . htmlspecialchars($sale['payment_screenshot_url']); ?>" alt="Payment Screenshot" width="200" style="border: 1px solid #ddd; border-radius: 4px;">
                        </a>
                    </div>
                    <?php endif; ?>
                    <?php if ($sale['remarks']): ?>
                    <div class="detail-item" style="grid-column: span 2;">
                        <span class="detail-label">Remarks:</span>
                        <p><?php echo nl2br(htmlspecialchars($sale['remarks'])); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
                
                <hr>

                <!-- Approval Chain & Remarks History -->
                <h4>Approval Chain & Remarks</h4>
                <div class="chain-timeline">
                    <div class="chain-item">
                        <div class="chain-content">
                            <div class="chain-actor"><?php echo htmlspecialchars($sale['user_name']); ?></div>
                            <div class="chain-action">Submitted on <?php echo date('d-M-Y H:i', strtotime($sale['created_at'])); ?></div>
                        </div>
                    </div>
                    <?php foreach ($approval_chain as $approval): ?>
                        <div class="chain-item">
                            <div class="chain-content">
                                <div class="chain-actor"><?php echo htmlspecialchars($approval['approver_name']); ?> (<?php echo htmlspecialchars($approval['approver_role']); ?>)</div>
                                <div class="chain-action"><?php echo htmlspecialchars($approval['status']); ?> on <?php echo date('d-M-Y H:i', strtotime($approval['created_at'])); ?></div>
                                <?php if ($approval['comments']): ?>
                                    <div class="chain-comment">Remark: "<?php echo htmlspecialchars($approval['comments']); ?>"</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <hr>

                <!-- Action Buttons -->
                <div class="actions">
                    <?php if ($can_edit): ?>
                        <a href="edit_sale.php?id=<?php echo $sale_id; ?>" class="btn btn-warning"><i class="fas fa-edit"></i> Edit</a>
                    <?php endif; ?>

                    <?php if ($show_verify_reject_buttons): ?>
                        <button class="btn btn-success" onclick="openVerifyModal(<?php echo $sale_id; ?>)"><i class="fas fa-check"></i> Verify</button>
                        <button class="btn btn-danger" onclick="openRejectModal(<?php echo $sale_id; ?>)"><i class="fas fa-times"></i> Reject</button>
                    <?php endif; ?>

                    <?php if ($show_paid_reject_buttons): ?>
                        <button class="btn btn-success" onclick="openPaidModal(<?php echo $sale_id; ?>)"><i class="fas fa-money-check-alt"></i> Mark as Paid</button>
                        <button class="btn btn-danger" onclick="openRejectModal(<?php echo $sale_id; ?>)"><i class="fas fa-times"></i> Reject</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <a href="index.php" class="btn btn-info"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>

    <!-- Modals for Actions with Remarks -->
    <!-- Verify Modal -->
    <div id="verifyModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('verifyModal')">&times;</span>
            <h3>Verify Request</h3>
            <form id="verifyForm" action="process_approval.php" method="post">
                <input type="hidden" name="sale_id" id="verifySaleId">
                <input type="hidden" name="action" value="verify">
                <div class="form-group">
                    <label for="verifyComments">Remarks (Optional)</label>
                    <textarea id="verifyComments" name="comments" class="form-control"></textarea>
                </div>
                <button type="submit" class="btn btn-success">Verify</button>
            </form>
        </div>
    </div>

    <!-- Reject Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('rejectModal')">&times;</span>
            <h3>Reject Request</h3>
            <form id="rejectForm" action="process_approval.php" method="post">
                <input type="hidden" name="sale_id" id="rejectSaleId">
                <input type="hidden" name="action" value="reject">
                <div class="form-group">
                    <label for="rejectReason">Rejection Reason <span style="color:red;">*</span></label>
                    <textarea id="rejectReason" name="comments" class="form-control" required></textarea>
                </div>
                <button type="submit" class="btn btn-danger">Reject</button>
            </form>
        </div>
    </div>

    <!-- Paid Modal -->
    <div id="paidModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('paidModal')">&times;</span>
            <h3>Mark as Paid</h3>
            <form id="paidForm" action="process_approval.php" method="post">
                <input type="hidden" name="sale_id" id="paidSaleId">
                <input type="hidden" name="action" value="paid">
                <div class="form-group">
                    <label for="paidComments">Remarks (Optional)</label>
                    <textarea id="paidComments" name="comments" class="form-control"></textarea>
                </div>
                <button type="submit" class="btn btn-success">Mark as Paid</button>
            </form>
        </div>
    </div>

    <script>
        function openVerifyModal(id) {
            document.getElementById('verifySaleId').value = id;
            document.getElementById('verifyModal').style.display = 'block';
        }
        function openRejectModal(id) {
            document.getElementById('rejectSaleId').value = id;
            document.getElementById('rejectModal').style.display = 'block';
        }
        function openPaidModal(id) {
            document.getElementById('paidSaleId').value = id;
            document.getElementById('paidModal').style.display = 'block';
        }
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        // Close modal if user clicks outside of it
        window.onclick = function(event) {
            const modals = ['verifyModal', 'rejectModal', 'paidModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target == modal) {
                    modal.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>