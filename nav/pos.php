<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
if ($_SESSION['role'] !== 'manager' && $_SESSION['role'] !== 'cashier' && $_SESSION['role'] !== 'assistant_manager') {
    header('Location: ../index.php');
    exit();
}

require_once '../src/POSManager.php';
require_once '../src/BakeryManager.php';

$posFunctions = new PosFunctions();
$products = $posFunctions->getAvailableProducts();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $json_data = file_get_contents('php://input');
    // --- MODIFIED: Expect an object with 'cart' and 'discount' keys ---
    $data = json_decode($json_data, true);

    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['cart']) || !isset($data['discount'])) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid cart data received.']);
        exit();
    }
    
    $cart_items = $data['cart'];
    $discount_percent = $data['discount']; // Get the discount

    if (empty($cart_items)) {
         echo json_encode(['status' => 'error', 'message' => 'Cart is empty.']);
        exit();
    }
        
    $bakeryManager = new BakeryManager();
    $user_id = $_SESSION['user_id'];
    $all_successful = true;
    $error_message = 'An unknown error occurred.';

    foreach ($cart_items as $item) {
        $product_id = $item['id'];
        $quantity = $item['quantity'];

        // --- MODIFIED: Pass the discount to the backend function ---
        $status_message = $bakeryManager->recordSale($user_id, $product_id, $quantity, $discount_percent);
        
        if ($status_message !== 'Success: Sale recorded.') {
            $all_successful = false;
            $error_message = $status_message;
            break;
        }
    }

    if ($all_successful) {
        echo json_encode(['status' => 'success', 'message' => 'Sale recorded successfully!']);
    } else {
        echo json_encode(['status' => 'error', 'message' => $error_message]);
    }
    exit();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Point of Sale</title>
    <link rel="icon" href="../images/kzklogo.png" type="image/x-icon"> 
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    
    <link rel="stylesheet" href="../styles/global.css"> 
    <link rel="stylesheet" href="../styles/pos.css"> 
    <link rel="stylesheet" href="../styles/responsive.css"> 
    
</head>
<body class="pos-page"> 
<div class="pos-container">
    <div class="product-grid">
        
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
            
            <span class="fs-5">Products</span>

            <div class="input-group" style="max-width: 400px;">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" id="product-search" class="form-control" placeholder="Search...">
            </div>

            <div class="d-flex flex-wrap justify-content-end align-items-center gap-2">
                
                <div class="d-flex align-items-center gap-1">
                    <label for="search-type" class="form-label mb-0 small text-muted flex-shrink-0">Search:</label>
                    <select class="form-select form-select-sm" id="search-type" style="width: auto;">
                        <option value="name" selected>by Name</option>
                        <option value="code">by Code</option>
                    </select>
                </div>

                <div class="d-flex align-items-center gap-1">
                    <label for="sort-type" class="form-label mb-0 small text-muted flex-shrink-0">Sort By:</label>
                    <select class="form-select form-select-sm" id="sort-type" style="width: auto;">
                        <option value="name-asc" selected>Name (A-Z)</option>
                        <option value="name-desc">Name (Z-A)</option>
                        <option value="price-asc">Price (Low-High)</option>
                        <option value="price-desc">Price (High-Low)</option>
                        <option value="stock-desc">Stock (High-Low)</option>
                        <option value="stock-asc">Stock (Low-High)</option>
                        <option value="code-asc">Code (Asc)</option>
                        <option value="code-desc">Code (Desc)</option>
                    </select>
                </div>

            </div>
        </div>
        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-4 g-3" id="product-list">
            <?php foreach ($products as $product): ?>
            <div class="col" 
                 data-product-name="<?= htmlspecialchars(strtolower($product['name'])) ?>"
                 data-product-code="<?= htmlspecialchars($product['product_id']) ?>"
                 data-product-price="<?= htmlspecialchars($product['price']) ?>"
                 data-product-stock="<?= htmlspecialchars($product['stock_qty']) ?>"
                 data-product-image="<?= htmlspecialchars($product['image_url'] ?? '') ?>">
                
                <div class="product-card" 
                     data-id="<?= htmlspecialchars($product['product_id']) ?>"
                     data-name="<?= htmlspecialchars($product['name']) ?>"
                     data-price="<?= htmlspecialchars($product['price']) ?>"
                     data-stock="<?= htmlspecialchars($product['stock_qty']) ?>"
                     data-image="<?= htmlspecialchars($product['image_url'] ?? '') ?>">
                     
                    <?php 
                    // Use a placeholder if no image is set
                    $image_path = !empty($product['image_url']) ? htmlspecialchars($product['image_url']) : '../images/breadlylogo.png'; 
                    ?>
                    <img src="<?= $image_path ?>" alt="<?= htmlspecialchars($product['name']) ?>" class="product-image">
                    
                    <div class="product-details">
                        <small class="product-code"><?= htmlspecialchars($product['product_id']) ?></small>
                        <div class="product-name"><?= htmlspecialchars($product['name']) ?></div>
                        <div class="product-price">P<?= htmlspecialchars(number_format($product['price'], 2)) ?></div>
                        <small class="text-muted">Stock: <?= htmlspecialchars($product['stock_qty']) ?></small>
                    </div>
                </div>
                </div>
            <?php endforeach; ?>

            <?php if (empty($products)): ?>
                <p class="text-muted">No products are currently in stock or available.</p>
            <?php endif; ?>
            
            <div id="no-results-message" class="col-12 text-center text-muted" style="display: none;">
                <p>No products match your search.</p>
            </div>
        </div>
    </div>

    <div class="order-panel d-none d-md-flex">
        <div class="d-flex justify-content-between align-items-center mb-3">
             <h4>Current Order</h4>
             <div class="btn-group">
                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#discountModal">
                    <i class="bi bi-percent me-1"></i> Discount
                </button>
                <a href="../index.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Menu
                </a>
             </div>
        </div>
       
        <div id="order-items-container" class="order-items">
            <p class="text-center text-muted mt-5">Select products to begin</p>
        </div>
        
        <div class="order-total">
            <div id="order-summary-details">
                <div class="d-flex justify-content-between order-summary-line" id="subtotal-line" style="display: none;">
                    <span class="text-muted">Subtotal:</span>
                    <span id="subtotal-price" class="text-muted">P0.00</span>
                </div>
                <div class="d-flex justify-content-between order-summary-line" id="discount-line" style="display: none;">
                    <span class="text-discount">Discount (0%):</span>
                    <span id="discount-amount" class="text-discount">-P0.00</span>
                </div>
            </div>

            <div class="d-flex justify-content-between h5 mt-2">
                <span>Total:</span>
                <span id="total-price">P0.00</span>
            </div>
            <div class="d-grid gap-2 mt-3">
                <button id="pay-button" class="btn btn-success btn-lg" disabled>Complete Sale</button>
                <button id="clear-button" class="btn btn-outline-danger btn-sm">Clear Order</button>
            </div>
        </div>
    </div>
