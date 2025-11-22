<?php
session_start();
require_once "../src/InventoryManager.php";
require_once "../src/BakeryManager.php";

// =================================================================
// 1. SECURITY & AUTHENTICATION
// =================================================================
if (!isset($_SESSION["user_id"])) {
    // If this is an AJAX/API request, return JSON 403
    if (isset($_SERVER['HTTP_CONTENT_TYPE']) && strpos($_SERVER['HTTP_CONTENT_TYPE'], 'application/json') !== false) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    header("Location: login.php");
    exit();
}

if (!in_array($_SESSION['role'], ['manager', 'assistant_manager'])) {
    header('Location: ../index.php');
    exit();
}

// =================================================================
// 2. INITIALIZATION
// =================================================================
$inventoryManager = new InventoryManager();
$bakeryManager = new BakeryManager();

// =================================================================
// 3. HANDLE JSON API REQUESTS (Batch Actions)
// =================================================================
$input_data = json_decode(file_get_contents('php://input'), true);
if ($input_data && isset($input_data['action'])) {
    header('Content-Type: application/json');
    $action = $input_data['action'];
    $response = ['success' => false, 'message' => 'Invalid action'];
    $userId = $_SESSION['user_id'];
    
    try {
        switch ($action) {
            case 'update_expiry':
                if (!isset($input_data['batch_id']) || !array_key_exists('expiration_date', $input_data)) {
                    throw new Exception('Missing parameters (batch_id or expiration_date)');
                }
                $response = $inventoryManager->updateBatchExpiry($input_data['batch_id'], $input_data['expiration_date']);
                break;

            case 'update_quantity':
                if (!isset($input_data['batch_id']) || !isset($input_data['new_quantity']) || !isset($input_data['reason'])) {
                    throw new Exception('Missing parameters (batch_id, new_quantity, or reason)');
                }
                $response = $inventoryManager->updateBatchQuantity($input_data['batch_id'], $userId, $input_data['new_quantity'], $input_data['reason']);
                break;

            case 'delete_batch':
                if (!isset($input_data['batch_id']) || !isset($input_data['reason'])) {
                    throw new Exception('Missing parameters (batch_id or reason)');
                }
                $response = $inventoryManager->deleteBatch($input_data['batch_id'], $userId, $input_data['reason']);
                break;
        }
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }
    
    echo json_encode($response);
    exit();
}

// =================================================================
// 4. HANDLE STANDARD FORM SUBMISSIONS (POST)
// =================================================================
$message = "";
$message_type = "";
if (isset($_SESSION["message"])) {
    $message = $_SESSION["message"];
    $message_type = $_SESSION["message_type"];
    unset($_SESSION["message"]);
    unset($_SESSION["message_type"]);
}

