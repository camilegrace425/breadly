<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$allowed_roles = ['manager', 'cashier', 'assistant_manager'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    header('Location: ../index.php');
    exit();
}

require_once '../src/POSManager.php';
require_once '../src/BakeryManager.php';

$posFunctions = new PosFunctions();
$products = $posFunctions->getAvailableProducts();

// Handle Sale Submission (JSON)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $data = json_decode(file_get_contents('php://input'), true);

    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['cart']) || !isset($data['discount'])) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid cart data received.']);
        exit();
    }
    
    $cart_items = $data['cart'];
    $discount_percent = $data['discount'];

    if (empty($cart_items)) {
         echo json_encode(['status' => 'error', 'message' => 'Cart is empty.']);
        exit();
    }
        
    $bakeryManager = new BakeryManager();
    $user_id = $_SESSION['user_id'];
    $all_successful = true;
    $error_message = 'An unknown error occurred.';

    foreach ($cart_items as $item) {
        $status_message = $bakeryManager->recordSale($user_id, $item['id'], $item['quantity'], $discount_percent);
        
        if ($status_message !== 'Success: Sale recorded.') {
            $all_successful = false;
            $error_message = $status_message;
            break;
        }
    }

    echo json_encode([
        'status' => $all_successful ? 'success' : 'error',
        'message' => $all_successful ? 'Sale recorded successfully!' : $error_message
    ]);
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
                            bg: '#F8F1E7',
                            panel: '#E4A26C',
                            btn: '#af6223',
                            'btn-hover': '#9b4a10',
                            dark: '#333333',
                            cream: '#FFFBF5',
                            light: '#fdfaf6'
                        }
                    }
                }
            }
        }
    </script>
    <style>
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #9ca3af; }
        input[type=number]::-webkit-inner-spin-button, input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
    </style>
</head>
<body class="bg-breadly-light h-screen overflow-hidden text-breadly-dark font-sans selection:bg-orange-200">

