<?php
session_start();
require_once "../src/InventoryManager.php";
require_once "../src/BakeryManager.php";

// 1. Security & Authentication
if (!isset($_SESSION["user_id"])) {
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

// 2. Initialization
$inventoryManager = new InventoryManager();
$bakeryManager = new BakeryManager();
$current_role = $_SESSION["role"];

// 3. Handle JSON API Requests
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
                    throw new Exception('Missing parameters');
                }
                $response = $inventoryManager->updateBatchExpiry($input_data['batch_id'], $input_data['expiration_date']);
                break;

            case 'update_quantity':
                if (!isset($input_data['batch_id']) || !isset($input_data['new_quantity'])) {
                    throw new Exception('Missing parameters');
                }
                $response = $inventoryManager->updateBatchQuantity($input_data['batch_id'], $userId, $input_data['new_quantity'], $input_data['reason']);
                break;

            case 'delete_batch':
                if (!isset($input_data['batch_id'])) {
                    throw new Exception('Missing parameters');
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

// 4. Handle Form Submissions
$message = "";
$message_type = "";
if (isset($_SESSION["message"])) {
    $message = $_SESSION["message"];
    $message_type = $_SESSION["message_type"];
    unset($_SESSION["message"]);
    unset($_SESSION["message_type"]);
}

$active_tab = $_POST["active_tab"] ?? $_GET["active_tab"] ?? $_SESSION["active_tab"] ?? "products";
unset($_SESSION["active_tab"]);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    $form_active_tab = $_POST["active_tab"] ?? "products"; 
    $current_user_id = $_SESSION["user_id"]; 
    $success_message = "";
    $error_message = "";

    function handleProductImageUpload($file_input_name) {
        $target_dir = "../uploads/products/"; 
        if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);

        if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] == 0) {
            $file = $_FILES[$file_input_name];
            $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $safe_filename = uniqid('prod_', true) . '.' . $file_extension;
            $target_file = $target_dir . $safe_filename;

            $check = getimagesize($file["tmp_name"]);
            if ($check === false) return [null, "File is not an image."];
            if ($file["size"] > 20000000) return [null, "File too large (20MB limit)."];
            
            $allowed = ['jpg', 'png', 'jpeg', 'gif'];
            if (!in_array($file_extension, $allowed)) return [null, "Only JPG, PNG & GIF allowed."];

            if (move_uploaded_file($file["tmp_name"], $target_file)) return [$target_file, null]; 
            return [null, "Error uploading file."];
        }
        return [null, null]; 
    }

    try {
        switch ($action) {
            case "add_ingredient":
                $result = $bakeryManager->addIngredient($_POST["name"], $_POST["unit"], $_POST["stock_qty"], $_POST["reorder_level"]);
                if ($result === 'duplicate') $error_message = "Ingredient name already exists.";
                elseif ($result === 'success') $success_message = "Ingredient added!";
                else $error_message = "Error adding ingredient.";
                break;

            case "restock_ingredient":
                $qty = floatval($_POST['adjustment_qty']);
                if ($qty <= 0) {
                    $error_message = "Quantity must be greater than zero.";
                } else {
                    $expiry = !empty($_POST['expiration_date']) ? $_POST['expiration_date'] : null;
                    $result = $inventoryManager->createBatch($_POST['ingredient_id'], $current_user_id, $qty, $expiry, $_POST['reason_note']);
                    
                    if ($result['success']) $success_message = $result['message'];
                    else $error_message = $result['message'];
                }
                break;

            case "edit_ingredient":
                $bakeryManager->ingredientUpdate($_POST["ingredient_id"], $_POST["name"], $_POST["unit"], $_POST["reorder_level"]);
                $success_message = "Ingredient updated!";
                break;

            case "delete_ingredient":
                $status = $bakeryManager->ingredientDelete($_POST["ingredient_id"]);
                if (strpos($status, "Success") !== false) $success_message = $status;
                else $error_message = $status; 
                break;

            case "add_product":
                list($image_path, $upload_error) = handleProductImageUpload('product_image');
                if ($upload_error) { $error_message = $upload_error; break; }
                $result = $bakeryManager->addProduct($_POST["name"], $_POST["price"], $image_path);
                if ($result === 'duplicate') { 
                    $error_message = "Product name exists."; 
                    if($image_path) @unlink($image_path); 
                } elseif ($result === 'success') {
                    $success_message = "Product added!";
                } else {
                    $error_message = "Error adding product.";
                }
                break;

            case "adjust_product":
                $user_id_to_pass = $current_user_id ?? null;
                $qty = floatval($_POST["adjustment_qty"]);
                $type = $_POST["adjustment_type"];
                $product_id = $_POST["product_id"];

                if ($type === 'Production' && $qty < 0) {
                    $error_message = "Production adjustment cannot be negative.";
                } elseif ($type === 'Recall' && $qty > 0) {
                    $error_message = "Recall adjustment must be negative (remove stock).";
                } elseif ($qty == 0) {
                     $error_message = "Quantity cannot be zero.";
                } else {
                    // --- PRODUCTION LOGIC (PHP HANDLED) ---
                    if ($type === 'Production') {
                        $products_list = $bakeryManager->getAllProductsSimple();
                        $batch_size = 1;
                        foreach ($products_list as $p) {
                            if ($p['product_id'] == $product_id) {
                                $batch_size = ($p['batch_size'] > 0) ? $p['batch_size'] : 1;
                                break;
                            }
                        }

                        $recipe = $bakeryManager->getRecipeForProduct($product_id);
                        $all_ingredients = $inventoryManager->getIngredientsInventory();
                        
                        // 1. Map Stock by Name and Define Conversions
                        $stock_map = [];
                        foreach ($all_ingredients as $ing) {
                            $key = strtolower(trim($ing['name'])); 
                            $stock_map[$key] = [
                                'id' => $ing['ingredient_id'],
                                'qty' => floatval($ing['stock_qty']),
                                'unit' => $ing['unit']
                            ];
                        }

                        // Conversion factors to Base Units (g, ml, pcs)
                        $conversions = [
                            'kg' => 1000,   'g' => 1,
                            'L' => 1000,    'ml' => 1,
                            'tray' => 30,   'pcs' => 1, 
                            'pack' => 1,    'can' => 1,     'bottle' => 1
                        ];

                        $insufficient_items = [];
                        $ingredients_to_deduct = [];

                        foreach ($recipe as $item) {
                            $key = strtolower(trim($item['name']));
                            
                            // Units and Factors
                            $recipe_unit = $item['unit'];
                            // If ingredient exists in stock, use its unit; otherwise assume recipe unit
                            $stock_unit = $stock_map[$key]['unit'] ?? $recipe_unit;
                            
                            $recipe_factor = $conversions[$recipe_unit] ?? 1;
                            $stock_factor = $conversions[$stock_unit] ?? 1;

                            // Calculate NEEDED amount in BASE units
                            $needed_base = ($item['qty_needed'] * $recipe_factor / $batch_size) * $qty;

                            // Calculate CURRENT STOCK in BASE units
                            $current_stock_qty = $stock_map[$key]['qty'] ?? 0;
                            $current_base = $current_stock_qty * $stock_factor;

                            // Compare
                            if ($current_base < ($needed_base - 0.0001)) {
                                // Convert needed base amount back to STOCK unit for display
                                $needed_display = $needed_base / $stock_factor;
                                $insufficient_items[] = $item['name'] . " (Need: " . number_format($needed_display, 2) . " " . $stock_unit . ", Have: " . number_format($current_stock_qty, 2) . ")";
                            } else {
                                // Prepare data for deduction (Convert needed base back to Stock Unit)
                                $deduct_amount = $needed_base / $stock_factor;
                                if (isset($stock_map[$key]['id'])) {
                                    $ingredients_to_deduct[] = [
                                        'id' => $stock_map[$key]['id'],
                                        'qty' => $deduct_amount
                                    ];
                                }
                            }
                        }

                        if (!empty($insufficient_items)) {
                            $error_message = "Insufficient ingredients for production:<br><ul class='list-disc pl-5 text-left text-xs mt-2'><li>" . implode('</li><li>', $insufficient_items) . "</li></ul>";
                        } else {
                            // VALIDATION PASSED - EXECUTE MANUAL DEDUCTION
                            foreach ($ingredients_to_deduct as $ing) {
                                // Pass NEGATIVE quantity to remove stock
                                $inventoryManager->adjustIngredientStock($ing['id'], $user_id_to_pass, -abs($ing['qty']), "[Used] Production of " . $product_id);
                            }
                            
                            // Update Product Stock manually
                            $status = $bakeryManager->updateProductStockDirectly($product_id, $qty, $user_id_to_pass, "[{$type}] {$_POST["reason_note"]}");
                            
                            if (strpos($status, "Success") !== false) $success_message = $status;
                            else $error_message = $status;
                        }
                    } 
                    // --- RECALL / CORRECTION LOGIC (Standard SP) ---
                    else {
                        // For Recall/Manual Correction, use the standard procedure (it handles basic +/- fine)
                        $status = $bakeryManager->adjustProductStock($product_id, $user_id_to_pass, $qty, "[{$type}] {$_POST["reason_note"]}");
                        if (strpos($status, "Success") !== false) $success_message = $status;
                        else $error_message = $status; 
                    }
                }
                break;

            case "edit_product":
                $old_image = $_POST['current_image_path'] ?? null;
                list($new_image, $upload_error) = handleProductImageUpload('edit_product_image');
                if ($upload_error) { $error_message = $upload_error; break; }
                
                $success = $bakeryManager->productUpdate($_POST["product_id"], $_POST["name"], $_POST["price"], $_POST["status"], $new_image);
                if ($success) { 
                    $success_message = "Product updated!"; 
                    if($new_image && $old_image && file_exists($old_image)) @unlink($old_image); 
                } else { 
                    $error_message = "Update failed."; 
                    if($new_image) @unlink($new_image); 
                }
                break;

            case "delete_product":
                $status = $bakeryManager->productDelete($_POST["product_id"]);
                if (strpos($status, "Success") !== false) $success_message = $status;
                else $error_message = $status; 
                break;

            case "undo_recall":
                $result = $inventoryManager->undoRecall($_POST["adjustment_id"], $current_user_id);
                if ($result['success']) $success_message = $result['message'];
                else $error_message = $result['message'];
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
        $_SESSION["message"] = "Database Error: " . $e->getMessage();
        $_SESSION["message_type"] = "danger";
        $_SESSION["active_tab"] = $form_active_tab; 
        header("Location: inventory_management.php");
        exit();
    }
} 

