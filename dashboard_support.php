<?php
// Assume config.php exists and connects to $conn
if (file_exists('config.php')) {
    require_once 'config.php';
} else {
    die("Error: config.php not found.");
}

// 1. ACCESS CONTROL
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Support') {
    $_SESSION['notification'] = ['message' => 'You do not have permission to access this page', 'type' => 'error'];
    header("Location: login.php");
    exit;
}

 $logged_in_support_id = $_SESSION['user_id'];
 $currentTab = isset($_GET['tab']) ? $_GET['tab'] : 'cashback';

// 2. HANDLE AJAX REQUEST FOR HISTORY
if (isset($_GET['action']) && $_GET['action'] == 'get_history' && isset($_GET['id']) && isset($_GET['type'])) {
    header('Content-Type: application/json');
    $id = intval($_GET['id']);
    $type = $_GET['type'];
    $history = [];
    try {
        if ($type == 'cashback') {
            $sql = "SELECT a.approver_role as role, u.full_name as name, a.status, a.comments, a.created_at as date 
                    FROM approvals a 
                    JOIN users u ON a.approver_id = u.id 
                    WHERE a.request_id = ? 
                    ORDER BY a.created_at ASC";
        } elseif ($type == 'sales') {
            $sql = "SELECT a.approver_role as role, u.full_name as name, a.status, a.comments, a.created_at as date 
                    FROM approvals_sales a 
                    JOIN users u ON a.approver_id = u.id 
                    WHERE a.sales_request_id = ? 
                    ORDER BY a.created_at ASC";
        } else {
            echo json_encode(['error' => 'Invalid type']);
            exit;
        }
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $id);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            while ($r = mysqli_fetch_assoc($res)) {
                $history[] = $r;
            }
            mysqli_stmt_close($stmt);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
    echo json_encode($history);
    exit;
}

// 3. DATA FETCHING (MAIN PAGE LOAD)
 $cashbackData = [];
 $salesData = [];

 $cb_sql = "SELECT cr.id, cr.reference_number, cr.form_type, cr.rm_name, cr.rm_emp_id, 
                 cr.department, cr.customer_name, cr.mobile_number, cr.premium_with_gst, 
                 cr.referral_amount, cr.status, cr.created_at, cr.attachment_url
          FROM cashback_requests cr 
          ORDER BY cr.created_at DESC";
 $cb_result = mysqli_query($conn, $cb_sql);
if ($cb_result) {
    while ($row = mysqli_fetch_assoc($cb_result)) {
        $cashbackData[] = $row;
    }
}

 $sales_sql = "SELECT sr.id, sr.reference_number, sr.rm_name, sr.name as customer_name, 
                     sr.mobile_no as mobile_number, sr.premium, sr.premium_wo_gst, 
                     sr.vehicle_number, sr.make, sr.model, sr.status, sr.created_at
              FROM sales_requests sr 
              ORDER BY sr.created_at DESC";
 $sales_result = mysqli_query($conn, $sales_sql);
