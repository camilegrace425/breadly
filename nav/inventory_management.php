<?php
session_start();
require_once '../src/InventoryManager.php';
require_once '../src/BakeryManager.php';

// --- Security Checks ---
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
if ($_SESSION['role'] !== 'manager') {
    header('Location: index.php');
    exit();
}

// --- Initialization ---
$inventoryManager = new InventoryManager();
$bakeryManager = new BakeryManager();

// --- Message Handling ---
$message = '';
$message_type = '';
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// --- Active Tab Handling ---
$active_tab = 'products'; // Default tab
if (isset($_SESSION['active_tab'])) {
    $active_tab = $_SESSION['active_tab'];
    unset($_SESSION['active_tab']);
}

// --- POST Request Handling ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $form_active_tab = $_POST['active_tab'] ?? 'products'; // Get tab from form submission

    try {
        $success_message = '';
        $error_message = '';
        $current_user_id = $_SESSION['user_id']; // Get current user ID

        // --- Action Switch ---
        switch ($action) {
             case 'add_ingredient':
                // --- MODIFIED ---
                $bakeryManager->addIngredient($_POST['name'], $_POST['unit'], $_POST['stock_qty'], $_POST['reorder_level']);
                $success_message = 'Successfully added new ingredient!';
                break;

            case 'restock_ingredient':
                // --- THIS IS THE CORRECTED LOGIC ---
                $user_id_to_pass = isset($current_user_id) ? $current_user_id : null;
                // 1. Get the status message from the function
                $status = $bakeryManager->restockIngredient($_POST['ingredient_id'], $user_id_to_pass, $_POST['added_qty']);
                
                // 2. Check if the status message contains "Success"
                if (strpos($status, 'Success') !== false) {
                    $success_message = 'Successfully restocked ingredient!';
                } else {
                    // It's an error, pass it along
                    $error_message = $status; 
                }
                break;
                // --- END OF CORRECTION ---

            case 'add_product':
                // --- MODIFIED ---
                $bakeryManager->addProduct($_POST['name'], $_POST['price']);
                $success_message = 'Successfully added new product!';
                break;

            case 'adjust_product':
                $user_id_to_pass = isset($current_user_id) ? $current_user_id : null;
                $result = $bakeryManager->adjustProductStock($_POST['product_id'], $user_id_to_pass, $_POST['adjustment_qty'], $_POST['reason']);
                if ($result) {
                    $success_message = 'Successfully adjusted product stock!';
                } else {
                    $error_message = 'Failed to execute stock adjustment. Please check input or contact support.';
                }
                break;

            case 'edit_ingredient':
                // --- MODIFIED ---
                $bakeryManager->ingredientUpdate($_POST['ingredient_id'], $_POST['name'], $_POST['unit'], $_POST['reorder_level']);
                $success_message = 'Successfully updated ingredient!';
                break;

            case 'edit_product':
                // --- MODIFIED ---
                $bakeryManager->productUpdate($_POST['product_id'], $_POST['name'], $_POST['price'], $_POST['status']);
                $success_message = 'Successfully updated product!';
                break;

            case 'delete_ingredient':
                $status = $bakeryManager->ingredientDelete($_POST['ingredient_id']);
                if (strpos($status, 'Success') !== false) {
                    $success_message = $status;
                } else {
                    $error_message = $status; // Pass the error message from the procedure
                }
                break;

            case 'delete_product':
                $status = $bakeryManager->productDelete($_POST['product_id']);
                 if (strpos($status, 'Success') !== false) {
                    $success_message = $status;
                } else {
                    $error_message = $status; // Pass the error message from the procedure
                }
                break;
        } // End Action Switch

        // --- Set Session Message & Redirect ---
        if ($error_message) {
            $_SESSION['message'] = $error_message;
            $_SESSION['message_type'] = 'danger';
        } else {
            $_SESSION['message'] = $success_message;
            $_SESSION['message_type'] = 'success';
        }
        $_SESSION['active_tab'] = $form_active_tab; // Remember the tab where the action occurred

        header('Location: inventory_management.php'); // Redirect to prevent form resubmission
        exit();

    } catch (PDOException $e) {
        // --- Database Error Handling ---
        error_log("Database Error in inventory_management.php: " . $e->getMessage());
        
        // Provide a more user-friendly error
        $user_error = 'A database error occurred. Please try again.';

        // --- MODIFIED: Check for custom recall error ---
        if (strpos($e->getMessage(), 'Recalls must have a negative quantity') !== false) {
            $user_error = 'Error: Recalls must have a negative quantity. Positive values are not allowed for recalls.';
        } 
        // --- END MODIFIED ---
        else if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
             $user_error = 'Error: An item with this name already exists.';
        }
        
        $_SESSION['message'] = $user_error;
        $_SESSION['message_type'] = 'danger';
        $_SESSION['active_tab'] = $form_active_tab; // Keep user on the current tab

        // Now, redirect to prevent resubmission
        header('Location: inventory_management.php'); 
        exit();
        
    }
} // End POST Handling

