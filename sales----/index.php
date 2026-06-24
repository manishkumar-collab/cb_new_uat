<?php
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

 $user = get_current_user_details();
 $user_role = $user['role'];
 $user_id = $user['id'];

// --- Fetch Data Based on Role ---

// For User Role
if ($user_role === 'User') {
    // Get all user's sales
    $sql_user_sales = "SELECT sr.*, 
                       (SELECT COUNT(*) FROM sales_approvals sa WHERE sa.sales_request_id = sr.id) as approval_chain_count
                       FROM sales_requests sr 
                       WHERE sr.user_id = ? 
                       ORDER BY sr.created_at DESC";
    $stmt = $conn->prepare($sql_user_sales);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user_sales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Get business stats
    $sql_stats = "SELECT 
                  SUM(premium) as total_business,
                  SUM(CASE WHEN status = 'Head Paid' THEN premium ELSE 0 END) as paid_business
                  FROM sales_requests 
                  WHERE user_id = ?";
    $stmt = $conn->prepare($sql_stats);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user_stats = $stmt->get_result()->fetch_assoc();
}

// For Manager Role
if ($user_role === 'Manager') {
    // Get pending requests from team
    $sql_pending = "SELECT sr.*, u.full_name as user_name 
                    FROM sales_requests sr 
                    JOIN users u ON sr.user_id = u.id 
                    WHERE sr.status = 'Pending' AND u.manager_id = ?";
    $stmt = $conn->prepare($sql_pending);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $pending_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Get requests verified by manager
    $sql_verified = "SELECT sr.*, u.full_name as user_name, sa.comments as manager_comments
                     FROM sales_requests sr 
                     JOIN users u ON sr.user_id = u.id 
                     JOIN sales_approvals sa ON sr.id = sa.sales_request_id
                     WHERE sr.status IN ('Manager Verified', 'Head Paid') AND u.manager_id = ? AND sa.approver_role = 'Manager'";
    $stmt = $conn->prepare($sql_verified);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $verified_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Get requests rejected by manager
    $sql_rejected = "SELECT sr.*, u.full_name as user_name, sa.comments as rejection_reason
                     FROM sales_requests sr 
                     JOIN users u ON sr.user_id = u.id 
                     JOIN sales_approvals sa ON sr.id = sa.sales_request_id
                     WHERE sr.status = 'Rejected' AND u.manager_id = ? AND sa.approver_role = 'Manager'";
    $stmt = $conn->prepare($sql_rejected);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $rejected_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// For Head Role
if ($user_role === 'Head') {
    // Get requests pending for payment
    $sql_pending_payment = "SELECT sr.*, u.full_name as user_name, m.full_name as manager_name
                            FROM sales_requests sr 
                            JOIN users u ON sr.user_id = u.id
                            JOIN users m ON u.manager_id = m.id
                            WHERE sr.status = 'Manager Verified' AND u.head_id = ?";
    $stmt = $conn->prepare($sql_pending_payment);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $pending_payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Get requests approved (paid) by head
    $sql_approved = "SELECT sr.*, u.full_name as user_name, m.full_name as manager_name, sa.comments as head_comments
                     FROM sales_requests sr 
                     JOIN users u ON sr.user_id = u.id
                     JOIN users m ON u.manager_id = m.id
                     JOIN sales_approvals sa ON sr.id = sa.sales_request_id
                     WHERE sr.status = 'Head Paid' AND u.head_id = ? AND sa.approver_role = 'Head'";
    $stmt = $conn->prepare($sql_approved);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $approved_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Get business summary by user
    $sql_user_summary = "SELECT u.full_name, SUM(sr.premium) as total_business
                         FROM sales_requests sr 
                         JOIN users u ON sr.user_id = u.id
                         WHERE sr.status = 'Head Paid' AND u.head_id = ?
                         GROUP BY u.id";
    $stmt = $conn->prepare($sql_user_summary);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user_summary = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Get business summary by manager
    $sql_manager_summary = "SELECT m.full_name, SUM(sr.premium) as total_business
                            FROM sales_requests sr 
                            JOIN users u ON sr.user_id = u.id
                            JOIN users m ON u.manager_id = m.id
                            WHERE sr.status = 'Head Paid' AND u.head_id = ?
                            GROUP BY m.id";
    $stmt = $conn->prepare($sql_manager_summary);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $manager_summary = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

 $conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Dashboard - CoverYou</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f8fafc; margin: 0; }
        .container { max-width: 1400px; margin: 20px auto; padding: 20px; }
        .header { text-align: center; margin-bottom: 30px; }
        .card { background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .card-header { background: #f05d49; color: white; padding: 15px; border-radius: 8px 8px 0 0; font-size: 18px; }
        .card-body { padding: 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 8px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); text-align: center; }
        .stat-value { font-size: 28px; font-weight: bold; color: #f05d49; margin-bottom: 5px; }
        .stat-label { color: #666; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        .table th { background: #f8f9fa; }
        .btn { padding: 8px 12px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; color: white; margin-right: 5px; display: inline-block; font-size: 14px; }
        .btn-primary { background: #f05d49; }
        .btn-success { background: #28a745; }
        .btn-danger { background: #dc3545; }
        .btn-info { background: #17a2b8; }
        .btn-warning { background: #ffc107; color: #212529; }
        .badge { padding: 5px 10px; border-radius: 12px; font-size: 12px; font-weight: bold; }
        .bg-pending { background-color: #ffc107; color: #212529; }
        .bg-verified { background-color: #17a2b8; color: white; }
        .bg-paid { background-color: #28a745; color: white; }
        .bg-rejected { background-color: #dc3545; color: white; }
        .chain { font-size: 12px; color: #666; }
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
        <div class="header">
            <h1>Sales Dashboard</h1>
            <p>Welcome, <?php echo htmlspecialchars($user['full_name']); ?> (<?php echo htmlspecialchars($user['role']); ?>)</p>
        </div>

        <?php if (isset($_SESSION['notification'])): ?>
            <div class="alert alert-<?php echo $_SESSION['notification']['type']; ?>">
                <?php echo $_SESSION['notification']['message']; ?>
            </div>
            <?php unset($_SESSION['notification']); ?>
        <?php endif; ?>

        <!-- User Dashboard -->
        <?php if ($user_role === 'User'): ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value">₹<?php echo number_format($user_stats['total_business'], 2); ?></div>
                    <div class="stat-label">Total Business Raised</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">₹<?php echo number_format($user_stats['paid_business'], 2); ?></div>
                    <div class="stat-label">Achieved/Paid Business</div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">My Sales Requests</div>
                <div class="card-body">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Ref. No.</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Premium</th>
                                <th>Status</th>
                                <th>Chain</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($user_sales as $sale): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($sale['reference_number']); ?></td>
                                    <td><?php echo date('d-M-Y', strtotime($sale['submission_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($sale['customer_name']); ?></td>
                                    <td>₹<?php echo number_format($sale['premium'], 2); ?></td>
                                    <td><span class="badge bg-<?php echo strtolower(str_replace(' ', '-', $sale['status'])); ?>"><?php echo htmlspecialchars($sale['status']); ?></span></td>
                                    <td class="chain">
                                        <?php
                                        $chain = [];
                                        if ($sale['status'] === 'Pending') $chain[] = 'Pending';
                                        if ($sale['status'] === 'Manager Verified' || $sale['status'] === 'Head Paid' || $sale['status'] === 'Rejected') $chain[] = 'Manager Verified';
                                        if ($sale['status'] === 'Head Paid' || $sale['status'] === 'Rejected') $chain[] = 'Head Paid';
                                        echo implode(' → ', $chain);
                                        ?>
                                    </td>
                                    <td>
                                        <a href="view_sale.php?id=<?php echo $sale['id']; ?>" class="btn btn-info">View</a>
                                        <?php if ($sale['status'] === 'Pending' || $sale['status'] === 'Rejected'): ?>
                                            <a href="edit_sale.php?id=<?php echo $sale['id']; ?>" class="btn btn-warning">Edit</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Manager Dashboard -->
        <?php if ($user_role === 'Manager'): ?>
            <div class="card">
                <div class="card-header">Pending Verification</div>
                <div class="card-body">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Ref. No.</th>
                                <th>User</th>
                                <th>Customer</th>
                                <th>Premium</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_requests as $request): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($request['reference_number']); ?></td>
                                    <td><?php echo htmlspecialchars($request['user_name']); ?></td>
                                    <td><?php echo htmlspecialchars($request['customer_name']); ?></td>
                                    <td>₹<?php echo number_format($request['premium'], 2); ?></td>
                                    <td>
                                        <button class="btn btn-success" onclick="openVerifyModal(<?php echo $request['id']; ?>)">Verify</button>
                                        <button class="btn btn-danger" onclick="openRejectModal(<?php echo $request['id']; ?>)">Reject</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header">Verified by Me</div>
                <div class="card-body">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Ref. No.</th>
                                <th>User</th>
                                <th>Customer</th>
                                <th>Premium</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($verified_requests as $request): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($request['reference_number']); ?></td>
                                    <td><?php echo htmlspecialchars($request['user_name']); ?></td>
                                    <td><?php echo htmlspecialchars($request['customer_name']); ?></td>
                                    <td>₹<?php echo number_format($request['premium'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($request['manager_comments']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header">Rejected by Me</div>
                <div class="card-body">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Ref. No.</th>
                                <th>User</th>
                                <th>Customer</th>
                                <th>Premium</th>
                                <th>Rejection Reason</th>
                                <th>History</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rejected_requests as $request): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($request['reference_number']); ?></td>
                                    <td><?php echo htmlspecialchars($request['user_name']); ?></td>
                                    <td><?php echo htmlspecialchars($request['customer_name']); ?></td>
                                    <td>₹<?php echo number_format($request['premium'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($request['rejection_reason']); ?></td>
                                    <td>
                                        <button class="btn btn-info" onclick="viewHistory(<?php echo $request['id']; ?>)">View History</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Verify Modal -->
            <div id="verifyModal" class="modal">
                <div class="modal-content">
                    <span class="close" onclick="closeModal('verifyModal')">&times;</span>
                    <h3>Verify Request</h3>
                    <form id="verifyForm" action="process_approval.php" method="post">
                        <input type="hidden" name="sale_id" id="verifySaleId">
                        <input type="hidden" name="action" value="verify">
                        <div class="form-group">
                            <label for="verifyComments">Remarks</label>
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
                            <label for="rejectReason">Rejection Reason</label>
                            <textarea id="rejectReason" name="comments" class="form-control" required></textarea>
                        </div>
                        <button type="submit" class="btn btn-danger">Reject</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Head Dashboard -->
        <?php if ($user_role === 'Head'): ?>
            <div class="card">
                <div class="card-header">Pending for Payment</div>
                <div class="card-body">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Ref. No.</th>
                                <th>User</th>
                                <th>Manager</th>
                                <th>Customer</th>
                                <th>Premium</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_payments as $request): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($request['reference_number']); ?></td>
                                    <td><?php echo htmlspecialchars($request['user_name']); ?></td>
                                    <td><?php echo htmlspecialchars($request['manager_name']); ?></td>
                                    <td><?php echo htmlspecialchars($request['customer_name']); ?></td>
                                    <td>₹<?php echo number_format($request['premium'], 2); ?></td>
                                    <td>
                                        <button class="btn btn-success" onclick="openPaidModal(<?php echo $request['id']; ?>)">Mark as Paid</button>
                                        <button class="btn btn-danger" onclick="openRejectModal(<?php echo $request['id']; ?>)">Reject</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header">Approved by Me</div>
                <div class="card-body">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Ref. No.</th>
                                <th>User</th>
                                <th>Manager</th>
                                <th>Customer</th>
                                <th>Premium</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($approved_requests as $request): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($request['reference_number']); ?></td>
                                    <td><?php echo htmlspecialchars($request['user_name']); ?></td>
                                    <td><?php echo htmlspecialchars($request['manager_name']); ?></td>
                                    <td><?php echo htmlspecialchars($request['customer_name']); ?></td>
                                    <td>₹<?php echo number_format($request['premium'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($request['head_comments']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header">Business Summary</div>
                <div class="card-body">
                    <h4>By User</h4>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Total Business</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($user_summary as $summary): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($summary['full_name']); ?></td>
                                    <td>₹<?php echo number_format($summary['total_business'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <h4>By Manager</h4>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Manager</th>
                                <th>Total Business</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($manager_summary as $summary): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($summary['full_name']); ?></td>
                                    <td>₹<?php echo number_format($summary['total_business'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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
                            <label for="paidComments">Remarks</label>
                            <textarea id="paidComments" name="comments" class="form-control"></textarea>
                        </div>
                        <button type="submit" class="btn btn-success">Mark as Paid</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
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
        function viewHistory(id) {
            window.location.href = 'view_history.php?id=' + id;
        }
    </script>
</body>
</html>