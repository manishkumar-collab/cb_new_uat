<?php
// Error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include required files - try different paths for config.php
 $configFound = false;
 $configPaths = [
    '../config.php',
    '../../config.php',
    __DIR__ . '/../config.php',
    __DIR__ . '/../../config.php',
    '/var/www/html/cb_new_uat/config.php'
];

foreach ($configPaths as $configPath) {
    if (file_exists($configPath)) {
        try {
            require_once $configPath;
            $configFound = true;
            break;
        } catch (Exception $e) {
            error_log("Error including config file at $configPath: " . $e->getMessage());
        }
    }
}

// If config not found, create a minimal database connection
if (!$configFound) {
    try {
        // Try to connect with default settings
        $pdo = new PDO('mysql:host=localhost;dbname=visitor_db', 'root', '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        $pdo = null;
    }
}

// Initialize variables
 $isLoggedIn = false;
 $userRole = null;

// Check if user is logged in
if (isset($_SESSION['user_id'])) {
    $isLoggedIn = true;
    if (isset($_SESSION['user_role'])) {
        $userRole = $_SESSION['user_role'];
    }
}

// Initialize variables
 $sales_date = date('Y-m-d'); // Default to today
 $sales_amount = '';
 $comments = '';
 $error = '';
 $success = '';

// Process form submission only if user is logged in
if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $sales_date = isset($_POST['sales_date']) ? $_POST['sales_date'] : '';
    $sales_amount = isset($_POST['sales_amount']) ? $_POST['sales_amount'] : '';
    $comments = isset($_POST['comments']) ? $_POST['comments'] : '';
    
    // Validate form data
    if (empty($sales_date)) {
        $error = 'Please select a sales date';
    } elseif (empty($sales_amount) || !is_numeric($sales_amount) || $sales_amount <= 0) {
        $error = 'Please enter a valid sales amount';
    } else {
        try {
            // Check if database connection is available
            if (!isset($pdo) || !$pdo) {
                throw new Exception("Database connection not available");
            }
            
            // Generate reference number
            if (!function_exists('generateSalesReferenceNumber')) {
                // Define the function if it doesn't exist
                function generateSalesReferenceNumber($pdo) {
                    // Get current date in YYMMDD format
                    $datePart = date('ymd');
                    
                    // Prefix for sales requests
                    $prefix = 'SA';
                    
                    // Query to count sales requests for today
                    $query = "SELECT COUNT(*) as count FROM sales_requests 
                              WHERE DATE(created_at) = CURDATE() 
                              AND reference_number LIKE :prefix_pattern";
                    
                    $stmt = $pdo->prepare($query);
                    $stmt->execute([':prefix_pattern' => $prefix . '-' . $datePart . '%']);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Get the count and increment by 1 for the new reference
                    $sequence = $result['count'] + 1;
                    
                    // Format the sequence number with leading zeros (3 digits)
                    $sequencePart = str_pad($sequence, 3, '0', STR_PAD_LEFT);
                    
                    // Combine all parts to create the reference number
                    $referenceNumber = $prefix . '-' . $datePart . '-' . $sequencePart;
                    
                    return $referenceNumber;
                }
            }
            
            $reference_number = generateSalesReferenceNumber($pdo);
            
            // Get current user ID
            $user_id = $_SESSION['user_id'];
            
            // Insert sales request into database
            $stmt = $pdo->prepare("INSERT INTO sales_requests (reference_number, user_id, sales_date, sales_amount, comments, status) 
                                  VALUES (?, ?, ?, ?, ?, 'Pending')");
            $result = $stmt->execute([$reference_number, $user_id, $sales_date, $sales_amount, $comments]);
            
            if ($result) {
                // Show success message
                $success = 'Sales request submitted successfully with reference number: ' . $reference_number;
                
                // Reset form fields
                $sales_date = date('Y-m-d');
                $sales_amount = '';
                $comments = '';
            } else {
                $error = 'Failed to submit sales request. Please try again.';
            }
            
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        } catch (Exception $e) {
            $error = 'Error submitting sales request: ' . $e->getMessage();
        }
    }
}

// Get current user information if logged in
 $user = ['full_name' => 'User'];
if ($isLoggedIn && isset($pdo) && $pdo) {
    try {
        $user_id = $_SESSION['user_id'];
        $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($userData) {
            $user = $userData;
        }
    } catch (Exception $e) {
        error_log("Error getting user info: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Sales - CoverYou Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .sidebar {
            min-height: 100vh;
            background-color: #f8f9fa;
        }
        .main-content {
            padding: 20px;
        }
        .card {
            margin-bottom: 20px;
            border: none;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .card-header {
            background-color: #0d6efd;
            color: white;
            font-weight: bold;
        }
        .btn-primary {
            background-color: #0d6efd;
            border-color: #0d6efd;
        }
        .btn-primary:hover {
            background-color: #0b5ed7;
            border-color: #0a58ca;
        }
        .table th {
            background-color: #f8f9fa;
        }
        .alert {
            margin-bottom: 20px;
        }
        .login-prompt {
            text-align: center;
            padding: 50px 20px;
        }
        .login-prompt i {
            font-size: 4rem;
            color: #0d6efd;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <?php 
            if ($isLoggedIn) {
                try {
                    if (file_exists('includes/sidebar.php')) {
                        include 'includes/sidebar.php';
                    } else {
                        echo '<div class="col-md-3 sidebar"><div class="p-3"><h5>Navigation</h5><ul class="nav flex-column"><li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li><li class="nav-item"><a class="nav-link active" href="submit_sales.php">Submit Sales</a></li><li class="nav-item"><a class="nav-link" href="sales_history.php">Sales History</a></li><li class="nav-item"><a class="nav-link" href="logout.php">Logout</a></li></ul></div></div>';
                    }
                } catch (Exception $e) {
                    echo '<div class="col-md-3 sidebar"><div class="p-3"><h5>Navigation</h5><ul class="nav flex-column"><li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li><li class="nav-item"><a class="nav-link active" href="submit_sales.php">Submit Sales</a></li><li class="nav-item"><a class="nav-link" href="sales_history.php">Sales History</a></li><li class="nav-item"><a class="nav-link" href="logout.php">Logout</a></li></ul></div></div>';
                    error_log("Error including sidebar: " . $e->getMessage());
                }
            } else {
                echo '<div class="col-md-3 sidebar"><div class="p-3"><h5>Navigation</h5><ul class="nav flex-column"><li class="nav-item"><a class="nav-link" href="login.php">Login</a></li></ul></div></div>';
            }
            ?>
            
            <!-- Main Content -->
            <div class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                <?php if ($isLoggedIn): ?>
                    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                        <h1 class="h2">Submit Sales</h1>
                        <div class="btn-toolbar mb-2 mb-md-0">
                            <div class="btn-group me-2">
                                <a href="sales_history.php" class="btn btn-sm btn-outline-secondary">View Sales History</a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Display notifications -->
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>
                    
                    <?php if (!empty($success)): ?>
                        <div class="alert alert-success"><?php echo $success; ?></div>
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-8">
                            <div class="card">
                                <div class="card-header">
                                    <i class="bi bi-cart-plus me-2"></i>Sales Information
                                </div>
                                <div class="card-body">
                                    <form method="post" action="">
                                        <div class="mb-3">
                                            <label for="sales_date" class="form-label">Sales Date</label>
                                            <input type="date" class="form-control" id="sales_date" name="sales_date" value="<?php echo $sales_date; ?>" required>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="sales_amount" class="form-label">Sales Amount (₹)</label>
                                            <input type="number" class="form-control" id="sales_amount" name="sales_amount" value="<?php echo $sales_amount; ?>" step="0.01" min="0" required>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="comments" class="form-label">Comments (Optional)</label>
                                            <textarea class="form-control" id="comments" name="comments" rows="3"><?php echo $comments; ?></textarea>
                                        </div>
                                        
                                        <button type="submit" class="btn btn-primary">Submit Sales</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="card">
                                <div class="card-header">
                                    <i class="bi bi-info-circle me-2"></i>Instructions
                                </div>
                                <div class="card-body">
                                    <ul>
                                        <li>Select the date for which you are submitting sales</li>
                                        <li>Enter the total sales amount for that day</li>
                                        <li>Add any relevant comments if needed</li>
                                        <li>After submission, your manager will verify the sales</li>
                                        <li>Once verified by the head, the amount will be added to your account</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="login-prompt">
                        <i class="bi bi-lock"></i>
                        <h2>Login Required</h2>
                        <p class="mb-4">You need to login to submit sales information.</p>
                        <a href="login.php" class="btn btn-primary btn-lg">
                            <i class="bi bi-box-arrow-in-right me-2"></i>Login to Your Account
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>