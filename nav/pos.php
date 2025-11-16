<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
if ($_SESSION['role'] !== 'manager' && $_SESSION['role'] !== 'cashier') {
    header('Location: index.php');
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
        <h4 class="mb-3">Products</h4>
        
        <div class="d-flex justify-content-between mb-3 gap-2">
            <div class="input-group flex-grow-1">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" id="product-search" class="form-control" placeholder="Search...">
            </div>
            
            <select class="form-select" id="search-type" style="width: auto; flex-grow: 0;">
                <option value="name" selected>by Name</option>
                <option value="code">by Code</option>
            </select>
            
            <select class="form-select" id="sort-type" style="width: auto; flex-grow: 0;">
                <option value="name-asc" selected>Sort: Name (A-Z)</option>
                <option value="name-desc">Sort: Name (Z-A)</option>
                <option value="price-asc">Sort: Price (Low-High)</option>
                <option value="price-desc">Sort: Price (High-Low)</option>
                <option value="stock-desc">Sort: Stock (High-Low)</option>
                <option value="stock-asc">Sort: Stock (Low-High)</option>
                <option value="code-asc">Sort: Code (Asc)</option>
                <option value="code-desc">Sort: Code (Desc)</option>
            </select>
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
                <a href="index.php" class="btn btn-sm btn-outline-secondary">
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
        <a href="index.php" class="btn btn-outline-secondary">
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
          <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#discountModal">
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

<script>
document.addEventListener('DOMContentLoaded', () => {
    // --- Mobile POS UI Sync Script ---

    // Get references to all duplicated elements
    const desktopCart = document.getElementById('order-items-container');
    const mobileCart = document.getElementById('order-items-container-mobile');
    
    const desktopSubtotalLine = document.getElementById('subtotal-line');
    const mobileSubtotalLine = document.getElementById('subtotal-line-mobile');
    const desktopSubtotalPrice = document.getElementById('subtotal-price');
    const mobileSubtotalPrice = document.getElementById('subtotal-price-mobile');

    const desktopDiscountLine = document.getElementById('discount-line');
    const mobileDiscountLine = document.getElementById('discount-line-mobile');
    const desktopDiscountAmount = document.getElementById('discount-amount');
    const mobileDiscountAmount = document.getElementById('discount-amount-mobile');
    const desktopDiscountText = document.querySelector('#discount-line .text-discount');
    const mobileDiscountText = document.querySelector('#discount-line-mobile .text-discount');

    const desktopTotal = document.getElementById('total-price');
    const mobileTotal = document.getElementById('total-price-mobile');

    const desktopPayBtn = document.getElementById('pay-button');
    const mobilePayBtn = document.getElementById('pay-button-mobile');

    const desktopClearBtn = document.getElementById('clear-button');
    const mobileClearBtn = document.getElementById('clear-button-mobile');

    // Mobile-only summary bar
    const mobileSummaryCount = document.getElementById('mobile-cart-count');
    const mobileSummaryTotal = document.getElementById('mobile-cart-total');

    const observer = new MutationObserver((mutations) => {
        // Sync cart items
        if (mobileCart) mobileCart.innerHTML = desktopCart.innerHTML;
        
        // Sync summary lines
        if (mobileSubtotalLine) mobileSubtotalLine.style.display = desktopSubtotalLine.style.display;
        if (mobileSubtotalPrice) mobileSubtotalPrice.textContent = desktopSubtotalPrice.textContent;
        
        if (mobileDiscountLine) mobileDiscountLine.style.display = desktopDiscountLine.style.display;
        if (mobileDiscountAmount) mobileDiscountAmount.textContent = desktopDiscountAmount.textContent;
        if (mobileDiscountText) mobileDiscountText.textContent = desktopDiscountText.textContent;

        // Sync main total
        if (mobileTotal) mobileTotal.textContent = desktopTotal.textContent;
        
        // Sync button states
        if (mobilePayBtn) mobilePayBtn.disabled = desktopPayBtn.disabled;
        
        // Sync mobile summary bar
        if (mobileSummaryTotal) mobileSummaryTotal.textContent = desktopTotal.textContent;
        if (mobileSummaryCount) {
            // Calculate item count (this is tricky, let's just grab it from the original script's `cart` variable)
            // A simpler way: count the items in the list.
            const itemCount = desktopCart.querySelectorAll('.cart-item').length;
            mobileSummaryCount.textContent = `${itemCount} Item${itemCount === 1 ? '' : 's'}`;
        }

        // --- Re-attach event listeners for mobile cart ---
        // (This is crucial because we copied innerHTML)
        if (mobileCart) {
            mobileCart.querySelectorAll('.btn-dec').forEach(btn => {
                btn.addEventListener('click', () => window.updateQuantity(parseInt(btn.dataset.id), -1));
            });
            mobileCart.querySelectorAll('.btn-inc').forEach(btn => {
                btn.addEventListener('click', () => window.updateQuantity(parseInt(btn.dataset.id), 1));
            });
            mobileCart.querySelectorAll('.btn-remove').forEach(btn => {
                btn.addEventListener('click', () => window.setQuantity(parseInt(btn.dataset.id), 0));
            });
            mobileCart.querySelectorAll('.cart-quantity-input').forEach(input => {
                input.addEventListener('change', (e) => {
                    window.setQuantity(parseInt(e.target.dataset.id, 10), parseInt(e.target.value, 10));
                });
            });
        }
    });

    // Start observing the desktop cart for changes
    observer.observe(desktopCart, { childList: true, subtree: true });
    
    // Also observe the summary lines for text/style changes
    observer.observe(desktopTotal, { characterData: true, childList: true });
    observer.observe(desktopSubtotalPrice, { characterData: true, childList: true });
    observer.observe(desktopDiscountAmount, { characterData: true, childList: true });
    observer.observe(desktopDiscountText, { characterData: true, childList: true });
    observer.observe(desktopPayBtn, { attributes: true, attributeFilter: ['disabled'] });

    // --- Sync button clicks from mobile to desktop ---
    // (The original script's listeners are only on the desktop buttons)
    if (mobilePayBtn) {
        mobilePayBtn.addEventListener('click', () => {
            desktopPayBtn.click(); // Trigger the original pay button
        });
    }
    if (mobileClearBtn) {
        mobileClearBtn.addEventListener('click', () => {
            desktopClearBtn.click(); // Trigger the original clear button
        });
    }
});
</script>

</body>
</html>