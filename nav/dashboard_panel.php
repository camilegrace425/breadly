<?php
session_start();
require_once '../src/DashboardManager.php';
require_once '../src/UserManager.php'; 
require_once '../src/InventoryManager.php'; 

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
if (!in_array($_SESSION['role'], ['manager', 'assistant_manager'])) {
    header('Location: ../index.php');
    exit();
}

$dashboardManager = new DashboardManager();
$userManager = new UserManager(); 
$inventoryManager = new InventoryManager(); 
$current_user_id = $_SESSION['user_id'];

// --- Handle POST requests ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_summary_report') {
    $phone_number = $_POST['phone_number'] ?? '';
    $p_start = $_POST['date_start'];
    $p_end = $_POST['date_end'];
    
    $success = $dashboardManager->sendDailySummaryReport($phone_number, $p_start, $p_end);
    
    if ($success) {
        $_SESSION['flash_message'] = "SMS Report sent successfully.";
        $_SESSION['flash_type'] = "success";
    } else {
        $_SESSION['flash_message'] = "Failed to send SMS Report.";
        $_SESSION['flash_type'] = "danger";
    }
    header("Location: dashboard_panel.php?date_start=$p_start&date_end=$p_end");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_settings') {
    $phone = $_POST['my_phone_number'] ?? '';
    $daily = isset($_POST['enable_daily_report']) ? 1 : 0;
    
    $userManager->updateMySettings($current_user_id, $phone, $daily);
    $_SESSION['flash_message'] = "Settings updated.";
    $_SESSION['flash_type'] = "success";
    header("Location: dashboard_panel.php");
    exit();
}

// --- Read flash message ---
$flash_message = $_SESSION['flash_message'] ?? '';
$flash_type = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_message']);
unset($_SESSION['flash_type']);

// --- Get main page date range ---
$date_start = $_GET['date_start'] ?? date('Y-m-d');
$date_end = $_GET['date_end'] ?? date('Y-m-d');
$active_tab = $_GET['active_tab'] ?? 'sales';

// --- Fetch data ---
$dateRangeSummary = $dashboardManager->getSalesSummaryByDateRange($date_start, $date_end);

$grossRevenue = $dateRangeSummary['totalRevenue'] ?? 0.00;
$totalReturnsValue = $dateRangeSummary['totalReturnsValue'] ?? 0.00;
$totalReturnsCount = $dateRangeSummary['totalReturnsCount'] ?? 0;
$netRevenue = $grossRevenue - $totalReturnsValue;

$topProducts = $dashboardManager->getTopSellingProducts($date_start, $date_end, 5);

// --- NEW: Fetch Daily Trend Data (Sales & Returns) ---
$dailySalesRaw = $dashboardManager->getDailySalesTrend($date_start, $date_end);
$dailyReturnsRaw = $dashboardManager->getDailyReturnsTrend($date_start, $date_end);

// Align data by date
$trendData = [];
$period = new DatePeriod(
     new DateTime($date_start),
     new DateInterval('P1D'),
     (new DateTime($date_end))->modify('+1 day')
);

foreach ($period as $date) {
    $d = $date->format('Y-m-d');
    $trendData[$d] = [
        'date' => $d,
        'sales' => 0.00,
        'returns' => 0.00
    ];
}

// Map Sales
foreach ($dailySalesRaw as $s) {
    if (isset($trendData[$s['sale_date']])) {
        $trendData[$s['sale_date']]['sales'] = (float)$s['daily_revenue'];
    }
}

// Map Returns
foreach ($dailyReturnsRaw as $r) {
    if (isset($trendData[$r['return_date']])) {
        $trendData[$r['return_date']]['returns'] = (float)$r['daily_return_value'];
    }
}

$dailyTrendData = array_values($trendData);
// --- END NEW ---

$recalledStockValue = $dashboardManager->getRecalledStockValue($date_start, $date_end);
$priorityAlert = $dashboardManager->getActiveLowStockAlerts(1); 
$manager_list = $dashboardManager->getManagers();
$userSettings = $userManager->getUserSettings($current_user_id);
$recalledStockCountToday = $dashboardManager->getRecallCountForToday();

// --- Fetch Expiration Data ---
$expiringBatches = $dashboardManager->getExpiringBatches(7); 
$expiringCount = count($expiringBatches);

