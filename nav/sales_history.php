<?php
session_start();
require_once '../src/SalesManager.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
if ($_SESSION['role'] !== 'manager') {
    header('Location: index.php');
    exit();
}

// Handle date filtering, default to today
$date_start = $_GET['date_start'] ?? date('Y-m-d');
$date_end = $_GET['date_end'] ?? date('Y-m-d');

$salesManager = new SalesManager();
$sales = $salesManager->getSalesHistory($date_start, $date_end);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales History</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../styles.css" />
</head>
<body class="dashboard">
<div class="container-fluid">
    <div class="row">
        <aside class="col-lg-2 col-md-3 sidebar">
            <div class="sidebar-brand">
                <img src="https://via.placeholder.com/50/6a381f/FFFFFF?Text=B" alt="BREADLY Logo">
                <h5>BREADLY</h5>
                <p>Kz & Rhyne's Bakery</p>
            </div>
            <ul class="nav flex-column sidebar-nav">
                <li class="nav-item">
                    <a class="nav-link" href="dashboard_panel.php">
                        <i class="bi bi-speedometer2 me-2"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="inventory_management.php">
                        <i class="bi bi-box me-2"></i> Inventory
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="sales_history.php">
                        <i class="bi bi-clock-history me-2"></i> Sales History
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="index.php">
                        <i class="bi bi-arrow-left me-2"></i> Main Menu
                    </a>
                </li>
            </ul>
            <div class="sidebar-user">
                <hr>
                <div class="dropdown">
                    <a href="#" class="d-flex align-items-center text-dark text-decoration-none dropdown-toggle" id="userMenu" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-person-circle me-2 fs-4"></i>
                        <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark text-small shadow" aria-labelledby="userMenu">
                        <li><a class="dropdown-item" href="logout.php">Sign out</a></li>
                    </ul>
                </div>
            </div>
        </aside>

        <main class="col-lg-10 col-md-9 main-content">
            <div class="header">
                <h1>Sales History</h1>
            </div>

            <div class="card shadow-sm">
                <div class="card-header">
                    <form method="GET" action="sales_history.php" class="row g-3 align-items-center">
                        <div class="col-md-4">
                            <label for="date_start" class="form-label">Start Date</label>
                            <input type="date" class="form-control" name="date_start" id="date_start" value="<?php echo htmlspecialchars($date_start); ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="date_end" class="form-label">End Date</label>
                            <input type="date" class="form-control" name="date_end" id="date_end" value="<?php echo htmlspecialchars($date_end); ?>">
                        </div>
                        <div class="col-md-4" style="margin-top: 38px;">
                            <button type="submit" class="btn btn-primary">Filter</button>
                        </div>
                    </form>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Product</th>
                                    <th>Quantity</th>
                                    <th>Total Price</th>
                                    <th>Cashier</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($sales)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted">No sales found for this period.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($sales as $sale): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(date('M d, Y', strtotime($sale['date']))); ?></td>
                                        <td><?php echo htmlspecialchars($sale['product_name']); ?></td>
                                        <td><?php echo htmlspecialchars($sale['qty_sold']); ?></td>
                                        <td>P<?php echo htmlspecialchars(number_format($sale['total_price'], 2)); ?></td>
                                        <td><?php echo htmlspecialchars($sale['cashier_username']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>