$active_tab = "products"; 
if (isset($_POST["active_tab"])) {
    $active_tab = $_POST["active_tab"];
} elseif (isset($_GET["active_tab"])) {
    $active_tab = $_GET["active_tab"];
} elseif (isset($_SESSION["active_tab"])) {
    $active_tab = $_SESSION["active_tab"];
    unset($_SESSION["active_tab"]);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    function handleProductImageUpload($file_input_name) {
        $target_dir = "../uploads/products/"; 
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }

        if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] == 0) {
            $file = $_FILES[$file_input_name];
            $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $safe_filename = uniqid('prod_', true) . '.' . $file_extension;
            $target_file = $target_dir . $safe_filename;

            $check = getimagesize($file["tmp_name"]);
            if ($check === false) return [null, "File is not an image."];
            if ($file["size"] > 20000000) return [null, "Sorry, your file is too large (20MB limit)."];
            
            $allowed_types = ['jpg', 'png', 'jpeg', 'gif'];
            if (!in_array($file_extension, $allowed_types)) return [null, "Sorry, only JPG, JPEG, PNG & GIF files are allowed."];

            if (move_uploaded_file($file["tmp_name"], $target_file)) {
                return [$target_file, null]; 
            } else {
                return [null, "Sorry, there was an error uploading your file."];
            }
        }
        return [null, null]; 
    }

    $action = $_POST["action"] ?? "";
    $form_active_tab = $_POST["active_tab"] ?? "products"; 

    try {
        $success_message = "";
        $error_message = "";
        $current_user_id = $_SESSION["user_id"]; 

        switch ($action) {
            // --- INGREDIENT ACTIONS ---
            case "add_ingredient":
                $result = $bakeryManager->addIngredient(
                    $_POST["name"],
                    $_POST["unit"],
                    $_POST["stock_qty"],
                    $_POST["reorder_level"]
                );

                if ($result === 'duplicate') {
                    $error_message = "Warning: An ingredient with this name already exists.";
                } elseif ($result === 'success') {
                    $success_message = "Successfully added new ingredient!";
                } else {
                    $error_message = "Error adding ingredient.";
                }
                break;

            // ... (Restock and Edit Ingredient cases remain the same) ...

            // --- PRODUCT ACTIONS ---
            case "add_product":
                list($image_path, $upload_error) = handleProductImageUpload('product_image');
                if ($upload_error) {
                    $error_message = $upload_error;
                    break;
                }
    
                $result = $bakeryManager->addProduct($_POST["name"], $_POST["price"], $image_path);
                
                if ($result === 'duplicate') {
                    $error_message = "Warning: A product with this name already exists.";
                    // Clean up uploaded image if duplicate
                    if ($image_path && file_exists($image_path)) { @unlink($image_path); }
                } elseif ($result === 'success') {
                    $success_message = "Successfully added new product!";
                } else {
                    $error_message = "Error adding product.";
                }
                break;

            case "edit_ingredient":
                $bakeryManager->ingredientUpdate(
                    $_POST["ingredient_id"],
                    $_POST["name"],
                    $_POST["unit"],
                    $_POST["reorder_level"]
                );
                $success_message = "Successfully updated ingredient!";
                break;

            case "delete_ingredient":
                $status = $bakeryManager->ingredientDelete($_POST["ingredient_id"]);
                if (strpos($status, "Success") !== false) {
                    $success_message = $status;
                } else {
                    $error_message = $status; 
                }
                break;

            // --- PRODUCT ACTIONS ---
            case "add_product":
                list($image_path, $upload_error) = handleProductImageUpload('product_image');
                if ($upload_error) {
                    $error_message = $upload_error;
                    break;
                }
    
                $bakeryManager->addProduct($_POST["name"], $_POST["price"], $image_path);
                $success_message = "Successfully added new product!";
                break;

            case "adjust_product":
                $user_id_to_pass = isset($current_user_id) ? $current_user_id : null;
                $adjustment_type = $_POST["adjustment_type"];
                $reason_note = $_POST["reason_note"];
                $combined_reason = "[$adjustment_type] $reason_note";

                $status = $bakeryManager->adjustProductStock(
                    $_POST["product_id"],
                    $user_id_to_pass,
                    $_POST["adjustment_qty"],
                    $combined_reason
                );

                if (strpos($status, "Success") !== false) {
                    $success_message = $status;
                } else {
                    $error_message = $status; 
                }
                break;

            case "edit_product":
                $old_image_path = $_POST['current_image_path'] ?? null;
                list($image_path, $upload_error) = handleProductImageUpload('edit_product_image');
                if ($upload_error) {
                    $error_message = $upload_error;
                    break;
                }
                
                $success = $bakeryManager->productUpdate(
                    $_POST["product_id"],
                    $_POST["name"],
                    $_POST["price"],
                    $_POST["status"],
                    $image_path 
                );

                if ($success) {
                    $success_message = "Successfully updated product!";
                    if ($image_path && $old_image_path) {
                        if (file_exists($old_image_path)) {
                            @unlink($old_image_path); 
                        }
                    }
                } else {
                    $error_message = "Error: Could not update product in database.";
                    if ($image_path && file_exists($image_path)) {
                        @unlink($image_path);
                    }
                }
                break;

            case "delete_product":
                $status = $bakeryManager->productDelete($_POST["product_id"]);
                if (strpos($status, "Success") !== false) {
                    $success_message = $status;
                } else {
                    $error_message = $status; 
                }
                break;

            // --- NEW: UNDO RECALL ACTION ---
            case "undo_recall":
                $result = $inventoryManager->undoRecall($_POST["adjustment_id"], $current_user_id);
                if ($result['success']) {
                    $success_message = $result['message'];
                } else {
                    $error_message = $result['message'];
                }
                // Force stay on the recall tab
                $form_active_tab = "recall";
                break;
        }

        if ($error_message) {
            $_SESSION["message"] = $error_message;
            $_SESSION["message_type"] = "danger";
        } else {
            $_SESSION["message"] = $success_message;
            $_SESSION["message_type"] = "success";
        }
        $_SESSION["active_tab"] = $form_active_tab; 

        header("Location: inventory_management.php"); 
        exit();
    } catch (PDOException $e) {
        error_log("Database Error in inventory_management.php: " . $e->getMessage());
        $user_error = "A database error occurred. Please try again.";
        if (strpos($e->getMessage(), "Duplicate entry") !== false) {
            $user_error = "Error: An item with this name already exists.";
        }
        $_SESSION["message"] = $user_error;
        $_SESSION["message_type"] = "danger";
        $_SESSION["active_tab"] = $form_active_tab; 
        header("Location: inventory_management.php");
        exit();
    }
} 

