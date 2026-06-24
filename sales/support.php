<?php
// /var/www/html/cb_new_uat/sales/support.php

require_once 'functions.php';

if (!isLoggedIn() || $_SESSION['user_role'] !== 'Support') {
    redirect('../login.php', 'You do not have permission to access this page.');
}

 $userDetails = getUserDetails($_SESSION['user_id']);
 $userId = $userDetails['id'];

 $salesData = [];
 $sql = "SELECT sr.*, u.full_name as user_name FROM sales_requests sr JOIN users u ON sr.user_id = u.id WHERE u.support_id = ? ORDER BY sr.created_at DESC";
 $stmt = $conn->prepare($sql);
 $stmt->bind_param("i", $userId);
 $stmt->execute();
 $result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $salesData[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support - Sales Module</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style> body { background-color: #f8f9fa; } .container { max-width: 1200px; } .card { margin-bottom: 20px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); } .card-header { background-color: #fd7e14; color: white; } </style>
</head>
<body>
    <div class="container mt-5">
        <h2 class="mb-4">Support - Your Team's Sales</h2>
        <?php displayMessage(); ?>
        <div class="card">
            <div class="card-header"><h4>Sales Requests from Your Mapped Users</h4></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="thead-dark">
                            <tr><th>Ref. Number</th><th>Sales Person</th><th>Date</th><th>Customer Name</th><th>Premium</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($salesData)): ?>
                                <tr><td colspan="6" class="text-center">No sales records found for your team.</td></tr>
                            <?php else: ?>
                                <?php foreach ($salesData as $sale): ?>
                                    <tr>
                                        <td><?php echo $sale['reference_number']; ?></td>
                                        <td><?php echo htmlspecialchars($sale['user_name']); ?></td>
                                        <td><?php echo date('d-m-Y', strtotime($sale['date'])); ?></td>
                                        <td><?php echo htmlspecialchars($sale['name']); ?></td>
                                        <td><?php echo number_format($sale['premium'], 2); ?></td>
                                        <td>
                                            <?php
                                            $statusClass = '';
                                            if ($sale['status'] == 'Pending') $statusClass = 'badge-warning';
                                            elseif ($sale['status'] == 'Manager Verified') $statusClass = 'badge-info';
                                            elseif ($sale['status'] == 'Head Paid') $statusClass = 'badge-success';
                                            elseif ($sale['status'] == 'Rejected') $statusClass = 'badge-danger';
                                            echo '<span class="badge ' . $statusClass . '">' . htmlspecialchars($sale['status']) . '</span>';
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>