// --- DATA FOR "IN STOCK" CARD ---
$allProducts = $inventoryManager->getProductsInventory();
$productsWithStock = [];
foreach ($allProducts as $product) {
    if ($product['stock_qty'] > 0) {
        $productsWithStock[] = $product;
    }
}
$productsWithStockCount = count($productsWithStock);

// --- Calculate Low Stock Based on Reorder Level ---
$allIngredients = $inventoryManager->getIngredientsInventory();
$lowStockCount = 0;
foreach ($allIngredients as $ing) {
    if ($ing['stock_qty'] <= $ing['reorder_level']) {
        $lowStockCount++;
    }
}

// --- Friendly date range text ---
if ($date_start == $date_end) {
    if (empty($_GET['date_start']) && empty($_GET['date_end'])) {
        $date_range_text = 'Today';
    } else {
        $date_range_text = date('M d, Y', strtotime($date_start));
    }
} else {
    $date_range_text = date('M d, Y', strtotime($date_start)) . ' to ' . date('M d, Y', strtotime($date_end));
}

$active_nav_link = 'dashboard'; 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Dashboard</title>
    <link rel="icon" href="../images/kzklogo.png" type="image/x-icon"> 
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../styles/global.css"> 
    <link rel="stylesheet" href="../styles/dashboard.css"> 
    <link rel="stylesheet" href="../styles/responsive.css"> 
