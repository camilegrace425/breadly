<?php
session_start();
require_once '../src/BakeryManager.php';
require_once '../src/InventoryManager.php'; // We need this for the unit options

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
            
            $bakeryManager->addIngredientToRecipe($product_id, $ingredient_id, $qty_needed, $unit);
            $_SESSION['message'] = 'Ingredient added to recipe.';
            $_SESSION['message_type'] = 'success';
        
        } elseif ($action === 'delete_recipe_item') {
            $recipe_id = $_POST['recipe_id'];
            $bakeryManager->removeIngredientFromRecipe($recipe_id);
            $_SESSION['message'] = 'Ingredient removed from recipe.';
            $_SESSION['message_type'] = 'success';
        
        // --- ::: NEW CASE ADDED ::: ---
        } elseif ($action === 'update_batch_size') {
            $product_id = $_POST['product_id'];
            $batch_size = $_POST['batch_size'];
            $bakeryManager->updateProductBatchSize($product_id, $batch_size);
            $_SESSION['message'] = 'Batch size updated successfully.';
            $_SESSION['message_type'] = 'success';
        }
        // --- ::: END NEW CASE ::: ---

    } catch (PDOException $e) {
        $_SESSION['message'] = 'Error: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
    
    // Redirect back to this page, preserving the selected product
    $redirect_url = 'recipes.php';
    if ($product_id_redirect) {
        $redirect_url .= '?product_id=' . $product_id_redirect;
    }
    header('Location: ' . $redirect_url);
    exit();
}


// --- Data Fetching for Display ---
$selected_product_id = $_GET['product_id'] ?? null;
$current_recipe_items = [];
$current_batch_size = 0; // --- ::: ADDED ::: ---
$products = $bakeryManager->getAllProductsSimple(); // Get all products for dropdown
$all_ingredients = $bakeryManager->getAllIngredientsSimple(); // Get all ingredients for dropdown

if ($selected_product_id) {
    $current_recipe_items = $bakeryManager->getRecipeForProduct($selected_product_id);
    
    // --- ::: ADDED: Get batch size for selected product ::: ---
    foreach ($products as $product) {
        if ($product['product_id'] == $selected_product_id) {
            $current_batch_size = $product['batch_size'] ?? 0;
            break;
        }
    }
    // --- ::: END ::: ---
}

