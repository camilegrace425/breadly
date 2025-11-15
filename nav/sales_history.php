<?php
session_start();
require_once "../src/SalesManager.php";
require_once "../src/InventoryManager.php";
require_once "../src/BakeryManager.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}
if ($_SESSION["role"] !== "manager") {
    header("Location: index.php");
    exit();
}

// --- Managers ---
$salesManager = new SalesManager();
$inventoryManager = new InventoryManager();
$bakeryManager = new BakeryManager();

// --- Message Handling ---
$message = "";
$message_type = "";
if (isset($_SESSION["message"])) {
    $message = $_SESSION["message"];
    $message_type = $_SESSION["message_type"];
    unset($_SESSION["message"]);
    unset($_SESSION["message_type"]);
}

// --- POST HANDLING FOR RETURNS ---
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["action"]) &&
    $_POST["action"] === "process_return"
) {
    $sale_id = $_POST["sale_id"];
    $return_qty = $_POST["return_qty"];
    $max_qty = $_POST["max_qty"];
    $reason = $_POST["reason"];
    $user_id = $_SESSION["user_id"];

    if ($return_qty > $max_qty) {
        $_SESSION[
            "message"
        ] = "Error: Cannot return more than the $max_qty items from this sale.";
        $_SESSION["message_type"] = "danger";
    } elseif ($return_qty <= 0) {
        $_SESSION["message"] =
            "Error: Return quantity must be a positive number.";
        $_SESSION["message_type"] = "danger";
    } else {
        $status = $bakeryManager->returnSale(
            $sale_id,
            $user_id,
            $return_qty,
            $reason
        );

        if (strpos($status, "Success") !== false) {
            $_SESSION["message"] = $status;
            $_SESSION["message_type"] = "success";
        } else {
            $_SESSION["message"] = $status;
            $_SESSION["message_type"] = "danger";
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

// Handle date filtering
$date_start = $_GET["date_start"] ?? date("Y-m-d");
$date_end = $_GET["date_end"] ?? date("Y-m-d");

// --- Active Tab Handling ---
$active_tab = $_GET["active_tab"] ?? "sales";

// --- Handle Sorting ---
$sort_column = $_GET["sort"] ?? "date";
$sort_direction = $_GET["order"] ?? "DESC";

function getSortLink(
    $column,
    $currentSort,
    $currentOrder,
    $dateStart,
    $dateEnd,
    $activeTab
) {
    $newOrder =
        $currentSort == $column && $currentOrder == "ASC" ? "DESC" : "ASC";

    $params = http_build_query([
        "date_start" => $dateStart,
        "date_end" => $dateEnd,
        "sort" => $column,
        "order" => $newOrder,
        "active_tab" => $activeTab,
    ]);
    return "sales_history.php?" . $params;
}

function getCurrentSortText($sort_column, $sort_direction)
{
    // --- MODIFIED MAP ---
    $column_map = [
        "sale_id" => "Sale ID",
        "date" => "Timestamp",
        "product" => "Product Name",
        "qty" => "Quantity",
        "subtotal" => "Subtotal",
        "discount_amt" => "Discount",
        "price" => "Net Total",
        "cashier" => "Cashier",
    ];
    $direction_text = $sort_direction == "ASC" ? "Ascending" : "Descending";
    $column_text = $column_map[$sort_column] ?? "Timestamp";
    return "{$column_text} ({$direction_text})";
}

$sales = $salesManager->getSalesHistory(
    $date_start,
    $date_end,
    $sort_column,
    $sort_direction
);
$return_history = $salesManager->getReturnHistory();

$total_sales_revenue = 0;
foreach ($sales as $sale) {
    $total_sales_revenue += $sale["total_price"];
}

$total_return_value = 0;
$start_date_obj = new DateTime($date_start . ' 00:00:00');
$end_date_obj = new DateTime($date_end . ' 23:59:59');

foreach ($return_history as $log) {
    $return_date_obj = new DateTime($log['timestamp']);
    
    if ($return_date_obj >= $start_date_obj && $return_date_obj <= $end_date_obj) {
        $total_return_value += $log["return_value"];
    }
}

// --- ::: NEW: Calculate Net Revenue ::: ---
// We only subtract returns, as recalls are a separate "loss" metric
$net_revenue = $total_sales_revenue - $total_return_value;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales & Transactions</title>
    <link rel="icon" href="../images/kzklogo.png" type="image/x-icon"> 
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../styles/global.css"> 
    <link rel="stylesheet" href="../styles/dashboard.css"> 
</head>
<body class="dashboard">
<div class="container-fluid">
    <div class="row">
        <aside class="col-lg-2 col-md-3 sidebar">
             <div class="sidebar-brand">
                <img src="../images/kzklogo.png" alt="BREADLY Logo">
                <h5>BREADLY</h5>
                <p>Kz & Khyle's Bakery</p>
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
                    <a class="nav-link" href="recipes.php">
                        <i class="bi bi-journal-bookmark me-2"></i> Recipes
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="sales_history.php">
                        <i class="bi bi-clock-history me-2"></i> Sales & Transactions
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
                        <strong><?php echo htmlspecialchars(
                            $_SESSION["username"]
                        ); ?></strong>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark text-small shadow" aria-labelledby="userMenu">
                        <li><a class="dropdown-item" href="logout.php">Sign out</a></li>
                    </ul>
                </div>
            </div>
        </aside>

        <main class="col-lg-10 col-md-9 main-content">
            <div class="header">
                <h1>Sales & Transaction History</h1>
            </div>
            
            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <ul class="nav nav-tabs" id="historyTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $active_tab === "sales"
                        ? "active"
                        : ""; ?>" id="sales-tab" data-bs-toggle="tab" data-bs-target="#sales-pane" type="button" role="tab">
                        <i class="bi bi-cash-coin me-1"></i> Sales History
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link text-info <?php echo $active_tab ===
                    "returns"
                        ? "active"
                        : ""; ?>" id="returns-tab" data-bs-toggle="tab" data-bs-target="#returns-pane" type="button" role="tab">
                        <i class="bi bi-arrow-return-left me-1"></i> Returns Log
                    </button>
                </li>
                </ul>

            <div class="tab-content" id="historyTabContent">

                <div class="tab-pane fade <?php echo $active_tab === "sales"
                    ? "show active"
                    : ""; ?>" id="sales-pane" role="tabpanel">
                    <div class="card shadow-sm mt-3">
                        <div class="card-header">
                             <form method="GET" action="sales_history.php" class="row g-3 align-items-end">
                                <input type="hidden" name="active_tab" value="sales">
                                <div class="col-md-3">
                                    <label for="date_start" class="form-label">Start Date</label>
                                    <input type="date" class="form-control" name="date_start" id="date_start" value="<?php echo htmlspecialchars(
                                        $date_start
                                    ); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label for="date_end" class="form-label">End Date</label>
                                    <input type="date" class="form-control" name="date_end" id="date_end" value="<?php echo htmlspecialchars(
                                        $date_end
                                    ); ?>">
                                </div>
                                <div class="col-md-2">
                                     <button type="submit" class="btn btn-primary w-100">Filter</button>
                                </div>
                                <div class="col-md-4 d-flex align-items-end justify-content-end">
                                    <div class="d-flex gap-2 align-items-center">
                                    <div class="btn-group btn-group-sm ms-2" role="group">
                                                <button type="button" class="btn btn-outline-secondary" id="sales-prev-btn" disabled>
                                                    <i class="bi bi-arrow-left"></i>
                                                </button>
                                            </div>
                                        <div class="d-flex align-items-center gap-1">
                                            <label for="sales-rows-select" class="form-label mb-0 small text-muted flex-shrink-0">Show</label>
                                            <select class="form-select form-select-sm" id="sales-rows-select" style="width: auto;">
                                                <option value="10" selected>10</option>
                                                <option value="25">25</option>
                                                <option value="50">50</option>
                                                <option value="all">All</option>
                                            </select>
                                            <div class="btn-group btn-group-sm ms-2" role="group">
                                                <button type="button" class="btn btn-outline-secondary" id="sales-next-btn">
                                                    <i class="bi bi-arrow-right"></i>
                                                </button>
                                            </div>
                                            </div>
                                        <div class="dropdown">
                                            <button class="btn btn-outline-secondary dropdown-toggle" type="button" id="sortDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                                Sort By: <?php echo getCurrentSortText(
                                                    $sort_column,
                                                    $sort_direction
                                                ); ?>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="sortDropdown">
                                                <li><a class="dropdown-item <?php if ($sort_column == "date") { echo "active"; } ?>" 
                                                       href="<?php echo getSortLink("date", $sort_column, $sort_direction, $date_start, $date_end, "sales"); ?>">
                                                       Timestamp <?php if ($sort_column == "date") { echo $sort_direction == "ASC" ? "(Asc)" : "(Desc)"; } ?></a></li>
                                                <li><a class="dropdown-item <?php if ($sort_column == "product") { echo "active"; } ?>" 
                                                       href="<?php echo getSortLink("product", $sort_column, $sort_direction, $date_start, $date_end, "sales"); ?>">
                                                       Product <?php if ($sort_column == "product") { echo $sort_direction == "ASC" ? "(Asc)" : "(Desc)"; } ?></a></li>
                                                <li><a class="dropdown-item <?php if ($sort_column == "qty") { echo "active"; } ?>" 
                                                       href="<?php echo getSortLink("qty", $sort_column, $sort_direction, $date_start, $date_end, "sales"); ?>">
                                                       Quantity <?php if ($sort_column == "qty") { echo $sort_direction == "ASC" ? "(Asc)" : "(Desc)"; } ?></a></li>
                                                <li><a class="dropdown-item <?php if ($sort_column == "subtotal") { echo "active"; } ?>" 
                                                       href="<?php echo getSortLink("subtotal", $sort_column, $sort_direction, $date_start, $date_end, "sales"); ?>">
                                                       Subtotal <?php if ($sort_column == "subtotal") { echo $sort_direction == "ASC" ? "(Asc)" : "(Desc)"; } ?></a></li>
                                                <li><a class="dropdown-item <?php if ($sort_column == "discount_amt") { echo "active"; } ?>" 
                                                       href="<?php echo getSortLink("discount_amt", $sort_column, $sort_direction, $date_start, $date_end, "sales"); ?>">
                                                       Discount <?php if ($sort_column == "discount_amt") { echo $sort_direction == "ASC" ? "(Asc)" : "(Desc)"; } ?></a></li>
                                                <li><a class="dropdown-item <?php if ($sort_column == "price") { echo "active"; } ?>" 
                                                       href="<?php echo getSortLink("price", $sort_column, $sort_direction, $date_start, $date_end, "sales"); ?>">
                                                       Net Total <?php if ($sort_column == "price") { echo $sort_direction == "ASC" ? "(Asc)" : "(Desc)"; } ?></a></li>
                                                <li><a class="dropdown-item <?php if ($sort_column == "cashier") { echo "active"; } ?>" 
                                                       href="<?php echo getSortLink("cashier", $sort_column, $sort_direction, $date_start, $date_end, "sales"); ?>">
                                                       Cashier <?php if ($sort_column == "cashier") { echo $sort_direction == "ASC" ? "(Asc)" : "(Desc)"; } ?></a></li>
                                                <li><a class="dropdown-item <?php if ($sort_column == "sale_id") { echo "active"; } ?>" 
                                                       href="<?php echo getSortLink("sale_id", $sort_column, $sort_direction, $date_start, $date_end, "sales"); ?>">
                                                       Sale ID <?php if ($sort_column == "sale_id") { echo $sort_direction == "ASC" ? "(Asc)" : "(Desc)"; } ?></a></li>
                                            </ul>
                                            </div>
                                    </div>
                                </div>
                                </form>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover align-middle">
                                    <thead>
                                        <tr>
                                            <th>Sale ID</th>
                                            <th>Timestamp</th>
                                            <th>Product</th>
                                            <th>Quantity</th>
                                            <th>Subtotal</th>
                                            <th>Discount</th>
                                            <th>Net Total</th>
                                            <th>Cashier</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="sales-table-body">
                                        <?php if (empty($sales)): ?>
                                            <tr>
                                                <td colspan="9" class="text-center text-muted">No sales found for this period.</td> </tr>
                                        <?php else: ?>
                                            <?php foreach ($sales as $sale): ?>
                                            <?php $qty_available_to_return =
                                                $sale["qty_sold"] -
                                                $sale["qty_returned"]; ?>
                                            <tr>
                                                <td><?php echo $sale[
                                                    "sale_id"
                                                ]; ?></td>
                                                <td><?php echo htmlspecialchars(
                                                    date(
                                                        "M d, Y h:i A",
                                                        strtotime($sale["date"])
                                                    )
                                                ); ?></td>
                                                <td><?php echo htmlspecialchars(
                                                    $sale["product_name"]
                                                ); ?></td>
                                                <td>
                                                    <?php echo htmlspecialchars(
                                                        $sale["qty_sold"]
                                                    ); ?>
                                                    <?php if (
                                                        $sale["qty_returned"] >
                                                        0
                                                    ): ?>
                                                        <span class="badge bg-info text-dark ms-1">-<?php echo $sale[
                                                            "qty_returned"
                                                        ]; ?> returned</span>
                                                    <?php endif; ?>
                                                </td>
                                                
                                                <td class="text-muted">₱<?php echo htmlspecialchars(
                                                    number_format(
                                                        $sale["subtotal"],
                                                        2
                                                    )
                                                ); ?></td>
                                                <td class="text-danger">
                                                    <?php if (isset($sale["discount_amount"]) && $sale["discount_amount"] > 0.005): ?>
                                                        (₱<?php echo htmlspecialchars(number_format($sale["discount_amount"], 2)); ?>)
                                                        <span class="badge bg-danger ms-1"><?php echo htmlspecialchars($sale["discount_percent"]); ?>%</span>
                                                    <?php else: ?>
                                                        <span class="text-muted">₱0.00</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><strong>₱<?php echo htmlspecialchars(
                                                    number_format(
                                                        $sale["total_price"],
                                                        2
                                                    )
                                                ); ?></strong></td>
                                                <td><?php echo htmlspecialchars(
                                                    $sale["cashier_username"]
                                                ); ?></td>
                                                <td>
                                                    <button class="btn btn-info btn-sm"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#returnSaleModal"
                                                            data-sale-id="<?php echo $sale[
                                                                "sale_id"
                                                            ]; ?>"
                                                            data-product-name="<?php echo htmlspecialchars(
                                                                $sale[
                                                                    "product_name"
                                                                ]
                                                            ); ?>"
                                                            data-qty-available="<?php echo $qty_available_to_return; ?>"
                                                            data-sale-date="<?php echo htmlspecialchars(
                                                                date(
                                                                    "M d, Y h:i A",
                                                                    strtotime(
                                                                        $sale[
                                                                            "date"
                                                                        ]
                                                                    )
                                                                )
                                                            ); ?>"
                                                            <?php if (
                                                                $qty_available_to_return <=
                                                                0
                                                            ) {
                                                                echo "disabled";
                                                            } ?>>
                                                        <i class="bi bi-arrow-return-left"></i> 
                                                        <?php echo $qty_available_to_return <=
                                                        0
                                                            ? "Returned"
                                                            : "Return"; ?>
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="card-footer">
                            <div class="d-flex justify-content-end fw-bold">
                                <span class="me-3 text-muted">Gross Revenue:</span>
                                <span class="text-muted">₱<?php echo htmlspecialchars(
                                    number_format($total_sales_revenue, 2)
                                ); ?></span>
                            </div>
                            <div class="d-flex justify-content-end fw-bold">
                                <span class="me-3 text-info">Less Returns:</span>
                                <span class="text-info">(₱<?php echo htmlspecialchars(
                                    number_format($total_return_value, 2)
                                ); ?>)</span>
                            </div>
                            <hr class="my-1">
                            <div class="d-flex justify-content-end fw-bold fs-5">
                                <span class="me-3">Net Revenue:</span>
                                <span>₱<?php echo htmlspecialchars(
                                    number_format($net_revenue, 2)
                                ); ?></span>
                            </div>
                        </div>
                        </div>
                </div> <div class="tab-pane fade <?php echo $active_tab ===
                "returns"
                    ? "show active"
                    : ""; ?>" id="returns-pane" role="tabpanel">
                    <div class="card shadow-sm mt-3 border-info">
                        <div class="card-header bg-info-subtle d-flex justify-content-between align-items-center">
                            <span>Returns Log</span>
                            <div class="d-flex gap-2 align-items-center">
                            <div class="btn-group btn-group-sm ms-2" role="group">
                                        <button type="button" class="btn btn-outline-secondary" id="returns-prev-btn" disabled>
                                            <i class="bi bi-arrow-left"></i>
                                        </button>
                            </div>
                                <div class="d-flex align-items-center gap-1">
                                    <label for="returns-rows-select" class="form-label mb-0 small text-muted flex-shrink-0">Show</label>
                                    <select class="form-select form-select-sm" id="returns-rows-select" style="width: auto;">
                                        <option value="10" selected>10</option>
                                        <option value="25">25</option>
                                        <option value="50">50</option>
                                        <option value="all">All</option>
                                    </select>
                                    <div class="btn-group btn-group-sm ms-2" role="group">
                                        <button type="button" class="btn btn-outline-secondary" id="returns-next-btn">
                                            <i class="bi bi-arrow-right"></i>
                                        </button>
                                    </div>
                                    </div>
                                <div class="dropdown">
                                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                        Sort By: <span class="current-sort-text">Timestamp (Newest First)</span>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><a class="dropdown-item sort-trigger active" data-sort-by="timestamp" data-sort-dir="desc" data-sort-type="date" href="#">Timestamp (Newest First)</a></li>
                                        <li><a class="dropdown-item sort-trigger" data-sort-by="timestamp" data-sort-dir="asc" data-sort-type="date" href="#">Timestamp (Oldest First)</a></li>
                                        <li><a class="dropdown-item sort-trigger" data-sort-by="sale_id" data-sort-dir="desc" data-sort-type="number" href="#">Sale ID (High-Low)</a></li>
                                        <li><a class="dropdown-item sort-trigger" data-sort-by="sale_id" data-sort-dir="asc" data-sort-type="number" href="#">Sale ID (Low-High)</a></li>
                                        <li><a class="dropdown-item sort-trigger" data-sort-by="item" data-sort-dir="asc" data-sort-type="text" href="#">Product (A-Z)</a></li>
                                        <li><a class="dropdown-item sort-trigger" data-sort-by="item" data-sort-dir="desc" data-sort-type="text" href="#">Product (Z-A)</a></li>
                                        <li><a class="dropdown-item sort-trigger" data-sort-by="user" data-sort-dir="asc" data-sort-type="text" href="#">Cashier (A-Z)</a></li>
                                        <li><a class="dropdown-item sort-trigger" data-sort-by="qty" data-sort-dir="asc" data-sort-type="number" href="#">Quantity (Low-High)</a></li>
                                    </ul>
                                </div>
                            </div>
                            </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover align-middle">
                                    <thead>
                                        <tr>
                                            <th data-sort-by="timestamp" data-sort-type="date">Timestamp</th>
                                            <th data-sort-by="sale_id" data-sort-type="number">Sale ID</th>
                                            <th data-sort-by="item">Product</th>
                                            <th data-sort-by="qty" data-sort-type="number">Quantity</th>
                                            <th data-sort-by="value" data-sort-type="number">Refunded Value</th>
                                            <th data-sort-by="user">Cashier</th>
                                            <th>Reason</th>
                                        </tr>
                                    </thead>
                                    <tbody id="returns-table-body">
                                        <?php if (empty($return_history)): ?>
                                            <tr><td colspan="7" class="text-center text-muted">No return history found.</td></tr>
                                        <?php else: ?>
                                            <?php foreach (
                                                $return_history
                                                as $log
                                            ): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars(
                                                    date(
                                                        "M d, Y h:i A",
                                                        strtotime(
                                                            $log["timestamp"]
                                                        )
                                                    )
                                                ); ?></td>
                                                <td><?php echo htmlspecialchars(
                                                    $log["sale_id"]
                                                ); ?></td>
                                                <td><?php echo htmlspecialchars(
                                                    $log["product_name"] ??
                                                        "Item Deleted"
                                                ); ?></td>
                                                <td>
                                                    <strong class="text-success">+<?php echo number_format(
                                                        $log["qty_returned"],
                                                        0
                                                    ); ?></strong>
                                                </td>
                                                <td>
                                                    <span class="text-info">(₱<?php echo htmlspecialchars(
                                                        number_format(
                                                            $log[
                                                                "return_value"
                                                            ],
                                                            2
                                                        )
                                                    ); ?>)</span>
                                                </td>
                                                <td><?php echo htmlspecialchars(
                                                    $log["username"] ?? "N/A"
                                                ); ?></td>
                                                <td><?php echo htmlspecialchars(
                                                    $log["reason"]
                                                ); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div> 
                </div> </main>
    </div>
