<?php
session_start();
require_once '../src/DashboardManager.php';
require_once '../src/UserManager.php'; 
require_once '../src/InventoryManager.php'; // <-- ADDED THIS

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
if ($_SESSION['role'] !== 'manager') {
    header('Location: index.php');
    exit();
}

$dashboardManager = new DashboardManager();
$userManager = new UserManager(); 
$inventoryManager = new InventoryManager(); // <-- ADDED THIS
$current_user_id = $_SESSION['user_id'];

// --- Handle POST requests (unchanged) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_summary_report') {
    // ... (code for sending report)
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_settings') {
    // ... (code for updating settings)
}

// --- Read flash message (unchanged) ---
$flash_message = $_SESSION['flash_message'] ?? '';
$flash_type = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_message']);
unset($_SESSION['flash_type']);

// --- Get main page date range ---
$date_start = $_GET['date_start'] ?? date('Y-m-d');
$date_end = $_GET['date_end'] ?? date('Y-m-d');

// --- ::: NEW: Get active tab from URL ---
$active_tab = $_GET['active_tab'] ?? 'sales';
// ---

// --- Fetch data based on date range ---
$dateRangeSummary = $dashboardManager->getSalesSummaryByDateRange($date_start, $date_end);

$grossRevenue = $dateRangeSummary['totalRevenue'] ?? 0.00;
$totalReturnsValue = $dateRangeSummary['totalReturnsValue'] ?? 0.00;
$totalReturnsCount = $dateRangeSummary['totalReturnsCount'] ?? 0;
$netRevenue = $grossRevenue - $totalReturnsValue;

$topProducts = $dashboardManager->getTopSellingProducts($date_start, $date_end, 5);
$recalledStockValue = $dashboardManager->getRecalledStockValue($date_start, $date_end);
$priorityAlert = $dashboardManager->getActiveLowStockAlerts(1);
$manager_list = $dashboardManager->getManagers();
$userSettings = $userManager->getUserSettings($current_user_id);
$lowStockCount = $dashboardManager->getLowStockAlertsCount();

// --- ::: NEW: Call the new function ::: ---
$recalledStockCountToday = $dashboardManager->getRecallCountForToday();
// --- ::: END NEW ::: ---

// --- === NEW DATA FOR "IN STOCK" CARD === ---
$allProducts = $inventoryManager->getProductsInventory();
$productsWithStock = [];
foreach ($allProducts as $product) {
    if ($product['stock_qty'] > 0) {
        $productsWithStock[] = $product;
    }
}
$productsWithStockCount = count($productsWithStock);
// --- === END NEW DATA === ---


// --- ::: MODIFIED: Cleaned up friendly date range text logic ::: ---
if ($date_start == $date_end) {
    // Check if it's the default "Today"
    if (empty($_GET['date_start']) && empty($_GET['date_end'])) {
        $date_range_text = 'Today';
    } else {
        // It's a single day, but not the default
        $date_range_text = date('M d, Y', strtotime($date_start));
    }
} else {
    // It's a range
    $date_range_text = date('M d, Y', strtotime($date_start)) . ' to ' . date('M d, Y', strtotime($date_end));
}
// --- ::: END MODIFICATION ::: ---

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

