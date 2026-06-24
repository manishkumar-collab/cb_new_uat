<?php
require_once 'config.php';

// Check if user is logged in
if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'You must be logged in to view this page']);
    exit;
}

// Get request ID
$request_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($request_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
    exit;
}

// Get request details
$request_sql = "SELECT cr.*, u.full_name AS user_name, u.emp_id AS user_emp_id, u.department AS user_department,
                m.full_name AS manager_name, m.emp_id AS manager_emp_id,
                h.full_name AS head_name, h.emp_id AS head_emp_id
                FROM cashback_requests cr 
                JOIN users u ON cr.user_id = u.id 
                JOIN users m ON u.manager_id = m.id 
                JOIN users h ON m.head_id = h.id 
                WHERE cr.id = ?";
                
$stmt = mysqli_prepare($conn, $request_sql);
mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($result) === 0) {
    echo json_encode(['success' => false, 'message' => 'Request not found']);
    exit;
}

$request = mysqli_fetch_assoc($result);

// Get approval history
$approval_sql = "SELECT a.*, u.full_name AS approver_name, u.role AS approver_role
                FROM approvals a
                JOIN users u ON a.approver_id = u.id
                WHERE a.request_id = ?
                ORDER BY a.created_at ASC";
                
$stmt = mysqli_prepare($conn, $approval_sql);
mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
$approval_result = mysqli_stmt_get_result($stmt);

$approvals = [];
while ($row = mysqli_fetch_assoc($approval_result)) {
    $approvals[] = $row;
}

echo json_encode([
    'success' => true,
    'request' => $request,
    'approvals' => $approvals
]);
?>

<div class="request-details">
    <div class="detail-card">
        <div class="detail-title">Reference Number</div>
        <div class="detail-value"><?php echo htmlspecialchars($request['reference_number']); ?></div>
    </div>
    
    <div class="detail-card">
        <div class="detail-title">Status</div>
        <div class="detail-value">
            <span class="status-badge <?php echo strtolower(str_replace(' ', '-', $request['status'])); ?>">
                <?php echo $request['status']; ?>
            </span>
        </div>
    </div>
    
    <div class="detail-card">
        <div class="detail-title">User</div>
        <div class="detail-value"><?php echo htmlspecialchars($request['user_name']); ?> (<?php echo htmlspecialchars($request['user_emp_id']); ?>)</div>
    </div>
    
    <div class="detail-card">
        <div class="detail-title">Department</div>
        <div class="detail-value"><?php echo htmlspecialchars($request['user_department']); ?></div>
    </div>
    
    <div class="detail-card">
        <div class="detail-title">Manager</div>
        <div class="detail-value"><?php echo htmlspecialchars($request['manager_name']); ?> (<?php echo htmlspecialchars($request['manager_emp_id']); ?>)</div>
    </div>
    
    <div class="detail-card">
        <div class="detail-title">Head</div>
        <div class="detail-value"><?php echo htmlspecialchars($request['head_name']); ?> (<?php echo htmlspecialchars($request['head_emp_id']); ?>)</div>
    </div>
    
    <div class="detail-card">
        <div class="detail-title">Customer</div>
        <div class="detail-value"><?php echo htmlspecialchars($request['customer_name']); ?></div>
    </div>
    
    <div class="detail-card">
        <div class="detail-title">RM Name</div>
        <div class="detail-value"><?php echo htmlspecialchars($request['rm_name']); ?></div>
    </div>
    
    <div class="detail-card">
        <div class="detail-title">Month & Year</div>
        <div class="detail-value"><?php echo htmlspecialchars($request['month']); ?>, <?php echo htmlspecialchars($request['year']); ?></div>
    </div>
    
    <div class="detail-card">
        <div class="detail-title">Insurance Company</div>
        <div class="detail-value"><?php echo htmlspecialchars($request['insurance_company']); ?></div>
    </div>
    
    <div class="detail-card">
        <div class="detail-title">Policy Type</div>
        <div class="detail-value"><?php echo htmlspecialchars($request['policy_type']); ?></div>
    </div>
    
    <div class="detail-card">
        <div class="detail-title">Premium (with GST)</div>
        <div class="detail-value">₹<?php echo number_format($request['premium_with_gst'], 2); ?></div>
    </div>
    
    <div class="detail-card">
        <div class="detail-title">Premium (without GST)</div>
        <div class="detail-value">₹<?php echo number_format($request['without_gst'], 2); ?></div>
    </div>
    
    <div class="detail-card">
        <div class="detail-title">Cashback Amount</div>
        <div class="detail-value">₹<?php echo number_format($request['referral_amount'], 2); ?></div>
    </div>
    
    <div class="detail-card">
        <div class="detail-title">Reason</div>
        <div class="detail-value"><?php echo nl2br(htmlspecialchars($request['reason'])); ?></div>
    </div>
    
    <div class="detail-card">
        <div class="detail-title">Created Date</div>
        <div class="detail-value"><?php echo date('d M Y, H:i', strtotime($request['created_at'])); ?></div>
    </div>
    
    <?php if ($request['status'] !== 'Pending'): ?>
        <div class="detail-card">
            <div class="detail-title">Updated Date</div>
            <div class="detail-value"><?php echo date('d M Y, H:i', strtotime($request['updated_at'])); ?></div>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($request['attachment_url'])): ?>
        <div class="detail-card">
            <div class="detail-title">Attachment</div>
            <div class="detail-value">
                <a href="<?php echo htmlspecialchars($request['attachment_url']); ?>" target="_blank" class="btn btn-sm btn-primary">
                    <i class="fas fa-download"></i> Download
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="approval-history">
    <h3 class="approval-history-title">Approval History</h3>
    
    <?php if (mysqli_num_rows($approvalResult) > 0): ?>
        <div class="timeline">
            <?php while ($approval = mysqli_fetch_assoc($approvalResult)): ?>
                <div class="timeline-item <?php echo strtolower($approval['status']); ?>">
                    <div class="timeline-date"><?php echo date('d M Y, H:i', strtotime($approval['created_at'])); ?></div>
                    <div class="timeline-content">
                        <div class="timeline-header">
                            <div class="timeline-approver"><?php echo htmlspecialchars($approval['approver_name']); ?> (<?php echo htmlspecialchars($approval['approver_emp_id']; ?>)</div>
                            <div class="timeline-status <?php echo strtolower($approval['status']); ?>"><?php echo $approval['status']; ?></div>
                        </div>
                        <div class="timeline-role"><?php echo htmlspecialchars($approval['approver_role']); ?></div>
                        <?php if (!empty($approval['comments'])): ?>
                            <div class="timeline-comments"><?php echo nl2br(htmlspecialchars($approval['comments'])); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <p>No approval history available.</p>
    <?php endif; ?>
</div>