<div class="h-full w-full p-2 lg:p-4 grid grid-cols-1 lg:grid-cols-3 gap-4">
    
    <div class="lg:col-span-2 bg-white rounded-2xl shadow-lg flex flex-col overflow-hidden h-full border border-orange-100">
        <div class="p-4 border-b border-gray-100 bg-white z-10">
            <div class="flex flex-col md:flex-row justify-between items-center gap-4">
                <h2 class="text-xl font-bold text-breadly-dark flex items-center gap-2">
                    <i class='bx bxs-store-alt text-breadly-btn'></i> Products
                </h2>
                <div class="relative w-full md:w-64">
                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><i class='bx bx-search text-gray-400 text-lg'></i></span>
                    <input type="text" id="product-search" class="w-full pl-10 pr-4 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:outline-none focus:border-breadly-btn focus:ring-1 focus:ring-breadly-btn transition-colors text-sm" placeholder="Search items...">
                </div>
                <div class="flex gap-2 w-full md:w-auto overflow-x-auto pb-1 md:pb-0">
                    <div class="flex items-center gap-1 bg-gray-50 px-2 py-1 rounded-lg border border-gray-200">
                        <label class="text-xs text-gray-500 whitespace-nowrap">Search:</label>
                        <select id="search-type" class="bg-transparent text-sm border-none focus:ring-0 text-gray-700 font-medium py-1 pr-6 cursor-pointer outline-none">
                            <option value="name" selected>Name</option>
                            <option value="code">Code</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-1 bg-gray-50 px-2 py-1 rounded-lg border border-gray-200">
                        <label class="text-xs text-gray-500 whitespace-nowrap">Sort:</label>
                        <select id="sort-type" class="bg-transparent text-sm border-none focus:ring-0 text-gray-700 font-medium py-1 pr-6 cursor-pointer outline-none">
                            <option value="name-asc" selected>A-Z</option>
                            <option value="name-desc">Z-A</option>
                            <option value="price-asc">Price L-H</option>
                            <option value="price-desc">Price H-L</option>
                            <option value="stock-desc">Stock H-L</option>
                            <option value="stock-asc">Stock L-H</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto p-4 custom-scrollbar bg-gray-50/50" id="product-list">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                <?php foreach ($products as $product): ?>
                <div class="col-product group"
                     data-product-name="<?= htmlspecialchars(strtolower($product['name'])) ?>"
                     data-product-code="<?= htmlspecialchars($product['product_id']) ?>"
                     data-product-price="<?= htmlspecialchars($product['price']) ?>"
                     data-product-stock="<?= htmlspecialchars($product['stock_qty']) ?>"
                     data-product-image="<?= htmlspecialchars($product['image_url'] ?? '') ?>">
                    
                    <div class="product-card bg-white p-3 rounded-xl border border-gray-100 hover:border-breadly-panel shadow-sm hover:shadow-md transition-all duration-200 cursor-pointer flex flex-row items-center gap-4 h-28 relative overflow-hidden"
                         data-id="<?= htmlspecialchars($product['product_id']) ?>"
                         data-name="<?= htmlspecialchars($product['name']) ?>"
                         data-price="<?= htmlspecialchars($product['price']) ?>"
                         data-stock="<?= htmlspecialchars($product['stock_qty']) ?>"
                         data-image="<?= htmlspecialchars($product['image_url'] ?? '') ?>">
                         
                        <div class="absolute inset-0 bg-breadly-btn/5 opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none"></div>
                        <span class="absolute top-2 right-3 text-sm font-mono font-bold text-gray-400 bg-white/80 px-1 rounded"><?= htmlspecialchars($product['product_id']) ?></span>

                        <?php $image_path = !empty($product['image_url']) ? htmlspecialchars($product['image_url']) : '../images/breadlylogo.png'; ?>
                        <div class="w-20 h-20 shrink-0">
                            <img src="<?= $image_path ?>" alt="<?= htmlspecialchars($product['name']) ?>" class="w-full h-full object-cover rounded-lg border border-gray-100 bg-white">
                        </div>
                        
                        <div class="flex flex-col flex-1 min-w-0 h-full justify-between py-1">
                            <div class="pr-6"> 
                                <h3 class="font-semibold text-gray-800 leading-tight text-sm line-clamp-2 group-hover:text-breadly-btn transition-colors mt-3">
                                    <?= htmlspecialchars($product['name']) ?>
                                </h3>
                            </div>
                            <div class="flex justify-between items-end">
                                <span class="text-breadly-btn font-bold">P<?= htmlspecialchars(number_format($product['price'], 2)) ?></span>
                                <span class="text-[13px] text-gray-1000 bg-gray-200 px-2 py-0.5 rounded-full"><b>Stock:</b>
                                    <?php if (htmlspecialchars($product['stock_qty']) <= 2): ?>
                                        <span class="text-red-500 font-semibold"><?= htmlspecialchars($product['stock_qty']) ?></span>
                                    <?php else: ?>
                                        <span class="text-green-700 font-semibold"><?= htmlspecialchars($product['stock_qty']) ?></span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php if (empty($products)): ?>
                    <div class="col-span-full text-center py-10 text-gray-400">
                        <i class='bx bx-package text-4xl mb-2'></i>
                        <p>No products available.</p>
                    </div>
                <?php endif; ?>
                
                <div id="no-results-message" class="col-span-full hidden text-center py-10 text-gray-400">
                     <i class='bx bx-search-alt text-4xl mb-2'></i>
                    <p>No products match your search.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="hidden lg:flex lg:col-span-1 bg-white rounded-2xl shadow-lg flex-col h-full border border-orange-100 overflow-hidden relative">
        <div class="p-4 border-b border-gray-100 bg-white flex justify-between items-center z-10">
            <h4 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                <i class='bx bx-cart text-breadly-btn'></i> Current Order
            </h4>
            <div class="flex gap-2">
                 <button type="button" onclick="toggleModal('discountModal')" class="text-xs bg-orange-50 text-breadly-btn border border-orange-200 px-3 py-1.5 rounded-lg hover:bg-orange-100 transition-colors font-medium flex items-center gap-1">
                    <i class='bx bxs-offer'></i> Discount
                </button>
                <a href="../index.php" class="text-xs bg-gray-50 text-gray-600 border border-gray-200 px-3 py-1.5 rounded-lg hover:bg-gray-100 transition-colors font-medium flex items-center gap-1">
                    <i class='bx bx-exit'></i> Exit
                </a>
            </div>
        </div>
       
        <div id="order-items-container" class="flex-1 overflow-y-auto p-4 custom-scrollbar bg-gray-50 flex flex-col gap-2">
            <div class="h-full flex flex-col items-center justify-center text-gray-400 opacity-60">
                <i class='bx bx-basket text-6xl mb-2'></i>
                <p>Select products to begin</p>
            </div>
        </div>
        
        <div class="p-4 bg-white border-t border-gray-200 shadow-[0_-5px_15px_rgba(0,0,0,0.02)] z-20">
            <div id="order-summary-details" class="space-y-1 mb-3 text-sm">
                <div class="flex justify-between hidden" id="subtotal-line">
                    <span class="text-gray-500">Subtotal:</span>
                    <span id="subtotal-price" class="font-medium text-gray-700">P0.00</span>
                </div>
                <div class="flex justify-between hidden" id="discount-line">
                    <span class="text-red-500 font-medium flex items-center gap-1"><i class='bx bxs-discount'></i> Discount (0%):</span>
                    <span id="discount-amount" class="text-red-500 font-medium">-P0.00</span>
                </div>
            </div>

            <div class="flex justify-between items-center mb-4 pt-2 border-t border-dashed border-gray-200">
                <span class="text-gray-600 font-medium">Total Amount:</span>
                <span id="total-price" class="text-2xl font-bold text-breadly-dark">P0.00</span>
            </div>
            
            <div class="grid grid-cols-3 gap-2">
                <button id="clear-button" class="col-span-1 py-3 border border-red-200 text-red-500 font-semibold rounded-xl hover:bg-red-50 transition-colors text-sm flex items-center justify-center gap-1">
                    <i class='bx bx-trash'></i> Clear
                </button>
                <button id="pay-button" class="col-span-2 py-3 bg-green-600 text-white font-bold rounded-xl shadow-lg hover:bg-green-700 hover:shadow-xl transition-all disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2" disabled>
                    Complete Sale <i class='bx bx-check-circle'></i>
                </button>
            </div>
        </div>
    </div>