</head>
<body class="dashboard">
<div class="container-fluid">
    <div class="row">
        <aside class="col-lg-2 col-md-3 sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="sidebarMenu" aria-labelledby="sidebarMenuLabel">
            <div class="offcanvas-header d-lg-none">
                <h5 class="offcanvas-title" id="sidebarMenuLabel">BREADLY</h5>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#sidebarMenu" aria-label="Close"></button>
            </div>
            <div class="offcanvas-body d-flex flex-column p-0"> 
                <div class="sidebar-brand">
                    <img src="../images/kzklogo.png" alt="BREADLY Logo">
                    <h5>BREADLY</h5>
                    <p>Kz & Khyle's Bakery</p>
                </div>
                <ul class="nav flex-column sidebar-nav">
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($active_nav_link == 'dashboard') ? 'active' : ''; ?>" href="dashboard_panel.php">
                            <i class="bi bi-speedometer2 me-2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($active_nav_link == 'inventory') ? 'active' : ''; ?>" href="inventory_management.php">
                            <i class="bi bi-box me-2"></i> Inventory
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($active_nav_link == 'recipes') ? 'active' : ''; ?>" href="recipes.php">
                            <i class="bi bi-journal-bookmark me-2"></i> Recipes
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($active_nav_link == 'sales') ? 'active' : ''; ?>" href="sales_history.php">
                            <i class="bi bi-clock-history me-2"></i> Sales & Transactions
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../index.php">
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
                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#settingsModal">
                                <i class="bi bi-gear me-2"></i>My Settings
                            </a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php">Sign out</a></li>
                        </ul>
                    </div>
                </div>
            </div> 
        </aside>

        <main class="col-lg-10 col-md-9 main-content">
            <div class="header d-flex justify-content-between align-items-center">
                <button class="btn btn-outline-secondary d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarMenu" aria-controls="sidebarMenu">
                    <i class="bi bi-list"></i>
                </button>
                
                <h1>Dashboard</h1>
                <div class="btn-group" role="group">
                    <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#sendReportModal">
                        <i class="bi bi-send me-1"></i> Send SMS Report
                    </button>
                    <button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#exportCsvModal">
                        <i class="bi bi-filetype-csv me-1"></i> Export CSV
                    </button>
                    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#generatePdfModal">
                        <i class="bi bi-file-earmark-pdf me-1"></i> Download PDF
                    </button>
                </div>
            </div>

            <?php if ($flash_message): ?>
            <div class="alert alert-<?php echo $flash_type; ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($flash_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <ul class="nav nav-tabs" id="dashboardTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo ($active_tab == 'sales') ? 'active' : ''; ?>" id="sales-tab" data-bs-toggle="tab" data-bs-target="#sales-pane" type="button" role="tab">
                        <i class="bi bi-graph-up me-1"></i> Sales Analytics
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo ($active_tab == 'inventory') ? 'active' : ''; ?>" id="inventory-tab" data-bs-toggle="tab" data-bs-target="#inventory-pane" type="button" role="tab">
                         <i class="bi bi-box-seam me-1"></i> Inventory Tracking
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="dashboardTabContent">
                <div class="tab-pane fade <?php echo ($active_tab == 'sales') ? 'show active' : ''; ?>" id="sales-pane" role="tabpanel">
                    <div class="card shadow-sm mt-3">
                        <div class="card-header">
                            <form method="GET" action="dashboard_panel.php" class="row g-3 align-items-end" id="date-filter-form">
                                <input type="hidden" name="active_tab" id="active_tab_input" value="<?php echo htmlspecialchars($active_tab); ?>">
                                <div class="col-md-4">
                                    <label for="date_start" class="form-label">Start Date</label>
                                    <input type="date" class="form-control" name="date_start" id="date_start" value="<?php echo htmlspecialchars($date_start); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label for="date_end" class="form-label">End Date</label>
                                    <input type="date" class="form-control" name="date_end" id="date_end" value="<?php echo htmlspecialchars($date_end); ?>">
                                </div>
                                <div class="col-md-2">
                                     <button type="submit" class="btn btn-primary w-100">Filter</button>
                                </div>
                                <div class="col-md-2">
                                     <a href="dashboard_panel.php" class="btn btn-outline-secondary w-100">Reset</a>
                                </div>
                            </form>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-xl-3 col-md-6">
                                    <div class="stat-card" style="background-color: var(--card-bg-1);">
                                        <h1 style="color: green">₱<?php echo number_format($netRevenue, 2); ?></h1>
                                        <p>Net Sales Revenue</p>
                                        <span class="percent-change text-muted">
                                            <?php echo $date_range_text; ?>
                                            <br>
                                            <small>(<span style="color: green">₱<?php echo number_format($grossRevenue, 2); ?> Gross</span> - <?php 
                                            if ($totalReturnsValue == 0) {
                                                echo '<span style="color: green">₱0.00 Returns</span>';
                                            } else {
                                                echo '<span style="color: red">₱' . number_format($totalReturnsValue, 2) . ' Returns</span>';
                                            }
                                            ?>)</small>
                                        </span>
                                    </div>
                                </div>
                                <div class="col-xl-3 col-md-6">
                                    <div class="stat-card" style="background-color: var(--card-bg-2);">
                                        <h1><?php echo $dateRangeSummary['totalSales']; ?></h1>
                                        <p>Total Orders</p>
                                        <span class="percent-change text-muted"><?php echo $date_range_text; ?></span>
                                    </div>
                                </div>
                                <div class="col-xl-3 col-md-6">
                                    <div class="stat-card" style="background-color: var(--card-bg-4);">
                                        <?php if ($totalReturnsCount == 0) echo '<h1 style="color: green">0</h1>'; else echo '<h1 style="color: red;">' . $totalReturnsCount . '</h1>'; ?>
                                        <p>Total Returns</p>
                                        <span class="percent-change text-muted"><?php echo $date_range_text; ?></span>
                                    </div>
                                </div>
                                <div class="col-xl-3 col-md-6">
                                    <div class="stat-card" style="background-color: var(--card-bg-2);">
                                        <?php if ($totalReturnsValue == 0) echo '<h1 style="color: green">₱0.00</h1>'; else echo '<h1 style="color: red">₱' . number_format($totalReturnsValue, 2) . '</h1>'; ?>
                                        <p>Return Amount</p>
                                        <span class="percent-change text-muted"><?php echo $date_range_text; ?></span>
                                    </div>
                                </div>
                            </div>

                            <div class="row mt-3">
                                <div class="col-lg-6 mb-3">
                                    <div class="chart-container">
                                        <h3 class="chart-title">Top Selling Products</h3>
                                        <canvas id="topProductsChart" 
                                                data-products="<?php echo htmlspecialchars(json_encode($topProducts)); ?>" 
                                                data-date-range="<?php echo htmlspecialchars($date_range_text); ?>">
                                        </canvas>
                                    </div>
                                </div>

                                <div class="col-lg-6 mb-3">
                                    <div class="chart-container">
                                        <h3 class="chart-title">Revenue vs Returns Trend</h3>
                                        <canvas id="dailyTrendChart" 
                                                data-trend="<?php echo htmlspecialchars(json_encode($dailyTrendData)); ?>">
                                        </canvas>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <div class="tab-pane fade <?php echo ($active_tab == 'inventory') ? 'show active' : ''; ?>" id="inventory-pane" role="tabpanel">
                    <div class="card shadow-sm mt-3">
                        <div class="card-header">
                            <span class="fs-5">Inventory Tracking (Daily)</span>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-xl-3 col-md-6">
                                    <a href="#" class="stat-card-link" data-bs-toggle="modal" data-bs-target="#stockListModal">
                                        <div class="stat-card h-100" style="background-color: var(--card-bg-3);">
                                            <h1 style="color: <?php echo ($productsWithStockCount > 0) ? '#0a58ca' : '#198754'; ?>;">
                                                <?php echo $productsWithStockCount; ?>
                                            </h1>
                                            <p>Products in Stock</p>
                                            <span class="percent-change text-muted">Current stock count<br>Click to view list <i class="bi bi-arrow-right-short"></i></span>
                                        </div>
                                    </a>
                                </div>
                                
                                <div class="col-xl-3 col-md-6">
                                    <a href="#" class="stat-card-link" data-bs-toggle="modal" data-bs-target="#ingredientStockModal">
                                        <div class="stat-card h-100" style="background-color: <?php echo ($lowStockCount > 0) ? '#f8d7da' : '#d1e7dd'; ?>;">
                                            <h1 style="color: <?php echo ($lowStockCount > 0) ? '#dc3545' : '#198754'; ?>;">
                                                <?php echo $lowStockCount; ?>
                                            </h1>
                                            <p>Low Stock Ingredients</p>
                                            <span class="percent-change text-muted">
                                                <?php if ($lowStockCount > 0): ?>
                                                    <span class="text-danger">Action Required</span>
                                                <?php else: ?>
                                                    <span class="text-success">Fully Stocked</span>
                                                <?php endif; ?>
                                                <br>
                                                <small>Click to view list</small>
                                            </span>
                                        </div>
                                    </a>
                                </div>
                                <div class="col-xl-3 col-md-6">
                                    <a href="inventory_management.php?active_tab=recall" class="stat-card-link">
                                        <div class="stat-card h-100" style="background-color: var(--card-bg-4);">
                                            <?php if ($recalledStockCountToday == 0) echo '<h1 style="color: green;">0</h1>'; else echo 
                                                '<h1 style="color: red;">' . $recalledStockCountToday . '</h1>'; ?>
                                            <p>Total Recalled Products</p>
                                            <span class="percent-change text-muted">Today<br><small>Click to view recall log</small></span>
                                        </div>
                                    </a>
                                </div>
                                
                                <div class="col-xl-3 col-md-6">
                                    <a href="#" class="stat-card-link" data-bs-toggle="modal" data-bs-target="#expirationModal">
                                        <div class="stat-card h-100" style="background-color: #fff3cd;">
                                            <h1 style="color: <?php echo ($expiringCount > 0) ? '#d9534f' : '#198754'; ?>;">
                                                <?php echo $expiringCount; ?>
                                            </h1>
                                            <p>Expiring Batches</p>
                                            <span class="percent-change text-muted">
                                                Next 7 Days<br>
                                                <small>
                                                <?php if($expiringCount > 0): ?>
                                                    <span class="text-danger">Action Required</span>
                                                <?php else: ?>
                                                    <span class="text-success">No upcoming expirations</span>
                                                <?php endif; ?>
                                                </small>
                                            </span>
                                        </div>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div> 
        </main>
    </div>
