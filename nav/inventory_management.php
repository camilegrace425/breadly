<?php
session_start();
require_once '../src/InventoryManager.php';
require_once '../src/BakeryManager.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
if ($_SESSION['role'] !== 'manager') {
    header('Location: index.php');
    exit();
}

$inventoryManager = new InventoryManager();
$bakeryManager = new BakeryManager();

$message = '';
$message_type = '';
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

$active_tab = 'products';
if (isset($_SESSION['active_tab'])) {
    $active_tab = $_SESSION['active_tab'];
    unset($_SESSION['active_tab']);
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $form_active_tab = $_POST['active_tab'] ?? 'products';

    try {
        $success_message = '';
        $error_message = '';

        switch ($action) {
             case 'add_ingredient':
                $bakeryManager->addIngredient($_POST['name'], $_POST['unit'], $_POST['stock_qty'], $_POST['reorder_level']);
                $success_message = 'Successfully added new ingredient!';
                break;

            case 'restock_ingredient':
                $bakeryManager->restockIngredient($_POST['ingredient_id'], $_POST['added_qty']);
                $success_message = 'Successfully restocked ingredient!';
                break;

            case 'add_product':
                $bakeryManager->addProduct($_POST['name'], $_POST['price']);
                $success_message = 'Successfully added new product!';
                break;

                case 'adjust_product':
                    // Use the simplified boolean return value
                    $result = $bakeryManager->adjustProductStock($_POST['product_id'], $_POST['adjustment_qty'], $_POST['reason']);
                    if ($result) {
                        $success_message = 'Successfully adjusted product stock!';
                    } else {
                        $error_message = 'Failed to execute stock adjustment. Check logs or database connection.';
                    }
                    break;

            case 'edit_ingredient':
                $bakeryManager->ingredientUpdate($_POST['ingredient_id'], $_POST['name'], $_POST['unit'], $_POST['reorder_level']);
                $success_message = 'Successfully updated ingredient!';
                break;

            case 'edit_product':
                $bakeryManager->productUpdate($_POST['product_id'], $_POST['name'], $_POST['price'], $_POST['status']);
                $success_message = 'Successfully updated product!';
                break;

            case 'delete_ingredient':
                $status = $bakeryManager->ingredientDelete($_POST['ingredient_id']);
                if (strpos($status, 'Success') !== false) {
                    $success_message = $status;
                } else {
                    $error_message = $status;
                }
                break;

            case 'delete_product':
                $status = $bakeryManager->productDelete($_POST['product_id']);
                 if (strpos($status, 'Success') !== false) {
                    $success_message = $status;
                } else {
                    $error_message = $status;
                }
                break;
        }

        if ($error_message) {
            $_SESSION['message'] = $error_message;
            $_SESSION['message_type'] = 'danger';
        } else {
            $_SESSION['message'] = $success_message;
            $_SESSION['message_type'] = 'success';
        }
        $_SESSION['active_tab'] = $form_active_tab;

        header('Location: inventory_management.php');
        exit();

    } catch (PDOException $e) {
        $message = 'An error occurred: ' . $e->getMessage();
        $message_type = 'danger';
        $active_tab = $form_active_tab;
    }
}


$products = $inventoryManager->getProductsInventory();
$ingredients = $inventoryManager->getIngredientsInventory();
$discontinued_products = $inventoryManager->getDiscontinuedProducts();

