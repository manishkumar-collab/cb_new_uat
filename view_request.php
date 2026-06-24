<?php
require_once 'config.php';

// Check if user is logged in
if (!is_logged_in()) {
    show_notification('You must be logged in to view this page', 'error');
    redirect('login.php');
}

// Check if request ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    show_notification('Invalid request', 'error');
    redirect('dashboard_' . strtolower($_SESSION['role']) . '.php');
}

 $request_id = $_GET['id'];

// Get request details
 $request_sql = "SELECT cr.*, u.full_name AS user_name, u.emp_id AS user_emp_id, u.department AS user_department,
               m.full_name AS manager_name, m.emp_id AS manager_emp_id,
               h.full_name AS head_name, h.emp_id AS head_emp_id
               FROM cashback_requests cr 
               JOIN users u ON cr.user_id = u.id 
               LEFT JOIN users m ON u.manager_id = m.id 
               LEFT JOIN users h ON m.head_id = h.id 
               WHERE cr.id = ?";
 $stmt = mysqli_prepare($conn, $request_sql);
mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
 $request_result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($request_result) === 0) {
    show_notification('Request not found', 'error');
    redirect('dashboard_' . strtolower($_SESSION['role']) . '.php');
}

 $request = mysqli_fetch_assoc($request_result);

// Get approval history
 $approvals_sql = "SELECT a.*, u.full_name AS approver_name 
                 FROM approvals a 
                 JOIN users u ON a.approver_id = u.id 
                 WHERE a.request_id = ? 
                 ORDER BY a.created_at";
 $stmt = mysqli_prepare($conn, $approvals_sql);
mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
 $approvals_result = mysqli_stmt_get_result($stmt);

// Get user justifications
 $justifications_sql = "SELECT * FROM user_justifications 
                      WHERE request_id = ? 
                      ORDER BY created_at DESC";
 $stmt = mysqli_prepare($conn, $justifications_sql);
mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
 $justifications_result = mysqli_stmt_get_result($stmt);

 $justifications = [];
if (mysqli_num_rows($justifications_result) > 0) {
    while ($justification = mysqli_fetch_assoc($justifications_result)) {
        $justifications[] = $justification;
    }
}

 $canJustify = false;
if (($request['status'] === 'Validator Rejected' || $request['status'] === 'Pending Validation') && $request['user_id'] == $_SESSION['user_id']) {
    $hasJustified = false;
    foreach ($justifications as $justification) {
        if ($justification['user_id'] == $_SESSION['user_id']) {
            $hasJustified = true;
            break;
        }
    }
    $canJustify = !$hasJustified;
}

 $validatorCanReview = false;
if (has_role('Validator') && ($request['status'] === 'Validator Rejected' || $request['status'] === 'Pending Validation')) {
    foreach ($justifications as $justification) {
        if ($justification['user_id'] == $request['user_id']) {
            $validatorCanReview = true;
            break;
        }
    }
}

// Progress Tracker Logic
 $status_steps = ['Pending', 'Manager Approved', 'Head Approved', 'Validator Approved', 'Finance Approved'];
 $current_step_index = array_search($request['status'], $status_steps);