</div>

<div class="modal fade" id="returnSaleModal" tabindex="-1" aria-labelledby="returnSaleModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="returnSaleModalLabel">Process Return</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form action="sales_history.php?date_start=<?php echo htmlspecialchars(
          $date_start
      ); ?>&date_end=<?php echo htmlspecialchars($date_end); ?>" method="POST">
        <div class="modal-body">
            <input type="hidden" name="action" value="process_return">
            <input type="hidden" name="sale_id" id="return_sale_id">
            <input type="hidden" name="max_qty" id="return_max_qty">
            
            <p><strong>Product:</strong> <span id="return_product_name"></span></p>
            <p><strong>Original Sale:</strong> <span id="return_sale_date"></span></p>
            
            <div class="mb-3">
                <label for="return_qty" class="form-label">Quantity to Return</label>
                <input type="number" class="form-control" id="return_qty" name="return_qty" min="1" required>
                <div class="form-text">
                    Max <span id="return_qty_sold_text"></span> items can be returned from this sale.
                </div>
            </div>
            <div class="mb-3">
                <label for="return_reason" class="form-label">Reason for Return</label>
                <input type="text" class="form-control" id="return_reason" name="reason" placeholder="e.g., Customer refund, Wrong item" required>
            </div>
            <p class="text-info small"><i class="bi bi-info-circle-fill"></i> This will add the item(s) back to stock and create a log in the "Returns Log" tab. The original sale will be updated.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-info">Process Return</button>
        </div>
      </form>
    </div>
  </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<?php
$js_file = "../js/script_sales_history.js";
$js_version = file_exists($js_file) ? filemtime($js_file) : "1";
?>
<script src="../js/script_sales_history.js?v=<?php echo $js_version; ?>"></script>

</body>
</html>