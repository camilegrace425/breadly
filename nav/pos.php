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
    $cart_items = json_decode($json_data, true);

    if (json_last_error() !== JSON_ERROR_NONE || empty($cart_items)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid cart data received.']);
        exit();
    }
        
    $bakeryManager = new BakeryManager();
    $user_id = $_SESSION['user_id'];
    $all_successful = true;
    $error_message = 'An unknown error occurred.';

    foreach ($cart_items as $item) {
        $product_id = $item['id'];
        $quantity = $item['quantity'];

        $status_message = $bakeryManager->recordSale($user_id, $product_id, $quantity);
        
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
    <title>Point of Sale - Bakery System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    
    <link rel="stylesheet" href="../styles/global.css"> 
    <link rel="stylesheet" href="../styles/pos.css"> 
    
</head>
<body class="pos-page"> <div class="pos-container">
    <div class="product-grid">
        <h4 class="mb-3">Products</h4>
        
        <div class="input-group mb-3">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" id="product-search" class="form-control" placeholder="Search products...">
        </div>
        <div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 g-3" id="product-list">
            <?php foreach ($products as $product): ?>
            <div class="col" data-product-name="<?= htmlspecialchars(strtolower($product['name'])) ?>">
                <div class="product-card" 
                     data-id="<?= htmlspecialchars($product['product_id']) ?>"
                     data-name="<?= htmlspecialchars($product['name']) ?>"
                     data-price="<?= htmlspecialchars($product['price']) ?>"
                     data-stock="<?= htmlspecialchars($product['stock_qty']) ?>">
                     <div class="product-name"><?= htmlspecialchars($product['name']) ?></div>
                    <div class="product-price">P<?= htmlspecialchars(number_format($product['price'], 2)) ?></div>
                    <small class="text-muted">Stock: <?= htmlspecialchars($product['stock_qty']) ?></small>
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

    <div class="order-panel">
        <div class="d-flex justify-content-between align-items-center mb-3">
             <h4>Current Order</h4>
             <a href="index.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Menu
             </a>
        </div>
       
        <div id="order-items-container" class="order-items">
            <p class="text-center text-muted mt-5">Select products to begin</p>
        </div>
        
        <div class="order-total">
            <div class="d-flex justify-content-between h5">
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

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="../js/script_pos.js"></script>

</body>
</html>