</div>

<div class="lg:hidden fixed bottom-0 left-0 w-full bg-white border-t border-gray-200 shadow-[0_-5px_20px_rgba(0,0,0,0.1)] p-4 z-50 flex items-center justify-between">
    <div class="flex flex-col">
        <small class="text-gray-500 text-xs" id="mobile-cart-count">0 Items</small>
        <strong class="text-xl text-breadly-dark" id="mobile-cart-total">P0.00</strong>
    </div>
    <div class="flex gap-3">
        <a href="../index.php" class="w-10 h-10 flex items-center justify-center rounded-full border border-gray-300 text-gray-600 hover:bg-gray-50">
            <i class='bx bx-arrow-back'></i>
        </a>
        <button onclick="toggleModal('mobileCartModal')" class="bg-breadly-btn text-white px-6 py-2 rounded-xl font-semibold shadow-md active:scale-95 transition-transform flex items-center gap-2">
            View Cart <i class='bx bx-chevron-up'></i>
        </button>
    </div>
</div>

<div id="mobileCartModal" class="fixed inset-0 z-[60] hidden" aria-labelledby="mobileCartModalLabel" aria-hidden="true">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm transition-opacity" onclick="toggleModal('mobileCartModal')"></div>
    
    <div class="absolute inset-x-0 bottom-0 top-10 bg-white rounded-t-3xl flex flex-col shadow-2xl transform transition-transform duration-300">
        <div class="w-full flex justify-center pt-3 pb-1" onclick="toggleModal('mobileCartModal')">
            <div class="w-12 h-1.5 bg-gray-300 rounded-full"></div>
        </div>

        <div class="px-5 py-2 border-b border-gray-100 flex justify-between items-center">
            <h5 class="text-lg font-bold text-gray-800">Current Order</h5>
            <button type="button" class="text-gray-400 hover:text-gray-600 text-2xl" onclick="toggleModal('mobileCartModal')">
                <i class='bx bx-x'></i>
            </button>
        </div>
        
        <div class="flex-1 overflow-y-auto p-4 custom-scrollbar bg-gray-50 flex flex-col gap-2">
             <div id="order-items-container-mobile" class="flex flex-col gap-2">
                <div class="py-10 text-center text-gray-400">
                    <p>Cart is empty</p>
                </div>
            </div>
        </div>
        
        <div class="p-5 bg-white border-t border-gray-200 pb-8">
            <div id="order-summary-details-mobile" class="space-y-1 mb-3 text-sm">
                <div class="flex justify-between hidden" id="subtotal-line-mobile">
                    <span class="text-gray-500">Subtotal:</span>
                    <span id="subtotal-price-mobile" class="font-medium text-gray-700">P0.00</span>
                </div>
                <div class="flex justify-between hidden" id="discount-line-mobile">
                    <span class="text-red-500 font-medium">Discount:</span>
                    <span id="discount-amount-mobile" class="text-red-500 font-medium">-P0.00</span>
                </div>
            </div>

            <div class="flex justify-between items-center mb-4">
                <span class="text-lg font-semibold text-gray-700">Total:</span>
                <span id="total-price-mobile" class="text-2xl font-bold text-breadly-dark">P0.00</span>
            </div>
            
            <div class="grid grid-cols-2 gap-3 mb-3">
                <button type="button" class="py-2 border border-orange-200 text-breadly-btn font-medium rounded-lg hover:bg-orange-50 flex items-center justify-center gap-1" id="mobile-discount-btn" onclick="toggleModal('mobileCartModal'); toggleModal('discountModal')">
                    <i class='bx bxs-offer'></i> Discount
                </button>
                 <button id="clear-button-mobile" class="py-2 border border-red-200 text-red-500 font-medium rounded-lg hover:bg-red-50 flex items-center justify-center gap-1">
                    <i class='bx bx-trash'></i> Clear
                </button>
            </div>
            
            <button id="pay-button-mobile" class="w-full py-4 bg-green-600 text-white font-bold rounded-xl shadow-lg active:scale-95 transition-transform disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2" disabled>
                Complete Sale
            </button>
        </div>
    </div>
