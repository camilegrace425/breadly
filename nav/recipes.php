<?php
session_start();
require_once '../src/BakeryManager.php';
require_once '../src/InventoryManager.php'; 

// --- Security Checks ---
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
if ($_SESSION['role'] !== 'manager' && $_SESSION['role'] !== 'assistant_manager') {
    header('Location: ../index.php');
    exit();
}

// --- Initialization ---
$bakeryManager = new BakeryManager();
$current_role = $_SESSION["role"];

// --- Message Handling ---
$message = '';
$message_type = '';
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// --- POST Request Handling ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $product_id_redirect = $_POST['product_id'] ?? $_GET['product_id'] ?? null;

    try {
        if ($action === 'add_recipe_item') {
            $product_id = $_POST['product_id'];
            $ingredient_id = $_POST['ingredient_id'];
            $qty_needed = $_POST['qty_needed'];
            $unit = $_POST['unit'];
            
            $result = $bakeryManager->addIngredientToRecipe($product_id, $ingredient_id, $qty_needed, $unit);
            
            if ($result === 'success') {
                $_SESSION['message'] = 'Ingredient added to recipe.';
                $_SESSION['message_type'] = 'success';
            } else {
                $_SESSION['message'] = 'Warning: This ingredient is already in the recipe.';
                $_SESSION['message_type'] = 'warning'; 
            }
        
        } elseif ($action === 'delete_recipe_item') {
            $recipe_id = $_POST['recipe_id'];
            $bakeryManager->removeIngredientFromRecipe($recipe_id);
            $_SESSION['message'] = 'Ingredient removed from recipe.';
            $_SESSION['message_type'] = 'success';
        
        } elseif ($action === 'update_batch_size') {
            $product_id = $_POST['product_id'];
            $batch_size = $_POST['batch_size'];
            $bakeryManager->updateProductBatchSize($product_id, $batch_size);
            $_SESSION['message'] = 'Batch size updated successfully.';
            $_SESSION['message_type'] = 'success';
        }

    } catch (PDOException $e) {
        $_SESSION['message'] = 'Error: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
    
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'message' => $_SESSION['message']]);
        unset($_SESSION['message']);
        unset($_SESSION['message_type']);
        exit();
    }
    
    $redirect_url = 'recipes.php';
    if ($product_id_redirect) {
        $redirect_url .= '?product_id=' . $product_id_redirect;
    }
    header('Location: ' . $redirect_url);
    exit();
}


// --- Data Fetching ---
$selected_product_id = $_GET['product_id'] ?? null;
$current_recipe_items = [];
$current_batch_size = 0;
$products = $bakeryManager->getAllProductsSimple(); 
$all_ingredients = $bakeryManager->getAllIngredientsSimple(); 
$selected_product_name = 'Recipe'; 

if ($selected_product_id) {
    $current_recipe_items = $bakeryManager->getRecipeForProduct($selected_product_id);
    
    foreach ($products as $product) {
        if ($product['product_id'] == $selected_product_id) {
            $current_batch_size = $product['batch_size'] ?? 0;
            $selected_product_name = $product['name']; 
            break;
        }
    }
}