</div>

<div class="modal fade" id="exportCsvModal" tabindex="-1" aria-labelledby="exportCsvModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="exportCsvModalLabel">Export CSV Report</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="generate_csv_report.php" method="POST" target="_blank" id="csvReportForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Reports to Export:</label>
                        <div class="list-group">
                            <label class="list-group-item">
                                <input class="form-check-input me-1" type="checkbox" name="report_types[]" value="sales" checked>
                                Sales Transactions
                            </label>
                            <label class="list-group-item">
                                <input class="form-check-input me-1" type="checkbox" name="report_types[]" value="product_inventory">
                                Product Inventory
                            </label>
                            <label class="list-group-item">
                                <input class="form-check-input me-1" type="checkbox" name="report_types[]" value="ingredient_inventory">
                                Ingredient Inventory & Par Levels
                            </label>
                            <label class="list-group-item">
                                <input class="form-check-input me-1" type="checkbox" name="report_types[]" value="returns">
                                Returns & Recalls History
                            </label>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label for="date_start_csv" class="form-label">Start Date</label>
                            <input type="date" class="form-control" name="date_start" id="date_start_csv" value="<?php echo htmlspecialchars($date_start); ?>" required>
                        </div>
                        <div class="col-6">
                            <label for="date_end_csv" class="form-label">End Date</label>
                            <input type="date" class="form-control" name="date_end" id="date_end_csv" value="<?php echo htmlspecialchars($date_end); ?>" required>
                        </div>
                    </div>
                    
                    <div class="mb-3 p-2 border rounded bg-light">
                        <label class="form-label fw-bold small">Action:</label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="csv_action" id="csvActionDownload" value="download" checked onclick="toggleEmailField(false, 'csv')">
                                <label class="form-check-label" for="csvActionDownload">Download</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="csv_action" id="csvActionEmail" value="email" onclick="toggleEmailField(true, 'csv')">
                                <label class="form-check-label" for="csvActionEmail">Email</label>
                            </div>
                        </div>
                        <div class="mt-2" id="csvEmailContainer" style="display:none;">
                            <input type="email" name="recipient_email" class="form-control form-control-sm" placeholder="Enter recipient email address">
                        </div>
                    </div>
                    <p class="text-muted small mb-0">
                        <i class="bi bi-info-circle"></i> If multiple reports are selected, they will be downloaded as a ZIP file.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-success">Export Selected</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="sendReportModal" tabindex="-1" aria-labelledby="sendReportModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="sendReportModalLabel">Send Summary Report via SMS</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="sms-report-form" action="dashboard_panel.php?date_start=<?php echo htmlspecialchars($date_start); ?>&date_end=<?php echo htmlspecialchars($date_end); ?>&active_tab=<?php echo htmlspecialchars($active_tab); ?>" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="send_summary_report">
                    <div class="mb-3">
                        <label for="phone_number" class="form-label">Admin Name</label>
                        <select class="form-select" name="phone_number" id="phone_number" required>
                            <option value="" disabled selected>Select an admin...</option>
                            <?php foreach ($manager_list as $manager): ?>
                                <option value="<?php echo htmlspecialchars($manager['phone_number']); ?>">
                                    <?php echo htmlspecialchars($manager['username']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="date_start" class="form-label">Start Date</label>
                        <input type="date" class="form-control" name="date_start" id="date_start_sms" value="<?php echo htmlspecialchars($date_start); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="date_end" class="form-label">End Date</label>
                        <input type="date" class="form-control" name="date_end" id="date_end_sms" value="<?php echo htmlspecialchars($date_end); ?>" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary" <?php if (empty($manager_list)) echo 'disabled'; ?>>Send Report</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="settingsModal" tabindex="-1" aria-labelledby="settingsModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="settingsModalLabel">My Settings</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="settings-form" action="dashboard_panel.php" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_settings">
                    <div class="mb-3">
                        <label for="my_phone_number" class="form-label">My Phone Number</label>
                        <input type="text" class="form-control" name="my_phone_number" id="my_phone_number" 
                               value="<?php echo htmlspecialchars($userSettings['phone_number'] ?? ''); ?>" 
                               placeholder="e.g., 09171234567" maxlength="12">
                    </div>
                    <hr>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="enable_daily_report" 
                               name="enable_daily_report" value="1" 
                               <?php if (!empty($userSettings['enable_daily_report'])) echo 'checked'; ?>>
                        <label class="form-check-label" for="enable_daily_report">Receive Automatic Daily Reports</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Settings</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="generatePdfModal" tabindex="-1" aria-labelledby="generatePdfModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="generatePdfModalLabel">Generate PDF Report</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="generate_pdf_report.php" method="POST" target="_blank" id="pdfReportForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="date_start_pdf" class="form-label">Start Date</label>
                        <input type="date" class="form-control" name="date_start" id="date_start_pdf" value="<?php echo htmlspecialchars($date_start); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="date_end_pdf" class="form-label">End Date</label>
                        <input type="date" class="form-control" name="date_end" id="date_end_pdf" value="<?php echo htmlspecialchars($date_end); ?>" required>
                    </div>
                    <div class="mb-3 p-2 border rounded bg-light">
                        <label class="form-label fw-bold small">Action:</label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="report_action" id="actionDownload" value="download" checked onclick="toggleEmailField(false, 'pdf')">
                                <label class="form-check-label" for="actionDownload">Download PDF</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="report_action" id="actionEmail" value="email" onclick="toggleEmailField(true, 'pdf')">
                                <label class="form-check-label" for="actionEmail">Email PDF</label>
                            </div>
                        </div>
                        <div class="mt-2" id="pdfEmailContainer" style="display:none;">
                            <input type="email" name="recipient_email" class="form-control form-control-sm" placeholder="Enter recipient email address">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Generate Report</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="stockListModal" tabindex="-1" aria-labelledby="stockListModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="stockListModalLabel">Current Products in Stock</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex justify-content-end mb-3">
                     <div class="dropdown">
                        <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Sort By: <span class="current-sort-text">Name (A-Z)</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item sort-trigger active" data-sort-by="name" data-sort-dir="asc" data-sort-type="text" href="#">Name (A-Z)</a></li>
                            <li><a class="dropdown-item sort-trigger" data-sort-by="name" data-sort-dir="desc" data-sort-type="text" href="#">Name (Z-A)</a></li>
                            <li><a class="dropdown-item sort-trigger" data-sort-by="stock" data-sort-dir="desc" data-sort-type="number" href="#">Stock (High-Low)</a></li>
                            <li><a class="dropdown-item sort-trigger" data-sort-by="stock" data-sort-dir="asc" data-sort-type="number" href="#">Stock (Low-High)</a></li>
                        </ul>
                    </div>
                </div>
                <div class="table-responsive stock-list-container">
                    <table class="table table-striped table-hover align-middle">
                        <thead>
                            <tr>
                                <th data-sort-by="name">Product</th>
                                <th data-sort-by="stock">Current Stock</th>
                            </tr>
                        </thead>
                        <tbody class="sortable-tbody">
                            <?php if ($productsWithStockCount == 0): ?>
                                <tr>
                                    <td colspan="2" class="text-center text-muted">
                                        <i class="bi bi-box-fill"></i> All products are out of stock.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($productsWithStock as $product): ?>
                                    <tr data-name="<?php echo htmlspecialchars(strtolower($product['name'])); ?>" data-stock="<?php echo $product['stock_qty']; ?>">
                                        <td data-label="Product"><?php echo htmlspecialchars($product['name']); ?></td>
                                        <td data-label="Stock"><strong><?php echo $product['stock_qty']; ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <a href="inventory_management.php?active_tab=products" class="btn btn-primary">
                    <i class="bi bi-box me-1"></i> Go to Inventory
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="ingredientStockModal" tabindex="-1" aria-labelledby="ingredientStockModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="ingredientStockModalLabel">Ingredient Stock Levels</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" id="filterLowStock">
                        <label class="form-check-label" for="filterLowStock">Show Low Stock Only</label>
                    </div>
                     <div class="dropdown">
                        <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Sort By: <span class="current-sort-text">Name (A-Z)</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item sort-trigger active" data-sort-by="name" data-sort-dir="asc" data-sort-type="text" href="#">Name (A-Z)</a></li>
                            <li><a class="dropdown-item sort-trigger" data-sort-by="name" data-sort-dir="desc" data-sort-type="text" href="#">Name (Z-A)</a></li>
                            <li><a class="dropdown-item sort-trigger" data-sort-by="stock" data-sort-dir="desc" data-sort-type="number" href="#">Stock (High-Low)</a></li>
                            <li><a class="dropdown-item sort-trigger" data-sort-by="stock" data-sort-dir="asc" data-sort-type="number" href="#">Stock (Low-High)</a></li>
                        </ul>
                    </div>
                </div>
                
                <div class="table-responsive stock-list-container">
                    <table class="table table-striped table-hover align-middle">
                        <thead>
                            <tr>
                                <th data-sort-by="name">Ingredient</th>
                                <th data-sort-by="stock">Current Stock</th>
                            </tr>
                        </thead>
                        <tbody class="sortable-tbody">
                            <?php if (empty($allIngredients)): ?>
                                <tr>
                                    <td colspan="2" class="text-center text-muted">
                                        <i class="bi bi-box-fill"></i> No ingredients found.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($allIngredients as $ing): 
                                    // Check stock level
                                    $is_low = ($ing['stock_qty'] <= $ing['reorder_level']);
                                ?>
                                    <tr data-name="<?php echo htmlspecialchars(strtolower($ing['name'])); ?>" 
                                        data-stock="<?php echo $ing['stock_qty']; ?>"
                                        data-is-low="<?php echo $is_low ? '1' : '0'; ?>">
                                        
                                        <td data-label="Ingredient"><?php echo htmlspecialchars($ing['name']); ?></td>
                                        <?php if ($is_low): ?>
                                            <td data-label="Stock"><strong class="text-danger"><?php echo number_format($ing['stock_qty'], decimals: 2) . ' ' . htmlspecialchars($ing['unit']); ?></strong></td>
                                        <?php else: ?>
                                            <td data-label="Stock"><strong><?php echo number_format($ing['stock_qty'], decimals: 2) . ' ' . htmlspecialchars($ing['unit']); ?></strong></td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <a href="inventory_management.php?active_tab=ingredients" class="btn btn-primary">
                    <i class="bi bi-box me-1"></i> Go to Inventory
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="expirationModal" tabindex="-1" aria-labelledby="expirationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-warning-subtle">
                <h5 class="modal-title" id="expirationModalLabel"><i class="bi bi-hourglass-split me-2"></i>Expiring Batches (Next 7 Days)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Ingredient</th>
                                <th>Batch Expiry</th>
                                <th>Status</th>
                                <th>Remaining Qty</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($expiringBatches)): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">
                                        <i class="bi bi-check-circle-fill text-success fs-4 d-block mb-2"></i>
                                        No batches are expiring soon.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($expiringBatches as $batch): 
                                    $days = $batch['days_remaining'];
                                    $rowClass = '';
                                    $badgeClass = 'bg-warning text-dark';
                                    $statusText = "Expires in $days days";

                                    if ($days < 0) {
                                        $rowClass = 'table-danger';
                                        $badgeClass = 'bg-danger';
                                        $statusText = "Expired " . abs($days) . " days ago";
                                    } elseif ($days == 0) {
                                        $rowClass = 'table-danger';
                                        $badgeClass = 'bg-danger';
                                        $statusText = "Expires Today!";
                                    }
                                ?>
                                    <tr class="<?php echo $rowClass; ?>">
                                        <td class="fw-bold"><?php echo htmlspecialchars($batch['ingredient_name']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($batch['expiration_date'])); ?></td>
                                        <td><span class="badge <?php echo $badgeClass; ?>"><?php echo $statusText; ?></span></td>
                                        <td><?php echo $batch['quantity'] . ' ' . $batch['unit']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <a href="inventory_management.php?active_tab=ingredients" class="btn btn-primary">
                    <i class="bi bi-arrow-right-circle me-1"></i> Manage Ingredients
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php $js_version = filemtime('../js/script_dashboard.js'); ?>
<script src="../js/script_dashboard.js?v=<?php echo $js_version; ?>"></script>