$unit_options = ['kg', 'g', 'L', 'ml', 'pcs', 'pack', 'tray', 'can', 'bottle'];
$product_status_options = ['available', 'recalled', 'discontinued'];
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
                    <button class="nav-link <?php echo ($active_tab === 'discontinued') ? 'active' : ''; ?>" id="discontinued-tab" data-bs-toggle="tab" data-bs-target="#discontinued-pane" type="button" role="tab">
                        <i class="bi bi-slash-circle me-1"></i> Discontinued Products
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="inventoryTabContent">

                <div class="tab-pane fade <?php echo ($active_tab === 'products') ? 'show active' : ''; ?>" id="products-pane" role="tabpanel">
                    <div class="card shadow-sm mt-3">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span>Active & Recalled Products</span>
                            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addProductModal">
                                <i class="bi bi-plus-circle me-1"></i> Add New Product
                            </button>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover align-middle">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Price</th>
                                            <th>Status</th>
                                            <th>Current Stock</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($products as $product): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($product['name']); ?></td>
                                            <td>P<?php echo number_format($product['price'], 2); ?></td>
                                            <td>
                                                <?php
                                                    $status_color = 'success'; // available
                                                    if ($product['status'] == 'recalled') $status_color = 'warning';
                                                ?>
                                                <span class="badge bg-<?php echo $status_color; ?>"><?php echo htmlspecialchars($product['status']); ?></span>
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
                                            <tr><td colspan="5" class="text-center text-muted">No active or recalled products found.</td></tr>
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
                            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addIngredientModal">
                                <i class="bi bi-plus-circle me-1"></i> Add New Ingredient
                            </button>
                        </div>
                        <div class="card-body">
                             <div class="table-responsive">
                                <table class="table table-striped table-hover align-middle">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Unit</th>
                                            <th>Current Stock</th>
                                            <th>Reorder Level</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($ingredients as $ing): ?>
                                        <tr class="<?php echo ($ing['stock_surplus'] <= 0) ? 'table-danger' : ''; ?>">
                                            <td><?php echo htmlspecialchars($ing['name']); ?></td>
                                            <td><?php echo htmlspecialchars($ing['unit']); ?></td>
                                            <td><strong><?php echo $ing['stock_qty']; ?></strong></td>
                                            <td><?php echo $ing['reorder_level']; ?></td>
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

                <div class="tab-pane fade <?php echo ($active_tab === 'discontinued') ? 'show active' : ''; ?>" id="discontinued-pane" role="tabpanel">
                    <div class="card shadow-sm mt-3">
                        <div class="card-header">
                            <span>Discontinued Products (Archived)</span>
                        </div>
                        <div classV="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover align-middle">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Price</th>
                                            <th>Status</th>
                                            <th>Last Stock</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($discontinued_products as $product): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($product['name']); ?></td>
                                            <td>P<?php echo number_format($product['price'], 2); ?></td>
                                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($product['status']); ?></span></td>
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

            </div>
        </main>
    </div>
</div>