if ($sales_result) {
    while ($row = mysqli_fetch_assoc($sales_result)) {
        $salesData[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Audit Dashboard | CB Account</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        /* --- DASHBOARD STYLES --- */
        :root {
            --primary: #f05d49;
            --primary-dark: #d84c38;
            --primary-light: #ff7d6a;
            --dark: #1a202c;
            --light: #ffffff;
            --gray-bg: #f7fafc;
            --gray-border: #e2e8f0;
            --text-main: #2d3748;
            --text-muted: #718096;
            --success: #38a169;
            --success-bg: #f0fff4;
            --warning: #d69e2e;
            --warning-bg: #fffff0;
            --danger: #e53e3e;
            --danger-bg: #fff5f5;
            --info: #3182ce;
            --info-bg: #ebf8ff;
            --purple: #805ad5;
            --purple-bg: #faf5ff;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --radius: 8px;
            --sidebar-width: 260px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background-color: var(--gray-bg); color: var(--text-main); height: 100vh; display: flex; overflow: hidden; }

        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--dark);
            color: var(--light);
            display: flex;
            flex-direction: column;
            transition: transform 0.3s ease;
            z-index: 50;
        }
        .sidebar-header { padding: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; gap: 12px; }
        .brand-icon { color: var(--primary); font-size: 24px; }
        .brand-text { font-size: 18px; font-weight: 700; letter-spacing: 0.5px; }
        .user-profile { padding: 20px; background: rgba(255,255,255,0.05); display: flex; align-items: center; gap: 12px; }
        .avatar { width: 40px; height: 40px; border-radius: 50%; background-color: var(--primary); display: flex; align-items: center; justify-content: center; font-weight: 600; }
        .user-info h4 { font-size: 14px; font-weight: 600; }
        .user-info p { font-size: 12px; color: var(--text-muted); display: flex; align-items: center; gap: 5px; }
        .badge-audit { background: var(--purple); color: white; padding: 2px 6px; border-radius: 4px; font-size: 10px; text-transform: uppercase; font-weight: 700; }
        .nav-menu { flex: 1; padding: 20px 0; overflow-y: auto; }
        .nav-item { padding: 12px 24px; display: flex; align-items: center; gap: 12px; color: #a0aec0; text-decoration: none; transition: all 0.2s; cursor: pointer; border-left: 3px solid transparent; }
        .nav-item:hover, .nav-item.active { background: rgba(255,255,255,0.05); color: var(--light); }
        .nav-item.active { border-left-color: var(--primary); }
        .nav-item i { width: 20px; text-align: center; }

        /* Main Content */
        .main-content { flex: 1; display: flex; flex-direction: column; overflow: hidden; position: relative; }
        .top-bar { height: 64px; background: var(--light); border-bottom: 1px solid var(--gray-border); display: flex; align-items: center; justify-content: space-between; padding: 0 30px; }
        .toggle-btn { display: none; background: none; border: none; font-size: 20px; cursor: pointer; color: var(--text-main); }
        .search-box { position: relative; width: 300px; }
        .search-box input { width: 100%; padding: 8px 12px 8px 40px; border: 1px solid var(--gray-border); border-radius: var(--radius); font-size: 14px; outline: none; transition: border-color 0.2s; }
        .search-box input:focus { border-color: var(--primary); }
        .search-box i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); }
        .content-scroll { flex: 1; padding: 30px; overflow-y: auto; }
        .dashboard-header { margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; }
        .dashboard-title { font-size: 24px; font-weight: 700; color: var(--dark); }
        .dashboard-subtitle { color: var(--text-muted); font-size: 14px; margin-top: 4px; }

        /* Tabs */
        .tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 1px solid var(--gray-border); padding-bottom: 1px; }
        .tab { padding: 10px 20px; background: none; border: none; font-weight: 500; color: var(--text-muted); cursor: pointer; border-bottom: 2px solid transparent; transition: all 0.2s; }
        .tab:hover { color: var(--primary); }
        .tab.active { color: var(--primary); border-bottom-color: var(--primary); }

        /* Table */
        .table-container { background: var(--light); border-radius: var(--radius); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-border); overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 900px; }
        th { background-color: #f8fafc; text-align: left; padding: 12px 24px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); font-weight: 600; border-bottom: 1px solid var(--gray-border); }
        td { padding: 16px 24px; border-bottom: 1px solid var(--gray-border); font-size: 14px; color: var(--text-main); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background-color: #f7fafc; }

        /* Status Pills */
        .status-pill { display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
        .status-pending { background: var(--warning-bg); color: var(--warning); }
        .status-approved, .status-manager-approved, .status-head-approved, .status-verified { background: var(--info-bg); color: var(--info); }
        .status-paid, .status-finance-approved { background: var(--success-bg); color: var(--success); }
        .status-rejected, .status-validator-rejected { background: var(--danger-bg); color: var(--danger); }
        .status-validation { background: var(--purple-bg); color: var(--purple); }

        /* Buttons */
        .btn-sm { padding: 6px 12px; font-size: 12px; border-radius: 4px; border: 1px solid var(--gray-border); background: white; cursor: pointer; transition: all 0.2s; color: var(--text-main); text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-sm:hover { background: #f7fafc; border-color: var(--text-muted); }
        .btn-sm.primary { background: var(--primary); color: white; border: none; }
        .btn-sm.primary:hover { background: var(--primary-dark); }

        /* Modal */
        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            display: none; justify-content: center; align-items: center;
            z-index: 1000;
            backdrop-filter: blur(2px);
        }
        .modal-overlay.active { display: flex; animation: fadeIn 0.2s ease; }
        .modal {
            background: var(--light);
            width: 95%; max-width: 1100px; /* Increased width for better view */
            height: 90vh; /* Fixed height */
            border-radius: 12px;
            box-shadow: var(--shadow-md);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .modal-header { padding: 15px 20px; border-bottom: 1px solid var(--gray-border); display: flex; justify-content: space-between; align-items: center; background: #f8fafc; flex-shrink: 0; }
        .modal-title { font-size: 18px; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 10px; }
        .modal-close { background: none; border: none; font-size: 20px; cursor: pointer; color: var(--text-muted); }
        .modal-body { 
            padding: 0; 
            overflow-y: auto; 
            flex: 1; 
            position: relative;
            background: #fff; /* Ensure white background */
        }
        
        /* Modal Loading Spinner */
        .loading-spinner {
            border: 3px solid #f3f3f3; border-top: 3px solid var(--primary); border-radius: 50%;
            width: 40px; height: 40px; animation: spin 1s linear infinite; display: inline-block;
        }
        
        /* SCOPED WRAPPER: This ensures injected styles don't break dashboard */
        .cb-view-wrapper {
            width: 100%;
            height: 100%;
            /* Reset potentially dangerous styles from fetched CSS */
            font-family: 'Inter', sans-serif !important;
            color: #2d3748 !important;
            background: #fff !important;
            overflow-y: auto;
            padding: 20px;
            box-sizing: border-box;
        }
        /* Prevent body margin/padding in wrapper */
        .cb-view-wrapper * {
            box-sizing: border-box;
        }

        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        @media (max-width: 768px) {
            .sidebar { position: absolute; height: 100%; transform: translateX(-100%); }
            .sidebar.active { transform: translateX(0); }
            .toggle-btn { display: block; }
            .search-box { display: none; }
            .modal { width: 100%; height: 100%; border-radius: 0; }
            .modal-header { padding: 10px 15px; }
        }
    </style>
</head>
<body>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-coins brand-icon"></i>
            <div class="brand-text">CB Account</div>
        </div>
        <div class="user-profile">
            <div class="avatar">SU</div>
            <div class="user-info">
                <h4><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Support User'); ?></h4>
                <p><span class="badge-audit">Audit Access</span></p>
            </div>
        </div>
        <nav class="nav-menu">
            <a href="dashboard_support.php?tab=<?php echo $currentTab; ?>" class="nav-item active">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            <a href="logout.php" class="nav-item">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <header class="top-bar">
            <button class="toggle-btn" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Search by Ref, Name, or Mobile..." onkeyup="filterTable()">
            </div>
            <div style="font-size: 14px; font-weight: 500; color: var(--text-muted);">
                <i class="far fa-clock"></i> <span id="currentTime"></span>
            </div>
        </header>

        <div class="content-scroll">
            <div class="dashboard-header">
                <div>
                    <h1 class="dashboard-title">Audit Overview</h1>
                    <p class="dashboard-subtitle">Monitoring Cashback and Sales Request Activity</p>
                </div>
                <button class="btn-sm primary" onclick="location.reload()"><i class="fas fa-sync-alt"></i> Refresh</button>
            </div>

            <div class="tabs">
                <button class="tab <?php echo $currentTab == 'cashback' ? 'active' : ''; ?>" onclick="switchTab('cashback')">Cashback Requests</button>
              <!--  <button class="tab <?php echo $currentTab == 'sales' ? 'active' : ''; ?>" onclick="switchTab('sales')">Sales Requests</button> -->
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 25px;">
                <div class="status-pill" style="justify-content:center; padding:15px; background:var(--light); border:1px solid var(--gray-border);">
                    <div style="font-size:12px; color:var(--text-muted);">Total Requests</div>
                    <div style="font-size:20px; font-weight:700; color:var(--dark);" id="stat-total">0</div>
                </div>
                <div class="status-pill" style="justify-content:center; padding:15px; background:var(--light); border:1px solid var(--gray-border);">
                    <div style="font-size:12px; color:var(--text-muted);">Pending Action</div>
                    <div style="font-size:20px; font-weight:700; color:var(--warning);" id="stat-pending">0</div>
                </div>
                <div class="status-pill" style="justify-content:center; padding:15px; background:var(--light); border:1px solid var(--gray-border);">
                    <div style="font-size:12px; color:var(--text-muted);">Approved/Paid</div>
                    <div style="font-size:20px; font-weight:700; color:var(--success);" id="stat-approved">0</div>
                </div>
                <div class="status-pill" style="justify-content:center; padding:15px; background:var(--light); border:1px solid var(--gray-border);">
                    <div style="font-size:12px; color:var(--text-muted);">Rejected</div>
                    <div style="font-size:20px; font-weight:700; color:var(--danger);" id="stat-rejected">0</div>
                </div>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr id="table-head"></tr>
                    </thead>
                    <tbody id="table-body"></tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Modal -->
    <div class="modal-overlay" id="detailModal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-title">
                    <i class="fas fa-file-invoice-dollar" style="color: var(--primary);"></i>
                    <span id="modalRefNum">REF-000</span>
                    <span class="status-pill" id="modalStatus">Status</span>
                </div>
                <button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body" id="modalContent">
                <!-- Content injected via JS -->
            </div>
        </div>
    </div>

    <!-- Data for JS -->
    <script>
        const cashbackData = <?php echo json_encode($cashbackData, JSON_UNESCAPED_UNICODE); ?>;
        const salesData = <?php echo json_encode($salesData, JSON_UNESCAPED_UNICODE); ?>;
        let currentType = '<?php echo $currentTab; ?>';
        let currentData = (currentType === 'cashback') ? cashbackData : salesData;
        const tableHead = document.getElementById('table-head');
        const tableBody = document.getElementById('table-body');
        const searchInput = document.getElementById('searchInput');
        const modal = document.getElementById('detailModal');
        const modalTitle = document.getElementById('modalRefNum');
        const modalStatus = document.getElementById('modalStatus');
        const modalContent = document.getElementById('modalContent');

        window.addEventListener('DOMContentLoaded', () => {
            renderTable();
            updateStats();
            updateTime();
            setInterval(updateTime, 60000);
            tableBody.addEventListener('click', function(e) {
                const button = e.target.closest('.view-btn');
                if (button) {
                    e.preventDefault();
                    const id = button.getAttribute('data-id');
                    openSmartView(id);
                }
            });
        });

        function updateTime() {
            const now = new Date();
            document.getElementById('currentTime').innerText = now.toLocaleString('en-US', { hour: '2-digit', minute: '2-digit' });
        }

        function toggleSidebar() { document.getElementById('sidebar').classList.toggle('active'); }
        function switchTab(type) { window.location.href = `dashboard_support.php?tab=${type}`; }

        function renderTable() {
            tableHead.innerHTML = '';
            tableBody.innerHTML = '';
            if (currentType === 'cashback') {
                tableHead.innerHTML = `<th>Reference</th><th>RM Name</th><th>Customer</th><th>Amount (₹)</th><th>Status</th><th>Date</th><th>Action</th>`;
            } else {
                tableHead.innerHTML = `<th>Reference</th><th>RM Name</th><th>Vehicle/Make</th><th>Premium (₹)</th><th>Status</th><th>Date</th><th>Action</th>`;
            }
            if (currentData.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding: 20px;">No records found.</td></tr>';
                return;
            }
            currentData.forEach(item => {
                const tr = document.createElement('tr');
                let statusClass = 'status-pending';
                const statusLower = item.status.toLowerCase();
                if (statusLower.includes('approved') || statusLower.includes('verified')) statusClass = 'status-approved';
                if (statusLower.includes('paid') || statusLower.includes('finance')) statusClass = 'status-paid';
                if (statusLower.includes('rejected')) statusClass = 'status-rejected';
                if (statusLower.includes('validation')) statusClass = 'status-validation';
                const btnHtml = `<button class="btn-sm primary view-btn" data-id="${item.id}"><i class="fas fa-eye"></i> View</button>`;
                if (currentType === 'cashback') {
                    tr.innerHTML = `<td><strong>${item.reference_number}</strong><br><span style="font-size:11px; color:gray;">${item.form_type}</span></td><td><div>${item.rm_name}</div><div style="font-size:11px; color:#718096;">${item.rm_emp_id}</div></td><td>${item.customer_name}<br><span style="font-size:11px;">${item.mobile_number}</span></td><td><strong>₹${parseFloat(item.referral_amount).toLocaleString('en-IN')}</strong></td><td><span class="status-pill ${statusClass}">${item.status}</span></td><td>${item.created_at.split(' ')[0]}</td><td>${btnHtml}</td>`;
                } else {
                    tr.innerHTML = `<td><strong>${item.reference_number}</strong></td><td>${item.rm_name}</td><td>${item.make} ${item.model}<br><span style="font-size:11px;">${item.vehicle_number}</span></td><td><strong>₹${parseFloat(item.premium).toLocaleString('en-IN')}</strong></td><td><span class="status-pill ${statusClass}">${item.status}</span></td><td>${item.created_at.split(' ')[0]}</td><td>${btnHtml}</td>`;
                }
                tableBody.appendChild(tr);
            });
        }

        function updateStats() {
            const total = currentData.length;
            const pending = currentData.filter(i => i.status.toLowerCase().includes('pending') || i.status.toLowerCase().includes('validation')).length;
            const approved = currentData.filter(i => i.status.toLowerCase().includes('approved') || i.status.toLowerCase().includes('paid') || i.status.toLowerCase().includes('verified')).length;
            const rejected = currentData.filter(i => i.status.toLowerCase().includes('rejected')).length;
            document.getElementById('stat-total').innerText = total;
            document.getElementById('stat-pending').innerText = pending;
            document.getElementById('stat-approved').innerText = approved;
            document.getElementById('stat-rejected').innerText = rejected;
        }

        function filterTable() {
            const query = searchInput.value.toLowerCase();
            const rows = tableBody.getElementsByTagName('tr');
            for (let i = 0; i < rows.length; i++) {
                rows[i].style.display = rows[i].innerText.toLowerCase().includes(query) ? "" : "none";
            }
        }

        // --- SCOPED SMART VIEW ---
        function openSmartView(id) {
            modal.classList.add('active');
            modalTitle.innerText = "Loading...";
            modalStatus.style.display = 'none';
            
            modalContent.innerHTML = `
                <div style="display:flex; justify-content:center; align-items:center; height:300px; flex-direction:column;">
                    <div class="loading-spinner"></div>
                    <p style="margin-top:15px; color:#718096;">Fetching full details...</p>
                </div>`;

            let viewUrl = (currentType === 'cashback') 
                ? `view_request.php?id=${id}` 
                : `view_sales_request.php?id=${id}`;

            fetch(viewUrl)
                .then(response => {
                    if (!response.ok) throw new Error('Page not found');
                    return response.text();
                })
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    
                    // 1. Extract CSS
                    const fetchedStyles = doc.querySelectorAll('style');
                    let scopedCSS = '';
                    fetchedStyles.forEach(style => {
                        // Prefix all selectors with .cb-view-wrapper
                        // Simple regex to prefix rules (works for most standard CSS)
                        let css = style.innerHTML;
                        // Replace body and html selectors with .cb-view-wrapper
                        css = css.replace(/(body|html)/g, '.cb-view-wrapper');
                        // Replace direct element selectors (e.g., .class, div) - careful with complex selectors
                        // We will append .cb-view-wrapper to the start of rules roughly
                        const rules = css.split('}');
                        rules.forEach(rule => {
                            if(rule.trim() !== '') {
                                // Heuristic: If it doesn't contain .cb-view-wrapper yet, prepend it
                                if(!rule.includes('.cb-view-wrapper') && !rule.includes('@')) {
                                     scopedCSS += '.cb-view-wrapper ' + rule + '}';
                                } else {
                                     scopedCSS += rule + '}';
                                }
                            }
                        });
                    });

                    // 2. Extract Content
                    const mainContent = doc.querySelector('main.main-content');
                    const containerDiv = mainContent ? mainContent.querySelector('.container') : null;

                    if (containerDiv) {
                        modalContent.innerHTML = '';
                        
                        // Inject Scoped CSS
                        const styleBlock = document.createElement('style');
                        styleBlock.innerHTML = scopedCSS;
                        modalContent.appendChild(styleBlock);

                        // Create Wrapper
                        const wrapper = document.createElement('div');
                        wrapper.className = 'cb-view-wrapper';
                        wrapper.innerHTML = containerDiv.innerHTML;

                        // 3. RESTRICTIONS
                        // Remove Action Buttons
                        const actionButtons = wrapper.querySelector('.action-buttons');
                        if (actionButtons) actionButtons.remove();
                        
                        // Remove Modals
                        const modals = wrapper.querySelectorAll('.modal');
                        modals.forEach(m => m.remove());
                        
                        // Remove Menu Toggle
                        const menuToggle = wrapper.querySelector('.menu-toggle');
                        if(menuToggle) menuToggle.remove();

                        // Update Header Info
                        const refSpan = wrapper.querySelector('.request-number');
                        if(refSpan) modalTitle.innerText = refSpan.innerText.replace('Ref: ', '');

                        const statusBadge = wrapper.querySelector('.status-badge');
                        if (statusBadge) {
                            modalStatus.innerText = statusBadge.innerText;
                            modalStatus.className = 'status-pill ' + statusBadge.className.replace('status-badge', '').trim();
                            modalStatus.style.display = 'inline-flex';
                        }

                        modalContent.appendChild(wrapper);
                    } else {
                        modalContent.innerHTML = `<div style="padding:20px; text-align:center; color:red;">Error: Content structure not found.</div>`;
                    }
                })
                .catch(err => {
                    console.error(err);
                    modalContent.innerHTML = `<div style="padding:20px; text-align:center;">Error loading view. Ensure <strong>${viewUrl}</strong> exists.</div>`;
                });
        }

        function closeModal() {
            modal.classList.remove('active');
            modalStatus.style.display = 'inline-flex';
            setTimeout(() => { modalContent.innerHTML = ''; }, 300);
        }

        modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
    </script>
</body>
</html>