$products = $inventoryManager->getProductsInventory();
$ingredients = $inventoryManager->getIngredientsInventory();
$discontinued_products = $inventoryManager->getDiscontinuedProducts();
$adjustment_history = $inventoryManager->getAdjustmentHistory();
$recall_history = $inventoryManager->getRecallHistoryByDate("1970-01-01", "2099-12-31");

$total_recall_value = 0;
foreach ($recall_history as $log) {
    $total_recall_value += abs($log['removed_value']);
}

$unit_options = ["kg", "g", "L", "ml", "pcs", "pack", "tray", "can", "bottle"];
$active_nav_link = 'inventory';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management</title>
    <link rel="icon" href="../images/kzklogo.png" type="image/x-icon"> 
    
    <link rel="stylesheet" href="../styles/global.css">

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
            <a href="dashboard_panel.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors text-breadly-secondary hover:bg-orange-100 hover:text-breadly-dark">
                <i class='bx bxs-dashboard text-xl'></i><span class="font-medium">Dashboard</span>
            </a>
            <a href="inventory_management.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors bg-breadly-dark text-white shadow-md">
                <i class='bx bxs-box text-xl'></i><span class="font-medium">Inventory</span>
            </a>
            <a href="recipes.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors text-breadly-secondary hover:bg-orange-100 hover:text-breadly-dark">
                <i class='bx bxs-book-bookmark text-xl'></i><span class="font-medium">Recipes</span>
            </a>
            <a href="sales_history.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors text-breadly-secondary hover:bg-orange-100 hover:text-breadly-dark">
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
            <a href="dashboard_panel.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-breadly-secondary">
                <i class='bx bxs-dashboard text-xl'></i> Dashboard
            </a>
            <a href="inventory_management.php" class="flex items-center gap-3 px-4 py-3 rounded-xl bg-breadly-dark text-white">
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
            <a href="logout.php" class="block w-full py-2 text-center text-sm bg-red-50 text-red-600 rounded-lg">Logout</a>
        </div>
    </div>

    <main class="flex-1 flex flex-col h-full overflow-hidden relative w-full">
        <div class="p-6 pb-2 flex flex-col md:flex-row md:items-center justify-between gap-4 bg-breadly-bg z-10">
            <div class="flex items-center gap-3">
                <button onclick="toggleSidebar()" class="lg:hidden text-breadly-dark text-2xl"><i class='bx bx-menu'></i></button>
                <h1 class="text-2xl font-bold text-breadly-dark">Inventory Management</h1>
            </div>
        </div>

        <?php if ($message): ?>
        <div class="px-6">
            <div class="<?php echo ($message_type === 'success') ? 'bg-green-100 text-green-800 border-green-200' : 'bg-red-100 text-red-800 border-red-200'; ?> border px-4 py-3 rounded-lg flex justify-between items-center mb-4 shadow-sm">
                <span><?php echo $message; ?></span>
                <button onclick="this.parentElement.remove()" class="text-lg font-bold">&times;</button>
            </div>
        </div>
        <?php endif; ?>

        <div class="px-6 border-b border-gray-200 bg-breadly-bg overflow-x-auto">
            <div class="flex gap-6 min-w-max" id="inventoryTabs">
                <button onclick="switchTab('products')" id="tab-products" class="pb-3 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 <?php echo ($active_tab == 'products') ? 'border-breadly-btn text-breadly-btn' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">
                    <i class='bx bxs-package text-lg'></i> Active Products
                </button>
                <button onclick="switchTab('ingredients')" id="tab-ingredients" class="pb-3 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 <?php echo ($active_tab == 'ingredients') ? 'border-breadly-btn text-breadly-btn' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">
                    <i class='bx bxs-flask text-lg'></i> Ingredients
                </button>
                <button onclick="switchTab('discontinued')" id="tab-discontinued" class="pb-3 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 <?php echo ($active_tab == 'discontinued') ? 'border-breadly-btn text-breadly-btn' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">
                    <i class='bx bxs-x-circle text-lg'></i> Discontinued
                </button>
                <button onclick="switchTab('recall')" id="tab-recall" class="pb-3 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 <?php echo ($active_tab == 'recall') ? 'border-yellow-500 text-yellow-600' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">
                    <i class='bx bxs-error text-lg'></i> Recall Log
                </button>
                <button onclick="switchTab('history')" id="tab-history" class="pb-3 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 <?php echo ($active_tab == 'history') ? 'border-breadly-btn text-breadly-btn' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">
                    <i class='bx bx-history text-lg'></i> History
                </button>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto p-6 pb-20 bg-breadly-bg">
            
            <div id="pane-products" class="<?php echo ($active_tab == 'products') ? '' : 'hidden'; ?>">
                <div class="bg-white rounded-xl shadow-sm border border-orange-100 overflow-hidden">
                    
                    <div class="p-4 border-b border-orange-100 flex flex-col sm:flex-row justify-between items-center gap-4 animate-slide-in delay-100">
                        <div class="relative w-full sm:w-64">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><i class='bx bx-search text-gray-400'></i></span>
                            <input type="text" id="product-search-input" class="w-full pl-10 pr-4 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-breadly-btn focus:border-breadly-btn outline-none" placeholder="Search products...">
                        </div>
                        <button onclick="openModal('addProductModal')" class="flex items-center gap-2 px-4 py-2 bg-breadly-btn text-white rounded-lg hover:bg-breadly-btn-hover transition-colors text-sm font-medium w-full sm:w-auto justify-center">
                            <i class='bx bx-plus-circle'></i> Add Product
                        </button>
                    </div>
                    
                    <div class="p-4 bg-gray-50 flex flex-wrap justify-end items-center gap-3 border-b border-gray-100 animate-slide-in delay-200">
                        <div class="flex items-center gap-1 text-sm">
                            <span class="text-gray-500">Show:</span>
                            <select id="product-rows-select" class="bg-white border border-gray-200 rounded-lg text-sm p-1.5 focus:outline-none">
                                <option value="10">10</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="all">All</option>
                            </select>
                        </div>
                        <div class="flex items-center gap-1">
                            <button id="product-prev-btn" disabled class="p-1.5 bg-white border rounded hover:bg-gray-100 disabled:opacity-50"><i class='bx bx-chevron-left'></i></button>
                            <button id="product-next-btn" class="p-1.5 bg-white border rounded hover:bg-gray-100 disabled:opacity-50"><i class='bx bx-chevron-right'></i></button>
                        </div>
                        <div class="flex items-center gap-1 text-sm">
                            <select id="product-sort-select" class="bg-white border border-gray-200 rounded-lg text-sm p-1.5 focus:outline-none cursor-pointer focus:ring-1 focus:ring-breadly-btn text-gray-700">
                                <option value="" disabled>Sort By...</option>
                                <option value="name_asc" selected>Name (A-Z)</option>
                                <option value="name_desc">Name (Z-A)</option>
                                <option value="price_asc">Price (Low-High)</option>
                                <option value="price_desc">Price (High-Low)</option>
                                <option value="stock_asc">Stock (Low-High)</option>
                                <option value="stock_desc">Stock (High-Low)</option>
                            </select>
                        </div>
                    </div>
                    <div class="p-6 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-6 animate-slide-in delay-200" id="product-card-list">
                        <?php if (empty($products)): ?>
                            <div class="col-span-full text-center py-10 text-gray-400">
                                <i class='bx bxs-package text-4xl mb-2'></i><p>No active products found.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($products as $product): ?>
                                <?php 
                                $image_path = !empty($product['image_url']) ? htmlspecialchars($product['image_url']) : '../images/breadlylogo.png'; 
                                $stock_class = $product['stock_qty'] <= 0 ? 'text-red-600' : 'text-gray-800';
                                ?>
                                <div class="product-item group bg-white rounded-xl border border-gray-100 hover:border-breadly-btn hover:shadow-md transition-all duration-200 flex flex-col overflow-hidden h-full" 
                                     data-product-name="<?php echo htmlspecialchars(strtolower($product["name"])); ?>"
                                     data-product-price="<?php echo $product["price"]; ?>"
                                     data-product-stock="<?php echo $product["stock_qty"]; ?>">
                                    <div class="relative pt-[75%] bg-gray-50 border-b border-gray-100">
                                        <img src="<?php echo $image_path; ?>" class="absolute inset-0 w-full h-full object-cover">
                                        <div class="absolute top-2 right-2">
                                            <span class="bg-white/90 backdrop-blur-sm px-2 py-1 rounded text-xs font-bold text-breadly-dark shadow-sm border border-gray-100">
                                                Stock: <span class="<?php echo $stock_class; ?>"><?php echo $product["stock_qty"]; ?></span>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="p-4 flex flex-col flex-1">
                                        <h5 class="font-semibold text-breadly-dark mb-1 line-clamp-1"><?php echo htmlspecialchars($product["name"]); ?></h5>
                                        <p class="text-sm text-gray-500 mb-4">₱<?php echo number_format($product["price"], 2); ?></p>
                                        <div class="mt-auto grid grid-cols-2 gap-2">
                                            <button onclick="openModal('editProductModal', this)" 
                                                    class="px-2 py-1.5 text-xs font-medium text-blue-600 bg-blue-50 rounded hover:bg-blue-100 transition-colors flex items-center justify-center gap-1"
                                                    data-product-id="<?php echo $product["product_id"]; ?>"
                                                    data-product-name="<?php echo htmlspecialchars($product["name"]); ?>"
                                                    data-product-price="<?php echo $product["price"]; ?>"
                                                    data-product-status="<?php echo $product["status"]; ?>"
                                                    data-product-image="<?php echo $image_path; ?>">
                                                <i class='bx bx-edit'></i> Edit
                                            </button>
                                            <button onclick="openModal('adjustProductModal', this)" 
                                                    class="px-2 py-1.5 text-xs font-medium text-orange-600 bg-orange-50 rounded hover:bg-orange-100 transition-colors flex items-center justify-center gap-1"
                                                    data-product-id="<?php echo $product["product_id"]; ?>"
                                                    data-product-name="<?php echo htmlspecialchars($product["name"]); ?>">
                                                <i class='bx bx-slider-alt'></i> Adjust
                                            </button>
                                            <button onclick="openModal('deleteProductModal', this)" 
                                                    class="col-span-2 px-2 py-1.5 text-xs font-medium text-red-600 bg-red-50 rounded hover:bg-red-100 transition-colors flex items-center justify-center gap-1"
                                                    data-product-id="<?php echo $product["product_id"]; ?>"
                                                    data-product-name="<?php echo htmlspecialchars($product["name"]); ?>">
                                                <i class='bx bx-trash'></i> Delete
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <div id="product-no-results" class="hidden col-span-full text-center py-10 text-gray-400">
                             <i class='bx bx-search text-4xl mb-2'></i>
                             <p>No products match your search.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div id="pane-ingredients" class="<?php echo ($active_tab == 'ingredients') ? '' : 'hidden'; ?>">
                <div class="bg-white rounded-xl shadow-sm border border-orange-100">
                    
                    <div class="p-4 border-b border-orange-100 flex flex-col sm:flex-row justify-between items-center gap-4 animate-slide-in delay-100">
                        <div class="relative w-full sm:w-64">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><i class='bx bx-search text-gray-400'></i></span>
                            <input type="text" id="ingredient-search-input" class="w-full pl-10 pr-4 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-breadly-btn focus:border-breadly-btn outline-none" placeholder="Search ingredients...">
                        </div>
                        <button onclick="openModal('addIngredientModal')" class="flex items-center gap-2 px-4 py-2 bg-breadly-btn text-white rounded-lg hover:bg-breadly-btn-hover transition-colors text-sm font-medium w-full sm:w-auto justify-center">
                            <i class='bx bx-plus-circle'></i> Add Ingredient
                        </button>
                    </div>
                    
                    <div class="p-4 bg-gray-50 flex flex-wrap justify-end items-center gap-3 border-b border-gray-100 animate-slide-in delay-200">
                        <div class="flex items-center gap-1 text-sm">
                            <span class="text-gray-500">Show:</span>
                            <select id="ingredient-rows-select" class="bg-white border border-gray-200 rounded-lg text-sm p-1.5 focus:outline-none">
                                <option value="10">10</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="all">All</option>
                            </select>
                        </div>
                        <div class="flex items-center gap-1">
                            <button id="ingredient-prev-btn" disabled class="p-1.5 bg-white border rounded hover:bg-gray-100 disabled:opacity-50"><i class='bx bx-chevron-left'></i></button>
                            <button id="ingredient-next-btn" class="p-1.5 bg-white border rounded hover:bg-gray-100 disabled:opacity-50"><i class='bx bx-chevron-right'></i></button>
                        </div>
                        
                        <select id="ingredient-sort-select" class="bg-white border border-gray-200 rounded-lg text-sm p-1.5 focus:outline-none cursor-pointer">
                            <option value="" disabled>Sort By...</option>
                            <option value="name_asc" data-sort-by="name" data-sort-type="text" data-sort-dir="ASC" selected>Name (Ascending)</option>
                            <option value="name_desc" data-sort-by="name" data-sort-type="text" data-sort-dir="DESC">Name (Descending)</option>
                            <option value="stock_desc" data-sort-by="stock" data-sort-type="number" data-sort-dir="DESC">Stock (Descending)</option>
                            <option value="stock_asc" data-sort-by="stock" data-sort-type="number" data-sort-dir="ASC">Stock (Ascending)</option>
                            <option value="reorder_asc" data-sort-by="reorder" data-sort-type="number" data-sort-dir="ASC">Reorder Level (Ascending)</option>
                            <option value="reorder_desc" data-sort-by="reorder" data-sort-type="number" data-sort-dir="DESC">Reorder Level (Descending)</option>
                            <option value="status_asc" data-sort-by="status" data-sort-type="text" data-sort-dir="ASC">Status (Ascending)</option>
                            <option value="status_desc" data-sort-by="status" data-sort-type="text" data-sort-dir="DESC">Status (Descending)</option>
                        </select>
                    </div>

                    <div class="overflow-x-auto animate-slide-in delay-300">
                        <table class="w-full text-left border-collapse">
                            <thead class="bg-gray-50 text-xs uppercase text-gray-500 font-semibold">
                                <tr>
                                    <th class="px-6 py-3" data-sort-by="name" data-sort-type="text">Name</th>
                                    <th class="px-6 py-3">Unit</th>
                                    <th class="px-6 py-3 text-right" data-sort-by="stock" data-sort-type="number">Stock</th>
                                    <th class="px-6 py-3 text-right" data-sort-by="reorder" data-sort-type="number">Reorder</th>
                                    <th class="px-6 py-3 text-center" data-sort-by="status" data-sort-type="text">Status</th>
                                    <th class="px-6 py-3 text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100" id="ingredient-table-body">
                                <?php if (empty($ingredients)): ?>
                                    <tr><td colspan="6" class="px-6 py-8 text-center text-gray-400">No ingredients found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($ingredients as $ing): ?>
                                    <tr class="hover:bg-gray-50 transition-colors <?php echo $ing["stock_surplus"] <= 0 ? "bg-red-50/50" : ""; ?>" data-name="<?php echo htmlspecialchars(strtolower($ing["name"])); ?>">
                                        <td class="px-6 py-3 font-medium text-gray-800"><?php echo htmlspecialchars($ing["name"]); ?></td>
                                        <td class="px-6 py-3 text-gray-600"><?php echo htmlspecialchars($ing["unit"]); ?></td>
                                        <td class="px-6 py-3 text-right font-bold" data-sort-value="<?php echo $ing["stock_qty"]; ?>"><?php echo number_format($ing["stock_qty"], 2); ?></td>
                                        <td class="px-6 py-3 text-right text-gray-600" data-sort-value="<?php echo $ing["reorder_level"]; ?>"><?php echo number_format($ing["reorder_level"], 2); ?></td>
                                        <td class="px-6 py-3 text-center">
                                            <?php if ($ing["stock_surplus"] <= 0): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">Low Stock</span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">In Stock</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-3 text-center">
                                            <div class="flex items-center justify-center gap-2">
                                                <button onclick="openModal('editIngredientModal', this)" class="flex items-center gap-1 bg-blue-50 text-blue-600 px-3 py-1.5 rounded hover:bg-blue-100 transition-colors text-xs font-medium"
                                                    data-ingredient-id="<?php echo $ing["ingredient_id"]; ?>" 
                                                    data-ingredient-name="<?php echo htmlspecialchars($ing["name"]); ?>" 
                                                    data-ingredient-unit="<?php echo htmlspecialchars($ing["unit"]); ?>" 
                                                    data-ingredient-reorder="<?php echo $ing["reorder_level"]; ?>">
                                                    <i class='bx bx-edit'></i> Edit
                                                </button>
                                                <button onclick="openModal('restockIngredientModal', this)" class="flex items-center gap-1 bg-green-50 text-green-600 px-3 py-1.5 rounded hover:bg-green-100 transition-colors text-xs font-medium"
                                                    data-ingredient-id="<?php echo $ing['ingredient_id']; ?>" 
                                                    data-ingredient-name="<?php echo htmlspecialchars($ing['name']); ?>" 
                                                    data-ingredient-unit="<?php echo $ing['unit']; ?>">
                                                    <i class='bx bx-plus-circle'></i> Restock
                                                </button>
                                                <button onclick="openModal('batchesModal', this)" class="flex items-center gap-1 bg-purple-50 text-purple-600 px-3 py-1.5 rounded hover:bg-purple-100 transition-colors text-xs font-medium"
                                                    data-id="<?php echo $ing['ingredient_id']; ?>" 
                                                    data-name="<?php echo htmlspecialchars($ing['name']); ?>" 
                                                    data-unit="<?php echo $ing['unit']; ?>">
                                                    <i class='bx bx-layer'></i> Batches
                                                </button>
                                                <button onclick="openModal('deleteIngredientModal', this)" class="flex items-center gap-1 bg-red-50 text-red-600 px-3 py-1.5 rounded hover:bg-red-100 transition-colors text-xs font-medium"
                                                    data-ingredient-id="<?php echo $ing["ingredient_id"]; ?>" 
                                                    data-ingredient-name="<?php echo htmlspecialchars($ing["name"]); ?>">
                                                    <i class='bx bx-trash'></i> Delete
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <tr id="ingredient-no-results" class="hidden">
                                    <td colspan="6" class="text-center py-4 text-gray-400">
                                         <i class='bx bx-search text-xl align-middle mr-1'></i> No ingredients match your search.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="pane-discontinued" class="<?php echo ($active_tab == 'discontinued') ? '' : 'hidden'; ?>">
                <div class="bg-white rounded-xl shadow-sm border border-orange-100 ">
                    <div class="p-4 border-b border-orange-100 animate-slide-in delay-100">
                        <h5 class="font-bold text-lg text-breadly-dark">Discontinued Products</h5>
                    </div>
                    
                    <div class="p-4 bg-gray-50 flex flex-wrap justify-end items-center gap-3 border-b border-gray-100 animate-slide-in delay-200">
                        <div class="flex items-center gap-1 text-sm">
                            <span class="text-gray-500">Show:</span>
                            <select id="discontinued-rows-select" class="bg-white border border-gray-200 rounded-lg text-sm p-1.5 focus:outline-none">
                                <option value="10">10</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="all">All</option>
                            </select>
                        </div>
                        <div class="flex items-center gap-1">
                            <button id="discontinued-prev-btn" disabled class="p-1.5 bg-white border rounded hover:bg-gray-100 disabled:opacity-50"><i class='bx bx-chevron-left'></i></button>
                            <button id="discontinued-next-btn" class="p-1.5 bg-white border rounded hover:bg-gray-100 disabled:opacity-50"><i class='bx bx-chevron-right'></i></button>
                        </div>
                        
                        <select id="discontinued-sort-select" class="bg-white border border-gray-200 rounded-lg text-sm p-1.5 focus:outline-none cursor-pointer">
                            <option value="" disabled>Sort By...</option>
                            <option value="name_asc" data-sort-by="name" data-sort-type="text" data-sort-dir="ASC" selected>Name (Ascending)</option>
                            <option value="name_desc" data-sort-by="name" data-sort-type="text" data-sort-dir="DESC">Name (Descending)</option>
                            <option value="price_asc" data-sort-by="price" data-sort-type="number" data-sort-dir="ASC">Price (Ascending)</option>
                            <option value="price_desc" data-sort-by="price" data-sort-type="number" data-sort-dir="DESC">Price (Descending)</option>
                            <option value="stock_asc" data-sort-by="stock" data-sort-type="number" data-sort-dir="ASC">Last Stock (Ascending)</option>
                            <option value="stock_desc" data-sort-by="stock" data-sort-type="number" data-sort-dir="DESC">Last Stock (Descending)</option>
                            <option value="status_asc" data-sort-by="status" data-sort-type="text" data-sort-dir="ASC">Status (Ascending)</option>
                            <option value="status_desc" data-sort-by="status" data-sort-type="text" data-sort-dir="DESC">Status (Descending)</option>
                        </select>
                    </div>

                    <div class="overflow-x-auto animate-slide-in delay-300">
                        <table class="w-full text-left border-collapse">
                            <thead class="bg-gray-50 text-xs uppercase text-gray-500 font-semibold">
                                <tr>
                                    <th class="px-6 py-3" data-sort-by="name" data-sort-type="text">Name</th>
                                    <th class="px-6 py-3" data-sort-by="price" data-sort-type="number">Price</th>
                                    <th class="px-6 py-3" data-sort-by="status" data-sort-type="text">Status</th>
                                    <th class="px-6 py-3" data-sort-by="stock" data-sort-type="number">Last Stock</th>
                                    <th class="px-6 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100" id="discontinued-table-body">
                                <?php foreach ($discontinued_products as $product): ?>
                                <tr>
                                    <td class="px-6 py-3 font-medium text-gray-800"><?php echo htmlspecialchars($product["name"]); ?></td>
                                    <td class="px-6 py-3" data-sort-value="<?php echo $product["price"]; ?>">₱<?php echo number_format($product["price"], 2); ?></td>
                                    <td class="px-6 py-3"><span class="bg-gray-100 text-gray-600 px-2 py-1 rounded text-xs font-bold"><?php echo htmlspecialchars(ucfirst($product["status"])); ?></span></td>
                                    <td class="px-6 py-3" data-sort-value="<?php echo $product["stock_qty"]; ?>"><?php echo $product["stock_qty"]; ?></td>
                                    <td class="px-6 py-3 text-right">
                                        <div class="flex justify-end gap-2">
                                            <button onclick="openModal('editProductModal', this)"
                                                    class="flex items-center gap-1 bg-blue-50 text-blue-600 px-4 py-2 rounded hover:bg-blue-100 transition-colors text-sm font-medium"
                                                    data-product-id="<?php echo $product["product_id"]; ?>"
                                                    data-product-name="<?php echo htmlspecialchars($product["name"]); ?>"
                                                    data-product-price="<?php echo $product["price"]; ?>"
                                                    data-product-status="<?php echo $product["status"]; ?>"
                                                    data-product-image="<?php echo !empty($product['image_url']) ? htmlspecialchars($product['image_url']) : ''; ?>">
                                                <i class='bx bx-refresh'></i> Restore
                                            </button>
                                            <button onclick="openModal('deleteProductModal', this)"
                                                    class="flex items-center gap-1 bg-red-50 text-red-600 px-4 py-2 rounded hover:bg-red-100 transition-colors text-sm font-medium"
                                                    data-product-id="<?php echo $product["product_id"]; ?>"
                                                    data-product-name="<?php echo htmlspecialchars($product["name"]); ?>">
                                                <i class='bx bx-trash'></i> Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="pane-recall" class="<?php echo ($active_tab == 'recall') ? '' : 'hidden'; ?>">
                 <div class="bg-white rounded-xl shadow-sm border border-orange-100">
                    <div class="p-4 border-b border-orange-100 bg-red-50/30 animate-slide-in delay-100">
                        <h5 class="font-bold text-lg text-red-800">Recall Log</h5>
                    </div>
                    
                    <div class="p-4 bg-gray-50 flex flex-wrap justify-end items-center gap-3 border-b border-gray-100 animate-slide-in delay-200">
                        <div class="flex items-center gap-1 text-sm">
                            <span class="text-gray-500">Show:</span>
                            <select id="recall-rows-select" class="bg-white border border-gray-200 rounded-lg text-sm p-1.5 focus:outline-none">
                                <option value="10">10</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="all">All</option>
                            </select>
                        </div>
                        <div class="flex items-center gap-1">
                            <button id="recall-prev-btn" disabled class="p-1.5 bg-white border rounded hover:bg-gray-100 disabled:opacity-50"><i class='bx bx-chevron-left'></i></button>
                            <button id="recall-next-btn" class="p-1.5 bg-white border rounded hover:bg-gray-100 disabled:opacity-50"><i class='bx bx-chevron-right'></i></button>
                        </div>
                        
                        <select id="recall-sort-select" class="bg-white border border-gray-200 rounded-lg text-sm p-1.5 focus:outline-none cursor-pointer">
                            <option value="" disabled>Sort By...</option>
                            <option value="date_asc" data-sort-by="date" data-sort-type="date" data-sort-dir="ASC">Date (Ascending)</option>
                            <option value="date_desc" data-sort-by="date" data-sort-type="date" data-sort-dir="DESC" selected>Date (Descending)</option>
                            <option value="product_asc" data-sort-by="product" data-sort-type="text" data-sort-dir="ASC">Product (Ascending)</option>
                            <option value="product_desc" data-sort-by="product" data-sort-type="text" data-sort-dir="DESC">Product (Descending)</option>
                            <option value="qty_asc" data-sort-by="qty" data-sort-type="number" data-sort-dir="ASC">Qty (Ascending)</option>
                            <option value="qty_desc" data-sort-by="qty" data-sort-type="number" data-sort-dir="DESC">Qty (Descending)</option>
                            <option value="value_asc" data-sort-by="value" data-sort-type="number" data-sort-dir="ASC">Value (Ascending)</option>
                            <option value="value_desc" data-sort-by="value" data-sort-type="number" data-sort-dir="DESC">Value (Descending)</option>
                        </select>
                    </div>

                    <div class="overflow-x-auto animate-slide-in delay-300">
                        <table class="w-full text-left border-collapse">
                            <thead class="bg-gray-50 text-xs uppercase text-gray-500 font-semibold">
                                <tr>
                                    <th class="px-6 py-3" data-sort-by="date" data-sort-type="date">Date</th>
                                    <th class="px-6 py-3" data-sort-by="product" data-sort-type="text">Product</th>
                                    <th class="px-6 py-3" data-sort-by="qty" data-sort-type="number">Qty</th>
                                    <th class="px-6 py-3" data-sort-by="value" data-sort-type="number">Value</th>
                                    <th class="px-6 py-3">Reason</th>
                                    <th class="px-6 py-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100" id="recall-table-body">
                                <?php foreach ($recall_history as $log): $isUndone = strpos($log['reason'], '(Undone)') !== false; ?>
                                <tr>
                                    <td class="px-6 py-3 text-sm" data-sort-value="<?php echo strtotime($log["timestamp"]); ?>"><?php echo htmlspecialchars(date("M d, Y h:i A", strtotime($log["timestamp"]))); ?></td>
                                    <td class="px-6 py-3 font-medium"><?php echo htmlspecialchars($log["item_name"] ?? "Deleted"); ?></td>
                                    <td class="px-6 py-3 text-red-600 font-bold" data-sort-value="<?php echo $log["adjustment_qty"]; ?>"><?php echo number_format($log["adjustment_qty"], 0); ?></td>
                                    <td class="px-6 py-3 text-red-600" data-sort-value="<?php echo abs($log["removed_value"]); ?>">(₱<?php echo htmlspecialchars(number_format(abs($log["removed_value"]), 2)); ?>)</td>
                                    <td class="px-6 py-3 text-sm"><?php echo htmlspecialchars($log["reason"]); ?></td>
                                    <td class="px-6 py-3 text-right">
                                        <?php if (!$isUndone): ?>
                                        <form method="POST" action="inventory_management.php" onsubmit="return confirm('Undo recall?');" class="inline">
                                            <input type="hidden" name="action" value="undo_recall">
                                            <input type="hidden" name="adjustment_id" value="<?php echo $log['adjustment_id']; ?>">
                                            <button type="submit" class="flex items-center gap-1 bg-gray-100 text-gray-700 px-3 py-1.5 rounded hover:bg-gray-200 transition-colors text-xs font-medium border border-gray-300 ml-auto">
                                                <i class='bx bx-undo'></i> Undo
                                            </button>
                                        </form>
                                        <?php else: ?>
                                            <span class="bg-gray-100 text-gray-400 px-2 py-1 rounded text-xs">Undone</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-red-100/50 text-red-800 font-bold border-t-2 border-red-200">
                                <tr>
                                    <td class="px-6 py-3 text-base" colspan="3">TOTAL RECALL VALUE:</td>
                                    <td class="px-6 py-3 text-base">(₱<?php echo number_format($total_recall_value, 2); ?>)</td>
                                    <td class="px-6 py-3" colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                 </div>
            </div>

            <div id="pane-history" class="<?php echo ($active_tab == 'history') ? '' : 'hidden'; ?>">
                <div class="bg-white rounded-xl shadow-sm border border-orange-100 overflow-hidden">
                    <div class="p-4 border-b border-orange-100 animate-slide-in delay-100">
                        <h5 class="font-bold text-lg text-breadly-dark">Adjustment History</h5>
                    </div>
                    
                    <div class="p-4 bg-gray-50 flex flex-wrap justify-end items-center gap-3 border-b border-gray-100 animate-slide-in delay-200">
                        <div class="flex items-center gap-1 text-sm">
                            <span class="text-gray-500">Show:</span>
                            <select id="history-rows-select" class="bg-white border border-gray-200 rounded-lg text-sm p-1.5 focus:outline-none">
                                <option value="10">10</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="all">All</option>
                            </select>
                        </div>
                        <div class="flex items-center gap-1">
                            <button id="history-prev-btn" disabled class="p-1.5 bg-white border rounded hover:bg-gray-100 disabled:opacity-50"><i class='bx bx-chevron-left'></i></button>
                            <button id="history-next-btn" class="p-1.5 bg-white border rounded hover:bg-gray-100 disabled:opacity-50"><i class='bx bx-chevron-right'></i></button>
                        </div>
                        
                        <select id="history-sort-select" class="bg-white border border-gray-200 rounded-lg text-sm p-1.5 focus:outline-none cursor-pointer">
                            <option value="" disabled>Sort By...</option>
                            <option value="date_asc" data-sort-by="date" data-sort-type="date" data-sort-dir="ASC">Date (Ascending)</option>
                            <option value="date_desc" data-sort-by="date" data-sort-type="date" data-sort-dir="DESC" selected>Date (Descending)</option>
                            <option value="user_asc" data-sort-by="user" data-sort-type="text" data-sort-dir="ASC">User (Ascending)</option>
                            <option value="user_desc" data-sort-by="user" data-sort-type="text" data-sort-dir="DESC">User (Descending)</option>
                            <option value="item_asc" data-sort-by="item" data-sort-type="text" data-sort-dir="ASC">Item (Ascending)</option>
                            <option value="item_desc" data-sort-by="item" data-sort-type="text" data-sort-dir="DESC">Item (Descending)</option>
                            <option value="qty_asc" data-sort-by="qty" data-sort-type="number" data-sort-dir="ASC">Quantity (Ascending)</option>
                            <option value="qty_desc" data-sort-by="qty" data-sort-type="number" data-sort-dir="DESC">Quantity (Descending)</option>
                        </select>
                    </div>

                    <div class="overflow-x-auto animate-slide-in delay-300">
                        <table class="w-full text-left border-collapse">
                            <thead class="bg-gray-50 text-xs uppercase text-gray-500 font-semibold">
                                <tr>
                                    <th class="px-6 py-3" data-sort-by="date" data-sort-type="date">Date</th>
                                    <th class="px-6 py-3" data-sort-by="user" data-sort-type="text">User</th>
                                    <th class="px-6 py-3" data-sort-by="item" data-sort-type="text">Item</th>
                                    <th class="px-6 py-3">Type</th>
                                    <th class="px-6 py-3" data-sort-by="qty" data-sort-type="number">Qty</th>
                                    <th class="px-6 py-3">Reason</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100" id="history-table-body">
                                <?php foreach ($adjustment_history as $log): ?>
                                <tr>
                                    <td class="px-6 py-3 text-sm" data-sort-value="<?php echo strtotime($log["timestamp"]); ?>"><?php echo htmlspecialchars(date("M d, Y h:i A", strtotime($log["timestamp"]))); ?></td>
                                    <td class="px-6 py-3"><?php echo htmlspecialchars($log["username"] ?? "N/A"); ?></td>
                                    <td class="px-6 py-3 font-medium"><?php echo htmlspecialchars($log["item_name"] ?? "Deleted"); ?></td>
                                    <td class="px-6 py-3">
                                        <span class="px-2 py-0.5 rounded text-xs font-semibold <?php echo $log["item_type"] == "product" ? "bg-blue-100 text-blue-800" : "bg-purple-100 text-purple-800"; ?>">
                                            <?php echo ucfirst($log["item_type"]); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-3 font-bold <?php echo $log["adjustment_qty"] > 0 ? 'text-green-600' : 'text-red-600'; ?>" data-sort-value="<?php echo $log["adjustment_qty"]; ?>">
                                        <?php echo ($log["adjustment_qty"] > 0 ? '+' : '') . number_format($log["adjustment_qty"], 2); ?>
                                    </td>
                                    <td class="px-6 py-3 text-sm"><?php echo htmlspecialchars($log["reason"]); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <div id="modalBackdrop" class="fixed inset-0 bg-black/50 z-40 hidden transition-opacity" onclick="closeAllModals()"></div>
    
    <div id="addIngredientModal" class="fixed inset-0 z-50 hidden flex items-center justify-center">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl m-4 overflow-hidden transform transition-all scale-100 relative z-50 modal-animate-in">
            <div class="p-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
                <h5 class="font-bold text-gray-800">Add New Ingredient</h5>
                <button onclick="closeModal('addIngredientModal')" class="text-gray-400 hover:text-gray-600"><i class='bx bx-x text-2xl'></i></button>
            </div>
            <form action="inventory_management.php" method="POST" class="p-6">
                <input type="hidden" name="action" value="add_ingredient">
                <input type="hidden" name="active_tab" value="ingredients"> 
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Ingredient Name</label>
                        <input type="text" name="name" required class="w-full p-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-breadly-btn focus:border-breadly-btn outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Unit</label>
                        <select name="unit" required class="w-full p-2.5 border border-gray-300 rounded-lg">
                            <option value="" selected disabled>Select...</option>
                            <?php foreach ($unit_options as $unit) echo "<option value='$unit'>$unit</option>"; ?>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Initial Stock</label>
                            <input type="number" step="0.01" name="stock_qty" value="0" class="w-full p-2.5 border border-gray-300 rounded-lg">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Reorder Level</label>
                            <input type="number" step="0.01" name="reorder_level" value="0" class="w-full p-2.5 border border-gray-300 rounded-lg">
                        </div>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" onclick="closeModal('addIngredientModal')" class="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-breadly-btn text-white rounded-lg hover:bg-breadly-btn-hover">Add Ingredient</button>
                </div>
            </form>
        </div>
    </div>

    <div id="addProductModal" class="fixed inset-0 z-50 hidden flex items-center justify-center">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl m-4 overflow-hidden relative z-50 modal-animate-in">
            <div class="p-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
                <h5 class="font-bold text-gray-800">Add New Product</h5>
                <button onclick="closeModal('addProductModal')" class="text-gray-400 hover:text-gray-600"><i class='bx bx-x text-2xl'></i></button>
            </div>
            <form action="inventory_management.php" method="POST" enctype="multipart/form-data" class="p-6">
                <input type="hidden" name="action" value="add_product">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Product Name</label>
                        <input type="text" name="name" required class="w-full p-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-breadly-btn outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Price (PHP)</label>
                        <input type="number" step="0.01" name="price" required class="w-full p-2.5 border border-gray-300 rounded-lg outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Image (Optional)</label>
                        <input type="file" name="product_image" accept="image/*" class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-orange-50 file:text-orange-700 hover:file:bg-orange-100">
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" onclick="closeModal('addProductModal')" class="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-breadly-btn text-white rounded-lg hover:bg-breadly-btn-hover">Add Product</button>
                </div>
            </form>
        </div>
    </div>

    <div id="adjustProductModal" class="fixed inset-0 z-50 hidden flex items-center justify-center">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl m-4 overflow-hidden relative z-50 modal-animate-in">
            <div class="p-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
                <h5 class="font-bold text-gray-800">Adjust Product Stock</h5>
                <button onclick="closeModal('adjustProductModal')" class="text-gray-400 hover:text-gray-600"><i class='bx bx-x text-2xl'></i></button>
            </div>
            <form action="inventory_management.php" method="POST" class="p-6">
                <input type="hidden" name="action" value="adjust_product">
                <input type="hidden" name="product_id" id="adjust_product_id">
                <input type="hidden" name="active_tab" value="products"> 
                <p class="mb-4 text-sm text-gray-600">Adjusting: <strong id="adjust_product_name" class="text-breadly-dark"></strong></p>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Adjustment Type</label>
                        <select name="adjustment_type" id="adjust_type" class="w-full p-2.5 border border-gray-300 rounded-lg">
                            <option value="Production">Production (Add Stock)</option>
                            <option value="Recall">Recall (Remove Stock)</option>
                            <option value="Correction">Correction (Manual +/-)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Quantity</label>
                        <input type="number" step="1" name="adjustment_qty" id="adjust_adjustment_qty" required class="w-full p-2.5 border border-gray-300 rounded-lg">
                        <p class="text-xs text-gray-500 mt-1" id="adjust_qty_helper"></p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Reason</label>
                        <input type="text" name="reason_note" required placeholder="e.g. Daily Bake" class="w-full p-2.5 border border-gray-300 rounded-lg">
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" onclick="closeModal('adjustProductModal')" class="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600">Save</button>
                </div>
            </form>
        </div>
    </div>

    <div id="editIngredientModal" class="fixed inset-0 z-50 hidden flex items-center justify-center">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl m-4 overflow-hidden relative z-50 modal-animate-in">
            <div class="p-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
                <h5 class="font-bold text-gray-800">Edit Ingredient</h5>
                <button onclick="closeModal('editIngredientModal')" class="text-gray-400 hover:text-gray-600"><i class='bx bx-x text-2xl'></i></button>
            </div>
            <form action="inventory_management.php" method="POST" class="p-6">
                <input type="hidden" name="action" value="edit_ingredient">
                <input type="hidden" name="active_tab" value="ingredients">
                <input type="hidden" name="ingredient_id" id="edit_ingredient_id">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                        <input type="text" name="name" id="edit_ingredient_name" required class="w-full p-2.5 border border-gray-300 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Unit</label>
                        <select name="unit" id="edit_ingredient_unit" required class="w-full p-2.5 border border-gray-300 rounded-lg">
                            <?php foreach ($unit_options as $unit) echo "<option value='$unit'>$unit</option>"; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Reorder Level</label>
                        <input type="number" step="0.01" name="reorder_level" id="edit_ingredient_reorder" required class="w-full p-2.5 border border-gray-300 rounded-lg">
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" onclick="closeModal('editIngredientModal')" class="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Save</button>
                </div>
            </form>
        </div>
    </div>

    <div id="editProductModal" class="fixed inset-0 z-50 hidden flex items-center justify-center">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl m-4 overflow-hidden relative z-50 modal-animate-in">
             <div class="p-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
                <h5 class="font-bold text-gray-800">Edit Product</h5>
                <button onclick="closeModal('editProductModal')" class="text-gray-400 hover:text-gray-600"><i class='bx bx-x text-2xl'></i></button>
            </div>
            <form action="inventory_management.php" method="POST" enctype="multipart/form-data" class="p-6">
                <input type="hidden" name="action" value="edit_product">
                <input type="hidden" name="product_id" id="edit_product_id">
                <input type="hidden" name="active_tab" id="edit_product_active_tab" value="products">
                <div class="space-y-3">
                    <input type="text" name="name" id="edit_product_name" class="w-full p-2 border rounded text-sm" placeholder="Name">
                    <input type="number" step="0.01" name="price" id="edit_product_price" class="w-full p-2 border rounded text-sm" placeholder="Price">
                    <select name="status" id="edit_product_status" class="w-full p-2 border rounded text-sm">
                        <option value="available">Available</option>
                        <option value="discontinued">Discontinued</option>
                    </select>
                    <input type="file" name="edit_product_image" class="w-full text-sm">
                    <input type="hidden" name="current_image_path" id="edit_product_current_image">
                </div>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="submit" class="px-4 py-2 bg-breadly-btn text-white rounded text-sm">Save</button>
                </div>
            </form>
        </div>
    </div>

    <div id="restockIngredientModal" class="fixed inset-0 z-50 hidden flex items-center justify-center">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl m-4 overflow-hidden relative z-50 modal-animate-in">
            <div class="p-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
                <h5 class="font-bold text-gray-800">Restock Ingredient</h5>
                <button onclick="closeModal('restockIngredientModal')" class="text-gray-400 hover:text-gray-600"><i class='bx bx-x text-2xl'></i></button>
            </div>
            <form action="inventory_management.php" method="POST" class="p-6">
                <input type="hidden" name="action" value="restock_ingredient">
                <input type="hidden" name="ingredient_id" id="restock_ingredient_id">
                <input type="hidden" name="active_tab" value="ingredients"> 
                <p class="text-sm mb-3">Restocking: <strong id="restock_ingredient_name"></strong></p>
                <div class="space-y-3">
                    <input type="number" step="0.01" name="adjustment_qty" id="restock_ing_qty" placeholder="Qty Added" required class="w-full p-2 border rounded text-sm">
                    <div class="flex gap-2">
                        <input type="date" name="expiration_date" id="restock_ing_expiration" class="w-full p-2 border rounded text-sm">
                        <div class="flex items-center gap-1 whitespace-nowrap">
                            <input type="checkbox" id="restock_no_expiry"> <label for="restock_no_expiry" class="text-xs">No Expiry</label>
                        </div>
                    </div>
                    <input type="text" name="reason_note" id="restock_ing_reason_note" placeholder="Note (e.g. Invoice #)" class="w-full p-2 border rounded text-sm">
                </div>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded text-sm">Restock</button>
                </div>
            </form>
        </div>
    </div>

    <div id="deleteIngredientModal" class="fixed inset-0 z-50 hidden flex items-center justify-center">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm m-4 overflow-hidden relative z-50 modal-animate-in">
            <div class="p-4 border-b border-gray-100 bg-red-50">
                <h5 class="font-bold text-red-800">Delete Ingredient?</h5>
            </div>
            <form action="inventory_management.php" method="POST" class="p-6">
                <input type="hidden" name="action" value="delete_ingredient">
                <input type="hidden" name="active_tab" value="ingredients"> 
                <input type="hidden" name="ingredient_id" id="delete_ingredient_id">
                <p class="mb-4 text-sm">Are you sure you want to delete <strong id="delete_ingredient_name"></strong>?</p>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="closeModal('deleteIngredientModal')" class="px-4 py-2 text-gray-600 bg-gray-100 rounded text-sm">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded text-sm">Delete</button>
                </div>
            </form>
        </div>
    </div>

    <div id="deleteProductModal" class="fixed inset-0 z-50 hidden flex items-center justify-center">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm m-4 overflow-hidden relative z-50 modal-animate-in">
            <div class="p-4 border-b border-gray-100 bg-red-50">
                <h5 class="font-bold text-red-800">Delete Product?</h5>
            </div>
            <form action="inventory_management.php" method="POST" class="p-6">
                <input type="hidden" name="action" value="delete_product">
                <input type="hidden" name="product_id" id="delete_product_id">
                <input type="hidden" name="active_tab" id="delete_product_active_tab" value="products">
                <p class="mb-4 text-sm">Are you sure you want to delete <strong id="delete_product_name"></strong>?</p>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="closeModal('deleteProductModal')" class="px-4 py-2 text-gray-600 bg-gray-100 rounded text-sm">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded text-sm">Delete</button>
                </div>
            </form>
        </div>
    </div>

    <div id="batchesModal" class="fixed inset-0 z-50 hidden flex items-center justify-center">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[85vh] flex flex-col m-4 overflow-hidden relative z-50 modal-animate-in">
            <div class="p-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
                <h5 class="font-bold text-gray-800">Batch Details: <span id="batch_modal_title" class="text-breadly-btn"></span></h5>
                <button onclick="closeModal('batchesModal')" class="text-gray-400 hover:text-gray-600"><i class='bx bx-x text-2xl'></i></button>
            </div>
            <div class="flex-1 overflow-y-auto p-0">
                <table class="w-full text-center border-collapse">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500 font-semibold sticky top-0">
                        <tr>
                            <th class="px-4 py-3 border-b">Received</th>
                            <th class="px-4 py-3 border-b">Expiration</th>
                            <th class="px-4 py-3 border-b">Qty</th>
                            <th class="px-4 py-3 border-b">Status</th>
                            <th class="px-4 py-3 border-b">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="batches_table_body" class="divide-y divide-gray-100 text-sm"></tbody>
                </table>
            </div>
            <div class="p-3 bg-gray-50 text-center text-xs text-gray-500 border-t border-gray-100">
                Manage specific batch expirations and corrections here.
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <?php $js_version = file_exists("../js/script_inventory.js") ? filemtime("../js/script_inventory.js") : "1"; ?>
    <script src="../js/script_inventory.js?v=<?php echo $js_version; ?>"></script>

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

        function openModal(modalId, triggerElement = null) {
            const modal = document.getElementById(modalId);
            const backdrop = document.getElementById('modalBackdrop');
            if (modal) {
                modal.classList.remove('hidden');
                if(backdrop) backdrop.classList.remove('hidden');
                
                if (triggerElement) {
                    const event = new CustomEvent('open-modal', { detail: { relatedTarget: triggerElement } });
                    modal.dispatchEvent(event);
                }
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

        function switchTab(tabName) {
            document.querySelectorAll('[id^="pane-"]').forEach(el => el.classList.add('hidden'));
            document.getElementById('pane-' + tabName).classList.remove('hidden');
            document.querySelectorAll('[id^="tab-"]').forEach(btn => {
                btn.className = 'pb-3 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 border-transparent text-gray-500 hover:text-gray-700';
            });
            const activeBtn = document.getElementById('tab-' + tabName);
            if (tabName === 'recall') {
                activeBtn.className = 'pb-3 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 border-yellow-500 text-yellow-600';
            } else {
                activeBtn.className = 'pb-3 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 border-breadly-btn text-breadly-btn';
            }
        }
    </script>
</body>
</html>