<?php
session_start();
// Set this page as the active page
$active_page = 'pos';

// Include PHP logic files
// require_once '../src/POSManager.php';
// $manager = new PosFunctions();
// $products = $manager->getAvailableProducts();

// Include the header
include 'header.php';
?>

<!--
This page uses a custom layout from pos.css.
We remove the default padding from the <main> element
by adding an inline style.
-->
<style>
    main.container-fluid {
        padding-top: 0 !important;
        padding-bottom: 0 !important;
        padding-left: 0.5rem !important;
        padding-right: 0.5rem !important;
        height: calc(100vh - 73px); /* 100vh minus navbar height */
    }
</style>

<div class="pos-container">
    <!-- Left Column: Product Grid -->
    <div class="product-grid">
        <div class="row g-3">
            
            <!-- PHP Loop for products would start here -->
            <?php /*
            foreach ($products as $product) {
                $stock_class = ($product['stock_qty'] <= $product['reorder_level']) ? 'low-stock' : 'in-stock';
            */ ?>
            
            <!-- Placeholder Product Card 1 -->
            <div class="col-md-6 col-lg-4">
                <a href="#" class="card product-card text-decoration-none">
                    <div class="product-image-placeholder">
                        <i class="bi bi-cake fs-1" style="color: var(--color-accent);"></i>
                    </div>
                    <div class="product-details">
                        <h3 class="product-name"><?php echo "Vanilla Cupcake"; /* htmlspecialchars($product['product_name']); */ ?></h3>
                        <div class="product-info-bottom">
                            <span class="product-price">$<?php echo "3.00"; /* number_format($product['price'], 2); */ ?></span>
                            <span class="product-stock-tag <?php echo "in-stock"; /* $stock_class; */ ?>">
                                <?php echo "72 left"; /* (int)$product['stock_qty'] . ' left'; */ ?>
                            </span>
                        </div>
                    </div>
                </a>
            </div>
            
            <!-- Placeholder Product Card 2 -->
            <div class="col-md-6 col-lg-4">
                <a href="#" class="card product-card text-decoration-none">
                    <div class="product-image-placeholder">
                        <i class="bi bi-cake fs-1" style="color: var(--color-accent);"></i>
                    </div>
                    <div class="product-details">
                        <h3 class="product-name">Whole Wheat Bread</h3>
                        <div class="product-info-bottom">
                            <span class="product-price">$5.50</span>
                            <span class="product-stock-tag in-stock">29 left</span>
                        </div>
                    </div>
                </a>
            </div>

            <!-- Placeholder Product Card 3 -->
            <div class="col-md-6 col-lg-4">
                <a href="#" class="card product-card text-decoration-none">
                    <div class="product-image-placeholder">
                        <i class="bi bi-cake fs-1" style="color: var(--color-accent);"></i>
                    </div>
                    <div class="product-details">
                        <h3 class="product-name">Carrot Cake</h3>
                        <div class="product-info-bottom">
                            <span class="product-price">$22.00</span>
                            <span class="product-stock-tag low-stock">8 left</span>
                        </div>
                    </div>
                </a>
            </div>

            <!-- ... more placeholder cards ... -->
            
            <?php /* } */ ?>
            <!-- End of PHP Loop -->
            
        </div>
    </div>
    
    <!-- Right Column: Current Order -->
    <div class="order-panel">
        <!-- Order Panel Header -->
        <div class="order-panel-header">
            <h3 class="fw-semibold">Current Order</h3>
            <p class="mb-0 text-muted">0 items</p>
        </div>
        
        <!-- Order Items (Scrollable) -->
        <div class="order-items">
            <!-- This empty state shows when cart is empty -->
            <div class="order-empty-state">
                <i class="bi bi-cart-x fs-1 text-light"></i>
                <h4 class="mt-3">Cart is empty</h4>
                <p class="text-muted">Add products to start an order</p>
            </div>
            
            <!--
            When items are in cart, you would hide .order-empty-state
            and show the items like this:
            
            <div class="cart-item">
                <div class="cart-item-details">
                    <div class="fw-bold">Vanilla Cupcake</div>
                    <small class="text-muted">$3.00</small>
                </div>
                <div class="cart-item-controls">
                    <button class="btn btn-sm btn-outline-secondary">-</button>
                    <input type="number" class="form-control form-control-sm cart-quantity-input" value="1">
                    <button class="btn btn-sm btn-outline-secondary">+</button>
                </div>
            </div>
            -->
        </div>
        
        <!-- Order Total (Footer) -->
        <div class="order-total">
            <div class="d-flex justify-content-between order-summary-line">
                <span class="text-muted">Subtotal</span>
                <span class="fw-medium">$0.00</span>
            </div>
            <div class="d-flex justify-content-between order-summary-line">
                <span class="text-muted">Tax</span>
                <span class="fw-medium">$0.00</span>
            </div>
            <hr class="my-2">
            <div class="d-flex justify-content-between h5 fw-bold">
                <span>Total</span>
                <span>$0.00</span>
            </div>
            <button class="btn btn-primary w-100 mt-3" style="background-color: var(--color-accent); border-color: var(--color-accent);">
                Charge
            </button>
        </div>
    </div>
</div>

<?php
// Include the footer
include 'footer.php';
?>