<script>
function toggleEmailField(show, type) {
    let containerId, formId;
    if (type === 'pdf') {
        containerId = 'pdfEmailContainer';
        formId = 'pdfReportForm';
    } else {
        containerId = 'csvEmailContainer';
        formId = 'csvReportForm';
    }
    const container = document.getElementById(containerId);
    const emailInput = container.querySelector('input');
    const form = document.getElementById(formId);
    if (show) {
        container.style.display = 'block';
        emailInput.required = true;
        form.removeAttribute('target');
    } else {
        container.style.display = 'none';
        emailInput.required = false;
        form.setAttribute('target', '_blank');
    }
}

// New script to handle Low Stock filtering
document.addEventListener('DOMContentLoaded', function() {
    const filterCheckbox = document.getElementById('filterLowStock');
    if (filterCheckbox) {
        filterCheckbox.addEventListener('change', function() {
            const showLowOnly = this.checked;
            const rows = document.querySelectorAll('#ingredientStockModal tbody tr');
            
            rows.forEach(row => {
                if (showLowOnly) {
                    const isLow = row.getAttribute('data-is-low') === '1';
                    if (!isLow) {
                        row.classList.add('d-none');
                    } else {
                        row.classList.remove('d-none');
                    }
                } else {
                     row.classList.remove('d-none');
                }
            });
        });
    }
});
</script>
</body>
</html>