// Static unit options (same as inventory_management.php)
$unit_options = ['kg', 'g', 'L', 'ml', 'pcs', 'pack', 'tray', 'can', 'bottle'];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recipe Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../styles/global.css"> 
    <link rel="stylesheet" href="../styles/dashboard.css"> 
    <link rel="stylesheet" href="../styles/pos.css"> 
    
    <style>
        .product-grid {
            height: 80vh; /* Make grid scrollable */
            overflow-y: auto;
        }
        .product-card {
            text-decoration: none;
            color: var(--text-dark, #6a381f);
        }
        .product-card.active {
            border-width: 2px;
            border-color: var(--bs-primary);
            background-color: #f0f8ff;
            box-shadow: 0 4px 12px rgba(0, 123, 255, 0.15);
        }
        /* Override default <a> behavior */
        a.product-card:hover {
            color: var(--text-dark, #6a381f);
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
                    <a class="nav-link active" href="recipes.php">
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
                        <li><a class="dropdown-item" href="logout.php">Sign out</a></li>
                    </ul>
                </div>
            </div>
        </aside>

        <main class="col-lg-10 col-md-9 main-content">
            <div class="header">
                <h1>Recipe Management</h1>
            </div>

            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-lg-5">
                    <div class="product-grid">
                        <h4 class="mb-3">Select a Product</h4>
                        
                        <div class="input-group mb-3">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="text" id="recipe-product-search" class="form-control" placeholder="Search products...">
                        </div>
                        
                        <div class="row row-cols-2 row-cols-md-2 row-cols-lg-3 g-3" id="recipe-product-list">
                            <?php foreach ($products as $product): ?>
                                <?php $is_active = ($selected_product_id == $product['product_id']); ?>
                                <div class="col" data-product-name="<?= htmlspecialchars(strtolower($product['name'])) ?>">
                                    <a href="recipes.php?product_id=<?php echo $product['product_id']; ?>" 
                                       class="product-card <?php echo $is_active ? 'active' : ''; ?>">
                                         <div class="product-name"><?= htmlspecialchars($product['name']) ?></div>
                                         <small class="text-muted">Batch: <?= htmlspecialchars($product['batch_size'] ?? 'N/A') ?> pcs</small>
                                    </a>
                                </div>
                            <?php endforeach; ?>

                            <?php if (empty($products)): ?>
                                <p class="text-muted">No products found. Please add products in the Inventory tab.</p>
                            <?php endif; ?>
                            
                            <div id="recipe-no-results" class="col-12 text-center text-muted" style="display: none;">
                                <p>No products match your search.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-7">
                    <?php if ($selected_product_id): ?>
                    <div class="row">
                        <div class="col-12 mb-4">
                            <div class="card shadow-sm">
                                <div class="card-header">
                                    Batch Size
                                </div>
                                <div class="card-body">
                                    <form action="recipes.php?product_id=<?php echo $selected_product_id; ?>" method="POST" class="row g-3 align-items-end">
                                        <input type="hidden" name="action" value="update_batch_size">
                                        <input type="hidden" name="product_id" value="<?php echo $selected_product_id; ?>">
                                        <div class="col-md-6">
                                            <label for="batch_size" class="form-label">Quantity per Recipe</label>
                                            <input type="number" step="1" class="form-control" id="batch_size" name="batch_size" required min="1" value="<?php echo htmlspecialchars($current_batch_size); ?>">
                                            <div class="form-text">How many "pcs" does this recipe produce? This is used by "Record Baking".</div>
                                        </div>
                                        <div class="col-md-6">
                                            <button type="submit" class="btn btn-primary w-100">
                                                <i class="bi bi-save me-1"></i> Update Batch Size
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 mb-4">
                            <div class="card shadow-sm">
                                <div class="card-header">
                                    Current Recipe for: <strong><?php echo htmlspecialchars($products[array_search($selected_product_id, array_column($products, 'product_id'))]['name']); ?></strong>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover align-middle">
                                            <thead>
                                                <tr>
                                                    <th>Ingredient</th>
                                                    <th>Quantity Needed</th>
                                                    <th>Unit</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($current_recipe_items)): ?>
                                                    <tr>
                                                        <td colspan="4" class="text-center text-muted">No recipe found for this product. Add ingredients using the form.</td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach ($current_recipe_items as $item): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($item['name']); ?></td>
                                                        <td><?php echo htmlspecialchars($item['qty_needed']); ?></td>
                                                        <td><?php echo htmlspecialchars($item['unit']); ?></td>
                                                        <td>
                                                            <button class="btn btn-outline-danger btn-sm"
                                                                    data-bs-toggle="modal" 
                                                                    data-bs-target="#deleteRecipeItemModal"
                                                                    data-recipe-id="<?php echo $item['recipe_id']; ?>"
                                                                    data-ingredient-name="<?php echo htmlspecialchars($item['name']); ?>">
                                                                <i class="bi bi-trash"></i> Remove
                                                            </button>
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

                        <div class="col-12">
                            <div class="card shadow-sm">
                                <div class="card-header">
                                    Add Ingredient to Recipe
                                </div>
                                <div class="card-body">
                                    <form action="recipes.php?product_id=<?php echo $selected_product_id; ?>" method="POST">
                                        <input type="hidden" name="action" value="add_recipe_item">
                                        <input type="hidden" name="product_id" value="<?php echo $selected_product_id; ?>">
                                        
                                        <div class="mb-3">
                                            <label for="ingredient_id" class="form-label">Ingredient</label>
                                            <select class="form-select" name="ingredient_id" id="ingredient_id" required>
                                                <option value="" disabled selected>-- Select an Ingredient --</option>
                                                <?php foreach ($all_ingredients as $ing): ?>
                                                    <option value="<?php echo $ing['ingredient_id']; ?>">
                                                        <?php echo htmlspecialchars($ing['name']) . ' (' . htmlspecialchars($ing['unit']) . ')'; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="qty_needed" class="form-label">Quantity Needed</label>
                                            <input type="number" step="0.01" class="form-control" id="qty_needed" name="qty_needed" required min="0.01">
                                        </div>

                                        <div class="mb-3">
                                            <label for="unit" class="form-label">Unit for Recipe</label>
                                            <select class="form-select" id="unit" name="unit" required>
                                                <option value="" selected disabled>Select a unit...</option>
                                                <?php foreach ($unit_options as $unit): ?>
                                                    <option value="<?php echo htmlspecialchars($unit); ?>"><?php echo htmlspecialchars($unit); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="form-text">This is the unit the system will use for deduction (e.g., 100 g).</div>
                                        </div>

                                        <div class="d-grid">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="bi bi-plus-circle me-1"></i> Add to Recipe
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                        <div class="card shadow-sm border-primary">
                            <div class="card-body text-center text-primary p-5">
                                <i class="bi bi-arrow-left-circle-dotted" style="font-size: 4rem;"></i>
                                <h4 class="mt-3">Select a Product</h4>
                                <p class="text-muted">Please select a product from the list on the left to view or edit its recipe.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>

<div class="modal fade" id="deleteRecipeItemModal" tabindex="-1" aria-labelledby="deleteRecipeItemModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="deleteRecipeItemModalLabel">Remove Ingredient?</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form action="recipes.php?product_id=<?php echo $selected_product_id; ?>" method="POST">
        <div class="modal-body">
            <input type="hidden" name="action" value="delete_recipe_item">
            <input type="hidden" name="recipe_id" id="delete_recipe_id">
            <input type="hidden" name="product_id" value="<?php echo $selected_product_id; ?>">
            <p>Are you sure you want to remove <strong id="delete_ingredient_name"></strong> from this recipe?</p>
            <p class="text-danger small">This action cannot be undone.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger">Yes, Remove</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/script_recipes.js"></script>
</body>
</html>