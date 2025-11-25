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
$current_role = $_SESSION["role"];

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'send_summary_report') {
        $phone_number = $_POST['phone_number'] ?? '';
        $p_start = $_POST['date_start'];
        $p_end = $_POST['date_end'];
        
        $success = $dashboardManager->sendDailySummaryReport($phone_number, $p_start, $p_end);
        
        $_SESSION['flash_message'] = $success ? "SMS Report sent successfully." : "Failed to send SMS Report.";
        $_SESSION['flash_type'] = $success ? "success" : "error";
        
        header("Location: dashboard_panel.php?date_start=$p_start&date_end=$p_end");
        exit();
    }

    if (isset($_POST['action']) && $_POST['action'] === 'update_settings') {
        $phone = $_POST['my_phone_number'] ?? '';
        $daily = isset($_POST['enable_daily_report']) ? 1 : 0;
        
        $userManager->updateMySettings($current_user_id, $phone, $daily);
        $_SESSION['flash_message'] = "Settings updated.";
        $_SESSION['flash_type'] = "success";
        header("Location: dashboard_panel.php");
        exit();
    }
}

// Flash Message Logic
$flash_message = $_SESSION['flash_message'] ?? '';
$flash_type = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_message']);
unset($_SESSION['flash_type']);

// Date Range & Tab
$date_start = $_GET['date_start'] ?? date('Y-m-d');
$date_end = $_GET['date_end'] ?? date('Y-m-d');
$active_tab = $_GET['active_tab'] ?? 'sales';

// Fetch Sales Summary
$dateRangeSummary = $dashboardManager->getSalesSummaryByDateRange($date_start, $date_end);
$grossRevenue = $dateRangeSummary['totalRevenue'] ?? 0.00;
$totalReturnsValue = $dateRangeSummary['totalReturnsValue'] ?? 0.00;
$totalReturnsCount = $dateRangeSummary['totalReturnsCount'] ?? 0;
$netRevenue = $grossRevenue - $totalReturnsValue;

$topProducts = $dashboardManager->getTopSellingProducts($date_start, $date_end, 5);

// Fetch Daily Trend Data (Sales & Returns)
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
    $trendData[$d] = ['date' => $d, 'sales' => 0.00, 'returns' => 0.00];
}

foreach ($dailySalesRaw as $s) {
    if (isset($trendData[$s['sale_date']])) $trendData[$s['sale_date']]['sales'] = (float)$s['daily_revenue'];
}
foreach ($dailyReturnsRaw as $r) {
    if (isset($trendData[$r['return_date']])) $trendData[$r['return_date']]['returns'] = (float)$r['daily_return_value'];
}
$dailyTrendData = array_values($trendData);

// Fetch Additional Data
$manager_list = $dashboardManager->getManagers();
$userSettings = $userManager->getUserSettings($current_user_id);

// --- MODIFIED: Fetch Recall Summary for the date range ---
$recallSummary = $dashboardManager->getRecallSummaryByDateRange($date_start, $date_end);
$totalRecalledCount = $recallSummary['count'];
$totalRecalledValue = $recallSummary['value'];
// --------------------------------------------------------

$expiringBatches = $dashboardManager->getExpiringBatches(7); 
$expiringCount = count($expiringBatches);

// Inventory Calculations
$allProducts = $inventoryManager->getProductsInventory();
$productsWithStock = array_filter($allProducts, fn($p) => $p['stock_qty'] > 0);
$productsWithStockCount = count($productsWithStock);

$allIngredients = $inventoryManager->getIngredientsInventory();
$lowStockCount = 0;
foreach ($allIngredients as $ing) {
    if ($ing['stock_qty'] <= $ing['reorder_level']) $lowStockCount++;
}

