<?php
// /var/www/html/cb_new_uat/sales/finance.php

require_once 'functions.php';

if (!isLoggedIn() || $_SESSION['user_role'] !== 'Finance') {
    redirect('../login.php', 'You do not have permission to access this page.');
}

 $salesData = [];
 $sql = "SELECT sr.*, u.full_name as user_name, u.department FROM sales_requests sr JOIN users u ON sr.user_id = u.id ORDER BY sr.created_at DESC";
 $stmt = $conn->prepare($sql);
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
    <title>Finance - Sales Module</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style> body { background-color: #f8f9fa; } .container { max-width: 1400px; } .card { margin-bottom: 20px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); } .card-header { background-color: #17a2b8; color: white; } </style>
</head>
<body>
    <div class="container mt-5">
        <h2 class="mb-4">Finance - All Sales Records</h2>
        <?php displayMessage(); ?>
        <div class="card">
            <div class="card-header"><h4>Complete Sales Overview</h4></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="thead-dark">
                            <tr><th>Ref. Number</th><th>Sales Person</th><th>Department</th><th>Date</th><th>Customer</th><th>Premium</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($salesData)): ?>
                                <tr><td colspan="7" class="text-center">No sales records found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($salesData as $sale): ?>
                                    <tr>
                                        <td><?php echo $sale['reference_number']; ?></td>
                                        <td><?php echo htmlspecialchars($sale['user_name']); ?></td>
                                        <td><?php echo htmlspecialchars($sale['department']); ?></td>
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