$unit_options = ['kg', 'g', 'L', 'ml', 'pcs', 'pack', 'tray', 'can', 'bottle'];
$active_nav_link = 'recipes';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recipe Management</title>
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
            <a href="dashboard_panel.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors text-breadly-secondary hover:bg-orange-100 hover:text-breadly-dark">
                <i class='bx bxs-dashboard text-xl'></i><span class="font-medium">Dashboard</span>
            </a>
            <a href="inventory_management.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors text-breadly-secondary hover:bg-orange-100 hover:text-breadly-dark">
                <i class='bx bxs-box text-xl'></i><span class="font-medium">Inventory</span>
            </a>
            <a href="recipes.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors bg-breadly-dark text-white shadow-md">
                <i class='bx bxs-book-bookmark text-xl'></i><span class="font-medium">Recipes</span>
            </a>
            <a href="sales_history.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors text-breadly-secondary hover:bg-orange-100 hover:text-breadly-dark">
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
            <a href="dashboard_panel.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-breadly-secondary">
                <i class='bx bxs-dashboard text-xl'></i> Dashboard
            </a>
            <a href="inventory_management.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-breadly-secondary">
                <i class='bx bxs-box text-xl'></i> Inventory
            </a>
            <a href="recipes.php" class="flex items-center gap-3 px-4 py-3 rounded-xl bg-breadly-dark text-white">
                <i class='bx bxs-book-bookmark text-xl'></i> Recipes
            </a>
            <a href="sales_history.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-breadly-secondary">
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
                <h1 class="text-2xl font-bold text-breadly-dark">Recipe Management</h1>
            </div>
        </div>

        <?php if ($message): ?>
        <div class="px-6">
            <div class="<?php echo ($message_type === 'success') ? 'bg-green-100 text-green-800 border-green-200' : (($message_type === 'warning') ? 'bg-yellow-100 text-yellow-800 border-yellow-200' : 'bg-red-100 text-red-800 border-red-200'); ?> border px-4 py-3 rounded-lg flex justify-between items-center mb-4 shadow-sm">
                <span><?php echo htmlspecialchars($message); ?></span>
                <button onclick="this.parentElement.remove()" class="text-lg font-bold">&times;</button>
            </div>
        </div>
        <?php endif; ?>

        <div class="flex-1 overflow-y-auto p-6 pb-20">
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 h-full">
                
                <div class="lg:col-span-4 xl:col-span-3 flex flex-col h-full">
                    <div class="bg-white rounded-xl shadow-sm border border-orange-100 flex flex-col h-full overflow-hidden">
                        <div class="p-4 border-b border-orange-100">
                            <h4 class="font-bold text-gray-800 mb-3">Select a Product</h4>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><i class='bx bx-search text-gray-400'></i></span>
                                <input type="text" id="recipe-product-search" class="w-full pl-10 pr-4 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-breadly-btn outline-none" placeholder="Search products...">
                            </div>
                        </div>
                        <div class="flex-1 overflow-y-auto p-4" id="recipe-product-list">
                            <div class="grid grid-cols-2 gap-3">
                                <?php foreach ($products as $product): ?>
                                    <?php $is_active = ($selected_product_id == $product['product_id']); ?>
                                    <div class="col-product" data-product-name="<?= htmlspecialchars(strtolower($product['name'])) ?>">
                                        <a href="recipes.php?product_id=<?php echo $product['product_id']; ?>" 
                                           class="block p-3 rounded-lg border transition-all duration-200 hover:shadow-md flex flex-col h-full <?php echo $is_active ? 'border-breadly-btn bg-orange-50 ring-1 ring-breadly-btn' : 'border-gray-100 bg-white hover:border-orange-200'; ?>"
                                           data-product-name="<?php echo htmlspecialchars($product['name']); ?>">
                                             <div class="font-semibold text-sm text-gray-800 mb-1 line-clamp-2"><?= htmlspecialchars($product['name']) ?></div>
                                             <div class="mt-auto text-xs text-gray-500 bg-white/50 rounded px-1 py-0.5 w-fit border border-gray-100">Batch: <?= htmlspecialchars($product['batch_size'] ?? '0') ?> pcs</div>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <?php if (empty($products)): ?>
                                <div class="text-center py-10 text-gray-400">
                                    <i class='bx bxs-package text-4xl mb-2'></i>
                                    <p class="text-sm">No products found.</p>
                                </div>
                            <?php endif; ?>
                            
                            <div id="recipe-no-results" class="hidden text-center py-8 text-gray-400">
                                <p class="text-sm">No products match your search.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-8 xl:col-span-9 h-full overflow-y-auto recipe-content-col">
                    <?php if ($selected_product_id): ?>
                        
                        <div class="bg-white rounded-xl shadow-sm border border-orange-100 p-6 mb-6">
                            <h5 class="font-bold text-gray-800 border-b border-gray-100 pb-2 mb-4">Batch Size Configuration</h5>
                            <form action="recipes.php?product_id=<?php echo $selected_product_id; ?>" method="POST" class="flex flex-col md:flex-row gap-4 items-end">
                                <input type="hidden" name="action" value="update_batch_size">
                                <input type="hidden" name="product_id" value="<?php echo $selected_product_id; ?>">
                                
                                <div class="flex-1 w-full">
                                    <label for="batch_size" class="block text-sm font-medium text-gray-700 mb-1">Quantity per Recipe (pcs)</label>
                                    <input type="number" step="1" class="w-full p-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-breadly-btn outline-none" id="batch_size" name="batch_size" required min="1" value="<?php echo htmlspecialchars($current_batch_size); ?>">
                                    <p class="text-xs text-gray-500 mt-1">Used to calculate ingredient usage during production.</p>
                                </div>
                                <button type="submit" class="w-full md:w-auto px-6 py-2.5 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition-colors shadow-sm">
                                    Update
                                </button>
                            </form>
                        </div>

                        <div class="bg-white rounded-xl shadow-sm border border-orange-100 overflow-hidden mb-6">
                            <div class="p-4 border-b border-orange-100 bg-gray-50 flex justify-between items-center">
                                <h5 class="font-bold text-gray-800">Recipe for: <span class="text-breadly-btn"><?php echo htmlspecialchars($selected_product_name); ?></span></h5>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left border-collapse">
                                    <thead class="bg-gray-100 text-xs uppercase text-gray-500 font-semibold">
                                        <tr>
                                            <th class="px-6 py-3">Ingredient</th>
                                            <th class="px-6 py-3">Quantity Needed</th>
                                            <th class="px-6 py-3">Unit</th>
                                            <th class="px-6 py-3 text-right">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <?php if (empty($current_recipe_items)): ?>
                                            <tr>
                                                <td colspan="4" class="px-6 py-8 text-center text-gray-400">
                                                    <i class='bx bxs-book-content text-4xl mb-2'></i>
                                                    <p>No ingredients in this recipe yet.</p>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($current_recipe_items as $item): ?>
                                            <tr class="hover:bg-gray-50 transition-colors">
                                                <td class="px-6 py-3 font-medium text-gray-800"><?php echo htmlspecialchars($item['name']); ?></td>
                                                <td class="px-6 py-3 font-bold text-blue-600"><?php echo htmlspecialchars($item['qty_needed']); ?></td>
                                                <td class="px-6 py-3 text-gray-600"><?php echo htmlspecialchars($item['unit']); ?></td>
                                                <td class="px-6 py-3 text-right">
                                                    <button class="px-3 py-1.5 bg-red-50 text-red-600 rounded-lg hover:bg-red-100 transition-colors text-xs font-medium flex items-center gap-1 ml-auto"
                                                            onclick="openDeleteModal('<?php echo $item['recipe_id']; ?>', '<?php echo htmlspecialchars($item['name']); ?>')">
                                                        <i class='bx bx-trash'></i> Remove
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="bg-white rounded-xl shadow-sm border border-orange-100 p-6">
                            <h5 class="font-bold text-gray-800 border-b border-gray-100 pb-2 mb-4">Add Ingredient to Recipe</h5>
                            <form action="recipes.php?product_id=<?php echo $selected_product_id; ?>" method="POST">
                                <input type="hidden" name="action" value="add_recipe_item">
                                <input type="hidden" name="product_id" value="<?php echo $selected_product_id; ?>">
                                
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Ingredient</label>
                                        <select name="ingredient_id" required class="w-full p-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-breadly-btn outline-none">
                                            <option value="" disabled selected>-- Select --</option>
                                            <?php foreach ($all_ingredients as $ing): ?>
                                                <option value="<?php echo $ing['ingredient_id']; ?>">
                                                    <?php echo htmlspecialchars($ing['name']) . ' (' . htmlspecialchars($ing['unit']) . ')'; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Quantity Needed</label>
                                        <input type="number" step="0.01" name="qty_needed" required min="0.01" class="w-full p-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-breadly-btn outline-none">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Unit</label>
                                        <select name="unit" required class="w-full p-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-breadly-btn outline-none">
                                            <option value="" selected disabled>-- Select --</option>
                                            <?php foreach ($unit_options as $unit): ?>
                                                <option value="<?php echo htmlspecialchars($unit); ?>"><?php echo htmlspecialchars($unit); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="flex justify-end">
                                    <button type="submit" class="px-6 py-2.5 bg-breadly-btn text-white font-medium rounded-lg hover:bg-breadly-btn-hover transition-colors shadow-sm flex items-center gap-2">
                                        <i class='bx bx-plus-circle'></i> Add to Recipe
                                    </button>
                                </div>
                            </form>
                        </div>

                    <?php else: ?>
                        <div class="h-full flex flex-col items-center justify-center text-gray-400 border-2 border-dashed border-gray-200 rounded-xl p-10 bg-gray-50/50">
                            <i class='bx bx-left-arrow-circle text-6xl mb-4 opacity-50'></i>
                            <h4 class="text-xl font-bold text-gray-600">Select a Product</h4>
                            <p class="text-sm mt-2">Choose a product from the list on the left to manage its recipe.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <div id="modalBackdrop" class="fixed inset-0 bg-black/50 z-40 hidden transition-opacity" onclick="closeAllModals()"></div>

    <div id="deleteRecipeItemModal" class="fixed inset-0 z-50 hidden flex items-center justify-center">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm m-4 overflow-hidden relative z-50 transform transition-all scale-100">
            <div class="p-4 border-b border-gray-100 bg-red-50">
                <h5 class="font-bold text-red-800">Remove Ingredient?</h5>
            </div>
            <form action="recipes.php?product_id=<?php echo $selected_product_id; ?>" method="POST" class="p-6">
                <input type="hidden" name="action" value="delete_recipe_item">
                <input type="hidden" name="recipe_id" id="delete_recipe_id">
                <input type="hidden" name="product_id" value="<?php echo $selected_product_id; ?>">
                
                <p class="text-sm text-gray-600 mb-4">Are you sure you want to remove <strong id="delete_ingredient_name" class="text-gray-900"></strong> from this recipe?</p>
                
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="closeModal('deleteRecipeItemModal')" class="px-4 py-2 text-gray-600 bg-gray-100 rounded text-sm font-medium hover:bg-gray-200">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded text-sm font-medium hover:bg-red-700">Yes, Remove</button>
                </div>
            </form>
        </div>
    </div>

    <div id="recipeModal" class="fixed inset-0 z-50 hidden flex items-center justify-center">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] m-4 overflow-hidden flex flex-col relative z-50">
            <div class="p-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
                <h5 class="font-bold text-gray-800" id="recipeModalLabel">Loading...</h5>
                <button onclick="closeModal('recipeModal')" class="text-gray-400 hover:text-gray-600"><i class='bx bx-x text-2xl'></i></button>
            </div>
            <div class="flex-1 overflow-y-auto p-4" id="recipeModalBody">
                <div class="flex justify-center p-10">
                    <div class="animate-spin w-8 h-8 border-4 border-breadly-btn border-t-transparent rounded-full"></div>
                </div>
            </div>
            <div class="p-4 border-t border-gray-100 bg-gray-50 flex justify-end">
                <button onclick="closeModal('recipeModal')" class="px-4 py-2 bg-white border text-gray-600 rounded-lg text-sm font-medium hover:bg-gray-100">Close</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <?php 
        $js_file = "../js/script_recipes.js";
        $js_version = file_exists($js_file) ? filemtime($js_file) : "1";
    ?>
    <script src="../js/script_recipes.js?v=<?php echo $js_version; ?>"></script>

    <script>
        // Global Modal Logic
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

        // Special helper for delete button
        function openDeleteModal(recipeId, ingredientName) {
            const modal = document.getElementById('deleteRecipeItemModal');
            const nameSpan = document.getElementById('delete_ingredient_name');
            const idInput = document.getElementById('delete_recipe_id');
            
            if(nameSpan) nameSpan.textContent = ingredientName;
            if(idInput) idInput.value = recipeId;
            
            openModal('deleteRecipeItemModal');
        }
    </script>
</body>
</html>