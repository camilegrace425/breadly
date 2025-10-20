<?php
session_start();

// (PHP logic remains the same as before)
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
if ($_SESSION['role'] !== 'manager') {
    header('Location: index.php');
    exit();
}

$totalSales = "P1,200";
$totalOrders = "60";
$bestSellingProduct = "Pandesal";
$inventoryStatus = "Flour 60% Remaining";
$lowStockIngredient = "Flour";
$lowStockPercent = "40%";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bakery Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    
    <link rel="stylesheet" href="styles.css" />
    
</head>
<body class="dashboard"> <div class="container-fluid">
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
                    <a class="nav-link" href="#">
                        <i class="bi bi-box me-2"></i> Inventory
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#">
                        <i class="bi bi-receipt me-2"></i> Orders
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#">
                        <i class="bi bi-cash-coin me-2"></i> Expenses
                    </a>
                </li>
            </ul>
        </aside>

        <main class="col-lg-10 col-md-9 main-content">
            <div class="header">
                <h1>Dashboard</h1>
                <div class="dropdown">
                    <button class="btn btn-light dropdown-toggle" type="button" id="adminUserMenu" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-person-circle me-2"></i> Admin User
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="adminUserMenu">
                        <li><a class="dropdown-item" href="#">Profile</a></li>
                        <li><a class="dropdown-item" href="user_management.php">Manage Users</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="logout.php">Logout</a></li>
                    </ul>
                </div>
            </div>
            <div class="row">
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card" style="background-color: var(--card-bg-1);">
                        <h1><?php echo $totalSales; ?></h1>
                        <p>Total Sales</p>
                        <span class="percent-change text-success">↑ 8% Last week</span>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card" style="background-color: var(--card-bg-2);">
                        <h1><?php echo $totalOrders; ?></h1>
                        <p>Total Orders</p>
                        <span class="percent-change text-success">↑ 8% Last week</span>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card" style="background-color: var(--card-bg-3);">
                        <h1><?php echo $bestSellingProduct; ?></h1>
                        <p>Best Selling Product</p>
                        <span class="percent-change text-success">↑ 8% Last week</span>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card" style="background-color: var(--card-bg-4);">
                        <h1 class="fs-4"><?php echo $inventoryStatus; ?></h1>
                        <p>Inventory Status</p>
                        <span class="percent-change text-danger">↓ 8% This week</span>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-lg-7">
                    <div class="chart-container">
                        <h3 class="chart-title">Top Selling Products</h3>
                        <div class="bar-chart-row">
                            <span class="bar-label">Pandesal</span>
                            <div class="bar-wrapper"><div class="bar" style="width: 100%; background-color: var(--bar-color-1);"></div></div>
                        </div>
                        <div class="bar-chart-row">
                            <span class="bar-label">Mini Donuts</span>
                            <div class="bar-wrapper"><div class="bar" style="width: 55%; background-color: var(--bar-color-2);"></div></div>
                        </div>
                        <div class="bar-chart-row">
                            <span class="bar-label">Chicken Floss</span>
                            <div class="bar-wrapper"><div class="bar" style="width: 35%; background-color: var(--bar-color-1);"></div></div>
                        </div>
                        <div class="bar-chart-row">
                            <span class="bar-label">Choco German</span>
                            <div class="bar-wrapper"><div class="bar" style="width: 45%; background-color: var(--bar-color-3);"></div></div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="low-stock-card h-100">
                        <h3>Best Seller</h3>
                        <p class="best-seller-name"><?php echo $bestSellingProduct; ?></p>
                        <div class="low-stock-warning"><i class="bi bi-exclamation-triangle-fill"></i> Low Stock!</div>
                        <p class="low-stock-details"><?php echo $lowStockIngredient; ?> running low (<?php echo $lowStockPercent; ?>) left</p>
                        <a href="#">View more <i class="bi bi-arrow-right-short"></i></a>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-lg-7">
                    <div class="chart-container">
                        <h3 class="chart-title">Sales by Category</h3>
                        <div class="pie-chart-wrapper"><div class="pie-chart"></div></div>
                        <ul class="pie-legend">
                            <li><span class="legend-dot" style="background-color: var(--pie-color-1);"></span> Pandesal</li>
                            <li><span class="legend-dot" style="background-color: var(--pie-color-2);"></span> Mini Donuts</li>
                            <li><span class="legend-dot" style="background-color: var(--pie-color-3);"></span> Chicken Floss</li>
                            <li><span class="legend-dot" style="background-color: var(--pie-color-4);"></span> Choco German</li>
                        </ul>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>