if ($current_step_index === false) $current_step_index = -1; 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Request - CB Account</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="https://www.coveryou.in/images/favicon.png" type="image/png">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        :root {
            --primary: #f05d49; --primary-dark: #d84c38; --primary-light: #ff7d6a; --primary-bg: #fff5f5;
            --dark: #1a202c; --gray-900: #2d3748; --gray-700: #4a5568; --gray-500: #718096; --gray-300: #e2e8f0; --gray-100: #f7fafc;
            --white: #ffffff;
            --success: #38a169; --success-bg: #f0fff4; --success-border: #9ae6b4;
            --danger: #e53e3e; --danger-bg: #fff5f5; --danger-border: #feb2b2;
            --warning: #d69e2e; --warning-bg: #fffff0; --warning-border: #fefcbf;
            --info: #3182ce; --info-bg: #ebf8ff; --info-border: #90cdf4;
            
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow: 0 2px 4px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.07), 0 2px 4px -1px rgba(0,0,0,0.04);
            --radius: 8px; --radius-lg: 12px;
            --sidebar-width: 260px;
        }
        body { background: var(--gray-100); color: var(--gray-700); line-height: 1.5; min-height: 100vh; font-size: 13px; }
        .app-container { display: flex; min-height: 100vh; }
        
        /* Sidebar */
        .sidebar { width: var(--sidebar-width); background: var(--dark); color: var(--white); position: fixed; height: 100vh; overflow-y: auto; z-index: 100; transition: transform 0.3s; }
        .sidebar-header { padding: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; gap: 10px; }
        .sidebar-logo-icon { font-size: 24px; color: var(--primary); }
        .sidebar-logo-text { font-size: 18px; font-weight: 700; letter-spacing: -0.5px; }
        .sidebar-user { padding: 15px 20px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-user-name { font-weight: 600; font-size: 14px; }
        .sidebar-user-role { color: var(--gray-500); font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
        .sidebar-menu { padding: 10px 0; }
        .sidebar-menu-item { display: flex; align-items: center; gap: 12px; padding: 12px 20px; color: #cbd5e0; text-decoration: none; font-size: 13px; transition: all 0.2s; }
        .sidebar-menu-item:hover { background: rgba(255,255,255,0.05); color: var(--white); }
        .sidebar-menu-item.active { background: var(--primary); color: var(--white); }
        .sidebar-menu-item i { width: 20px; text-align: center; }
        .sidebar-footer { padding: 15px 20px; font-size: 11px; color: var(--gray-500); border-top: 1px solid rgba(255,255,255,0.1); position: absolute; bottom: 0; width: 100%; }
        
        /* Main Content */
        .main-content { flex: 1; margin-left: var(--sidebar-width); padding: 20px; background: var(--gray-100); }
        .container { max-width: 1440px; margin: 0 auto; }
        
        /* Header Card (Original) */
        header { text-align: center; margin-bottom: 20px; padding: 20px; background: var(--white); border-radius: var(--radius-lg); box-shadow: var(--shadow-md); position: relative; border-top: 4px solid var(--primary); }
        .logo { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 5px; }
        .logo-icon { font-size: 26px; color: var(--primary); }
        .logo-text { font-size: 24px; font-weight: 800; color: var(--dark); letter-spacing: -0.5px; }
        .logo-text span { color: var(--primary); }
        .tagline { color: var(--gray-500); font-size: 13px; font-weight: 500; }
        .user-info { position: absolute; top: 20px; right: 20px; display: flex; align-items: center; gap: 15px; }
        .user-details { display: flex; flex-direction: column; align-items: flex-end; }
        .username { font-weight: 700; color: var(--dark); font-size: 13px; }
        .user-role { font-size: 11px; color: var(--gray-500); text-transform: uppercase; }
        .logout-btn { padding: 7px 14px; background-color: var(--danger); color: white; border: none; border-radius: var(--radius); cursor: pointer; display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; transition: all 0.2s; }
        .logout-btn:hover { background-color: #c53030; transform: translateY(-1px); }
        
        /* Top Action & Progress Section */
        .top-section { background: var(--white); border-radius: var(--radius-lg); padding: 20px; margin-bottom: 20px; box-shadow: var(--shadow); display: flex; justify-content: space-between; align-items: center; gap: 20px; }
        .progress-tracker { display: flex; justify-content: space-between; flex: 1; position: relative; padding: 0 10px; }
        .progress-tracker::before { content: ''; position: absolute; top: 15px; left: 20px; right: 20px; height: 2px; background: var(--gray-300); z-index: 0; }
        .step { position: relative; z-index: 1; text-align: center; flex: 1; }
        .step-dot { width: 30px; height: 30px; border-radius: 50%; background: var(--white); border: 2px solid var(--gray-300); margin: 0 auto 6px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; color: var(--gray-500); transition: all 0.3s; }
        .step.active .step-dot { border-color: var(--primary); color: var(--primary); background: var(--primary-bg); box-shadow: 0 0 0 4px rgba(240, 93, 73, 0.15); }
        .step.completed .step-dot { border-color: var(--success); background: var(--success); color: var(--white); }
        .step.rejected .step-dot { border-color: var(--danger); background: var(--danger); color: var(--white); box-shadow: 0 0 0 4px rgba(229, 62, 62, 0.15); }
        .step-label { font-size: 10px; color: var(--gray-500); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .step.active .step-label { color: var(--primary); }
        .step.completed .step-label { color: var(--success); }
        .step.rejected .step-label { color: var(--danger); }
        
        .action-bar { display: flex; flex-direction: column; gap: 8px; min-width: 200px; }
        .btn { padding: 8px 16px; border-radius: var(--radius); font-weight: 600; cursor: pointer; border: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-size: 12px; text-decoration: none; transition: all 0.2s; }
        .btn-primary { background: var(--primary); color: var(--white); }
        .btn-primary:hover { background: var(--primary-dark); transform: translateY(-1px); }
        .btn-success { background: var(--success); color: var(--white); }
        .btn-success:hover { background: #2f855a; transform: translateY(-1px); }
        .btn-danger { background: var(--danger); color: var(--white); }
        .btn-danger:hover { background: #c53030; transform: translateY(-1px); }
        .btn-outline { background: var(--white); border: 1px solid var(--gray-300); color: var(--gray-700); }
        .btn-outline:hover { border-color: var(--primary); color: var(--primary); }
        
        /* Dashboard Grid */
        .dashboard-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; }
        .card { background: var(--white); border-radius: var(--radius-lg); box-shadow: var(--shadow); border: 1px solid rgba(0,0,0,0.03); display: flex; flex-direction: column; overflow: hidden; }
        .card-header { padding: 14px 16px; border-bottom: 1px solid var(--gray-100); background: #fafafa; display: flex; justify-content: space-between; align-items: center; }
        .card-title { font-size: 14px; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 8px; }
        .card-title i { color: var(--primary); font-size: 15px; }
        .card-body { padding: 16px; flex-grow: 1; }
        
        /* Badges */
        .badge { padding: 3px 8px; border-radius: 6px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; }
        .badge-pending { background: var(--warning-bg); color: #975a16; border: 1px solid var(--warning-border); }
        .badge-approved { background: var(--success-bg); color: #276749; border: 1px solid var(--success-border); }
        .badge-rejected { background: var(--danger-bg); color: #9b2c2c; border: 1px solid var(--danger-border); }
        .badge-info { background: var(--info-bg); color: #2c5282; border: 1px solid var(--info-border); }
        
        /* Details Grid */
        .details-list { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .detail-group { display: flex; flex-direction: column; gap: 1px; }
        .detail-label { font-size: 10px; color: var(--gray-500); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .detail-value { font-size: 13px; color: var(--gray-900); font-weight: 500; }
        .detail-full { grid-column: span 2; }
        
        /* Financial Grid */
        .finance-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 14px; padding-top: 14px; border-top: 1px dashed var(--gray-300); }
        .finance-item { background: linear-gradient(135deg, #f8fafc 0%, #edf2f7 100%); padding: 10px; border-radius: var(--radius); border-left: 3px solid var(--primary); }
        .finance-label { font-size: 9px; color: var(--gray-500); margin-bottom: 2px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; }
        .finance-value { font-size: 16px; font-weight: 800; color: var(--dark); }
        .finance-value.highlight { color: var(--primary); }
        
        /* Payment Info */
        .payment-type-box { background: var(--primary-bg); border: 1px solid #fed7d7; padding: 10px 12px; border-radius: var(--radius); margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; }
        .payment-label { font-size: 11px; color: var(--primary-dark); font-weight: 600; display: flex; align-items: center; gap: 6px; }
        .payment-val { font-weight: 800; color: var(--primary); font-size: 12px; text-transform: uppercase; }
        .img-preview { width: 100%; max-height: 140px; object-fit: contain; border-radius: var(--radius); border: 1px solid var(--gray-300); cursor: pointer; margin-top: 6px; transition: all 0.2s; background: var(--white); }
        .img-preview:hover { transform: scale(1.01); box-shadow: var(--shadow-md); }
        .empty-state { text-align:center; padding:25px; background:var(--gray-100); border-radius:var(--radius); color:var(--gray-500); font-size:12px; border: 1px dashed var(--gray-300); margin-top: 6px; }
        .empty-state i { font-size: 24px; display: block; margin-bottom: 8px; color: var(--gray-300); }
        
        /* Timeline */
        .timeline { position: relative; padding-left: 20px; }
        .timeline::before { content: ''; position: absolute; left: 5px; top: 5px; bottom: 5px; width: 2px; background: var(--gray-300); border-radius: 2px; }
        .timeline-item { position: relative; margin-bottom: 12px; padding-left: 14px; }
        .timeline-dot { position: absolute; left: -16px; top: 5px; width: 8px; height: 8px; border-radius: 50%; border: 2px solid var(--primary); background: var(--white); z-index: 1; }
        .timeline-dot.success { border-color: var(--success); background: var(--success); }
        .timeline-dot.danger { border-color: var(--danger); background: var(--danger); }
        .timeline-card { background: var(--gray-100); border: 1px solid var(--gray-300); padding: 10px; border-radius: var(--radius); }
        .timeline-head { display: flex; justify-content: space-between; margin-bottom: 4px; }
        .timeline-role { font-weight: 700; color: var(--dark); font-size: 12px; }
        .timeline-date { font-size: 10px; color: var(--gray-500); font-weight: 500; }
        .timeline-body { font-size: 11px; color: var(--gray-700); }
        .justification-box { background: var(--white); border-left: 3px solid var(--info); padding: 8px; margin-top: 6px; border-radius: 4px; box-shadow: var(--shadow-sm); }
        .utr-box { background: var(--success-bg); border: 1px solid var(--success-border); padding: 6px 8px; margin-top: 6px; border-radius: 4px; font-size: 11px; color: #276749; display: flex; align-items: center; gap: 6px; font-weight: 600; }
        
        /* Modals */
        .alert { padding: 12px 16px; border-radius: var(--radius); margin-bottom: 20px; font-size: 13px; display: flex; align-items: center; gap: 10px; font-weight: 500; }
        .alert-success { background: var(--success-bg); color: #276749; border: 1px solid var(--success-border); }
        .alert-error { background: var(--danger-bg); color: #9b2c2c; border: 1px solid var(--danger-border); }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(2px); z-index: 1000; justify-content: center; align-items: center; }
        .modal-content { background: var(--white); border-radius: var(--radius-lg); width: 100%; max-width: 450px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); }
        .modal-header { padding: 16px 20px; border-bottom: 1px solid var(--gray-300); display: flex; justify-content: space-between; align-items: center; background: var(--gray-100); border-radius: var(--radius-lg) var(--radius-lg) 0 0; }
        .modal-title { font-size: 16px; color: var(--dark); font-weight: 700; display: flex; align-items: center; gap: 8px; }
        .modal-close { background: none; border: none; font-size: 20px; cursor: pointer; color: var(--gray-500); }
        .modal-body { padding: 20px; }
        .modal-footer { padding: 12px 20px; border-top: 1px solid var(--gray-300); display: flex; justify-content: flex-end; gap: 10px; background: var(--gray-100); border-radius: 0 0 var(--radius-lg) var(--radius-lg); }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 6px; font-size: 12px; font-weight: 600; color: var(--gray-900); }
        .form-control { width: 100%; padding: 10px 12px; border: 1px solid var(--gray-300); border-radius: var(--radius); font-size: 13px; transition: border-color 0.2s; }
        .form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(240, 93, 73, 0.1); }
        textarea.form-control { min-height: 80px; resize: vertical; }
        
        .menu-toggle { display: none; position: fixed; top: 15px; left: 15px; z-index: 101; background: var(--dark); color: var(--white); border: none; border-radius: var(--radius); padding: 10px 14px; cursor: pointer; box-shadow: var(--shadow-md); }

        @media (max-width: 1200px) { .dashboard-grid { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 992px) { .top-section { flex-direction: column; } .action-bar { flex-direction: row; width: 100%; min-width: auto; } }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.active { transform: translateX(0); }
            .main-content { margin-left: 0; padding: 60px 15px 15px; }
            .menu-toggle { display: block; }
            .dashboard-grid { grid-template-columns: 1fr; }
            .details-list { grid-template-columns: 1fr; }
            .detail-full { grid-column: span 1; }
            .progress-tracker { flex-wrap: wrap; gap: 10px; }
            .progress-tracker::before { display: none; }
            .action-bar { flex-wrap: wrap; }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <i class="fas fa-coins sidebar-logo-icon"></i>
                <div class="sidebar-logo-text">CB Account</div>
            </div>
            <div class="sidebar-user">
                <div class="sidebar-user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                <div class="sidebar-user-role"><?php echo htmlspecialchars($_SESSION['role']); ?></div>
            </div>
            <nav class="sidebar-menu">
                <a href="dashboard_<?php echo strtolower($_SESSION['role']); ?>.php" class="sidebar-menu-item"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <?php if (has_role('User')): ?>
                    <a href="index.php" class="sidebar-menu-item"><i class="fas fa-plus-circle"></i> New Request</a>
                <?php endif; ?>
                <a href="logout.php" class="sidebar-menu-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </nav>
            <div class="sidebar-footer">&copy; <?php echo date('Y'); ?> CB Account System</div>
        </aside>
        
        <main class="main-content">
            <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
            
            <div class="container">
                <!-- Header Card -->
                <header>
                    <div class="logo">
                        <i class="fas fa-file-invoice-dollar logo-icon"></i>
                        <div class="logo-text">CB Account <span>Request</span></div>
                    </div>
                    <p class="tagline">Comprehensive request details and approval workflow</p>
                    <div class="user-info">
                        <div class="user-details">
                            <div class="username"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                            <div class="user-role"><?php echo htmlspecialchars($_SESSION['role']); ?></div>
                        </div>
                        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </header>
                
                <?php if (isset($_SESSION['notification'])): ?>
                    <div class="alert alert-<?php echo $_SESSION['notification']['type']; ?>">
                        <i class="fas fa-<?php echo $_SESSION['notification']['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                        <?php echo $_SESSION['notification']['message']; ?>
                    </div>
                    <?php unset($_SESSION['notification']); ?>
                <?php endif; ?>
                
<!-- Progress & Actions -->
<div class="top-section">
    <div class="progress-tracker">
        <?php 
        $steps = ['Pending', 'Manager', 'Head', 'Validator', 'Finance'];
        
        $status_map = [
            'Pending' => 0,
            'Manager Approved' => 1,
            'Head Approved' => 2,
            'Validator Approved' => 3,
            'Finance Approved' => 4
        ];
        
        $current_step_index = isset($status_map[$request['status']]) ? $status_map[$request['status']] : -1;
        
        $rejection_step = -1;
        if ($request['status'] === 'Validator Rejected') $rejection_step = 3;
        if ($request['status'] === 'Rejected') $rejection_step = 1; 
        
        for($i=0; $i<count($steps); $i++):
            $class = '';
            
            if ($rejection_step >= 0) {
                // Rejection Flow
                if ($i < $rejection_step) {
                    $class = 'completed'; // Green for steps before rejection
                } elseif ($i == $rejection_step) {
                    $class = 'rejected';  // Red for the rejected step
                }
            } else {
                // Normal Flow
                if ($request['status'] === 'Finance Approved') {
                    $class = 'completed'; // If finance approved, all steps are green
                } else {
                    if ($i < $current_step_index) {
                        $class = 'completed'; // Previous steps (e.g., Manager, Head) -> Green ✅
                    } elseif ($i == $current_step_index) {
                        $class = 'completed'; // Current approved step (e.g., Validator) -> Green ✅ (Fixed Here!)
                    } elseif ($i == $current_step_index + 1) {
                        $class = 'active';    // Next step (e.g., Finance) -> Red ⏳ (Fixed Here!)
                    }
                }
            }
        ?>
        <div class="step <?php echo $class; ?>">
            <div class="step-dot">
                <?php if($class == 'completed'): ?>
                    <i class="fas fa-check" style="font-size:10px;"></i>
                <?php elseif($class == 'active'): ?>
                    <i class="fas fa-clock" style="font-size:9px;"></i> 
                <?php elseif($class == 'rejected'): ?>
                    <i class="fas fa-times" style="font-size:10px;"></i>
                <?php else: ?>
                    <?php echo $i+1; ?>
                <?php endif; ?>
            </div>
            <div class="step-label"><?php echo $steps[$i]; ?></div>
        </div>
        <?php endfor; ?>
    </div>
                    
                    <div class="action-bar">
                        <?php if (has_role('Manager') && $request['status'] === 'Pending'): ?>
                            <button class="btn btn-success" onclick="approveRequest()"><i class="fas fa-check"></i> Approve</button>
                            <button class="btn btn-danger" onclick="rejectRequest()"><i class="fas fa-times"></i> Reject</button>
                        <?php endif; ?>
                        <?php if (has_role('Head') && $request['status'] === 'Manager Approved'): ?>
                            <button class="btn btn-success" onclick="approveRequest()"><i class="fas fa-check"></i> Approve</button>
                            <button class="btn btn-danger" onclick="rejectRequest()"><i class="fas fa-times"></i> Reject</button>
                        <?php endif; ?>
                        <?php if (has_role('Validator') && ($request['status'] === 'Head Approved' || $validatorCanReview)): ?>
                            <button class="btn btn-success" onclick="approveRequest()"><i class="fas fa-check"></i> Approve</button>
                            <button class="btn btn-danger" onclick="rejectRequest()"><i class="fas fa-times"></i> Reject</button>
                        <?php endif; ?>
                        <?php if (has_role('Finance') && $request['status'] === 'Validator Approved'): ?>
                            <button class="btn btn-success" onclick="payRequest()"><i class="fas fa-money-check-alt"></i> Pay</button>
                            <button class="btn btn-danger" onclick="rejectRequest()"><i class="fas fa-times"></i> Reject</button>
                        <?php endif; ?>
                        <?php if ($canJustify): ?>
                            <button class="btn btn-primary" onclick="showJustificationForm()"><i class="fas fa-comment"></i> Justify</button>
                        <?php endif; ?>
                        <a href="dashboard_<?php echo strtolower($_SESSION['role']); ?>.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Dashboard</a>
                    </div>
                </div>
                
                <!-- Main Grid -->
                <div class="dashboard-grid">
                    
                    <!-- Column 1: Request & Financial -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title"><i class="fas fa-clipboard-list"></i> Request Information</div>
                            <div style="display:flex; gap:6px;">
                                <span class="badge <?php echo (strpos($request['status'], 'Rejected') !== false) ? 'badge-rejected' : (($request['status'] == 'Pending' || $request['status'] == 'Pending Validation') ? 'badge-pending' : 'badge-approved'); ?>">
                                    <?php echo htmlspecialchars($request['status']); ?>
                                </span>
                                <span class="badge badge-info"><?php echo htmlspecialchars($request['form_type']); ?></span>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="details-list">
                                    <div class="detail-group"><div class="detail-label">Reference ID</div><div class="detail-value" style="font-weight: 700; color: var(--primary);"><?php echo htmlspecialchars($request['reference_number'] ?? 'N/A'); ?></div></div>
                                <div class="detail-group"><div class="detail-label">RM Name</div><div class="detail-value"><?php echo htmlspecialchars($request['rm_name']); ?></div></div>
                                <div class="detail-group"><div class="detail-label">RM EMP ID</div><div class="detail-value"><?php echo htmlspecialchars($request['rm_emp_id']); ?></div></div>
                                <div class="detail-group"><div class="detail-label">Customer Name</div><div class="detail-value"><?php echo htmlspecialchars($request['customer_name']); ?></div></div>
                                <div class="detail-group"><div class="detail-label">Mobile Number</div><div class="detail-value"><?php echo htmlspecialchars($request['mobile_number']); ?></div></div>
                                <div class="detail-group"><div class="detail-label">Insurance Co.</div><div class="detail-value"><?php echo htmlspecialchars($request['insurance_company']); ?></div></div>
                                <div class="detail-group"><div class="detail-label">Policy Type</div><div class="detail-value"><?php echo htmlspecialchars($request['policy_type']); ?></div></div>
                                <div class="detail-group"><div class="detail-label">Month & Year</div><div class="detail-value"><?php echo htmlspecialchars($request['month'] . ' ' . $request['year']); ?></div></div>
                                <div class="detail-group"><div class="detail-label">Department</div><div class="detail-value"><?php echo htmlspecialchars($request['department']); ?></div></div>
                                <?php if ($request['form_type'] === 'Shortfall'): ?>
                                <div class="detail-group"><div class="detail-label">Policy Copy</div><div class="detail-value"><?php if (!empty($request['policy_copy_url'])): ?><a href="<?php echo htmlspecialchars($request['policy_copy_url']); ?>" target="_blank" style="color:var(--primary); font-size:12px;"><i class="fas fa-file-pdf"></i> View</a><?php else: ?> N/A <?php endif; ?></div></div>
                                <div class="detail-group"><div class="detail-label">Payment Link</div><div class="detail-value"><?php if (!empty($request['payment_link'])): ?><a href="<?php echo htmlspecialchars($request['payment_link']); ?>" target="_blank" style="color:var(--primary); font-size:12px;"><i class="fas fa-link"></i> View</a><?php else: ?> N/A <?php endif; ?></div></div>
                                <?php endif; ?>
                                <div class="detail-group detail-full"><div class="detail-label">Reason</div><div class="detail-value" style="color:var(--gray-700);"><?php $reason_parts = explode("\n\n || User Justification: ", $request['reason']); echo htmlspecialchars(trim($reason_parts[0])); ?></div></div>
                            </div>
                            
                            <div class="finance-grid">
                                <div class="finance-item"><div class="finance-label">Premium + GST</div><div class="finance-value">₹<?php echo number_format($request['premium_with_gst'], 2); ?></div></div>
                                <div class="finance-item"><div class="finance-label">Without GST</div><div class="finance-value">₹<?php echo number_format($request['without_gst'], 2); ?></div></div>
                                <div class="finance-item"><div class="finance-label">Referral Amount</div><div class="finance-value highlight">₹<?php echo number_format($request['referral_amount'], 2); ?></div></div>
                                <div class="finance-item"><div class="finance-label">Ratio</div><div class="finance-value"><?php echo ($request['without_gst'] > 0) ? round(($request['referral_amount'] / $request['without_gst']) * 100, 2) . '%' : 'N/A'; ?></div></div>
                            </div>
                        </div>
                    </div>

                    <!-- Column 2: Payment & Files -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title"><i class="fas fa-wallet"></i> Payment & Files</div>
                        </div>
                        <div class="card-body">
                            <div class="payment-type-box">
                                <span class="payment-label"><i class="fas fa-credit-card"></i> Payment Type</span>
                                <span class="payment-val"><?php echo !empty($request['user_payment_type']) ? htmlspecialchars($request['user_payment_type']) : 'N/A'; ?></span>
                            </div>
                            
                            <?php if (!empty($request['user_payment_screenshot'])): ?>
                                <div style="margin-bottom: 15px;">
                                    <div class="detail-label">Payment Proof</div>
                                    <img src="<?php echo htmlspecialchars($request['user_payment_screenshot']); ?>" alt="Payment Proof" class="img-preview" onclick="window.open('<?php echo htmlspecialchars($request['user_payment_screenshot']); ?>', '_blank')">
                                </div>
                            <?php endif; ?>

                            <div>
                                <div class="detail-label">Account Details Attachment</div>
                                <?php if (!empty($request['attachment_url'])): ?>
                                    <img src="<?php echo htmlspecialchars($request['attachment_url']); ?>" alt="Account Attachment" class="img-preview" style="max-height: 200px;" onclick="window.open('<?php echo htmlspecialchars($request['attachment_url']); ?>', '_blank')">
                                <?php else: ?>
                                    <div class="empty-state"><i class="fas fa-cloud-upload-alt"></i> No attachment uploaded</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Column 3: Approval History -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title"><i class="fas fa-history"></i> Approval Workflow</div>
                        </div>
                        <div class="card-body">
                            <div class="timeline">
                                <?php if (mysqli_num_rows($approvals_result) > 0): ?>
                                    <?php while ($approval = mysqli_fetch_assoc($approvals_result)): ?>
                                        <div class="timeline-item">
                                            <div class="timeline-dot <?php echo $approval['status'] === 'Approved' ? 'success' : 'danger'; ?>"></div>
                                            <div class="timeline-card">
                                                <div class="timeline-head">
                                                    <span class="timeline-role"><?php echo htmlspecialchars($approval['approver_role']); ?></span>
                                                    <span class="badge <?php echo $approval['status'] === 'Approved' ? 'badge-approved' : 'badge-rejected'; ?>" style="font-size:9px;"><?php echo htmlspecialchars($approval['status']); ?></span>
                                                </div>
                                                <div class="timeline-body">
                                                    <div style="color:var(--gray-500); margin-bottom:2px; font-size:10px; font-weight:600;"><i class="fas fa-user" style="width:12px;"></i> <?php echo htmlspecialchars($approval['approver_name']); ?></div>
                                                    <?php if (!empty($approval['comments'])): ?>
                                                        <div style="margin-top:4px; font-size:12px;"><i class="fas fa-comment" style="width:12px; color:var(--gray-500);"></i> <?php echo htmlspecialchars($approval['comments']); ?></div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($approval['approver_role'] === 'Validator' && $approval['status'] === 'Rejected'): ?>
                                                        <?php 
                                                        mysqli_data_seek($justifications_result, 0); 
                                                        $user_justification_text = ''; $justification_date = '';
                                                        while ($justification = mysqli_fetch_assoc($justifications_result)) {
                                                            if ($justification['approval_id'] == $approval['id']) {
                                                                $user_justification_text = htmlspecialchars($justification['justification_text']);
                                                                $justification_date = $justification['created_at'];
                                                                break; 
                                                            }
                                                        }
                                                        if (!empty($user_justification_text)):
                                                        ?>
                                                            <div class="justification-box">
                                                                <div style="font-weight:700; color:var(--info); margin-bottom:2px; font-size:10px; text-transform:uppercase;"><i class="fas fa-user"></i> User Justification</div>
                                                                <?php echo $user_justification_text; ?>
                                                                <div style="font-size:9px; color:var(--gray-500); text-align:right; margin-top:2px;"><?php echo date('d M Y, h:i A', strtotime($justification_date)); ?></div>
                                                            </div>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($approval['approver_role'] === 'Finance' && !empty($request['utr_number'])): ?>
                                                        <div class="utr-box"><i class="fas fa-receipt"></i> <strong>UTR:</strong> <?php echo htmlspecialchars($request['utr_number']); ?></div>
                                                        <?php if (!empty($request['payment_screenshot_url'])): ?>
                                                            <img src="<?php echo htmlspecialchars($request['payment_screenshot_url']); ?>" alt="Payment" class="img-preview" style="max-height:70px; margin-top:5px;" onclick="window.open('<?php echo htmlspecialchars($request['payment_screenshot_url']); ?>', '_blank')">
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="timeline-date"><?php echo date('d M Y, h:i A', strtotime($approval['created_at'])); ?></div>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <div class="empty-state"><i class="fas fa-hourglass-start"></i> Awaiting first action</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modals -->
    <div id="approveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-check-circle" style="color:var(--success)"></i> Approve Request</h3>
                <button class="modal-close" onclick="closeModal('approveModal')">&times;</button>
            </div>
            <form action="approve_request.php?id=<?php echo $request_id; ?>" method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Comments (Optional)</label>
                        <textarea name="comments" class="form-control" placeholder="Add any remarks..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('approveModal')">Cancel</button>
                    <button type="submit" class="btn btn-success">Confirm Approve</button>
                </div>
            </form>
        </div>
    </div>

    <div id="payModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-money-check-alt" style="color:var(--success)"></i> Process Payment</h3>
                <button class="modal-close" onclick="closeModal('payModal')">&times;</button>
            </div>
            <form action="approve_request.php?id=<?php echo $request_id; ?>" method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="form-group">
                        <label>UTR Number <span style="color:var(--danger)">*</span></label>
                        <input type="text" name="utr_number" class="form-control" placeholder="Enter UTR/Transaction Number" required>
                    </div>
                    <div class="form-group">
                        <label>Payment Screenshot</label>
                        <input type="file" name="payment_screenshot" class="form-control" accept="image/*">
                    </div>
                    <div class="form-group">
                        <label>Comment <span style="color:var(--danger)">*</span></label>
                        <textarea name="comments" class="form-control" placeholder="Add payment remarks..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('payModal')">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Confirm Payment</button>
                </div>
            </form>
        </div>
    </div>

    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-times-circle" style="color:var(--danger)"></i> Reject Request</h3>
                <button class="modal-close" onclick="closeModal('rejectModal')">&times;</button>
            </div>
            <form action="reject_request.php?id=<?php echo $request_id; ?>" method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Reason for Rejection <span style="color:var(--danger)">*</span></label>
                        <textarea name="comments" class="form-control" placeholder="Provide rejection reason..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('rejectModal')">Cancel</button>
                    <button type="submit" class="btn btn-danger">Confirm Reject</button>
                </div>
            </form>
        </div>
    </div>

    <div id="justificationModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-comment-dots" style="color:var(--info)"></i> Provide Justification</h3>
                <button class="modal-close" onclick="closeModal('justificationModal')">&times;</button>
            </div>
            <form action="submit_justification.php?id=<?php echo $request_id; ?>" method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Your Justification <span style="color:var(--danger)">*</span></label>
                        <textarea name="justification_text" class="form-control" placeholder="Explain why this should be reconsidered..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModal('justificationModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Submit</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });

        function approveRequest() { document.getElementById('approveModal').style.display = 'flex'; }
        function rejectRequest() { document.getElementById('rejectModal').style.display = 'flex'; }
        function payRequest() { document.getElementById('payModal').style.display = 'flex'; }
        function showJustificationForm() { document.getElementById('justificationModal').style.display = 'flex'; }
        function closeModal(id) { document.getElementById(id).style.display = 'none'; }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>