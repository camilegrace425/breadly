<?php
session_start();
require_once "../src/SalesManager.php";
require_once "../src/BakeryManager.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

$valid_roles = ["manager", "assistant_manager", "cashier"];
if (!in_array($_SESSION["role"], $valid_roles)) {
    header("Location: ../index.php"); 
    exit();
}

$current_role = $_SESSION["role"];
$salesManager = new SalesManager();
$bakeryManager = new BakeryManager();

// Session Messages
$message = "";
$message_type = "";
if (isset($_SESSION["message"])) {
    $message = $_SESSION["message"];
    $message_type = $_SESSION["message_type"];
    unset($_SESSION["message"]);
    unset($_SESSION["message_type"]);
}

// Handle Return Processing
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"]) && $_POST["action"] === "process_return") {
    $sale_id = $_POST["sale_id"];
    $return_qty = $_POST["return_qty"];
    $max_qty = $_POST["max_qty"];
    $reason = $_POST["reason"];
    $user_id = $_SESSION["user_id"];

    if ($return_qty > $max_qty) {
        $_SESSION["message"] = "Error: Cannot return more than the $max_qty items from this sale.";
        $_SESSION["message_type"] = "error";
    } elseif ($return_qty <= 0) {
        $_SESSION["message"] = "Error: Return quantity must be a positive number.";
        $_SESSION["message_type"] = "error";
    } else {
        $status = $bakeryManager->returnSale($sale_id, $user_id, $return_qty, $reason);
        if (strpos($status, "Success") !== false) {
            $_SESSION["message"] = $status;
            $_SESSION["message_type"] = "success";
        } else {
            $_SESSION["message"] = $status;
            $_SESSION["message_type"] = "error";
        }
    }

    $query_params = http_build_query([
        "date_start" => $_GET["date_start"] ?? date("Y-m-d"),
        "date_end" => $_GET["date_end"] ?? date("Y-m-d"),
        "active_tab" => "sales",
    ]);
    header("Location: sales_history.php?" . $query_params);
    exit();
}

// Filter & Sort Parameters
$date_start = $_GET["date_start"] ?? date("Y-m-d");
$date_end = $_GET["date_end"] ?? date("Y-m-d");
$active_tab = $_GET["active_tab"] ?? "sales";
$sort_column = $_GET["sort"] ?? "date";
$sort_direction = $_GET["order"] ?? "DESC";

// Helper: Sort Link Generation
function getSortLink($column, $currentSort, $currentOrder, $dateStart, $dateEnd, $activeTab) {
    // This function is kept only to prevent PHP errors if still called somewhere else, but its output is unused for main table sorting.
    $newOrder = ($currentSort == $column && $currentOrder == "ASC") ? "DESC" : "ASC";
    return "sales_history.php?" . http_build_query([
        "date_start" => $dateStart,
        "date_end" => $dateEnd,
        "sort" => $column,
        "order" => $newOrder,
        "active_tab" => $activeTab,
    ]);
}

// Helper: Sort Display Text
function getCurrentSortText($sort_column, $sort_direction) {
    // This function is no longer needed for the main display as JS manages it.
    return "Timestamp (Descending)";
}

// Fetch Data
$sales = $salesManager->getSalesHistory($date_start, $date_end, "date", "DESC"); 
$all_return_history = $salesManager->getReturnHistory(); 

// Calculate Totals & Filter Display Data
$total_sales_revenue = array_sum(array_column($sales, "total_price"));

$filtered_return_history = [];
$total_return_value = 0;
$start_date_obj = new DateTime($date_start . ' 00:00:00');
$end_date_obj = new DateTime($date_end . ' 23:59:59');

foreach ($all_return_history as $log) {
    $return_date_obj = new DateTime($log['timestamp']);
    // Filter by date range for both display and total calculation
    if ($return_date_obj >= $start_date_obj && $return_date_obj <= $end_date_obj) {
        $total_return_value += $log["return_value"];
        $filtered_return_history[] = $log;
    }
}