<div class="modal fade" id="addIngredientModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Add New Ingredient</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form action="inventory_management.php" method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_ingredient">
                <input type="hidden" name="active_tab" value="ingredients">
                <div class="mb-3">
                    <label for="name" class="form-label">Ingredient Name</label>
                    <input type="text" class="form-control" name="name" required>
                </div>
                <div class="mb-3">
                    <label for="unit" class="form-label">Unit</label>
                    <select class="form-select" name="unit" required>
                        <option value="" selected disabled>Select a unit...</option>
                        <?php foreach ($unit_options as $unit): ?>
                            <option value="<?php echo $unit; ?>"><?php echo $unit; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="stock_qty" class="form-label">Initial Stock Quantity</label>
                    <input type="number" step="0.01" class="form-control" name="stock_qty" value="0" required>
                </div>
                <div class="mb-3">
                    <label for="reorder_level" class="form-label">Reorder Level</label>
                    <input type="number" step="0.01" class="form-control" name="reorder_level" value="0" required>
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
<div class="modal fade" id="restockModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="restockModalTitle">Restock Ingredient</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form action="inventory_management.php" method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="restock_ingredient">
                <input type="hidden" name="active_tab" value="ingredients">
                <input type="hidden" name="ingredient_id" id="restock_ingredient_id">
                <p>Restocking: <strong id="restock_ingredient_name"></strong></p>
                <div class="mb-3">
                    <label for="added_qty" class="form-label">Quantity to Add</label>
                    <div class="input-group">
                        <input type="number" step="0.01" class="form-control" name="added_qty" required>
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
<div class="modal fade" id="addProductModal" tabindex="-1">
     <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Add New Product</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form action="inventory_management.php" method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_product">
                <input type="hidden" name="active_tab" value="products">
                <div class="mb-3">
                    <label for="name" class="form-label">Product Name</label>
                    <input type="text" class="form-control" name="name" required>
                </div>
                <div class="mb-3">
                    <label for="price" class="form-label">Price (PHP)</label>
                    <input type="number" step="0.01" class="form-control" name="price" required>
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
<div class="modal fade" id="adjustProductModal" tabindex="-1">
     <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="adjustProductModalTitle">Adjust Product Stock</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form action="inventory_management.php" method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="adjust_product">
                <input type="hidden" name="active_tab" value="products">
                <input type="hidden" name="product_id" id="adjust_product_id">
                <p>Adjusting: <strong id="adjust_product_name"></strong></p>
                <div class="mb-3">
                    <label for="adjustment_qty" class="form-label">Adjustment Quantity</label>
                    <input type="number" class="form-control" name="adjustment_qty" required>
                    <div class="form-text">Use a negative number to remove stock (e.g., -5 for spoilage) or a positive number to add stock.</div>
                </div>
                <div class="mb-3">
                    <label for="reason" class="form-label">Reason for Adjustment</label>
                    <input type="text" class="form-control" name="reason" placeholder="e.g., Spoilage, Manual count correction" required>
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
<div class="modal fade" id="editIngredientModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="editIngredientModalTitle">Edit Ingredient</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
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
                            <option value="<?php echo $unit; ?>"><?php echo $unit; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="edit_ingredient_reorder" class="form-label">Reorder Level</label>
                    <input type="number" step="0.01" class="form-control" name="reorder_level" id="edit_ingredient_reorder" required>
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
<div class="modal fade" id="editProductModal" tabindex="-1">
     <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="editProductModalTitle">Edit Product</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form action="inventory_management.php" method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="edit_product">
                <input type="hidden" name="active_tab" id="edit_product_active_tab" value="products">
                <input type="hidden" name="product_id" id="edit_product_id">
                <div class="mb-3">
                    <label for="edit_product_name" class="form-label">Product Name</label>
                    <input type="text" class="form-control" name="name" id="edit_product_name" required>
                </div>
                <div class="mb-3">
                    <label for="edit_product_price" class="form-label">Price (PHP)</label>
                    <input type="number" step="0.01" class="form-control" name="price" id="edit_product_price" required>
                </div>
                <div class="mb-3">
                    <label for="edit_product_status" class="form-label">Status</label>
                    <select class="form-select" name="status" id="edit_product_status" required>
                        <option value="" disabled>Select status...</option>
                        <?php foreach ($product_status_options as $status): ?>
                            <option value="<?php echo $status; ?>"><?php echo ucfirst($status); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Setting to "Discontinued" will move it to the Discontinued tab.</div>
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
<div class="modal fade" id="deleteIngredientModal" tabindex="-1">
     <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="deleteIngredientModalTitle">Delete Ingredient?</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form action="inventory_management.php" method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="delete_ingredient">
                <input type="hidden" name="active_tab" value="ingredients">
                <input type="hidden" name="ingredient_id" id="delete_ingredient_id">
                <p>Are you sure you want to permanently delete <strong id="delete_ingredient_name"></strong>?</p>
                <p class="text-danger">This action cannot be undone and will fail if the ingredient is used in any recipes.</p>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-danger">Yes, Delete</button>
            </div>
          </form>
        </div>
      </div>
</div>
<div class="modal fade" id="deleteProductModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="deleteProductModalTitle">Delete Product?</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form action="inventory_management.php" method="POST">
        <div class="modal-body">
            <input type="hidden" name="action" value="delete_product">
            <input type="hidden" name="active_tab" id="delete_product_active_tab" value="products">
            <input type="hidden" name="product_id" id="delete_product_id">
            <p>Are you sure you want to permanently delete <strong id="delete_product_name"></strong>?</p>
            <p class="text-danger">This action cannot be undone and will fail if the product has any sales or production history. Consider marking it as "Discontinued" instead.</p>
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