</div>

<div class="mobile-cart-summary d-md-none">
    <div class="cart-info">
        <small id="mobile-cart-count">0 Items</small>
        <strong id="mobile-cart-total">P0.00</strong>
    </div>
    <div class="btn-group">
        <a href="../index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i>
        </a>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#mobileCartModal">
            View Cart <i class="bi bi-arrow-right-short"></i>
        </button>
    </div>
</div>

<div class="modal fade" id="mobileCartModal" tabindex="-1" aria-labelledby="mobileCartModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="mobileCartModalLabel">Current Order</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="order-items-container-mobile" class="order-items">
            <p class="text-center text-muted mt-5">Select products to begin</p>
        </div>
        
        <div class="order-total">
            <div id="order-summary-details-mobile">
                <div class="d-flex justify-content-between order-summary-line" id="subtotal-line-mobile" style="display: none;">
                    <span class="text-muted">Subtotal:</span>
                    <span id="subtotal-price-mobile" class="text-muted">P0.00</span>
                </div>
                <div class="d-flex justify-content-between order-summary-line" id="discount-line-mobile" style="display: none;">
                    <span class="text-discount">Discount (0%):</span>
                    <span id="discount-amount-mobile" class="text-discount">-P0.00</span>
                </div>
            </div>

            <div class="d-flex justify-content-between h5 mt-2">
                <span>Total:</span>
                <span id="total-price-mobile">P0.00</span>
            </div>
            <div class="d-grid gap-2 mt-3">
                <button id="pay-button-mobile" class="btn btn-success btn-lg" disabled>Complete Sale</Fbutton>
                <button id="clear-button-mobile" class="btn btn-outline-danger btn-sm">Clear Order</button>
            </div>
        </div>
      </div>
      <div class="modal-footer justify-content-between">
          <button class="btn btn-sm btn-outline-secondary" type="button" id="mobile-discount-btn">
            <i class="bi bi-percent me-1"></i> Discount
          </button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Back to Products</button>
      </div>
      </div>
  </div>
</div>


<div class="modal fade" id="discountModal" tabindex="-1" aria-labelledby="discountModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="discountModalLabel">Apply Discount</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <label for="discount-input" class="form-label">Discount Percentage</label>
        <input type="number" id="discount-input" class="form-control" placeholder="e.g., 10" min="0" max="100">
      </div>
      <div class="modal-footer justify-content-between">
        <button type="button" class="btn btn-outline-danger" id="remove-discount-btn-modal">No Discount</button>
        <button type="button" class="btn btn-primary" id="apply-discount-btn-modal">Apply</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="../js/script_pos.js"></script>

<script src="../js/script_pos_mobile.js"></script>

</body>
</html>