$net_revenue = $total_sales_revenue - $total_return_value;
$active_nav_link = 'sales';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales & Transactions</title>
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
            <?php if ($current_role !== 'cashier'): ?>
            <a href="dashboard_panel.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors text-breadly-secondary hover:bg-orange-100 hover:text-breadly-dark">
                <i class='bx bxs-dashboard text-xl'></i><span class="font-medium">Dashboard</span>
            </a>
            <a href="inventory_management.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors text-breadly-secondary hover:bg-orange-100 hover:text-breadly-dark">
                <i class='bx bxs-box text-xl'></i><span class="font-medium">Inventory</span>
            </a>
            <a href="recipes.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors text-breadly-secondary hover:bg-orange-100 hover:text-breadly-dark">
                <i class='bx bxs-book-bookmark text-xl'></i><span class="font-medium">Recipes</span>
            </a>
            <?php endif; ?>
            
            <a href="sales_history.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors bg-breadly-dark text-white shadow-md">
                <i class='bx bx-history text-xl'></i><span class="font-medium">Sales History</span>
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
            <?php if ($current_role !== 'cashier'): ?>
                <a href="dashboard_panel.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-breadly-secondary">
                    <i class='bx bxs-dashboard text-xl'></i> Dashboard
                </a>
                <a href="inventory_management.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-breadly-secondary">
                    <i class='bx bxs-box text-xl'></i> Inventory
                </a>
                <a href="recipes.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-breadly-secondary">
                    <i class='bx bxs-book-bookmark text-xl'></i> Recipes
                </a>
            <?php endif; ?>
            
            <a href="sales_history.php" class="flex items-center gap-3 px-4 py-3 rounded-xl bg-breadly-dark text-white">
                <i class='bx bx-history text-xl'></i> Sales History
            </a>
            
            <a href="../index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-breadly-secondary mt-4 border-t border-orange-200 pt-4">
                <i class='bx bx-arrow-back text-xl'></i> Main Menu
            </a>
        </nav>
        <div class="p-4 border-t border-orange-200">
            <a href="logout.php" class="block w-full py-2 text-center text-sm bg-red-50 text-red-600 rounded-lg">Logout</a>
        </div>
    </div>

    <main class="flex-1 flex flex-col h-full overflow-hidden relative w-full">
        <div class="p-6 pb-2 flex flex-col md:flex-row md:items-center justify-between gap-4 bg-breadly-bg z-10">
            <div class="flex items-center gap-3">
                <button onclick="toggleSidebar()" class="lg:hidden text-breadly-dark text-2xl"><i class='bx bx-menu'></i></button>
                <h1 class="text-2xl font-bold text-breadly-dark">Sales & Transactions</h1>
            </div>
        </div>

        <?php if ($message): ?>
        <div class="px-6">
            <div class="<?php echo ($message_type === 'success') ? 'bg-green-100 text-green-800 border-green-200' : 'bg-red-100 text-red-800 border-red-200'; ?> border px-4 py-3 rounded-lg flex justify-between items-center mb-4 shadow-sm">
                <span><?php echo htmlspecialchars($message); ?></span>
                <button onclick="this.parentElement.remove()" class="text-lg font-bold">&times;</button>
            </div>
        </div>
        <?php endif; ?>

        <div class="px-6 border-b border-gray-200 bg-breadly-bg overflow-x-auto">
            <div class="flex gap-6 min-w-max" id="historyTabs">
                <button onclick="switchTab('sales')" id="tab-sales" class="pb-3 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 <?php echo ($active_tab == 'sales') ? 'border-breadly-btn text-breadly-btn' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">
                    <i class='bx bi-cash-coin text-lg'></i> Sales History
                </button>
                <button onclick="switchTab('returns')" id="tab-returns" class="pb-3 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 <?php echo ($active_tab == 'returns') ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">
                    <i class='bx bi-arrow-return-left text-lg'></i> Returns Log
                </button>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto p-6 pb-20 bg-breadly-bg">
            
            <div id="pane-sales" class="<?php echo ($active_tab == 'sales') ? '' : 'hidden'; ?>">
                <div class="bg-white rounded-xl shadow-sm border border-orange-100"> <div class="p-4 border-b border-orange-100">
                        <form method="GET" action="sales_history.php" class="flex flex-col md:flex-row gap-4 items-end">
                            <input type="hidden" name="active_tab" value="sales">
                            <div class="w-full md:w-auto flex-1">
                                <label class="block text-xs font-bold text-gray-500 mb-1">Start Date</label>
                                <input type="date" name="date_start" value="<?php echo htmlspecialchars($date_start); ?>" class="w-full p-2 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-breadly-btn outline-none">
                            </div>
                            <div class="w-full md:w-auto flex-1">
                                <label class="block text-xs font-bold text-gray-500 mb-1">End Date</label>
                                <input type="date" name="date_end" value="<?php echo htmlspecialchars($date_end); ?>" class="w-full p-2 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-breadly-btn outline-none">
                            </div>
                            <div class="w-full md:w-auto">
                                <button type="submit" class="w-full md:w-auto px-6 py-2 bg-breadly-btn text-white rounded-lg hover:bg-breadly-btn-hover transition-colors text-sm font-medium">Filter</button>
                            </div>
                        </form>
                    </div>

                    <div class="p-4 bg-gray-50 flex flex-wrap justify-end items-center gap-3 border-b border-gray-100">
                        <div class="flex items-center gap-1 text-sm">
                            <span class="text-gray-500">Show:</span>
                            <select id="sales-rows-select" class="bg-white border border-gray-200 rounded-lg text-sm p-1.5 focus:outline-none">
                                <option value="10">10</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="all">All</option>
                            </select>
                        </div>
                        <div class="flex items-center gap-1">
                            <button id="sales-prev-btn" disabled class="p-1.5 bg-white border rounded hover:bg-gray-100 disabled:opacity-50"><i class='bx bx-chevron-left'></i></button>
                            <button id="sales-next-btn" class="p-1.5 bg-white border rounded hover:bg-gray-100 disabled:opacity-50"><i class='bx bx-chevron-right'></i></button>
                        </div>
                        
                        <div class="relative dropdown" id="sales-sort-dropdown">
                            <button id="sales-sort-btn" class="flex items-center gap-2 px-3 py-1.5 bg-white border border-gray-200 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-50">
                                Sort By: <span class="current-sort-text">Timestamp (Descending)</span> <i class='bx bx-chevron-down'></i>
                            </button>
                            <div id="sales-sort-menu" class="absolute right-0 mt-1 w-48 bg-white border border-gray-100 rounded-lg shadow-lg hidden z-20 dropdown-menu">
                                <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-orange-50 sort-trigger" data-sort-by="date" data-sort-type="date" data-sort-dir="ASC">Timestamp (ASC)</a>
                                <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-orange-50 sort-trigger active" data-sort-by="date" data-sort-type="date" data-sort-dir="DESC">Timestamp (DESC)</a>
                                <div class="border-t border-gray-100 my-1"></div>
                                <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-orange-50 sort-trigger" data-sort-by="sale_id" data-sort-type="number" data-sort-dir="ASC">Sale ID (ASC)</a>
                                <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-orange-50 sort-trigger" data-sort-by="sale_id" data-sort-type="number" data-sort-dir="DESC">Sale ID (DESC)</a>
                                <div class="border-t border-gray-100 my-1"></div>
                                <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-orange-50 sort-trigger" data-sort-by="product" data-sort-type="text" data-sort-dir="ASC">Product (ASC)</a>
                                <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-orange-50 sort-trigger" data-sort-by="product" data-sort-type="text" data-sort-dir="DESC">Product (DESC)</a>
                                <div class="border-t border-gray-100 my-1"></div>
                                <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-orange-50 sort-trigger" data-sort-by="net_total" data-sort-type="number" data-sort-dir="ASC">Net Total (ASC)</a>
                                <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-orange-50 sort-trigger" data-sort-by="net_total" data-sort-type="number" data-sort-dir="DESC">Net Total (DESC)</a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead class="bg-gray-50 text-xs uppercase text-gray-500 font-semibold">
                                <tr>
                                    <th class="px-6 py-3" data-sort-by="date" data-sort-type="date">Timestamp</th>
                                    <th class="px-6 py-3" data-sort-by="sale_id" data-sort-type="number">Sale ID</th>
                                    <th class="px-6 py-3" data-sort-by="product" data-sort-type="text">Product</th>
                                    <th class="px-6 py-3">Qty</th>
                                    <th class="px-6 py-3">Subtotal</th>
                                    <th class="px-6 py-3">Discount</th>
                                    <th class="px-6 py-3" data-sort-by="net_total" data-sort-type="number">Net Total</th>
                                    <th class="px-6 py-3">Cashier</th>
                                    <th class="px-6 py-3 text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100" id="sales-table-body">
                                <?php if (empty($sales)): ?>
                                    <tr><td colspan="9" class="px-6 py-8 text-center text-gray-400">No sales found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($sales as $sale): 
                                        $qty_available = $sale["qty_sold"] - $sale["qty_returned"];
                                    ?>
                                    <tr class="hover:bg-gray-50 transition-colors">
                                        <td class="px-6 py-3 text-sm" data-sort-value="<?php echo strtotime($sale["date"]); ?>"><?php echo htmlspecialchars(date("M d, Y h:i A", strtotime($sale["date"]))); ?></td>
                                        <td class="px-6 py-3 text-gray-600" data-sort-value="<?php echo $sale["sale_id"]; ?>"><?php echo $sale["sale_id"]; ?></td>
                                        <td class="px-6 py-3 font-medium text-gray-800"><?php echo htmlspecialchars($sale["product_name"]); ?></td>
                                        <td class="px-6 py-3">
                                            <?php echo $sale["qty_sold"]; ?>
                                            <?php if ($sale["qty_returned"] > 0): ?>
                                                <span class="bg-blue-100 text-blue-800 text-xs px-2 py-0.5 rounded ml-1">-<?php echo $sale["qty_returned"]; ?> returned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-3 text-gray-500">₱<?php echo number_format($sale["subtotal"], 2); ?></td>
                                        <td class="px-6 py-3 text-red-500">
                                            <?php if (isset($sale["discount_amount"]) && $sale["discount_amount"] > 0.005): ?>
                                                (₱<?php echo number_format($sale["discount_amount"], 2); ?>)
                                                <span class="bg-red-100 text-red-800 text-xs px-1 rounded ml-1"><?php echo $sale["discount_percent"]; ?>%</span>
                                            <?php else: ?>
                                                <span class="text-gray-400">₱0.00</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-3 font-bold text-green-700" data-sort-value="<?php echo $sale["total_price"]; ?>">₱<?php echo number_format($sale["total_price"], 2); ?></td>
                                        <td class="px-6 py-3 text-sm"><?php echo htmlspecialchars($sale["cashier_username"]); ?></td>
                                        <td class="px-6 py-3 text-center">
                                            <button onclick="openReturnModal(this)" 
                                                class="px-3 py-1 bg-blue-50 text-blue-600 rounded-lg hover:bg-blue-100 transition-colors text-xs font-medium flex items-center gap-1 mx-auto disabled:opacity-50 disabled:cursor-not-allowed"
                                                data-sale-id="<?php echo $sale["sale_id"]; ?>"
                                                data-product-name="<?php echo htmlspecialchars($sale["product_name"]); ?>"
                                                data-qty-available="<?php echo $qty_available; ?>"
                                                data-sale-date="<?php echo htmlspecialchars(date("M d, Y", strtotime($sale["date"]))); ?>"
                                                <?php if ($qty_available <= 0) echo "disabled"; ?>>
                                                <i class='bx bx-undo'></i> <?php echo $qty_available <= 0 ? "Returned" : "Return"; ?>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="bg-gray-50 p-4 border-t border-gray-200">
                        <div class="flex flex-col items-end gap-1 text-sm">
                            <div class="flex justify-between w-48 text-gray-600">
                                <span>Gross Revenue:</span>
                                <span>₱<?php echo number_format($total_sales_revenue, 2); ?></span>
                            </div>
                            <div class="flex justify-between w-48 text-red-500">
                                <span>Less Returns:</span>
                                <span>(₱<?php echo number_format($total_return_value, 2); ?>)</span>
                            </div>
                            <div class="w-48 border-t border-gray-300 my-1"></div>
                            <div class="flex justify-between w-48 font-bold text-lg text-breadly-dark">
                                <span>Net Revenue:</span>
                                <span>₱<?php echo number_format($net_revenue, 2); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="pane-returns" class="<?php echo ($active_tab == 'returns') ? '' : 'hidden'; ?>">
                <div class="bg-white rounded-xl shadow-sm border border-blue-100"> <div class="p-4 border-b border-blue-100">
                        <form method="GET" action="sales_history.php" class="flex flex-col md:flex-row gap-4 items-end">
                            <input type="hidden" name="active_tab" value="returns">
                            <div class="w-full md:w-auto flex-1">
                                <label class="block text-xs font-bold text-gray-500 mb-1">Start Date</label>
                                <input type="date" name="date_start" value="<?php echo htmlspecialchars($date_start); ?>" class="w-full p-2 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                            </div>
                            <div class="w-full md:w-auto flex-1">
                                <label class="block text-xs font-bold text-gray-500 mb-1">End Date</label>
                                <input type="date" name="date_end" value="<?php echo htmlspecialchars($date_end); ?>" class="w-full p-2 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                            </div>
                            <div class="w-full md:w-auto">
                                <button type="submit" class="w-full md:w-auto px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm font-medium">Filter Returns</button>
                            </div>
                        </form>
                    </div>

                    <div class="p-4 border-b border-blue-100 bg-blue-50/30 flex justify-between items-center">
                        <h5 class="font-bold text-lg text-blue-800">Returns Log</h5>
                        
                        <div class="flex items-center gap-3">
                            <div class="flex items-center gap-1 text-sm">
                                <span class="text-gray-500">Show:</span>
                                <select id="returns-rows-select" class="bg-white border border-gray-200 rounded-lg text-sm p-1.5 focus:outline-none">
                                    <option value="10">10</option>
                                    <option value="25">25</option>
                                    <option value="50">50</option>
                                    <option value="all">All</option>
                                </select>
                            </div>
                            <div class="flex items-center gap-1">
                                <button id="returns-prev-btn" disabled class="p-1.5 bg-white border rounded hover:bg-gray-100 disabled:opacity-50"><i class='bx bx-chevron-left'></i></button>
                                <button id="returns-next-btn" class="p-1.5 bg-white border rounded hover:bg-gray-100 disabled:opacity-50"><i class='bx bx-chevron-right'></i></button>
                            </div>
                            
                            <div class="relative dropdown" id="returns-sort-dropdown">
                                <button id="returns-sort-btn" class="flex items-center gap-2 px-3 py-1.5 bg-white border border-gray-200 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-50">
                                    Sort By: <span class="current-sort-text">Timestamp (Descending)</span> <i class='bx bx-chevron-down'></i>
                                </button>
                                <div id="returns-sort-menu" class="absolute right-0 mt-1 w-52 bg-white border border-gray-100 rounded-lg shadow-lg hidden z-20 dropdown-menu">
                                    <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 sort-trigger" data-sort-by="date" data-sort-type="date" data-sort-dir="ASC">Timestamp (ASC)</a>
                                    <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 sort-trigger active" data-sort-by="date" data-sort-type="date" data-sort-dir="DESC">Timestamp (DESC)</a>
                                    <div class="border-t border-gray-100 my-1"></div>
                                    <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 sort-trigger" data-sort-by="product" data-sort-type="text" data-sort-dir="ASC">Product (ASC)</a>
                                    <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 sort-trigger" data-sort-by="product" data-sort-type="text" data-sort-dir="DESC">Product (DESC)</a>
                                    <div class="border-t border-gray-100 my-1"></div>
                                    <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 sort-trigger" data-sort-by="qty" data-sort-type="number" data-sort-dir="ASC">Quantity (ASC)</a>
                                    <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 sort-trigger" data-sort-by="qty" data-sort-type="number" data-sort-dir="DESC">Quantity (DESC)</a>
                                    <div class="border-t border-gray-100 my-1"></div>
                                    <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 sort-trigger" data-sort-by="refunded_value" data-sort-type="number" data-sort-dir="ASC">Refunded Value (ASC)</a>
                                    <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 sort-trigger" data-sort-by="refunded_value" data-sort-type="number" data-sort-dir="DESC">Refunded Value (DESC)</a>
                                    <div class="border-t border-gray-100 my-1"></div>
                                    <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 sort-trigger" data-sort-by="cashier" data-sort-type="text" data-sort-dir="ASC">Cashier (ASC)</a>
                                    <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 sort-trigger" data-sort-by="cashier" data-sort-type="text" data-sort-dir="DESC">Cashier (DESC)</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead class="bg-gray-50 text-xs uppercase text-gray-500 font-semibold">
                                <tr>
                                    <th class="px-6 py-3" data-sort-by="date" data-sort-type="date">Timestamp</th>
                                    <th class="px-6 py-3" data-sort-by="sale_id" data-sort-type="number">Sale ID</th>
                                    <th class="px-6 py-3" data-sort-by="product" data-sort-type="text">Product</th>
                                    <th class="px-6 py-3" data-sort-by="qty" data-sort-type="number">Quantity</th>
                                    <th class="px-6 py-3" data-sort-by="refunded_value" data-sort-type="number">Refunded</th>
                                    <th class="px-6 py-3" data-sort-by="cashier" data-sort-type="text">Cashier</th>
                                    <th class="px-6 py-3">Reason</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100" id="returns-table-body">
                                <?php if (empty($filtered_return_history)): ?>
                                    <tr><td colspan="7" class="px-6 py-8 text-center text-gray-400">No returns found for the selected date range.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($filtered_return_history as $log): ?>
                                    <tr class="hover:bg-gray-50 transition-colors">
                                        <td class="px-6 py-3 text-sm" data-sort-value="<?php echo strtotime($log["timestamp"]); ?>"><?php echo htmlspecialchars(date("M d, Y h:i A", strtotime($log["timestamp"]))); ?></td>
                                        <td class="px-6 py-3 text-gray-600" data-sort-value="<?php echo $log["sale_id"]; ?>"><?php echo $log["sale_id"]; ?></td>
                                        <td class="px-6 py-3 font-medium text-gray-800"><?php echo htmlspecialchars($log["product_name"] ?? "Deleted"); ?></td>
                                        <td class="px-6 py-3 font-bold text-green-600" data-sort-value="<?php echo $log["qty_returned"]; ?>">+<?php echo $log["qty_returned"]; ?></td>
                                        <td class="px-6 py-3 text-blue-600" data-sort-value="<?php echo $log["return_value"]; ?>">(₱<?php echo number_format($log["return_value"], 2); ?>)</td>
                                        <td class="px-6 py-3 text-sm"><?php echo htmlspecialchars($log["username"] ?? "N/A"); ?></td>
                                        <td class="px-6 py-3 text-sm"><?php echo htmlspecialchars($log["reason"]); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <div id="modalBackdrop" class="fixed inset-0 bg-black/50 z-40 hidden transition-opacity" onclick="closeAllModals()"></div>

    <div id="returnSaleModal" class="fixed inset-0 z-50 hidden flex items-center justify-center">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md m-4 overflow-hidden relative z-50">
            <div class="p-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
                <h5 class="font-bold text-gray-800">Process Return</h5>
                <button onclick="closeModal('returnSaleModal')" class="text-gray-400 hover:text-gray-600"><i class='bx bx-x text-2xl'></i></button>
            </div>
            <form action="sales_history.php?date_start=<?php echo htmlspecialchars($date_start); ?>&date_end=<?php echo htmlspecialchars($date_end); ?>" method="POST" class="p-6">
                <input type="hidden" name="action" value="process_return">
                <input type="hidden" name="sale_id" id="return_sale_id">
                <input type="hidden" name="max_qty" id="return_max_qty">
                
                <div class="mb-4 p-3 bg-blue-50 rounded-lg border border-blue-100 text-sm">
                    <p><strong>Product:</strong> <span id="return_product_name" class="text-blue-800"></span></p>
                    <p><strong>Date:</strong> <span id="return_sale_date" class="text-blue-800"></span></p>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Quantity to Return</label>
                    <input type="number" name="return_qty" id="return_qty" min="1" required class="w-full p-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                    <p class="text-xs text-gray-500 mt-1">Max <span id="return_qty_sold_text"></span> items available.</p>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Reason</label>
                    <input type="text" name="reason" id="return_reason" required placeholder="e.g. Refund, Wrong item" class="w-full p-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="closeModal('returnSaleModal')" class="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Confirm</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <?php $js_version = file_exists("../js/script_sales_history.js") ? filemtime("../js/script_sales_history.js") : "1"; ?>
    <script src="../js/script_sales_history.js?v=<?php echo $js_version; ?>"></script>

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
            const backdrop = document.getElementById('modalBackdrop');
            if (modal) {
                modal.classList.remove('hidden');
                if(backdrop) backdrop.classList.remove('hidden');
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            const backdrop = document.getElementById('modalBackdrop');
            if (modal) modal.classList.add('hidden');
            if(backdrop) backdrop.classList.add('hidden');
        }
        
        function closeAllModals() {
            document.querySelectorAll('.fixed.z-50').forEach(el => el.classList.add('hidden'));
            const backdrop = document.getElementById('modalBackdrop');
            if(backdrop) backdrop.classList.add('hidden');
        }

        function openReturnModal(button) {
            const modal = document.getElementById('returnSaleModal');
            
            document.getElementById('return_sale_id').value = button.dataset.saleId;
            document.getElementById('return_product_name').textContent = button.dataset.productName;
            document.getElementById('return_sale_date').textContent = button.dataset.saleDate;
            
            const qtyAvailable = parseInt(button.dataset.qtyAvailable);
            const qtyInput = document.getElementById('return_qty');
            
            qtyInput.value = qtyAvailable;
            qtyInput.max = qtyAvailable;
            document.getElementById('return_max_qty').value = qtyAvailable;
            document.getElementById('return_qty_sold_text').textContent = qtyAvailable;
            document.getElementById('return_reason').value = '';

            openModal('returnSaleModal');
        }

        function switchTab(tabName) {
            document.querySelectorAll('[id^="pane-"]').forEach(el => el.classList.add('hidden'));
            document.getElementById('pane-' + tabName).classList.remove('hidden');
            
            const salesBtn = document.getElementById('tab-sales');
            const returnsBtn = document.getElementById('tab-returns');
            
            salesBtn.className = 'pb-3 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 border-transparent text-gray-500 hover:text-gray-700';
            returnsBtn.className = 'pb-3 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 border-transparent text-gray-500 hover:text-gray-700';
            
            if (tabName === 'sales') {
                salesBtn.className = 'pb-3 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 border-breadly-btn text-breadly-btn';
            } else {
                returnsBtn.className = 'pb-3 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 border-blue-500 text-blue-600';
            }
        }
    </script>
</body>
</html>