// =================================================================
// 5. FETCH DATA FOR VIEW
// =================================================================
$products = $inventoryManager->getProductsInventory();
$ingredients = $inventoryManager->getIngredientsInventory();
$discontinued_products = $inventoryManager->getDiscontinuedProducts();
if (method_exists($inventoryManager, "getAdjustmentHistory")) {
    $adjustment_history = $inventoryManager->getAdjustmentHistory();
} else {
    $adjustment_history = [];
}

// Fetch recall data
$recall_history = $inventoryManager->getRecallHistoryByDate("1970-01-01", "2099-12-31");
$total_recall_value = 0;
foreach ($recall_history as $log) {
    $total_recall_value += $log["removed_value"];
}

$unit_options = ["kg", "g", "L", "ml", "pcs", "pack", "tray", "can", "bottle"];
$product_status_options = ["available", "discontinued"];
$active_nav_link = 'inventory';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management</title>
    <link rel="icon" href="../images/kzklogo.png" type="image/x-icon"> 
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../styles/global.css"> 
    <link rel="stylesheet" href="../styles/dashboard.css"> 
    <link rel="stylesheet" href="../styles/recipes.css?v=3"> 
    <link rel="stylesheet" href="../styles/responsive.css?v=3"> 
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
                            <strong><?php echo htmlspecialchars($_SESSION["username"]); ?></strong>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark text-small shadow" aria-labelledby="userMenu">
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
                    <button class="nav-link <?php echo $active_tab === "products" ? "active" : ""; ?>" id="products-tab" data-bs-toggle="tab" data-bs-target="#products-pane" type="button" role="tab">
                        <i class="bi bi-archive me-1"></i> Active Products
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $active_tab === "ingredients" ? "active" : ""; ?>" id="ingredients-tab" data-bs-toggle="tab" data-bs-target="#ingredients-pane" type="button" role="tab">
                        <i class="bi bi-droplet me-1"></i> Ingredients
                    </button>
                </li>
                 <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $active_tab === "discontinued" ? "active" : ""; ?>" id="discontinued-tab" data-bs-toggle="tab" data-bs-target="#discontinued-pane" type="button" role="tab">
                        <i class="bi bi-slash-circle me-1"></i> Discontinued
                    </button>
                </li>
                 <li class="nav-item" role="presentation">
                    <button class="nav-link text-warning <?php echo $active_tab === "recall" ? "active" : ""; ?>" id="recall-tab" data-bs-toggle="tab" data-bs-target="#recall-pane" type="button" role="tab">
                        <i class="bi bi-exclamation-triangle me-1"></i> Recall Log
                    </button>
                </li>
                 <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $active_tab === "history" ? "active" : ""; ?>" id="history-tab" data-bs-toggle="tab" data-bs-target="#history-pane" type="button" role="tab">
                        <i class="bi bi-clock-history me-1"></i> Adjustment History
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="inventoryTabContent">

                <div class="tab-pane fade <?php echo $active_tab === "products" ? "show active" : ""; ?>" id="products-pane" role="tabpanel">
                   <div class="card shadow-sm mt-3">
                        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-3">
                            <span class="fs-5">Active Products</span>
                            <div class="input-group" style="max-width: 400px;">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" id="product-search-input" class="form-control" placeholder="Search products...">
                            </div>
                            <div class="d-flex flex-wrap justify-content-end align-items-center gap-2">
                                <div class="d-flex align-items-center gap-1">
                                    <label for="product-rows-select" class="form-label mb-0 small text-muted flex-shrink-0">Show</label>
                                    <select class="form-select form-select-sm" id="product-rows-select" style="width: auto;">
                                        <option value="10" selected>10</option>
                                        <option value="25">25</option>
                                        <option value="50">50</option>
                                        <option value="all">All</option>
                                    </select>
                                    <div class="btn-group btn-group-sm ms-1" role="group">
                                        <button type="button" class="btn btn-outline-secondary" id="product-prev-btn" disabled><i class="bi bi-arrow-left"></i></button>
                                        <button type="button" class="btn btn-outline-secondary" id="product-next-btn"><i class="bi bi-arrow-right"></i></button>
                                    </div>
                                </div>
                                <div class="dropdown d-inline-block">
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
                            <div class="row row-cols-2 row-cols-md-3 row-cols-xl-6 g-3" id="product-card-list">
                                <?php if (empty($products)): ?>
                                    <div class="col-12"><p class="text-center text-muted">No active products found.</p></div>
                                <?php else: ?>
                                    <?php foreach ($products as $product): ?>
                                        <?php 
                                        $image_path = !empty($product['image_url']) ? htmlspecialchars($product['image_url']) : '../images/breadlylogo.png'; 
                                        $stock_class = $product['stock_qty'] <= 0 ? 'text-danger' : 'text-dark';
                                        ?>
                                        <div class="col product-item" 
                                             data-product-name="<?php echo htmlspecialchars(strtolower($product["name"])); ?>"
                                             data-product-price="<?php echo $product["price"]; ?>"
                                             data-product-stock="<?php echo $product["stock_qty"]; ?>"
                                             data-product-status="<?php echo $product["status"]; ?>">
                                            
                                            <div class="product-card h-100 d-flex flex-column"> 
                                                <img src="<?php echo $image_path; ?>" alt="<?php echo htmlspecialchars($product["name"]); ?>" class="img-fluid rounded mb-2" style="height: 150px; object-fit: cover;">
                                                <h5 class="product-name mb-1"><?php echo htmlspecialchars($product["name"]); ?></h5>
                                                <p class="text-muted mb-1">₱<?php echo number_format($product["price"], 2); ?></p>
                                                <p class="fw-bold <?php echo $stock_class; ?> mb-2">Stock: <?php echo $product["stock_qty"]; ?></p>
                                                <div class="mt-auto d-grid gap-2">
                                                     <button class="btn btn-outline-primary btn-sm"
                                                            data-bs-toggle="modal" data-bs-target="#editProductModal"
                                                            data-product-id="<?php echo $product["product_id"]; ?>"
                                                            data-product-name="<?php echo htmlspecialchars($product["name"]); ?>"
                                                            data-product-price="<?php echo $product["price"]; ?>"
                                                            data-product-status="<?php echo $product["status"]; ?>"
                                                            data-product-image="<?php echo htmlspecialchars($product["image_url"] ?? ''); ?>">
                                                        <i class="bi bi-pencil-square"></i> Edit
                                                    </button>
                                                    <button class="btn btn-outline-secondary btn-sm"
                                                            data-bs-toggle="modal" data-bs-target="#adjustProductModal"
                                                            data-product-id="<?php echo $product["product_id"]; ?>"
                                                            data-product-name="<?php echo htmlspecialchars($product["name"]); ?>">
                                                        Adjust Stock
                                                    </button>
                                                    <button class="btn btn-outline-danger btn-sm"
                                                            data-bs-toggle="modal" data-bs-target="#deleteProductModal"
                                                            data-product-id="<?php echo $product["product_id"]; ?>"
                                                            data-product-name="<?php echo htmlspecialchars($product["name"]); ?>">
                                                        <i class="bi bi-trash"></i> Delete
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <div class="col-12" id="product-no-results" style="display: none;"><p class="text-center text-muted">No products match your search.</p></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade <?php echo $active_tab === "ingredients" ? "show active" : ""; ?>" id="ingredients-pane" role="tabpanel">
                    <div class="card shadow-sm mt-3">
                        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-3">
                            <span class="fs-5">All Ingredients</span>
                            <div class="input-group" style="max-width: 400px;">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" id="ingredient-search-input" class="form-control" placeholder="Search ingredients...">
                            </div>
                            <div class="d-flex flex-wrap justify-content-end align-items-center gap-2">
                                <div class="d-flex align-items-center gap-1">
                                    <label for="ingredient-rows-select" class="form-label mb-0 small text-muted flex-shrink-0">Show</label>
                                    <select class="form-select form-select-sm" id="ingredient-rows-select" style="width: auto;">
                                        <option value="10" selected>10</option>
                                        <option value="25">25</option>
                                        <option value="50">50</option>
                                        <option value="all">All</option>
                                    </select>
                                    <div class="btn-group btn-group-sm ms-1" role="group">
                                        <button type="button" class="btn btn-outline-secondary" id="ingredient-prev-btn" disabled><i class="bi bi-arrow-left"></i></button>
                                        <button type="button" class="btn btn-outline-secondary" id="ingredient-next-btn"><i class="bi bi-arrow-right"></i></button>
                                    </div>
                                </div>
                                <div class="dropdown d-inline-block">
                                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Sort By: <span class="current-sort-text">Name (A-Z)</span></button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><a class="dropdown-item sort-trigger active" data-sort-by="name" data-sort-dir="asc" data-sort-type="text" href="#">Name (A-Z)</a></li>
                                        <li><a class="dropdown-item sort-trigger" data-sort-by="name" data-sort-dir="desc" data-sort-type="text" href="#">Name (Z-A)</a></li>
                                        <li><a class="dropdown-item sort-trigger" data-sort-by="stock" data-sort-dir="asc" data-sort-type="number" href="#">Stock (Low-High)</a></li>
                                        <li><a class="dropdown-item sort-trigger" data-sort-by="stock" data-sort-dir="desc" data-sort-type="number" href="#">Stock (High-Low)</a></li>
                                        <li><a class="dropdown-item sort-trigger" data-sort-by="reorder" data-sort-dir="asc" data-sort-type="number" href="#">Reorder (Low-High)</a></li>
                                        <li><a class="dropdown-item sort-trigger" data-sort-by="reorder" data-sort-dir="desc" data-sort-type="number" href="#">Reorder (High-Low)</a></li>
                                        <li><a class="dropdown-item sort-trigger" data-sort-by="status" data-sort-dir="desc" data-sort-type="text" href="#">Status (Low Stock First)</a></li>
                                         <li><a class="dropdown-item sort-trigger" data-sort-by="status" data-sort-dir="asc" data-sort-type="text" href="#">Status (In Stock First)</a></li>
                                    </ul>
                                </div>
                                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addIngredientModal"><i class="bi bi-plus-circle me-1"></i> Add New Ingredient</button>
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
                                    <tbody id="ingredient-table-body">
                                        <?php if (empty($ingredients)): ?>
                                            <tr><td colspan="6" class="text-center text-muted">No ingredients found. Add one to get started!</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($ingredients as $ing): ?>
                                            <tr class="<?php echo $ing["stock_surplus"] <= 0 ? "table-danger" : ""; ?>">
                                                <td data-label="Name"><?php echo htmlspecialchars($ing["name"]); ?></td>
                                                <td data-label="Unit"><?php echo htmlspecialchars($ing["unit"]); ?></td>
                                                <td data-label="Current Stock"><strong><?php echo number_format($ing["stock_qty"], 2); ?></strong></td>
                                                <td data-label="Reorder Level"><?php echo number_format($ing["reorder_level"], 2); ?></td>
                                                <td data-label="Status"><?php if ($ing["stock_surplus"] <= 0): ?><span class="badge bg-danger">Low Stock</span><?php else: ?><span class="badge bg-success">In Stock</span><?php endif; ?></td>
                                                <td>
                                                    <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editIngredientModal" data-ingredient-id="<?php echo $ing["ingredient_id"]; ?>" data-ingredient-name="<?php echo htmlspecialchars($ing["name"]); ?>" data-ingredient-unit="<?php echo htmlspecialchars($ing["unit"]); ?>" data-ingredient-reorder="<?php echo $ing["reorder_level"]; ?>"><i class="bi bi-pencil-square"></i> Edit</button>
                                                    <button class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#restockIngredientModal" data-ingredient-id="<?php echo $ing['ingredient_id']; ?>" data-ingredient-name="<?php echo htmlspecialchars($ing['name']); ?>" data-ingredient-unit="<?php echo $ing['unit']; ?>"><i class="bi bi-plus-lg"></i> Restock</button>
                                                    <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteIngredientModal" data-ingredient-id="<?php echo $ing["ingredient_id"]; ?>" data-ingredient-name="<?php echo htmlspecialchars($ing["name"]); ?>"><i class="bi bi-trash"></i> Delete</button>
                                                    <button class="btn btn-sm btn-info text-white view-batches-btn" data-bs-toggle="modal" data-bs-target="#batchesModal" data-id="<?php echo $ing['ingredient_id']; ?>" data-name="<?php echo htmlspecialchars($ing['name']); ?>" data-unit="<?php echo $ing['unit']; ?>" title="Manage Batches"><i class="bi bi-layers"></i> Batch Details</button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        <tr id="ingredient-no-results" style="display: none;"><td colspan="6" class="text-center text-muted">No ingredients match your search.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade <?php echo $active_tab === "discontinued" ? "show active" : ""; ?>" id="discontinued-pane" role="tabpanel">
                    <div class="card shadow-sm mt-3">
                        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-3">
                            <span class="fs-5">Discontinued Products</span>
                            <div class="d-flex flex-wrap justify-content-end align-items-center gap-2">
                                <div class="d-flex align-items-center gap-1">
                                    <label for="discontinued-rows-select" class="form-label mb-0 small text-muted flex-shrink-0">Show</label>
                                    <select class="form-select form-select-sm" id="discontinued-rows-select" style="width: auto;">
                                        <option value="10" selected>10</option>
                                        <option value="25">25</option>
                                        <option value="50">50</option>
                                        <option value="all">All</option>
                                    </select>
                                    <div class="btn-group btn-group-sm ms-1" role="group">
                                        <button type="button" class="btn btn-outline-secondary" id="discontinued-prev-btn" disabled><i class="bi bi-arrow-left"></i></button>
                                        <button type="button" class="btn btn-outline-secondary" id="discontinued-next-btn"><i class="bi bi-arrow-right"></i></button>
                                    </div>
                                </div>
                                <div class="dropdown">
                                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Sort By: <span class="current-sort-text">Name (A-Z)</span></button>
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
                                    <tbody id="discontinued-table-body">
                                        <?php if (empty($discontinued_products)): ?>
                                            <tr><td colspan="5" class="text-center text-muted">No discontinued products found.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($discontinued_products as $product): ?>
                                            <tr>
                                                <td data-label="Name"><?php echo htmlspecialchars($product["name"]); ?></td>
                                                <td data-label="Price">₱<?php echo number_format($product["price"], 2); ?></td>
                                                <td data-label="Status"><span class="badge bg-secondary"><?php echo htmlspecialchars(ucfirst($product["status"])); ?></span></td>
                                                <td data-label="Last Stock"><?php echo $product["stock_qty"]; ?></td>
                                                <td>
                                                    <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editProductModal" data-product-id="<?php echo $product["product_id"]; ?>" data-product-name="<?php echo htmlspecialchars($product["name"]); ?>" data-product-price="<?php echo $product["price"]; ?>" data-product-status="<?php echo $product["status"]; ?>"><i class="bi bi-pencil-square"></i> Edit Status</button>
                                                    <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteProductModal" data-product-id="<?php echo $product["product_id"]; ?>" data-product-name="<?php echo htmlspecialchars($product["name"]); ?>"><i class="bi bi-trash"></i> Delete</button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade <?php echo $active_tab === "recall" ? "show active" : ""; ?>" id="recall-pane" role="tabpanel">
                    <div class="card shadow-sm mt-3 border-warning">
                        <div class="card-header bg-warning-subtle d-flex flex-wrap justify-content-between align-items-center gap-3">
                            <span class="fs-5">Recall Log</span>
                            <div class="d-flex flex-wrap justify-content-end align-items-center gap-2">
                                <div class="d-flex align-items-center gap-1">
                                    <label for="recall-rows-select" class="form-label mb-0 small text-muted flex-shrink-0">Show</label>
                                    <select class="form-select form-select-sm" id="recall-rows-select" style="width: auto;">
                                        <option value="10" selected>10</option>
                                        <option value="25">25</option>
                                        <option value="50">50</option>
                                        <option value="all">All</option>
                                    </select>
                                    <div class="btn-group btn-group-sm ms-1" role="group">
                                        <button type="button" class="btn btn-outline-secondary" id="recall-prev-btn" disabled><i class="bi bi-arrow-left"></i></button>
                                        <button type="button" class="btn btn-outline-secondary" id="recall-next-btn"><i class="bi bi-arrow-right"></i></button>
                                    </div>
                                </div>
                                <div class="dropdown">
                                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Sort By: <span class="current-sort-text">Date (Newest First)</span></button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><a class="dropdown-item sort-trigger active" data-sort-by="timestamp" data-sort-dir="desc" data-sort-type="date" href="#">Timestamp (Newest First)</a></li>
                                        <li><a class="dropdown-item sort-trigger" data-sort-by="timestamp" data-sort-dir="asc" data-sort-type="date" href="#">Timestamp (Oldest First)</a></li>
                                        <li><a class="dropdown-item sort-trigger" data-sort-by="item" data-sort-dir="asc" data-sort-type="text" href="#">Product (A-Z)</a></li>
                                        <li><a class="dropdown-item sort-trigger" data-sort-by="item" data-sort-dir="desc" data-sort-type="text" href="#">Product (Z-A)</a></li>
                                        <li><a class="dropdown-item sort-trigger" data-sort-by="user" data-sort-dir="asc" data-sort-type="text" href="#">Cashier (A-Z)</a></li>
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
                                            <th data-sort-by="item">Product</th>
                                            <th data-sort-by="qty" data-sort-type="number">Quantity</th>
                                            <th data-sort-by="value" data-sort-type="number">Total Price</th>
                                            <th data-sort-by="user">Cashier</th>
                                            <th>Reason</th>
                                            <th>Actions</th> </tr>
                                    </thead>
                                    <tbody id="recall-table-body">
                                        <?php if (empty($recall_history)): ?>
                                            <tr><td colspan="7" class="text-center text-muted">No recall history found.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($recall_history as $log): 
                                                $isUndone = strpos($log['reason'], '(Undone)') !== false;
                                            ?>
                                            <tr>
                                                <td data-label="Timestamp"><?php echo htmlspecialchars(date("M d, Y h:i A", strtotime($log["timestamp"]))); ?></td>
                                                <td data-label="Product"><?php echo htmlspecialchars($log["item_name"] ?? "Item Deleted"); ?></td>
                                                <td data-label="Quantity">
                                                    <?php if ($log["adjustment_qty"] < 0): ?>
                                                        <strong class="text-danger"><?php echo number_format($log["adjustment_qty"], 0); ?></strong>
                                                    <?php else: ?>
                                                        <strong class="text-success">+<?php echo number_format($log["adjustment_qty"], 0); ?></strong>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="Total Price">
                                                    <?php if ($log["removed_value"] < 0): ?>
                                                        <span class="text-danger">(₱<?php echo htmlspecialchars(number_format(abs($log["removed_value"]), 2)); ?>)</span>
                                                    <?php else: ?>
                                                        ₱0.00
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="Cashier"><?php echo htmlspecialchars($log["username"] ?? "N/A"); ?></td>
                                                <td data-label="Reason"><?php echo htmlspecialchars($log["reason"]); ?></td>
                                                
                                                <td>
                                                    <?php if (!$isUndone): ?>
                                                    <form method="POST" action="inventory_management.php" onsubmit="return confirm('Are you sure you want to undo this recall? Stock will be added back.');">
                                                        <input type="hidden" name="action" value="undo_recall">
                                                        <input type="hidden" name="active_tab" value="recall">
                                                        <input type="hidden" name="adjustment_id" value="<?php echo $log['adjustment_id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Undo Recall">
                                                            <i class="bi bi-arrow-counterclockwise"></i> Undo
                                                        </button>
                                                    </form>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Undone</span>
                                                    <?php endif; ?>
                                                </td>

                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="card-footer">
                            <div class="d-flex justify-content-end fw-bold fs-5">
                                <span class="me-3 text-danger">Total Removed Value:</span>
                                <span class="text-danger">₱<?php echo htmlspecialchars(number_format(abs($total_recall_value), 2)); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade <?php echo $active_tab === "history" ? "show active" : ""; ?>" id="history-pane" role="tabpanel">
                    <div class="card shadow-sm mt-3">
                        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-3">
                            <span class="fs-5">Stock Adjustment History</span>
                            <div class="d-flex flex-wrap justify-content-end align-items-center gap-2">
                                <div class="d-flex align-items-center gap-1">
                                    <label for="history-rows-select" class="form-label mb-0 small text-muted flex-shrink-0">Show</label>
                                    <select class="form-select form-select-sm" id="history-rows-select" style="width: auto;">
                                        <option value="10" selected>10</option>
                                        <option value="25">25</option>
                                        <option value="50">50</option>
                                        <option value="100">100</option>
                                        <option value="all">All</option>
                                    </select>
                                    <div class="btn-group btn-group-sm ms-1" role="group">
                                        <button type="button" class="btn btn-outline-secondary" id="history-prev-btn" disabled><i class="bi bi-arrow-left"></i></button>
                                        <button type="button" class="btn btn-outline-secondary" id="history-next-btn"><i class="bi bi-arrow-right"></i></button>
                                    </div>
                                </div>
                                <div class="dropdown">
                                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Sort By: <span class="current-sort-text">Date (Newest First)</span></button>
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
                                    <tbody id="history-table-body">
                                        <?php if (empty($adjustment_history)): ?>
                                            <tr><td colspan="6" class="text-center text-muted">No adjustment history found.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($adjustment_history as $log): ?>
                                            <tr>
                                                <td data-label="Timestamp"><?php echo htmlspecialchars(date("M d, Y h:i A", strtotime($log["timestamp"]))); ?></td>
                                                <td data-label="User"><?php echo htmlspecialchars($log["username"] ?? "N/A"); ?></td>
                                                <td data-label="Item Name"><?php echo htmlspecialchars($log["item_name"] ?? "Item Deleted"); ?></td>
                                                <td data-label="Type"><?php if ($log["item_type"] == "product"): ?><span class="badge bg-primary">Product</span><?php else: ?><span class="badge bg-secondary">Ingredient</span><?php endif; ?></td>
                                                <td data-label="Quantity">
                                                    <?php if ($log["adjustment_qty"] > 0): ?>
                                                        <strong class="text-success">+<?php echo number_format($log["adjustment_qty"], 2); ?></strong>
                                                    <?php elseif ($log["adjustment_qty"] < 0): ?>
                                                        <strong class="text-danger"><?php echo number_format($log["adjustment_qty"], 2); ?></strong>
                                                    <?php else: ?>
                                                        <?php echo number_format($log["adjustment_qty"], 2); ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="Reason"><?php echo htmlspecialchars($log["reason"]); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

            </div> 
        </main>
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