</div>

<div id="discountModal" class="fixed inset-0 z-[70] hidden flex items-center justify-center p-4" aria-hidden="true">
    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" onclick="toggleModal('discountModal')"></div>
    
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm relative z-10 overflow-hidden transform transition-all scale-100">
        <div class="bg-orange-50 p-4 border-b border-orange-100 flex justify-between items-center">
            <h5 class="font-bold text-breadly-dark flex items-center gap-2">
                <i class='bx bxs-coupon text-breadly-btn text-xl'></i> Apply Discount
            </h5>
            <button type="button" class="text-gray-400 hover:text-gray-600" onclick="toggleModal('discountModal')">
                <i class='bx bx-x text-2xl'></i>
            </button>
        </div>
        
        <div class="p-6">
            <label for="discount-input" class="block text-sm font-medium text-gray-700 mb-2">Discount Percentage (%)</label>
            <div class="relative">
                <input type="number" id="discount-input" class="w-full p-3 pl-4 pr-10 border border-gray-300 rounded-xl focus:ring-2 focus:ring-breadly-btn focus:border-breadly-btn outline-none font-bold text-lg text-center" placeholder="0" min="0" max="100">
                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 font-bold">%</span>
            </div>
            <p class="text-xs text-gray-500 mt-2 text-center">Enter a value between 0 and 100.</p>
        </div>
        
        <div class="p-4 bg-gray-50 flex gap-3">
            <button type="button" class="flex-1 py-2.5 border border-gray-300 text-gray-600 font-semibold rounded-lg hover:bg-white hover:text-red-500 transition-colors" id="remove-discount-btn-modal">
                No Discount
            </button>
            <button type="button" class="flex-1 py-2.5 bg-breadly-btn text-white font-semibold rounded-lg shadow hover:bg-breadly-btn-hover transition-colors" id="apply-discount-btn-modal">
                Apply
            </button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="../js/script_pos.js"></script>
<script src="../js/script_pos_mobile.js"></script>

<script>
    function toggleModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            if (modal.classList.contains('hidden')) {
                modal.classList.remove('hidden');
                modal.setAttribute('aria-hidden', 'false');
            } else {
                modal.classList.add('hidden');
                modal.setAttribute('aria-hidden', 'true');
            }
        }
    }
    window.toggleModal = toggleModal;
</script>

</body>
</html>