// --- Active Nav Link for Sidebar ---
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
                        <a class="nav-link <?php echo ($active_nav_link == 'login_history') ? 'active' : ''; ?>" href="login_history.php">
                            <i class="bi bi-person-check me-2"></i> Login History
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
                                        <?php
                                        if ($totalReturnsCount == 0) {
                                            echo '<h1 style="color: green">0</h1>';
                                        } else {
                                            echo '<h1 style="color: red;">' . $totalReturnsCount . '</h1>';
                                        }
                                        ?>
                                        <p>Total Returns</p>
                                        <span class="percent-change text-muted">
                                            <?php echo $date_range_text; ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="col-xl-3 col-md-6">
                                    <div class="stat-card" style="background-color: var(--card-bg-2);">
                                        <?php
                                        if ($totalReturnsValue == 0) {
                                            echo '<h1 style="color: green">₱0.00</h1>';
                                        } else {
                                            echo '<h1 style="color: red">₱' . number_format($totalReturnsValue, 2) . '</h1>';
                                        }
                                        ?>
                                        <p>Return Amount</p>
                                        <span class="percent-change text-muted">
                                            <?php echo $date_range_text; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-lg-12">
                                    <div class="chart-container">
                                        <h3 class="chart-title">Top Selling Products (<?php echo $date_range_text; ?>)</h3>
                                        <canvas id="topProductsChart" 
                                                data-products="<?php echo htmlspecialchars(json_encode($topProducts)); ?>"
                                                data-date-range="<?php echo htmlspecialchars($date_range_text); ?>">
                                        </canvas>
                                    </div>
                                </div>
                            </div>
                        </div> </div> </div>

                <div class="tab-pane fade <?php echo ($active_tab == 'inventory') ? 'show active' : ''; ?>" id="inventory-pane" role="tabpanel">
                    
                    <div class="card shadow-sm mt-3">
                        <div class="card-header">
                            <span class="fs-5">Inventory Tracking</span>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-lg-4 col-md-6">
                                    <a href="#" class="stat-card-link" data-bs-toggle="modal" data-bs-target="#stockListModal">
                                        <div class="stat-card h-100" style="background-color: var(--card-bg-3);">
                                            <h1 style="color: <?php echo ($productsWithStockCount > 0) ? '#0a58ca' : '#198754'; ?>;">
                                                <?php echo $productsWithStockCount; ?>
                                            </h1>
                                            <p>Products in Stock</p>
                                            <span class="percent-change text-muted">
                                                Current stock count
                                                <br>
                                                Click to view list <i class="bi bi-arrow-right-short"></i>
                                            </span>
                                        </div>
                                    </a>
                                </div>
                                <div class="col-lg-4 col-md-6">
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
                                <div class="col-lg-4 col-md-6">
                                    <a href="inventory_management.php?active_tab=recall" class="stat-card-link">
                                        <div class="stat-card h-100" style="background-color: var(--card-bg-4);">
                                            <h1 style="color: red;"><?php echo $recalledStockCountToday; ?></h1>
                                            <p>Total Recalled Products</p>
                                            <span class="percent-change text-muted">
                                                Today
                                                <br>
                                                <small>Click to view recall log</small>
                                            </span>
                                        </div>
                                    </a>
                                </div>
                            </div>
                        </div> </div> </div>

            </div> </main>
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
                        <?php if (empty($manager_list)): ?>
                            <div class="form-text text-danger">No managers with phone numbers found.</div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="date_start" class="form-label">Start Date</label>
                        <input type="date" class="form-control" name="date_start" id="date_start_sms" value="<?php echo htmlspecialchars($date_start); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="date_end" class="form-label">End Date</label>
                        <input type="date" class="form-control" name="date_end" id="date_end_sms" value="<?php echo htmlspecialchars($date_end); ?>" required>
                    </div>
                    <p class="text-muted small">This will send an SMS summary of sales and recalls for the selected date range.</p>
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
            <form id="settings-form" action="dashboard_panel.php?date_start=<?php echo htmlspecialchars($date_start); ?>&date_end=<?php echo htmlspecialchars($date_end); ?>&active_tab=<?php echo htmlspecialchars($active_tab); ?>" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_settings">
                    <div class="mb-3">
                        <label for="my_phone_number" class="form-label">My Phone Number</label>
                        <input type="text" class="form-control" name="my_phone_number" id="my_phone_number" 
                               value="<?php echo htmlspecialchars($userSettings['phone_number'] ?? ''); ?>" 
                               placeholder="e.g., 09171234567"
                               maxlength="12">
                        <div class="form-text">Your number for receiving all notifications, including password resets and daily reports.</div>
                    </div>
                    <hr>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="enable_daily_report" 
                               name="enable_daily_report" value="1" 
                               <?php if (!empty($userSettings['enable_daily_report'])) echo 'checked'; ?>>
                        <label class="form-check-label" for="enable_daily_report">Receive Automatic Daily Reports</label>
                    </div>
                    <p class="text-muted small">
                        If checked, you will automatically receive the "Sales & Recall Report" via SMS at the end of each day. 
                        (Note: This requires a server Cron Job to be set up by the administrator).
                    </p>
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
            <form action="../lib/generate_pdf_report.php" method="POST" target="_blank">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="date_start_pdf" class="form-label">Start Date</label>
                        <input type="date" class="form-control" name="date_start" id="date_start_pdf" value="<?php echo htmlspecialchars($date_start); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="date_end_pdf" class="form-label">End Date</label>
                        <input type="date" class="form-control" name="date_end" id="date_end_pdf" value="<?php echo htmlspecialchars($date_end); ?>" required>
                    </div>
                    
                    <p class="text-muted small">This will generate a downloadable PDF containing a detailed summary of sales and recalls for the selected date range.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Download PDF</button>
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
                        <tbody id="stock-list-tbody">
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
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php 
    $js_version = filemtime('../js/script_dashboard.js'); 
?>
<script src="../js/script_dashboard.js?v=<?php echo $js_version; ?>"></script>

</body>
</html>