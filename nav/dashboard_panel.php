<?php
session_start();
require_once '../src/DashboardManager.php';
require_once '../src/UserManager.php'; // --- ADDED: Include UserManager ---

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
if ($_SESSION['role'] !== 'manager') {
    header('Location: index.php');
    exit();
}

$dashboardManager = new DashboardManager();
$userManager = new UserManager(); // --- ADDED: Instantiate UserManager ---
$current_user_id = $_SESSION['user_id'];

// --- Handle POST request for sending NEW report ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_summary_report') {
    $phone_number = $_POST['phone_number'];
    $date_start_sms = $_POST['date_start'];
    $date_end_sms = $_POST['date_end'];

    $success = $dashboardManager->sendDailySummaryReport($phone_number, $date_start_sms, $date_end_sms);

    if ($success) {
        $manager_list_for_msg = $dashboardManager->getManagers();
        $sent_to_username = 'the selected number';
        foreach ($manager_list_for_msg as $mgr) {
            if ($mgr['phone_number'] == $phone_number) {
                $sent_to_username = $mgr['username'];
                break;
            }
        }
        // STEP 1: Set the session message
        $_SESSION['flash_message'] = "Summary Report successfully sent to $sent_to_username ($phone_number).";
        $_SESSION['flash_type'] = 'success';
    } else {
        $_SESSION['flash_message'] = "Failed to send report. Check Internet Connection, API token, credits, and number.";
        $_SESSION['flash_type'] = 'danger';
    }
    
    // Preserve date range in URL on redirect
    $query_params = http_build_query([
        'date_start' => $_GET['date_start'] ?? date('Y-m-d', strtotime('-29 days')),
        'date_end' => $_GET['date_end'] ?? date('Y-m-d')
    ]);
    // STEP 2: Redirect
    header('Location: dashboard_panel.php?' . $query_params);
    exit();
}

// --- Handle POST request for updating settings ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_settings') {
    $phone_number = $_POST['my_phone_number'];
    $enable_daily_report = isset($_POST['enable_daily_report']) ? 1 : 0;

    $success = $userManager->updateMySettings($current_user_id, $phone_number, $enable_daily_report);

    if ($success) {
        $_SESSION['flash_message'] = "Your settings have been saved.";
        $_SESSION['flash_type'] = 'success';
    } else {
        $_SESSION['flash_message'] = "Failed to save settings.";
        $_SESSION['flash_type'] = 'danger';
    }
    
    // Preserve date range in URL on redirect
    $query_params = http_build_query([
        'date_start' => $_GET['date_start'] ?? date('Y-m-d', strtotime('-29 days')),
        'date_end' => $_GET['date_end'] ?? date('Y-m-d')
    ]);
    // STEP 2: Redirect
    header('Location: dashboard_panel.php?' . $query_params);
    exit();
}
// --- (The PDF POST is handled by 'generate_pdf_report.php', so no handler is needed here) ---


// STEP 3: Read the session message on page load (GET request)
$flash_message = $_SESSION['flash_message'] ?? '';
$flash_type = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_message']);
unset($_SESSION['flash_type']);

// --- MODIFIED: Handle Date Range Filtering ---
$date_start = $_GET['date_start'] ?? date('Y-m-d', strtotime('-29 days'));
$date_end = $_GET['date_end'] ?? date('Y-m-d');

// --- MODIFIED: Fetch data based on date range ---
$dateRangeSummary = $dashboardManager->getSalesSummaryByDateRange($date_start, $date_end);
$topProducts = $dashboardManager->getTopSellingProducts($date_start, $date_end, 5);
// --- (These are not date-specific, so they remain the same) ---
// --- MODIFIED: Pass date range to getRecalledStockValue ---
$recalledStockValue = $dashboardManager->getRecalledStockValue($date_start, $date_end);
$priorityAlert = $dashboardManager->getActiveLowStockAlerts(1);
$manager_list = $dashboardManager->getManagers();
$userSettings = $userManager->getUserSettings($current_user_id);

$bestSellingProduct = $topProducts[0]['name'] ?? 'N/A';

// --- Create friendly date range text ---
$date_range_text = date('M d, Y', strtotime($date_start));
if ($date_start != $date_end) {
    $date_range_text .= ' to ' . date('M d, Y', strtotime($date_end));
}
// --- MODIFIED: Check defaults more robustly ---
if (empty($_GET['date_start']) && empty($_GET['date_end'])) {
    if ($date_start == date('Y-m-d', strtotime('-29 days')) && $date_end == date('Y-m-d')) {
        $date_range_text = 'Last 30 Days';
    }
}
if ($date_start == date('Y-m-d') && $date_end == date('Y-m-d')) {
    $date_range_text = 'Today';
}
// ---

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
    <link rel="stylesheet" href="../styles/global.css"> 
    <link rel="stylesheet" href="../styles/dashboard.css"> 
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
                <img src="../images/kzklogo.png" alt="BREADLY Logo">
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
                    <a class="nav-link" href="recipes.php">
                        <i class="bi bi-journal-bookmark me-2"></i> Recipes
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
                        <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#settingsModal">
                            <i class="bi bi-gear me-2"></i>My Settings
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="logout.php">Sign out</a></li>
                    </ul>
                </div>
            </div>
        </aside>

        <main class="col-lg-10 col-md-9 main-content">
            <div class="header d-flex justify-content-between align-items-center">
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

            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <form method="GET" action="dashboard_panel.php" class="row g-3 align-items-end">
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
            </div>
            <div class="row">
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card" style="background-color: var(--card-bg-1);">
                        <h1 style="color: green">₱<?php echo number_format($dateRangeSummary['totalRevenue'], 2); ?></h1>
                        <p>Total Sales Revenue</p>
                        <span class="percent-change text-muted"><?php echo $date_range_text; ?></span>
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
                    <div class="stat-card" style="background-color: var(--card-bg-3);">
                        <h1><?php echo htmlspecialchars($bestSellingProduct); ?></h1>
                        <p>Best Selling Product</p>
                        <span class="percent-change text-muted"><?php echo $date_range_text; ?></span>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card" style="background-color: var(--card-bg-4);">
                        <h1 style="color: red">₱<?php echo number_format($recalledStockValue, 2); ?></h1>
                        <p>Recalled Stock Value</p>
                        <span class="percent-change text-muted">
                            <?php echo $date_range_text; ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-7">
                    <div class="chart-container">
                        <h3 class="chart-title">Top Selling Products (<?php echo $date_range_text; ?>)</h3>
                        <canvas id="topProductsChart" 
                                data-products="<?php echo htmlspecialchars(json_encode($topProducts)); ?>"
                                data-date-range="<?php echo htmlspecialchars($date_range_text); ?>">
                        </canvas>
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

<div class="modal fade" id="sendReportModal" tabindex="-1" aria-labelledby="sendReportModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="sendReportModalLabel">Send Summary Report via SMS</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="dashboard_panel.php?date_start=<?php echo htmlspecialchars($date_start); ?>&date_end=<?php echo htmlspecialchars($date_end); ?>" method="POST">
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
            <form action="dashboard_panel.php?date_start=<?php echo htmlspecialchars($date_start); ?>&date_end=<?php echo htmlspecialchars($date_end); ?>" method="POST">
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
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php 
    // This PHP code adds the file's last modified time to the script URL
    // This forces the browser to re-download the file if it has changed.
    $js_version = filemtime('../js/script_dashboard.js'); 
?>
<script src="../js/script_dashboard.js?v=<?php echo $js_version; ?>"></script>

</body>
</html>