// --- Fetch Data for Display ---
$products = $inventoryManager->getProductsInventory();
$ingredients = $inventoryManager->getIngredientsInventory();
// Fetch recall history using the date-aware function (defaulting to a wide range for the tab view)
$recall_history = $inventoryManager->getRecallHistoryByDate('1970-01-01', date('Y-m-d')); 
$discontinued_products = $inventoryManager->getDiscontinuedProducts();
if (method_exists($inventoryManager, 'getAdjustmentHistory')) {
    $adjustment_history = $inventoryManager->getAdjustmentHistory();
} else {
    $adjustment_history = [];
}


// --- Static Options for Modals ---
$unit_options = ['kg', 'g', 'L', 'ml', 'pcs', 'pack', 'tray', 'can', 'bottle'];
// Removed 'recalled' status
$product_status_options = ['available', 'discontinued'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management</title>
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
                    <a class="nav-link active" href="inventory_management.php">
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
                <h1>Inventory Management</h1>
            </div>

            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <ul class="nav nav-tabs" id="inventoryTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo ($active_tab === 'products') ? 'active' : ''; ?>" id="products-tab" data-bs-toggle="tab" data-bs-target="#products-pane" type="button" role="tab">
                        <i class="bi bi-archive me-1"></i> Active Products
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo ($active_tab === 'ingredients') ? 'active' : ''; ?>" id="ingredients-tab" data-bs-toggle="tab" data-bs-target="#ingredients-pane" type="button" role="tab">
                        <i class="bi bi-droplet me-1"></i> Ingredients
                    </button>
                </li>
                 <li class="nav-item" role="presentation">
                    <button class="nav-link text-warning <?php echo ($active_tab === 'recall_log') ? 'active' : ''; ?>" id="recall-log-tab" data-bs-toggle="tab" data-bs-target="#recall-log-pane" type="button" role="tab">
                        <i class="bi bi-exclamation-triangle me-1"></i> Recall Log
                    </button>
                </li>
                 <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo ($active_tab === 'discontinued') ? 'active' : ''; ?>" id="discontinued-tab" data-bs-toggle="tab" data-bs-target="#discontinued-pane" type="button" role="tab">
                        <i class="bi bi-slash-circle me-1"></i> Discontinued
                    </button>
                </li>
                 <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo ($active_tab === 'history') ? 'active' : ''; ?>" id="history-tab" data-bs-toggle="tab" data-bs-target="#history-pane" type="button" role="tab">
                        <i class="bi bi-clock-history me-1"></i> Adjustment History
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="inventoryTabContent">

                <div class="tab-pane fade <?php echo ($active_tab === 'products') ? 'show active' : ''; ?>" id="products-pane" role="tabpanel">
                    <div class="card shadow-sm mt-3">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span>Active Products</span>
                            <div>
                                <div class="dropdown d-inline-block me-2">
                                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                        Sort By: <span class="current-sort-text">Name (A-Z)</span>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><a class="dropdown-item sort-trigger active" data-sort-by="name" data-sort-dir="asc" data-sort-type="text" href="#">Name (A-Z)</a></li>
                                        <li><a class="dropdown-item sort-trigger" data-sort-by="name" data-sort-dir="desc" data-sort-type="text" href="#">Name (Z-A)</a></li>
                                        <li><a class="dropdown-item sort-trigger" data-sort-by="price" data-sort-dir="asc" data-sort-type="number" href="#">Price (Low-High)</a></li>
                                        <li><a class="dropdown-item sort-trigger" data-sort-by="price" data-sort-dir="desc" data-sort-type="number" href="#">Price (High-Low)</a></li>
                                        <li><a class="dropdown-item sort-trigger" data-sort-by="stock" data-sort-dir="asc" data-sort-type="number" href="#">Stock (Low-High)</a></li>
                                        <li><a class="dropdown-item sort-trigger" data-sort-by="stock" data-sort-dir="desc" data-sort-type="number" href="#">Stock (High-Low)</a></li>
                                    </ul>
                                </div>
                                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addProductModal">
                                    <i class="bi bi-plus-circle me-1"></i> Add New Product
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover align-middle">
                                    <thead>
                                        <tr>
                                            <th data-sort-by="name">Name</th>
                                            <th data-sort-by="price" data-sort-type="number">Price</th>
                                            <th data-sort-by="status">Status</th>
                                            <th data-sort-by="stock" data-sort-type="number">Current Stock</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($products as $product): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($product['name']); ?></td>
                                            <td>₱<?php echo number_format($product['price'], 2); ?></td>
                                            <td>
                                                <span class="badge bg-success"><?php echo htmlspecialchars(ucfirst($product['status'])); ?></span>
                                            </td>
                                            <td><strong><?php echo $product['stock_qty']; ?></strong></td>
                                            <td>
                                                <button class="btn btn-outline-primary btn-sm"
                                                        data-bs-toggle="modal" data-bs-target="#editProductModal"
                                                        data-product-id="<?php echo $product['product_id']; ?>"
                                                        data-product-name="<?php echo htmlspecialchars($product['name']); ?>"
                                                        data-product-price="<?php echo $product['price']; ?>"
                                                        data-product-status="<?php echo $product['status']; ?>">
                                                    <i class="bi bi-pencil-square"></i> Edit
                                                </button>
                                                <button class="btn btn-outline-secondary btn-sm"
                                                        data-bs-toggle="modal" data-bs-target="#adjustProductModal"
                                                        data-product-id="<?php echo $product['product_id']; ?>"
                                                        data-product-name="<?php echo htmlspecialchars($product['name']); ?>">
                                                    Adjust Stock
                                                </button>
                                                <button class="btn btn-outline-danger btn-sm"
                                                        data-bs-toggle="modal" data-bs-target="#deleteProductModal"
                                                        data-product-id="<?php echo $product['product_id']; ?>"
                                                        data-product-name="<?php echo htmlspecialchars($product['name']); ?>">
                                                    <i class="bi bi-trash"></i> Delete
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($products)): ?>
                                            <tr><td colspan="5" class="text-center text-muted">No active products found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade <?php echo ($active_tab === 'ingredients') ? 'show active' : ''; ?>" id="ingredients-pane" role="tabpanel">
                    <div class="card shadow-sm mt-3">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span>All Ingredients</span>
                            <div>
                                <div class="dropdown d-inline-block me-2">
                                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                        Sort By: <span class="current-sort-text">Name (A-Z)</span>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><a class="dropdown-item sort-trigger active" data-sort-by="name" data-sort-dir="asc" data-sort-type="text" href="#">Name (A-Z)</a></li>
                                        <li><a class="dropdown-item sort-trigger" data-sort-by="name" data-sort-dir="desc" data-sort-type="text" href="#">Name (Z-A)</a></li>
                                        <li><a class="dropdown-item sort-trigger" data-sort-by="stock" data-sort-dir="asc" data-sort-type="number" href="#">Stock (Low-High)</a></li>
                                        <li><a class="dropdown-item sort-trigger" data-sort-by="stock" data-sort-dir="desc" data-sort-type="number" href="#">Stock (High-Low)</a></li>
                                        <li><a class="dropdown-item sort-trigger" data-sort-by="reorder" data-sort-dir="asc" data-sort-type="number" href="#">Reorder (Low-High)</a></li>
                                        <li><a class="dropdown-item sort-trigger" data-sort-by="reorder" data-sort-dir="desc" data-sort-type="number" href="#">Reorder (High-Low)</a></li>
                                        <li><a class="dropdown-item sort-trigger" data-sort-by="status" data-sort-dir="asc" data-sort-type="text" href="#">Status (Low Stock First)</a></li>
                                         <li><a class="dropdown-item sort-trigger" data-sort-by="status" data-sort-dir="desc" data-sort-type="text" href="#">Status (In Stock First)</a></li>
                                    </ul>
                                </div>
                                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addIngredientModal">
                                    <i class="bi bi-plus-circle me-1"></i> Add New Ingredient
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                             <div class="table-responsive">
                                <table class="table table-striped table-hover align-middle">
                                    <thead>
                                        <tr>
                                            <th data-sort-by="name">Name</th>
                                            <th data-sort-by="unit">Unit</th>
                                            <th data-sort-by="stock" data-sort-type="number">Current Stock</th>
                                            <th data-sort-by="reorder" data-sort-type="number">Reorder Level</th>
                                            <th data-sort-by="status">Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($ingredients as $ing): ?>
                                        <tr class="<?php echo ($ing['stock_surplus'] <= 0) ? 'table-danger' : ''; // Highlight low stock rows ?>">
                                            <td><?php echo htmlspecialchars($ing['name']); ?></td>
                                            <td><?php echo htmlspecialchars($ing['unit']); ?></td>
                                            <td><strong><?php echo number_format($ing['stock_qty'], 2); ?></strong></td>
                                            <td><?php echo number_format($ing['reorder_level'], 2); ?></td>
                                            <td>
                                                <?php if ($ing['stock_surplus'] <= 0): ?>
                                                    <span class="badge bg-danger">Low Stock</span>
                                                <?php else: ?>
                                                    <span class="badge bg-success">In Stock</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-outline-primary btn-sm"
                                                        data-bs-toggle="modal" data-bs-target="#editIngredientModal"
                                                        data-ingredient-id="<?php echo $ing['ingredient_id']; ?>"
                                                        data-ingredient-name="<?php echo htmlspecialchars($ing['name']); ?>"
                                                        data-ingredient-unit="<?php echo htmlspecialchars($ing['unit']); ?>"
                                                        data-ingredient-reorder="<?php echo $ing['reorder_level']; ?>">
                                                    <i class="bi bi-pencil-square"></i> Edit
                                                </button>
                                                <button class="btn btn-outline-success btn-sm"
                                                        data-bs-toggle="modal" data-bs-target="#restockModal"
                                                        data-ingredient-id="<?php echo $ing['ingredient_id']; ?>"
                                                        data-ingredient-name="<?php echo htmlspecialchars($ing['name']); ?>"
                                                        data-ingredient-unit="<?php echo htmlspecialchars($ing['unit']); ?>">
                                                    Restock
                                                </button>
                                                <button class="btn btn-outline-danger btn-sm"
                                                        data-bs-toggle="modal" data-bs-target="#deleteIngredientModal"
                                                        data-ingredient-id="<?php echo $ing['ingredient_id']; ?>"
                                                        data-ingredient-name="<?php echo htmlspecialchars($ing['name']); ?>">
                                                    <i class="bi bi-trash"></i> Delete
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                         <?php if (empty($ingredients)): ?>
                                            <tr><td colspan="6" class="text-center text-muted">No ingredients found. Add one to get started!</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade <?php echo ($active_tab === 'recall_log') ? 'show active' : ''; ?>" id="recall-log-pane" role="tabpanel">
                    <div class="card shadow-sm mt-3 border-warning">
                        <div class="card-header bg-warning-subtle d-flex justify-content-between align-items-center">
                            <span>Recall Log (From Adjust Stock)</span>
                            <div class="dropdown">
                                <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    Sort By: <span class="current-sort-text">Date (Newest First)</span>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item sort-trigger active" data-sort-by="timestamp" data-sort-dir="desc" data-sort-type="date" href="#">Date (Newest First)</a></li>
                                    <li><a class="dropdown-item sort-trigger" data-sort-by="timestamp" data-sort-dir="asc" data-sort-type="date" href="#">Date (Oldest First)</a></li>
                                    <li><a class="dropdown-item sort-trigger" data-sort-by="item" data-sort-dir="asc" data-sort-type="text" href="#">Item Name (A-Z)</a></li>
                                    <li><a class="dropdown-item sort-trigger" data-sort-by="item" data-sort-dir="desc" data-sort-type="text" href="#">Item Name (Z-A)</a></li>
                                    <li><a class="dropdown-item sort-trigger" data-sort-by="user" data-sort-dir="asc" data-sort-type="text" href="#">User (A-Z)</a></li>
                                </ul>
                            </div>
                        </div>
                        <div class="card-body">
                             <p class="text-muted">This log shows all stock adjustments where the reason contains the word "recall". Use the "Adjust Stock" button on a product to create a recall entry.</p>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover align-middle">
                                    <thead>
                                        <tr>
                                            <th data-sort-by="timestamp" data-sort-type="date">Timestamp</th>
                                            <th data-sort-by="user">User</th>
                                            <th data-sort-by="item">Item Name</th>
                                            <th data-sort-by="type">Type</th>
                                            <th data-sort-by="qty" data-sort-type="number">Quantity</th>
                                            <th data-sort-by="value" data-sort-type="number">Removed Value</th>
                                            <th data-sort-by="reason">Reason</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($recall_history)): ?>
                                            <tr><td colspan="7" class="text-center text-muted">No recall history found.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($recall_history as $log): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($log['timestamp']))); ?></td>
                                                <td><?php echo htmlspecialchars($log['username'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($log['item_name'] ?? 'Item Deleted'); ?></td>
                                                <td>
                                                    <?php if ($log['item_type'] == 'product'): ?>
                                                        <span class="badge bg-primary">Product</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Ingredient</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($log['adjustment_qty'] > 0): ?>
                                                        <strong class="text-success">+<?php echo number_format($log['adjustment_qty'], 2); ?></strong>
                                                    <?php elseif ($log['adjustment_qty'] < 0): ?>
                                                        <strong class="text-danger"><?php echo number_format($log['adjustment_qty'], 2); ?></strong>
                                                    <?php else: ?>
                                                        <?php echo number_format($log['adjustment_qty'], 2); ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($log['removed_value'] < 0): ?>
                                                        <span class="text-danger">₱<?php echo number_format(abs($log['removed_value']), 2); ?></span>
                                                    <?php else: ?>
                                                        P0.00
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($log['reason']); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade <?php echo ($active_tab === 'discontinued') ? 'show active' : ''; ?>" id="discontinued-pane" role="tabpanel">
                    <div class="card shadow-sm mt-3">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span>Discontinued Products (Archived)</span>
                            <div class="dropdown">
                                <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    Sort By: <span class="current-sort-text">Name (A-Z)</span>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item sort-trigger active" data-sort-by="name" data-sort-dir="asc" data-sort-type="text" href="#">Name (A-Z)</a></li>
                                    <li><a class="dropdown-item sort-trigger" data-sort-by="name" data-sort-dir="desc" data-sort-type="text" href="#">Name (Z-A)</a></li>
                                    <li><a class="dropdown-item sort-trigger" data-sort-by="price" data-sort-dir="asc" data-sort-type="number" href="#">Price (Low-High)</a></li>
                                    <li><a class="dropdown-item sort-trigger" data-sort-by="price" data-sort-dir="desc" data-sort-type="number" href="#">Price (High-Low)</a></li>
                                    <li><a class="dropdown-item sort-trigger" data-sort-by="stock" data-sort-dir="asc" data-sort-type="number" href="#">Last Stock (Low-High)</a></li>
                                     <li><a class="dropdown-item sort-trigger" data-sort-by="stock" data-sort-dir="desc" data-sort-type="number" href="#">Last Stock (High-Low)</a></li>
                                </ul>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover align-middle">
                                    <thead>
                                        <tr>
                                            <th data-sort-by="name">Name</th>
                                            <th data-sort-by="price" data-sort-type="number">Price</th>
                                            <th data-sort-by="status">Status</th>
                                            <th data-sort-by="stock" data-sort-type="number">Last Stock</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($discontinued_products as $product): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($product['name']); ?></td>
                                            <td>₱<?php echo number_format($product['price'], 2); ?></td>
                                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars(ucfirst($product['status'])); ?></span></td>
                                            <td><?php echo $product['stock_qty']; ?></td>
                                            <td>
                                                <button class="btn btn-outline-primary btn-sm"
                                                        data-bs-toggle="modal" data-bs-target="#editProductModal"
                                                        data-product-id="<?php echo $product['product_id']; ?>"
                                                        data-product-name="<?php echo htmlspecialchars($product['name']); ?>"
                                                        data-product-price="<?php echo $product['price']; ?>"
                                                        data-product-status="<?php echo $product['status']; ?>">
                                                    <i class="bi bi-pencil-square"></i> Edit Status
                                                </button>
                                                <button class="btn btn-outline-danger btn-sm"
                                                        data-bs-toggle="modal" data-bs-target="#deleteProductModal"
                                                        data-product-id="<?php echo $product['product_id']; ?>"
                                                        data-product-name="<?php echo htmlspecialchars($product['name']); ?>">
                                                    <i class="bi bi-trash"></i> Delete
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($discontinued_products)): ?>
                                            <tr><td colspan="5" class="text-center text-muted">No discontinued products found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade <?php echo ($active_tab === 'history') ? 'show active' : ''; ?>" id="history-pane" role="tabpanel">
                    <div class="card shadow-sm mt-3">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span>Stock Adjustment History (Last 200)</span>
                            <div class="dropdown">
                                <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    Sort By: <span class="current-sort-text">Date (Newest First)</span>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item sort-trigger active" data-sort-by="timestamp" data-sort-dir="desc" data-sort-type="date" href="#">Date (Newest First)</a></li>
                                    <li><a class="dropdown-item sort-trigger" data-sort-by="timestamp" data-sort-dir="asc" data-sort-type="date" href="#">Date (Oldest First)</a></li>
                                    <li><a class="dropdown-item sort-trigger" data-sort-by="item" data-sort-dir="asc" data-sort-type="text" href="#">Item Name (A-Z)</a></li>
                                    <li><a class="dropdown-item sort-trigger" data-sort-by="item" data-sort-dir="desc" data-sort-type="text" href="#">Item Name (Z-A)</a></li>
                                    <li><a class="dropdown-item sort-trigger" data-sort-by="user" data-sort-dir="asc" data-sort-type="text" href="#">User (A-Z)</a></li>
                                    <li><a class="dropdown-item sort-trigger" data-sort-by="user" data-sort-dir="desc" data-sort-type="text" href="#">User (Z-A)</a></li>
                                    <li><a class="dropdown-item sort-trigger" data-sort-by="type" data-sort-dir="asc" data-sort-type="text" href="#">Type (Ingredient First)</a></li>
                                    <li><a class="dropdown-item sort-trigger" data-sort-by="type" data-sort-dir="desc" data-sort-type="text" href="#">Type (Product First)</a></li>
                                </ul>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover align-middle">
                                    <thead>
                                        <tr>
                                            <th data-sort-by="timestamp" data-sort-type="date">Timestamp</th>
                                            <th data-sort-by="user">User</th>
                                            <th data-sort-by="item">Item Name</th>
                                            <th data-sort-by="type">Type</th>
                                            <th data-sort-by="qty" data-sort-type="number">Quantity</th>
                                            <th data-sort-by="reason">Reason</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($adjustment_history)): ?>
                                            <tr><td colspan="6" class="text-center text-muted">No adjustment history found.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($adjustment_history as $log): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($log['timestamp']))); ?></td>
                                                <td><?php echo htmlspecialchars($log['username'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($log['item_name'] ?? 'Item Deleted'); ?></td>
                                                <td>
                                                    <?php if ($log['item_type'] == 'product'): ?>
                                                        <span class="badge bg-primary">Product</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Ingredient</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($log['adjustment_qty'] > 0): ?>
                                                        <strong class="text-success">+<?php echo number_format($log['adjustment_qty'], 2); ?></strong>
                                                    <?php elseif ($log['adjustment_qty'] < 0): ?>
                                                        <strong class="text-danger"><?php echo number_format($log['adjustment_qty'], 2); ?></strong>
                                                    <?php else: ?>
                                                        <?php echo number_format($log['adjustment_qty'], 2); ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($log['reason']); ?></td>
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

<div class="modal fade" id="addIngredientModal" tabindex="-1" aria-labelledby="addIngredientModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="addIngredientModalLabel">Add New Ingredient</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="inventory_management.php" method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_ingredient">
                <input type="hidden" name="active_tab" value="ingredients"> 
                <div class="mb-3">
                    <label for="add_ing_name" class="form-label">Ingredient Name</label>
                    <input type="text" class="form-control" id="add_ing_name" name="name" required>
                </div>
                <div class="mb-3">
                    <label for="add_ing_unit" class="form-label">Unit</label>
                    <select class="form-select" id="add_ing_unit" name="unit" required>
                        <option value="" selected disabled>Select a unit...</option>
                        <?php foreach ($unit_options as $unit): ?>
                            <option value="<?php echo htmlspecialchars($unit); ?>"><?php echo htmlspecialchars($unit); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="add_ing_stock" class="form-label">Initial Stock Quantity</label>
                    <input type="number" step="0.01" class="form-control" id="add_ing_stock" name="stock_qty" value="0" required min="0">
                </div>
                <div class="mb-3">
                    <label for="add_ing_reorder" class="form-label">Reorder Level</label>
                    <input type="number" step="0.01" class="form-control" id="add_ing_reorder" name="reorder_level" value="0" required min="0">
                </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
              <button type="submit" class="btn btn-primary">Add Ingredient</button>
            </div>
          </form>
        </div>
      </div>
</div>

<div class="modal fade" id="restockModal" tabindex="-1" aria-labelledby="restockModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="restockModalLabel">Restock Ingredient</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="inventory_management.php" method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="restock_ingredient">
                <input type="hidden" name="active_tab" value="ingredients">
                <input type="hidden" name="ingredient_id" id="restock_ingredient_id">
                <p>Restocking: <strong id="restock_ingredient_name"></strong></p>
                <div class="mb-3">
                    <label for="restock_added_qty" class="form-label">Quantity to Add</label>
                    <div class="input-group">
                        <input type="number" step="0.01" class="form-control" id="restock_added_qty" name="added_qty" required min="0.01">
                        <span class="input-group-text" id="restock_ingredient_unit"></span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
              <button type="submit" class="btn btn-success">Save Restock</button>
            </div>
          </form>
        </div>
      </div>
</div>

<div class="modal fade" id="addProductModal" tabindex="-1" aria-labelledby="addProductModalLabel" aria-hidden="true">
     <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="addProductModalLabel">Add New Product</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="inventory_management.php" method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_product">
                <input type="hidden" name="active_tab" value="products">
                <div class="mb-3">
                    <label for="add_prod_name" class="form-label">Product Name</label>
                    <input type="text" class="form-control" id="add_prod_name" name="name" required>
                </div>
                <div class="mb-3">
                    <label for="add_prod_price" class="form-label">Price (PHP)</label>
                    <input type="number" step="0.01" class="form-control" id="add_prod_price" name="price" required min="0">
                </div>
                <p class="text-muted small">Note: Initial stock is 0. You must record a production run or use "Adjust Stock" to add inventory.</p>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
              <button type="submit" class="btn btn-primary">Add Product</button>
            </div>
          </form>
        </div>
      </div>
</div>

<div class="modal fade" id="adjustProductModal" tabindex="-1" aria-labelledby="adjustProductModalLabel" aria-hidden="true">
     <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="adjustProductModalLabel">Adjust Product Stock</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="inventory_management.php" method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="adjust_product">
                <input type="hidden" name="active_tab" value="products"> <input type="hidden" name="product_id" id="adjust_product_id">
                <p>Adjusting: <strong id="adjust_product_name"></strong></p>
                <div class="mb-3">
                    <label for="adjust_adjustment_qty" class="form-label">Adjustment Quantity</label>
                    <input type="number" step="1" class="form-control" id="adjust_adjustment_qty" name="adjustment_qty" required placeholder="e.g., -5 or 10">
                    <div class="form-text">Use a negative number to remove stock (e.g., -5 for spoilage) or a positive number to add stock.</div>
                </div>
                <div class="mb-3">
                    <label for="adjust_reason" class="form-label">Reason for Adjustment</label>
                    <input type="text" class="form-control" id="adjust_reason" name="reason" placeholder="e.g., Spoilage, Recall batch, Correction" required>
                    <div class="form-text text-warning">To log a recall, enter a negative quantity and include the word "recall" in the reason.</div>
                </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
              <button type="submit" class="btn btn-warning">Save Adjustment</button>
            </div>
          </form>
        </div>
      </div>
</div>

<div class="modal fade" id="editIngredientModal" tabindex="-1" aria-labelledby="editIngredientModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="editIngredientModalLabel">Edit Ingredient</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="inventory_management.php" method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="edit_ingredient">
                <input type="hidden" name="active_tab" value="ingredients">
                <input type="hidden" name="ingredient_id" id="edit_ingredient_id">
                <div class="mb-3">
                    <label for="edit_ingredient_name" class="form-label">Ingredient Name</label>
                    <input type="text" class="form-control" name="name" id="edit_ingredient_name" required>
                </div>
                <div class="mb-3">
                    <label for="edit_ingredient_unit" class="form-label">Unit</label>
                    <select class="form-select" name="unit" id="edit_ingredient_unit" required>
                        <?php foreach ($unit_options as $unit): ?>
                            <option value="<?php echo htmlspecialchars($unit); ?>"><?php echo htmlspecialchars($unit); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="edit_ingredient_reorder" class="form-label">Reorder Level</label>
                    <input type="number" step="0.01" class="form-control" name="reorder_level" id="edit_ingredient_reorder" required min="0">
                </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
              <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
          </form>
        </div>
      </div>
</div>

<div class="modal fade" id="editProductModal" tabindex="-1" aria-labelledby="editProductModalLabel" aria-hidden="true">
     <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="editProductModalLabel">Edit Product</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="inventory_management.php" method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="edit_product">
                <input type="hidden" name="active_tab" id="edit_product_active_tab" value="products"> <input type="hidden" name="product_id" id="edit_product_id">
                <div class="mb-3">
                    <label for="edit_product_name" class="form-label">Product Name</label>
                    <input type="text" class="form-control" name="name" id="edit_product_name" required>
                </div>
                <div class="mb-3">
                    <label for="edit_product_price" class="form-label">Price (PHP)</label>
                    <input type="number" step="0.01" class="form-control" name="price" id="edit_product_price" required min="0">
                </div>
                <div class="mb-3">
                    <label for="edit_product_status" class="form-label">Status</label>
                    <select class="form-select" name="status" id="edit_product_status" required>
                        <option value="" disabled>Select status...</option>
                        <?php foreach ($product_status_options as $status): ?>
                            <option value="<?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Setting to "Discontinued" will move it to the Discontinued tab and remove it from POS.</div>
                </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
              <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
          </form>
        </div>
      </div>
</div>

<div class="modal fade" id="deleteIngredientModal" tabindex="-1" aria-labelledby="deleteIngredientModalLabel" aria-hidden="true">
     <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="deleteIngredientModalLabel">Delete Ingredient?</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="inventory_management.php" method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="delete_ingredient">
                <input type="hidden" name="active_tab" value="ingredients">
                <input type="hidden" name="ingredient_id" id="delete_ingredient_id">
                <p>Are you sure you want to permanently delete <strong id="delete_ingredient_name"></strong>?</p>
                <p class="text-danger small">This action cannot be undone. Deletion will fail if the ingredient is currently used in any product recipes.</p>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-danger">Yes, Delete</button>
            </div>
          </form>
        </div>
      </div>
</div>

<div class="modal fade" id="deleteProductModal" tabindex="-1" aria-labelledby="deleteProductModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="deleteProductModalLabel">Delete Product?</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form action="inventory_management.php" method="POST">
        <div class="modal-body">
            <input type="hidden" name="action" value="delete_product">
            <input type="hidden" name="active_tab" id="delete_product_active_tab" value="products"> <input type="hidden" name="product_id" id="delete_product_id">
            <p>Are you sure you want to permanently delete <strong id="delete_product_name"></strong>?</p>
            <p class="text-danger small">This action cannot be undone. Deletion will fail if the product has associated sales or production history. Consider marking it as "Discontinued" instead.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger">Yes, Delete</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/script_inventory.js"></script>
</body>
</html>