// Friendly Date Text
if ($date_start == $date_end) {
    $date_range_text = (empty($_GET['date_start'])) ? 'Today' : date('M d, Y', strtotime($date_start));
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
    
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Poppins', 'sans-serif'] },
                    colors: {
                        breadly: {
                            bg: '#FFFBF5',
                            sidebar: '#FDEEDC',
                            'card-1': '#FEE8D0', 
                            'card-2': '#FBF3D5', 
                            'card-3': '#FBF3D5', 
                            'card-4': '#F8DFAA', 
                            dark: '#6a381f',
                            secondary: '#7a7a7a',
                            btn: '#af6223',
                            'btn-hover': '#9b4a10',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .chart-container { position: relative; height: 400px; width: 100%; }
    </style>
</head>
<body class="bg-breadly-bg text-breadly-dark font-sans h-screen flex overflow-hidden selection:bg-orange-200">

    <aside class="hidden lg:flex w-64 flex-col bg-breadly-sidebar h-full border-r border-orange-100 shrink-0 transition-all duration-300" id="sidebar">
        <div class="p-6 text-center border-b border-orange-100/50">
            <img src="../images/kzklogo.png" alt="BREADLY Logo" class="w-16 mx-auto mb-2">
            <h5 class="font-bold text-lg text-breadly-dark">BREADLY</h5>
            <p class="text-xs text-breadly-secondary">Kz & Khyle's Bakery</p>
        </div>
        
        <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-1">
            <a href="dashboard_panel.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors <?php echo ($active_nav_link == 'dashboard') ? 'bg-breadly-dark text-white shadow-md' : 'text-breadly-secondary hover:bg-orange-100 hover:text-breadly-dark'; ?>">
                <i class='bx bxs-dashboard text-xl'></i><span class="font-medium">Dashboard</span>
            </a>
            <a href="inventory_management.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors <?php echo ($active_nav_link == 'inventory') ? 'bg-breadly-dark text-white shadow-md' : 'text-breadly-secondary hover:bg-orange-100 hover:text-breadly-dark'; ?>">
                <i class='bx bxs-box text-xl'></i><span class="font-medium">Inventory</span>
            </a>
            <a href="recipes.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors <?php echo ($active_nav_link == 'recipes') ? 'bg-breadly-dark text-white shadow-md' : 'text-breadly-secondary hover:bg-orange-100 hover:text-breadly-dark'; ?>">
                <i class='bx bxs-book-bookmark text-xl'></i><span class="font-medium">Recipes</span>
            </a>
            <a href="sales_history.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors <?php echo ($active_nav_link == 'sales') ? 'bg-breadly-dark text-white shadow-md' : 'text-breadly-secondary hover:bg-orange-100 hover:text-breadly-dark'; ?>">
                <i class='bx bx-history text-xl'></i><span class="font-medium">Sales & Transactions</span>
            </a>
            <div class="my-4 border-t border-orange-200"></div>
            <a href="../index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-breadly-secondary hover:bg-orange-100 hover:text-breadly-dark transition-colors">
                <i class='bx bx-arrow-back text-xl'></i><span class="font-medium">Main Menu</span>
            </a>
        </nav>

        <div class="p-4 border-t border-orange-200">
            <div class="flex items-center gap-3 px-2 mb-3">
                <div class="w-10 h-10 rounded-full bg-orange-200 flex items-center justify-center text-breadly-dark font-bold"><i class='bx bxs-user'></i></div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-bold text-breadly-dark truncate"><?php echo htmlspecialchars($_SESSION['username']); ?></p>
                    <p class="text-xs text-breadly-secondary uppercase"><?php echo str_replace('_', ' ', $current_role); ?></p>
                </div>
            </div>
            <a href="logout.php" class="flex items-center justify-center gap-1 py-2 text-xs font-medium text-red-500 bg-white border border-red-100 rounded-lg hover:bg-red-50 transition-colors">
                <i class='bx bx-log-out'></i> Logout
            </a>
        </div>
    </aside>

    <div id="mobileSidebarOverlay" class="fixed inset-0 bg-black/50 z-40 hidden lg:hidden" onclick="toggleSidebar()"></div>
    
    <div id="mobileSidebar" class="fixed inset-y-0 left-0 w-64 bg-breadly-sidebar z-50 transform -translate-x-full transition-transform duration-300 lg:hidden flex flex-col h-full shadow-2xl">
        <div class="p-6 text-center border-b border-orange-100/50">
            <div class="flex justify-end mb-2">
                <button onclick="toggleSidebar()" class="text-breadly-secondary hover:text-breadly-dark"><i class='bx bx-x text-2xl'></i></button>
            </div>
            <img src="../images/kzklogo.png" alt="BREADLY Logo" class="w-16 mx-auto mb-2">
            <h5 class="font-bold text-lg text-breadly-dark">BREADLY</h5>
        </div>
        <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-1">
            <a href="dashboard_panel.php" class="flex items-center gap-3 px-4 py-3 rounded-xl <?php echo ($active_nav_link == 'dashboard') ? 'bg-breadly-dark text-white' : 'text-breadly-secondary'; ?>">
                <i class='bx bxs-dashboard text-xl'></i> Dashboard
            </a>
            <a href="inventory_management.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-breadly-secondary">
                <i class='bx bxs-box text-xl'></i> Inventory
            </a>
            <a href="recipes.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-breadly-secondary">
                <i class='bx bxs-book-bookmark text-xl'></i> Recipes
            </a>
            <a href="sales_history.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-breadly-secondary">
                <i class='bx bx-history text-xl'></i> Sales & Transactions
            </a>
            <a href="../index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-breadly-secondary mt-4 border-t border-orange-200 pt-4">
                <i class='bx bx-arrow-back text-xl'></i> Main Menu
            </a>
        </nav>
        <div class="p-4 border-t border-orange-200">
            <button onclick="openModal('settingsModal')" class="w-full py-2 mb-2 text-sm bg-white border border-orange-200 rounded-lg text-breadly-secondary">Settings</button>
            <a href="logout.php" class="block w-full py-2 text-center text-sm bg-red-50 text-red-600 rounded-lg">Logout</a>
        </div>
    </div>

    <main class="flex-1 flex flex-col h-full overflow-hidden relative w-full">
        
        <div class="p-6 pb-2 flex flex-col md:flex-row md:items-center justify-between gap-4 bg-breadly-bg z-10">
            <div class="flex items-center gap-3">
                <button onclick="toggleSidebar()" class="lg:hidden text-breadly-dark text-2xl"><i class='bx bx-menu'></i></button>
                <h1 class="text-2xl font-bold text-breadly-dark">Dashboard</h1>
            </div>
            
            <div class="flex flex-wrap gap-2">
                <button onclick="openModal('sendReportModal')" class="px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg shadow-sm hover:bg-gray-50 transition-colors flex items-center gap-2 text-sm font-medium">
                    <i class='bx bx-send'></i> <span class="hidden sm:inline">SMS Report</span>
                </button>
                <button onclick="openModal('exportCsvModal')" class="px-4 py-2 bg-green-600 text-white rounded-lg shadow-sm hover:bg-green-700 transition-colors flex items-center gap-2 text-sm font-medium">
                    <i class='bx bxs-file-csv'></i> <span class="hidden sm:inline">Export CSV</span>
                </button>
                <button onclick="openModal('generatePdfModal')" class="px-4 py-2 bg-blue-600 text-white rounded-lg shadow-sm hover:bg-blue-700 transition-colors flex items-center gap-2 text-sm font-medium">
                    <i class='bx bxs-file-pdf'></i> <span class="hidden sm:inline">Download PDF</span>
                </button>
            </div>
        </div>

        <?php if ($flash_message): ?>
        <div class="px-6">
            <div class="<?php echo ($flash_type === 'success') ? 'bg-green-100 text-green-800 border-green-200' : 'bg-red-100 text-red-800 border-red-200'; ?> border px-4 py-3 rounded-lg flex justify-between items-center mb-4 shadow-sm">
                <span><?php echo htmlspecialchars($flash_message); ?></span>
                <button onclick="this.parentElement.remove()" class="text-lg font-bold">&times;</button>
            </div>
        </div>
        <?php endif; ?>

        <div class="px-6 border-b border-gray-200 bg-breadly-bg">
            <div class="flex gap-6" id="dashboardTabs">
                <button onclick="switchTab('sales')" id="tab-sales" class="pb-3 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 <?php echo ($active_tab == 'sales') ? 'border-breadly-btn text-breadly-btn' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">
                    <i class='bx bx-line-chart text-lg'></i> Sales Analytics
                </button>
                <button onclick="switchTab('inventory')" id="tab-inventory" class="pb-3 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 <?php echo ($active_tab == 'inventory') ? 'border-breadly-btn text-breadly-btn' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">
                    <i class='bx bx-box text-lg'></i> Inventory Tracking
                </button>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto p-6 pb-20 bg-breadly-bg">
            
            <div id="pane-sales" class="<?php echo ($active_tab == 'sales') ? '' : 'hidden'; ?>">
                
                <div class="bg-white p-4 rounded-xl shadow-sm border border-orange-100 mb-6">
                    <form method="GET" action="dashboard_panel.php" class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end" id="date-filter-form">
                        <input type="hidden" name="active_tab" id="active_tab_input" value="<?php echo htmlspecialchars($active_tab); ?>">
                        
                        <div class="md:col-span-4">
                            <label class="block text-xs font-semibold text-gray-500 mb-1 uppercase tracking-wider">Start Date</label>
                            <input type="date" name="date_start" value="<?php echo htmlspecialchars($date_start); ?>" class="w-full p-2.5 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-breadly-btn focus:border-breadly-btn outline-none">
                        </div>
                        <div class="md:col-span-4">
                            <label class="block text-xs font-semibold text-gray-500 mb-1 uppercase tracking-wider">End Date</label>
                            <input type="date" name="date_end" value="<?php echo htmlspecialchars($date_end); ?>" class="w-full p-2.5 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-breadly-btn focus:border-breadly-btn outline-none">
                        </div>
                        <div class="md:col-span-2">
                            <button type="submit" class="w-full py-2.5 bg-breadly-btn text-white font-medium rounded-lg hover:bg-breadly-btn-hover transition-colors shadow-sm">Filter</button>
                        </div>
                        <div class="md:col-span-2">
                            <a href="dashboard_panel.php" class="flex items-center justify-center w-full py-2.5 border border-gray-300 text-gray-600 font-medium rounded-lg hover:bg-gray-50 transition-colors">Reset</a>
                        </div>
                    </form>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
                    
                    <div class="bg-breadly-card-1 p-6 rounded-xl shadow-sm flex flex-col justify-between h-auto min-h-[8rem] border border-orange-100">
                        <div>
                            <h2 class="text-3xl font-bold text-green-700">₱<?php echo number_format($netRevenue, 2); ?></h2>
                            <p class="text-sm text-breadly-dark font-medium mt-1">Net Sales Revenue</p>
                        </div>
                        <div class="text-xs text-gray-600 mt-3 pt-2 border-t border-orange-200/50">
                            <div class="flex justify-between items-center mb-1">
                                <span>Gross:</span>
                                <span class="font-medium">₱<?php echo number_format($grossRevenue, 2); ?></span>
                            </div>
                            <div class="flex justify-between items-center text-red-600">
                                <span>Returns:</span>
                                <span class="font-medium">-₱<?php echo number_format($totalReturnsValue, 2); ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="bg-breadly-card-2 p-6 rounded-xl shadow-sm flex flex-col justify-between h-auto min-h-[8rem] border border-orange-100">
                        <div>
                            <h2 class="text-3xl font-bold text-breadly-dark"><?php echo $dateRangeSummary['totalSales']; ?></h2>
                            <p class="text-sm text-breadly-dark font-medium mt-1">Total Orders</p>
                        </div>
                        <p class="text-xs text-gray-500 mt-2"><?php echo $date_range_text; ?></p>
                    </div>

                    <div class="bg-breadly-card-4 p-6 rounded-xl shadow-sm flex flex-col justify-between h-auto min-h-[8rem] border border-orange-100">
                        <div>
                            <h2 class="text-3xl font-bold <?php echo ($totalReturnsCount > 0) ? 'text-red-600' : 'text-green-700'; ?>">
                                <?php echo $totalReturnsCount; ?>
                            </h2>
                            <p class="text-sm text-breadly-dark font-medium mt-1">Total Returns</p>
                        </div>
                        <p class="text-xs text-gray-600 mt-2">Items Returned</p>
                    </div>

                    <div class="bg-breadly-card-2 p-6 rounded-xl shadow-sm flex flex-col justify-between h-auto min-h-[8rem] border border-orange-100">
                        <div>
                            <h2 class="text-3xl font-bold <?php echo ($totalReturnsValue > 0) ? 'text-red-600' : 'text-green-700'; ?>">
                                ₱<?php echo number_format($totalReturnsValue, 2); ?>
                            </h2>
                            <p class="text-sm text-breadly-dark font-medium mt-1">Return Amount</p>
                        </div>
                        <p class="text-xs text-gray-500 mt-2"><?php echo $date_range_text; ?></p>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-orange-100">
                        <h3 class="font-bold text-gray-800 mb-4">Top Selling Products</h3>
                        <div class="chart-container">
                            <canvas id="topProductsChart" 
                                    data-products="<?php echo htmlspecialchars(json_encode($topProducts)); ?>" 
                                    data-date-range="<?php echo htmlspecialchars($date_range_text); ?>">
                            </canvas>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-xl shadow-sm border border-orange-100">
                        <h3 class="font-bold text-gray-800 mb-4">Revenue vs Returns Trend</h3>
                        <div class="chart-container">
                            <canvas id="dailyTrendChart" 
                                    data-trend="<?php echo htmlspecialchars(json_encode($dailyTrendData)); ?>">
                            </canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div id="pane-inventory" class="<?php echo ($active_tab == 'inventory') ? '' : 'hidden'; ?>">
                
                <div class="bg-white p-4 rounded-xl shadow-sm border border-orange-100 mb-6">
                    <h2 class="text-lg font-bold text-breadly-dark flex items-center gap-2">
                        <i class='bx bxs-data'></i> Inventory Tracking (Daily Snapshot)
                    </h2>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6">
                    <div onclick="openModal('stockListModal')" class="bg-breadly-card-3 p-6 rounded-xl shadow-sm border border-orange-100 cursor-pointer hover:-translate-y-1 hover:shadow-md transition-all group h-40 flex flex-col justify-between relative overflow-hidden">
                        <div class="absolute right-0 top-0 p-4 opacity-10 group-hover:opacity-20 transition-opacity">
                            <i class='bx bxs-box text-6xl text-breadly-dark'></i>
                        </div>
                        <div>
                            <h2 class="text-4xl font-bold <?php echo ($productsWithStockCount > 0) ? 'text-blue-700' : 'text-green-700'; ?>">
                                <?php echo $productsWithStockCount; ?>
                            </h2>
                            <p class="text-breadly-dark font-semibold mt-1">Products in Stock</p>
                        </div>
                        <div class="text-xs text-gray-600 flex items-center gap-1">
                            View details <i class='bx bx-right-arrow-alt'></i>
                        </div>
                    </div>

                    <div onclick="openModal('ingredientStockModal')" class="p-6 rounded-xl shadow-sm border cursor-pointer hover:-translate-y-1 hover:shadow-md transition-all group h-40 flex flex-col justify-between relative overflow-hidden <?php echo ($lowStockCount > 0) ? 'bg-red-50 border-red-100' : 'bg-green-50 border-green-100'; ?>">
                        <div class="absolute right-0 top-0 p-4 opacity-10 group-hover:opacity-20 transition-opacity">
                            <i class='bx bxs-error-circle text-6xl text-black'></i>
                        </div>
                        <div>
                            <h2 class="text-4xl font-bold <?php echo ($lowStockCount > 0) ? 'text-red-600' : 'text-green-700'; ?>">
                                <?php echo $lowStockCount; ?>
                            </h2>
                            <p class="text-breadly-dark font-semibold mt-1">Low Stock Ingredients</p>
                        </div>
                        <div class="text-xs flex items-center gap-1 <?php echo ($lowStockCount > 0) ? 'text-red-700' : 'text-green-700'; ?>">
                            <?php echo ($lowStockCount > 0) ? 'Action Required' : 'Fully Stocked'; ?>
                        </div>
                    </div>

                    <a href="inventory_management.php?active_tab=recall" class="bg-breadly-card-4 p-6 rounded-xl shadow-sm border border-orange-100 cursor-pointer hover:-translate-y-1 hover:shadow-md transition-all group h-40 flex flex-col justify-between relative overflow-hidden block">
                        <div class="absolute right-0 top-0 p-4 opacity-10 group-hover:opacity-20 transition-opacity">
                            <i class='bx bxs-x-circle text-6xl text-breadly-dark'></i>
                        </div>
                        <div>
                            <h2 class="text-4xl font-bold <?php echo ($totalRecalledCount > 0) ? 'text-red-600' : 'text-green-700'; ?>">
                                <?php echo $totalRecalledCount; ?>
                            </h2>
                            <p class="text-breadly-dark font-semibold mt-1">Products Recalled (Qty)</p>
                        </div>
                        <div class="text-xs text-gray-600">
                            Value: 
                            <strong class="font-medium <?php echo ($totalRecalledValue > 0) ? 'text-red-700' : 'text-green-700'; ?>">
                                ₱<?php echo number_format($totalRecalledValue, 2); ?>
                            </strong>
                        </div>
                    </a>

                    <div onclick="openModal('expirationModal')" class="bg-yellow-50 p-6 rounded-xl shadow-sm border border-yellow-100 cursor-pointer hover:-translate-y-1 hover:shadow-md transition-all group h-40 flex flex-col justify-between relative overflow-hidden">
                        <div class="absolute right-0 top-0 p-4 opacity-10 group-hover:opacity-20 transition-opacity">
                            <i class='bx bxs-hourglass text-6xl text-yellow-700'></i>
                        </div>
                        <div>
                            <h2 class="text-4xl font-bold <?php echo ($expiringCount > 0) ? 'text-red-500' : 'text-green-600'; ?>">
                                <?php echo $expiringCount; ?>
                            </h2>
                            <p class="text-breadly-dark font-semibold mt-1">Expiring Batches</p>
                        </div>
                        <div class="text-xs text-yellow-800">
                            Next 7 Days
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <div id="exportCsvModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal('exportCsvModal')"></div>
        <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
            <div class="bg-green-600 p-4 flex justify-between items-center text-white">
                <h5 class="font-bold flex items-center gap-2"><i class='bx bxs-file-csv'></i> Export CSV Report</h5>
                <button onclick="closeModal('exportCsvModal')" class="text-white/80 hover:text-white"><i class='bx bx-x text-2xl'></i></button>
            </div>
            <form action="generate_csv_report.php" method="POST" target="_blank" id="csvReportForm" class="p-6">
                <div class="mb-4">
                    <label class="block text-sm font-bold text-gray-700 mb-2">Select Reports:</label>
                    <div class="space-y-2">
                        <label class="flex items-center gap-2 p-2 border rounded-lg hover:bg-gray-50 cursor-pointer">
                            <input type="checkbox" name="report_types[]" value="sales" checked class="rounded text-green-600 focus:ring-green-500">
                            <span class="text-sm">Sales Transactions</span>
                        </label>
                        <label class="flex items-center gap-2 p-2 border rounded-lg hover:bg-gray-50 cursor-pointer">
                            <input type="checkbox" name="report_types[]" value="product_inventory" class="rounded text-green-600 focus:ring-green-500">
                            <span class="text-sm">Product Inventory</span>
                        </label>
                        <label class="flex items-center gap-2 p-2 border rounded-lg hover:bg-gray-50 cursor-pointer">
                            <input type="checkbox" name="report_types[]" value="ingredient_inventory" class="rounded text-green-600 focus:ring-green-500">
                            <span class="text-sm">Ingredient Inventory</span>
                        </label>
                        <label class="flex items-center gap-2 p-2 border rounded-lg hover:bg-gray-50 cursor-pointer">
                            <input type="checkbox" name="report_types[]" value="returns" class="rounded text-green-600 focus:ring-green-500">
                            <span class="text-sm">Returns & Recalls</span>
                        </label>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3 mb-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 mb-1">Start Date</label>
                        <input type="date" name="date_start" value="<?php echo htmlspecialchars($date_start); ?>" required class="w-full p-2 bg-gray-50 border rounded-lg text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 mb-1">End Date</label>
                        <input type="date" name="date_end" value="<?php echo htmlspecialchars($date_end); ?>" required class="w-full p-2 bg-gray-50 border rounded-lg text-sm">
                    </div>
                </div>
                <div class="mb-4 bg-gray-50 p-3 rounded-lg border border-gray-200">
                    <label class="block text-xs font-bold text-gray-600 mb-2">Action:</label>
                    <div class="flex gap-4 mb-2">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="csv_action" value="download" checked onclick="toggleEmailField(false, 'csv')" class="text-green-600">
                            <span class="text-sm">Download</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="csv_action" value="email" onclick="toggleEmailField(true, 'csv')" class="text-green-600">
                            <span class="text-sm">Email</span>
                        </label>
                    </div>
                    <div id="csvEmailContainer" class="hidden">
                        <input type="email" name="recipient_email" placeholder="Enter email address" class="w-full p-2 border rounded bg-white text-sm">
                    </div>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="closeModal('exportCsvModal')" class="px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 rounded-lg transition-colors">Cancel</button>
                    <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-green-600 hover:bg-green-700 rounded-lg shadow transition-colors">Export</button>
                </div>
            </form>
        </div>
    </div>

    <div id="sendReportModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal('sendReportModal')"></div>
        <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
            <div class="bg-breadly-dark p-4 flex justify-between items-center text-white">
                <h5 class="font-bold flex items-center gap-2"><i class='bx bx-message-detail'></i> Send SMS Report</h5>
                <button onclick="closeModal('sendReportModal')" class="text-white/80 hover:text-white"><i class='bx bx-x text-2xl'></i></button>
            </div>
            <form id="sms-report-form" action="dashboard_panel.php" method="POST" class="p-6">
                <input type="hidden" name="action" value="send_summary_report">
                <input type="hidden" name="active_tab" value="<?php echo htmlspecialchars($active_tab); ?>">
                
                <div class="mb-4">
                    <label class="block text-sm font-bold text-gray-700 mb-2">Admin Name</label>
                    <select name="phone_number" required class="w-full p-2.5 bg-gray-50 border border-gray-200 rounded-lg text-sm">
                        <option value="" disabled selected>Select an admin...</option>
                        <?php foreach ($manager_list as $manager): ?>
                            <option value="<?php echo htmlspecialchars($manager['phone_number']); ?>">
                                <?php echo htmlspecialchars($manager['username']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="block text-xs font-bold text-gray-500 mb-1">Start Date</label>
                    <input type="date" name="date_start" value="<?php echo htmlspecialchars($date_start); ?>" required class="w-full p-2 bg-gray-50 border rounded-lg text-sm">
                </div>
                <div class="mb-6">
                    <label class="block text-xs font-bold text-gray-500 mb-1">End Date</label>
                    <input type="date" name="date_end" value="<?php echo htmlspecialchars($date_end); ?>" required class="w-full p-2 bg-gray-50 border rounded-lg text-sm">
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="closeModal('sendReportModal')" class="px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 rounded-lg transition-colors">Cancel</button>
                    <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-breadly-btn hover:bg-breadly-btn-hover rounded-lg shadow transition-colors">Send Report</button>
                </div>
            </form>
        </div>
    </div>

    <div id="generatePdfModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal('generatePdfModal')"></div>
        <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
            <div class="bg-blue-600 p-4 flex justify-between items-center text-white">
                <h5 class="font-bold flex items-center gap-2"><i class='bx bxs-file-pdf'></i> Generate PDF</h5>
                <button onclick="closeModal('generatePdfModal')" class="text-white/80 hover:text-white"><i class='bx bx-x text-2xl'></i></button>
            </div>
            <form action="generate_pdf_report.php" method="POST" target="_blank" id="pdfReportForm" class="p-6">
                <div class="mb-4">
                    <label class="block text-xs font-bold text-gray-500 mb-1">Start Date</label>
                    <input type="date" name="date_start" value="<?php echo htmlspecialchars($date_start); ?>" required class="w-full p-2 bg-gray-50 border rounded-lg text-sm">
                </div>
                <div class="mb-4">
                    <label class="block text-xs font-bold text-gray-500 mb-1">End Date</label>
                    <input type="date" name="date_end" value="<?php echo htmlspecialchars($date_end); ?>" required class="w-full p-2 bg-gray-50 border rounded-lg text-sm">
                </div>
                <div class="mb-4 bg-gray-50 p-3 rounded-lg border border-gray-200">
                    <label class="block text-xs font-bold text-gray-600 mb-2">Action:</label>
                    <div class="flex gap-4 mb-2">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="report_action" value="download" checked onclick="toggleEmailField(false, 'pdf')" class="text-blue-600">
                            <span class="text-sm">Download</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="report_action" value="email" onclick="toggleEmailField(true, 'pdf')" class="text-blue-600">
                            <span class="text-sm">Email</span>
                        </label>
                    </div>
                    <div id="pdfEmailContainer" class="hidden">
                        <input type="email" name="recipient_email" placeholder="Enter email address" class="w-full p-2 border rounded bg-white text-sm">
                    </div>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="closeModal('generatePdfModal')" class="px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 rounded-lg transition-colors">Cancel</button>
                    <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg shadow transition-colors">Generate</button>
                </div>
            </form>
        </div>
    </div>

    <div id="settingsModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal('settingsModal')"></div>
        <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
            <div class="bg-gray-800 p-4 flex justify-between items-center text-white">
                <h5 class="font-bold flex items-center gap-2"><i class='bx bx-cog'></i> My Settings</h5>
                <button onclick="closeModal('settingsModal')" class="text-white/80 hover:text-white"><i class='bx bx-x text-2xl'></i></button>
            </div>
            <form id="settings-form" action="dashboard_panel.php" method="POST" class="p-6">
                <input type="hidden" name="action" value="update_settings">
                <input type="hidden" name="active_tab" value="<?php echo htmlspecialchars($active_tab); ?>">
                
                <div class="mb-4">
                    <label class="block text-sm font-bold text-gray-700 mb-2">My Phone Number</label>
                    <input type="text" name="my_phone_number" value="<?php echo htmlspecialchars($userSettings['phone_number'] ?? ''); ?>" placeholder="0917xxxxxxx" maxlength="12" class="w-full p-2.5 bg-gray-50 border border-gray-200 rounded-lg text-sm">
                </div>
                <div class="mb-6 flex items-center gap-3 p-3 bg-gray-50 rounded-lg border border-gray-200">
                    <div class="relative inline-block w-10 mr-2 align-middle select-none transition duration-200 ease-in">
                        <input type="checkbox" name="enable_daily_report" id="enable_daily_report" class="absolute block w-6 h-6 rounded-full bg-white border-4 appearance-none cursor-pointer checked:right-0 checked:border-green-500" style="right: 100%; transform: translateX(100%);" value="1" <?php if (!empty($userSettings['enable_daily_report'])) echo 'checked'; ?>>
                        <label for="enable_daily_report" class="block overflow-hidden h-6 rounded-full bg-gray-300 cursor-pointer"></label>
                    </div>
                    <label for="enable_daily_report" class="text-sm font-medium text-gray-700 cursor-pointer">Receive Daily Reports</label>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="closeModal('settingsModal')" class="px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 rounded-lg transition-colors">Cancel</button>
                    <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-gray-800 hover:bg-gray-900 rounded-lg shadow transition-colors">Save</button>
                </div>
            </form>
        </div>
    </div>

    <div id="stockListModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal('stockListModal')"></div>
        <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-full max-w-3xl max-h-[85vh] bg-white rounded-2xl shadow-2xl overflow-hidden flex flex-col">
            <div class="p-4 border-b border-gray-100 flex justify-between items-center">
                <h5 class="font-bold text-lg">Products in Stock</h5>
                <button onclick="closeModal('stockListModal')" class="text-gray-400 hover:text-gray-600"><i class='bx bx-x text-2xl'></i></button>
            </div>
            <div class="p-4 bg-gray-50 flex justify-end border-b border-gray-100">
                <div class="relative group">
                    <button class="flex items-center gap-1 text-sm font-medium text-gray-600 bg-white border px-3 py-1.5 rounded-lg hover:bg-gray-50">
                        Sort By: <span class="current-sort-text">Name (A-Z)</span> <i class='bx bx-chevron-down'></i>
                    </button>
                    <div class="absolute right-0 mt-1 w-48 bg-white border border-gray-100 rounded-lg shadow-lg hidden group-hover:block z-20">
                        <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-orange-50 sort-trigger active" data-sort-by="name" data-sort-dir="asc" data-sort-type="text">Name (A-Z)</a>
                        <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-orange-50 sort-trigger" data-sort-by="name" data-sort-dir="desc" data-sort-type="text">Name (Z-A)</a>
                        <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-orange-50 sort-trigger" data-sort-by="stock" data-sort-dir="desc" data-sort-type="number">Stock (High-Low)</a>
                        <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-orange-50 sort-trigger" data-sort-by="stock" data-sort-dir="asc" data-sort-type="number">Stock (Low-High)</a>
                    </div>
                </div>
            </div>
            <div class="flex-1 overflow-y-auto p-0">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-gray-50 sticky top-0 text-xs uppercase text-gray-500 font-semibold">
                        <tr>
                            <th class="px-6 py-3">Product</th>
                            <th class="px-6 py-3">Current Stock</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 sortable-tbody">
                        <?php if ($productsWithStockCount == 0): ?>
                            <tr>
                                <td colspan="2" class="px-6 py-8 text-center text-gray-400">
                                    <i class='bx bxs-box text-2xl mb-2'></i>
                                    <p>All products are out of stock.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($productsWithStock as $product): ?>
                                <tr class="hover:bg-gray-50 transition-colors" data-name="<?php echo htmlspecialchars(strtolower($product['name'])); ?>" data-stock="<?php echo $product['stock_qty']; ?>">
                                    <td class="px-6 py-3 font-medium text-gray-800"><?php echo htmlspecialchars($product['name']); ?></td>
                                    <td class="px-6 py-3 font-bold text-blue-600"><?php echo $product['stock_qty']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="p-4 border-t border-gray-100 flex justify-between items-center bg-gray-50">
                <a href="inventory_management.php?active_tab=products" class="px-4 py-2 bg-breadly-btn text-white rounded-lg text-sm font-medium hover:bg-breadly-btn-hover transition-colors">Go to Inventory</a>
                <button onclick="closeModal('stockListModal')" class="px-4 py-2 bg-white border text-gray-600 rounded-lg text-sm font-medium hover:bg-gray-50">Close</button>
            </div>
        </div>
    </div>

    <div id="ingredientStockModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal('ingredientStockModal')"></div>
        <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-full max-w-3xl max-h-[85vh] bg-white rounded-2xl shadow-2xl overflow-hidden flex flex-col">
            <div class="p-4 border-b border-gray-100 flex justify-between items-center">
                <h5 class="font-bold text-lg">Ingredient Stock Levels</h5>
                <button onclick="closeModal('ingredientStockModal')" class="text-gray-400 hover:text-gray-600"><i class='bx bx-x text-2xl'></i></button>
            </div>
            <div class="p-4 bg-gray-50 flex justify-between items-center border-b border-gray-100">
                <label class="flex items-center gap-2 cursor-pointer select-none">
                    <input type="checkbox" id="filterLowStock" class="w-4 h-4 text-breadly-btn rounded border-gray-300 focus:ring-breadly-btn">
                    <span class="text-sm font-medium text-gray-700">Show Low Stock Only</span>
                </label>
                <div class="relative group">
                    <button class="flex items-center gap-1 text-sm font-medium text-gray-600 bg-white border px-3 py-1.5 rounded-lg hover:bg-gray-50">
                        Sort By: <span class="current-sort-text">Name (A-Z)</span> <i class='bx bx-chevron-down'></i>
                    </button>
                    <div class="absolute right-0 mt-1 w-48 bg-white border border-gray-100 rounded-lg shadow-lg hidden group-hover:block z-20">
                        <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-orange-50 sort-trigger active" data-sort-by="name" data-sort-dir="asc" data-sort-type="text">Name (A-Z)</a>
                        <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-orange-50 sort-trigger" data-sort-by="name" data-sort-dir="desc" data-sort-type="text">Name (Z-A)</a>
                        <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-orange-50 sort-trigger" data-sort-by="stock" data-sort-dir="desc" data-sort-type="number">Stock (High-Low)</a>
                        <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-orange-50 sort-trigger" data-sort-by="stock" data-sort-dir="asc" data-sort-type="number">Stock (Low-High)</a>
                    </div>
                </div>
            </div>
            <div class="flex-1 overflow-y-auto p-0">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-gray-50 sticky top-0 text-xs uppercase text-gray-500 font-semibold">
                        <tr>
                            <th class="px-6 py-3">Ingredient</th>
                            <th class="px-6 py-3">Current Stock</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 sortable-tbody">
                        <?php if (empty($allIngredients)): ?>
                            <tr>
                                <td colspan="2" class="px-6 py-8 text-center text-gray-400">
                                    <p>No ingredients found.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($allIngredients as $ing): 
                                $is_low = ($ing['stock_qty'] <= $ing['reorder_level']);
                            ?>
                                <tr class="hover:bg-gray-50 transition-colors" 
                                    data-name="<?php echo htmlspecialchars(strtolower($ing['name'])); ?>" 
                                    data-stock="<?php echo $ing['stock_qty']; ?>"
                                    data-is-low="<?php echo $is_low ? '1' : '0'; ?>">
                                    
                                    <td class="px-6 py-3 font-medium text-gray-800"><?php echo htmlspecialchars($ing['name']); ?></td>
                                    <td class="px-6 py-3">
                                        <strong class="<?php echo $is_low ? 'text-red-600' : 'text-green-700'; ?>">
                                            <?php echo number_format($ing['stock_qty'], 2) . ' ' . htmlspecialchars($ing['unit']); ?>
                                        </strong>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="p-4 border-t border-gray-100 flex justify-between items-center bg-gray-50">
                <a href="inventory_management.php?active_tab=ingredients" class="px-4 py-2 bg-breadly-btn text-white rounded-lg text-sm font-medium hover:bg-breadly-btn-hover transition-colors">Manage Ingredients</a>
                <button onclick="closeModal('ingredientStockModal')" class="px-4 py-2 bg-white border text-gray-600 rounded-lg text-sm font-medium hover:bg-gray-50">Close</button>
            </div>
        </div>
    </div>

    <div id="expirationModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal('expirationModal')"></div>
        <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-full max-w-3xl max-h-[85vh] bg-white rounded-2xl shadow-2xl overflow-hidden flex flex-col">
            <div class="p-4 border-b border-yellow-100 bg-yellow-50 flex justify-between items-center">
                <h5 class="font-bold text-lg text-yellow-800 flex items-center gap-2"><i class='bx bx-hourglass'></i> Expiring Batches (Next 7 Days)</h5>
                <button onclick="closeModal('expirationModal')" class="text-yellow-700 hover:text-yellow-900"><i class='bx bx-x text-2xl'></i></button>
            </div>
            <div class="flex-1 overflow-y-auto p-0">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-gray-50 sticky top-0 text-xs uppercase text-gray-500 font-semibold">
                        <tr>
                            <th class="px-6 py-3">Ingredient</th>
                            <th class="px-6 py-3">Expiry Date</th>
                            <th class="px-6 py-3">Status</th>
                            <th class="px-6 py-3">Remaining Qty</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (empty($expiringBatches)): ?>
                            <tr>
                                <td colspan="4" class="px-6 py-12 text-center text-gray-400">
                                    <i class='bx bx-check-circle text-4xl text-green-500 mb-2'></i>
                                    <p class="text-gray-500">No batches are expiring soon.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($expiringBatches as $batch): 
                                $days = $batch['days_remaining'];
                                $rowClass = '';
                                $badgeClass = 'bg-yellow-100 text-yellow-800';
                                $statusText = "Expires in $days days";

                                if ($days < 0) {
                                    $rowClass = 'bg-red-50';
                                    $badgeClass = 'bg-red-100 text-red-800';
                                    $statusText = "Expired " . abs($days) . " days ago";
                                } elseif ($days == 0) {
                                    $rowClass = 'bg-red-50';
                                    $badgeClass = 'bg-red-100 text-red-800';
                                    $statusText = "Expires Today!";
                                }
                            ?>
                                <tr class="hover:bg-gray-50 transition-colors <?php echo $rowClass; ?>">
                                    <td class="px-6 py-3 font-bold text-gray-800"><?php echo htmlspecialchars($batch['ingredient_name']); ?></td>
                                    <td class="px-6 py-3"><?php echo date('M d, Y', strtotime($batch['expiration_date'])); ?></td>
                                    <td class="px-6 py-3"><span class="px-2 py-1 rounded-full text-xs font-semibold <?php echo $badgeClass; ?>"><?php echo $statusText; ?></span></td>
                                    <td class="px-6 py-3"><?php echo $batch['quantity'] . ' ' . $batch['unit']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="p-4 border-t border-gray-100 flex justify-between items-center bg-gray-50">
                <a href="inventory_management.php?active_tab=ingredients" class="px-4 py-2 bg-breadly-btn text-white rounded-lg text-sm font-medium hover:bg-breadly-btn-hover transition-colors">Manage Ingredients</a>
                <button onclick="closeModal('expirationModal')" class="px-4 py-2 bg-white border text-gray-600 rounded-lg text-sm font-medium hover:bg-gray-50">Close</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <?php $js_version = filemtime('../js/script_dashboard.js'); ?>
    <script src="../js/script_dashboard.js?v=<?php echo $js_version; ?>"></script>

    <script>
    function toggleSidebar() {
        const sidebar = document.getElementById('mobileSidebar');
        const overlay = document.getElementById('mobileSidebarOverlay');
        
        if (sidebar.classList.contains('-translate-x-full')) {
            sidebar.classList.remove('-translate-x-full');
            overlay.classList.remove('hidden');
        } else {
            sidebar.classList.add('-translate-x-full');
            overlay.classList.add('hidden');
        }
    }

    function openModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) modal.classList.remove('hidden');
    }

    function closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) modal.classList.add('hidden');
    }

    function switchTab(tabName) {
        document.getElementById('pane-sales').classList.add('hidden');
        document.getElementById('pane-inventory').classList.add('hidden');
        document.getElementById('pane-' + tabName).classList.remove('hidden');
        
        const salesBtn = document.getElementById('tab-sales');
        const invBtn = document.getElementById('tab-inventory');
        
        const activeClasses = ['border-breadly-btn', 'text-breadly-btn'];
        const inactiveClasses = ['border-transparent', 'text-gray-500', 'hover:text-gray-700'];
        
        if (tabName === 'sales') {
            salesBtn.classList.add(...activeClasses);
            salesBtn.classList.remove(...inactiveClasses);
            invBtn.classList.remove(...activeClasses);
            invBtn.classList.add(...inactiveClasses);
        } else {
            invBtn.classList.add(...activeClasses);
            invBtn.classList.remove(...inactiveClasses);
            salesBtn.classList.remove(...activeClasses);
            salesBtn.classList.add(...inactiveClasses);
        }
    }

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
            container.classList.remove('hidden');
            emailInput.required = true;
            form.removeAttribute('target');
        } else {
            container.classList.add('hidden');
            emailInput.required = false;
            form.setAttribute('target', '_blank');
        }
    }

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
                            row.classList.add('hidden');
                        } else {
                            row.classList.remove('hidden');
                        }
                    } else {
                         row.classList.remove('hidden');
                    }
                });
            });
        }
    });
    </script>
</body>
</html>