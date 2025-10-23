<?php
session_start();
require_once '../src/DashboardManager.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
if ($_SESSION['role'] !== 'manager') {
    header('Location: index.php');
    exit();
}

$dashboardManager = new DashboardManager();
$today = date('Y-m-d');
$thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));

$todaySummary = $dashboardManager->getSalesSummaryToday();
$topProducts = $dashboardManager->getTopSellingProducts($thirtyDaysAgo, $today, 5);
$lowStockAlertsCount = $dashboardManager->getLowStockAlertsCount();
$bestSellingProduct = $topProducts[0]['name'] ?? 'N/A';

$priorityAlert = $dashboardManager->getActiveLowStockAlerts(1);
$lowStockIngredient = 'N/A';
$lowStockDetails = 'All items are well-stocked.';
$lowStockUnit = '';
$lowStockReorder = '';

if (!empty($priorityAlert)) {
    $alert = $priorityAlert[0];
    $lowStockIngredient = htmlspecialchars($alert['ingredient_name']);
    $lowStockUnit = htmlspecialchars($alert['unit'] ?? '');
    $lowStockReorder = htmlspecialchars($alert['reorder_level'] ?? '');
    $lowStockDetails = "Only <strong>" . ($alert['current_stock'] ?? '0') . " {$lowStockUnit}</strong> left.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bakery Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../styles.css" />
    <style>
        .chart-container {
            position: relative;
            height: 40vh;
            min-height: 300px;
        }
        .low-stock-card a {
            text-decoration: none;
            color: inherit;
        }
        .low-stock-card a:hover {
            text-decoration: underline;
        }
        .stat-card .fs-4 {
            font-size: 1.75rem !important;
             line-height: 1.2;
        }
    </style>
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
                    <a class="nav-link active" href="dashboard_panel.php">
                        <i class="bi bi-speedometer2 me-2"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="inventory_management.php">
                        <i class="bi bi-box me-2"></i> Inventory
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="sales_history.php">
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
                <h1>Dashboard</h1>
            </div>
            <div class="row">
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card" style="background-color: var(--card-bg-1);">
                        <h1>P<?php echo number_format($todaySummary['totalRevenue'], 2); ?></h1>
                        <p>Total Sales Revenue</p>
                        <span class="percent-change text-muted">Today</span>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card" style="background-color: var(--card-bg-2);">
                        <h1><?php echo $todaySummary['totalSales']; ?></h1>
                        <p>Total Orders</p>
                        <span class="percent-change text-muted">Today</span>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card" style="background-color: var(--card-bg-3);">
                        <h1><?php echo htmlspecialchars($bestSellingProduct); ?></h1>
                        <p>Best Selling Product</p>
                        <span class="percent-change text-muted">Last 30 Days</span>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card" style="background-color: var(--card-bg-4);">
                        <h1 class="fs-4"><?php echo $lowStockAlertsCount; ?> Item(s)</h1>
                        <p>Inventory Status</p>
                        <span class="percent-change <?php echo ($lowStockAlertsCount > 0) ? 'text-danger' : 'text-success'; ?>">
                            <?php echo ($lowStockAlertsCount > 0) ? 'Need Restocking' : 'All Stocked'; ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-7">
                    <div class="chart-container">
                        <h3 class="chart-title">Top Selling Products (Last 30 Days)</h3>
                        <canvas id="topProductsChart" data-products="<?php echo htmlspecialchars(json_encode($topProducts)); ?>"></canvas>
                    </div>
                </div>
                <div class="col-lg-5">
                    <a href="inventory_management.php#ingredients-pane" class="low-stock-card h-100 d-block">
                        <h3>Priority Low Stock</h3>
                        <p class="best-seller-name mb-2"><?php echo $lowStockIngredient; ?></p>
                        <?php if (!empty($priorityAlert)): ?>
                            <div class="low-stock-warning mb-1"><i class="bi bi-exclamation-triangle-fill"></i> Urgent!</div>
                            <p class="low-stock-details mb-2"><?php echo $lowStockDetails; ?></p>
                            <p class="text-muted small">Reorder Level: <?php echo $lowStockReorder; ?> <?php echo $lowStockUnit; ?></p>
                        <?php else: ?>
                            <div class="low-stock-warning text-success mb-1"><i class="bi bi-check-circle-fill"></i> Fully Stocked</div>
                            <p class="low-stock-details mb-2"><?php echo $lowStockDetails; ?></p>
                        <?php endif; ?>
                        <span class="mt-auto">View all ingredients <i class="bi bi-arrow-right-short"></i></span>
                    </a>
                </div>
            </div>

        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/script_dashboard.js"></script>

</body>
</html>