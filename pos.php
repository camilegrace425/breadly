<?php
session_start();

// --- 1. Security Check ---
// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
// Redirect if not a manager or cashier
if ($_SESSION['role'] !== 'manager' && $_SESSION['role'] !== 'cashier') {
    header('Location: index.php');
    exit();
}
// --- End Security Check ---

require_once 'pos_functions.php';
require_once 'BakeryManager.php'; // To process the sale

$posFunctions = new PosFunctions();
$products = $posFunctions->getAvailableProducts();

// --- 3. Handle Sale Submission (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get the JSON data sent from JavaScript
    $json_data = file_get_contents('php://input');
    $cart_items = json_decode($json_data, true);

    if (json_last_error() === JSON_ERROR_NONE && !empty($cart_items)) {
        $bakeryManager = new BakeryManager();
        $user_id = $_SESSION['user_id'];
        $all_successful = true;

        foreach ($cart_items as $item) {
            // Call the recordSale method for each item in the cart
            $success = $bakeryManager->recordSale($user_id, $item['id'], $item['quantity']);
            if (!$success) {
                $all_successful = false;
                break; // Stop if one transaction fails
            }
        }

        header('Content-Type: application/json');
        if ($all_successful) {
            echo json_encode(['status' => 'success', 'message' => 'Sale recorded successfully!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'An error occurred. Check product stock and try again.']);
        }
        exit(); // Stop script execution after handling AJAX request
    }
}
// --- End Sale Submission ---

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Point of Sale - Bakery System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    
    <link rel="stylesheet" href="style.css"> 
    
</head>
<body class="pos-page"> <div class="pos-container">
    <div class="product-grid">
        <h4 class="mb-3">Products</h4>
        <div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 g-3">
            <?php foreach ($products as $product): ?>
            <div class="col">
                <div class="product-card" 
                     data-id="<?= htmlspecialchars($product['product_id']) ?>"
                     data-name="<?= htmlspecialchars($product['name']) ?>"
                     data-price="<?= htmlspecialchars($product['price']) ?>"
                     onclick="addToCart(this)">
                    <div class="product-name"><?= htmlspecialchars($product['name']) ?></div>
                    <div class="product-price">P<?= htmlspecialchars(number_format($product['price'], 2)) ?></div>
                    <small class="text-muted">Stock: <?= htmlspecialchars($product['stock_qty']) ?></small>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="order-panel">
        <div class="d-flex justify-content-between align-items-center mb-3">
             <h4>Current Order</h4>
             <a href="dashboard_panel.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-speedometer2"></i> Dashboard
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
<script>
    // All JavaScript logic remains the same
    let cart = [];

    const orderItemsContainer = document.getElementById('order-items-container');
    const totalPriceEl = document.getElementById('total-price');
    const payButton = document.getElementById('pay-button');
    const clearButton = document.getElementById('clear-button');

    function addToCart(cardElement) {
        const productId = parseInt(cardElement.dataset.id);
        const name = cardElement.dataset.name;
        const price = parseFloat(cardElement.dataset.price);
        const existingItem = cart.find(item => item.id === productId);
        if (existingItem) {
            existingItem.quantity++;
        } else {
            cart.push({ id: productId, name: name, price: price, quantity: 1 });
        }
        renderCart();
    }

    function updateQuantity(productId, change) {
        const item = cart.find(item => item.id === productId);
        if (item) {
            item.quantity += change;
            if (item.quantity <= 0) {
                removeFromCart(productId);
            }
        }
        renderCart();
    }

    function removeFromCart(productId) {
        cart = cart.filter(item => item.id !== productId);
        renderCart();
    }
    
    clearButton.addEventListener('click', () => {
        cart = [];
        renderCart();
    });

    function renderCart() {
        orderItemsContainer.innerHTML = '';
        if (cart.length === 0) {
            orderItemsContainer.innerHTML = '<p class="text-center text-muted mt-5">Select products to begin</p>';
            payButton.disabled = true;
            return;
        }
        let total = 0;
        cart.forEach(item => {
            const itemTotal = item.price * item.quantity;
            total += itemTotal;
            const itemEl = document.createElement('div');
            itemEl.className = 'cart-item';
            itemEl.innerHTML = `
                <div class="cart-item-details">
                    <strong>${item.name}</strong>
                    <div class="text-muted">${item.quantity} x P${item.price.toFixed(2)} = P${itemTotal.toFixed(2)}</div>
                </div>
                <div class="cart-item-controls">
                    <button class="btn btn-outline-secondary" onclick="updateQuantity(${item.id}, -1)">-</button>
                    <span class="mx-2">${item.quantity}</span>
                    <button class="btn btn-outline-secondary" onclick="updateQuantity(${item.id}, 1)">+</button>
                </div>
            `;
            orderItemsContainer.appendChild(itemEl);
        });
        totalPriceEl.textContent = `P${total.toFixed(2)}`;
        payButton.disabled = false;
    }

    payButton.addEventListener('click', () => {
        if (cart.length === 0) return;
        Swal.fire({
            title: 'Confirm Sale',
            text: `Total amount is ${totalPriceEl.textContent}. Proceed?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#198754',
            confirmButtonText: 'Yes, complete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                fetch('pos.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(cart)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        Swal.fire('Success!', data.message, 'success');
                        cart = [];
                        renderCart();
                        // Consider reloading the page to show updated stock
                        // location.reload(); 
                    } else {
                        Swal.fire('Error!', data.message, 'error');
                    }
                })
                .catch(error => {
                    Swal.fire('Network Error', 'Could not complete the sale.', 'error');
                });
            }
        });
    });

</script>

</body>
</html>