<div class="modal fade" id="addProductModal" tabindex="-1" aria-labelledby="addProductModalLabel" aria-hidden="true">
     <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="addProductModalLabel">Add New Product</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="inventory_management.php" method="POST" enctype="multipart/form-data">
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
    
                <div class="mb-3">
                    <label for="add_prod_image" class="form-label">Product Image (Optional)</label>
                    <input type="file" class="form-control" id="add_prod_image" name="product_image" accept="image/*">
                </div>
                <p class="text-muted small">Note: Initial stock is 0. Use "Adjust Stock" to add inventory and consume ingredients.</p>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
              <button type="submit" class="btn btn-primary">Add Product</button>
            </div>
          </form>
        </div>
      </div>
</div>

<div class="modal fade" id="restockIngredientModal" tabindex="-1" aria-labelledby="restockIngredientModalLabel" aria-hidden="true">
     <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="restockIngredientModalLabel">Restock Ingredient</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="inventory_management.php" method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="restock_ingredient">
                <input type="hidden" name="active_tab" value="ingredients"> 
                <input type="hidden" name="ingredient_id" id="restock_ingredient_id">
                <p>Restocking: <strong id="restock_ingredient_name"></strong> (<span id="restock_ingredient_unit"></span>)</p>
                
                <div class="mb-3">
                    <label for="restock_ing_qty" class="form-label">Quantity Added</label>
                    <input type="number" step="0.01" class="form-control" id="restock_ing_qty" name="adjustment_qty" required min="0.01">
                </div>

                <div class="mb-3">
                    <label for="restock_ing_expiration" class="form-label">Expiration Date</label>
                    <div class="input-group">
                        <input type="date" class="form-control" id="restock_ing_expiration" name="expiration_date" required>
                        <div class="input-group-text">
                            <input class="form-check-input mt-0" type="checkbox" value="" id="restock_no_expiry" aria-label="No Expiration">
                            <label class="form-check-label ms-2 mb-0 small" for="restock_no_expiry">N/A</label>
                        </div>
                    </div>
                    <div class="form-text">New stock will be added as a new batch.</div>
                </div>

                <div class="mb-3">
                    <label for="restock_ing_reason_note" class="form-label">Reason / Note</label>
                    <input type="text" class="form-control" id="restock_ing_reason_note" name="reason_note" placeholder="e.g., Invoice #1234" required>
                </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
              <button type="submit" class="btn btn-success">Add Stock</button>
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
                <input type="hidden" name="active_tab" value="products"> 
                <input type="hidden" name="product_id" id="adjust_product_id">
                <p>Adjusting: <strong id="adjust_product_name"></strong></p>
                
                <div class="mb-3">
                    <label for="adjust_type" class="form-label">Adjustment Type</label>
                    <select class="form-select" id="adjust_type" name="adjustment_type">
                        <option value="Production" selected>Production (Add Stock, Deduct Ingredients)</option>
                        <option value="Recall">Recall (Remove Stock)</option>
                        <option value="Correction">Correction (Add/Remove Stock & Ingredients)</option>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label for="adjust_adjustment_qty" class="form-label">Adjustment Quantity</label>
                    <input type="number" step="1" class="form-control" id="adjust_adjustment_qty" name="adjustment_qty" required>
                    <div class="form-text" id="adjust_qty_helper">
                        </div>
                </div>

                <div class="mb-3">
                    <label for="adjust_reason_note" class="form-label">Reason / Note</label>
                    <input type="text" class="form-control" id="adjust_reason_note" name="reason_note" placeholder="e.g., Daily bake, Expired items, Batch #123" required>
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
          <form action="inventory_management.php" method="POST" enctype="multipart/form-data">
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
    
                <div class="mb-3">
                    <label for="edit_product_image" class="form-label">Upload New Image (Optional)</label>
                    <input type="file" class="form-control" id="edit_product_image" name="edit_product_image" accept="image/*">
                    <div class="form-text">Leave blank to keep the current image.</div>
                    <input type="hidden" name="current_image_path" id="edit_product_current_image">
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

<div class="modal fade" id="batchesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg"> <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Batch Management: <span id="batch_modal_title"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered table-hover text-center align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 20%">Received</th>
                                <th style="width: 25%">Expiration</th>
                                <th style="width: 15%">Qty (<span id="batch_unit_display"></span>)</th>
                                <th style="width: 15%">Status</th>
                                <th style="width: 25%">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="batches_table_body">
                            </tbody>
                    </table>
                </div>
                <p class="small text-muted mt-2 text-center">
                    Use <strong>Edit</strong> icon to change expiration. Use <strong>Correction</strong> for manual corrections.
                </p>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<?php 
    $js_file = "../js/script_inventory.js";
    // Get the last modification time of the file to bust cache
    $js_version = file_exists($js_file) ? filemtime($js_file) : "1";
?>
<script src="../js/script_inventory.js?v=<?php echo $js_version; ?>"></script>

</body>
</html>