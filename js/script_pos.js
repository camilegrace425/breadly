document.addEventListener('DOMContentLoaded', () => {
    let cart = []; // Stores cart items
    let discountPercent = 0; // Global state for discount

    // DOM References
    const orderItemsContainer = document.getElementById('order-items-container');
    const totalPriceEl = document.getElementById('total-price');
    const payButton = document.getElementById('pay-button');
    const clearButton = document.getElementById('clear-button');
    
    // Wrapper/Grid Container references
    const productListWrapper = document.getElementById('product-list');
    const productListContainer = productListWrapper ? productListWrapper.querySelector('.grid') || productListWrapper : null;

    const searchInput = document.getElementById('product-search');
    const searchTypeSelect = document.getElementById('search-type'); 
    const sortTypeSelect = document.getElementById('sort-type');   
    const noResultsMessage = document.getElementById('no-results-message');

    // --- Discount DOM References ---
    const discountModalEl = document.getElementById('discountModal');
    const discountInput = document.getElementById('discount-input');
    const applyDiscountBtn = document.getElementById('apply-discount-btn-modal'); 
    const removeDiscountBtn = document.getElementById('remove-discount-btn-modal'); 
    
    const subtotalLine = document.getElementById('subtotal-line');
    const discountLine = document.getElementById('discount-line');
    const subtotalPriceEl = document.getElementById('subtotal-price');
    const discountAmountEl = document.getElementById('discount-amount');
    const discountLineText = document.querySelector('#discount-line span:first-child') || document.getElementById('discount-line');

    // --- Helper: Toggle Modal ---
    function safeToggleModal(modalId) {
        if (typeof window.toggleModal === 'function') {
            window.toggleModal(modalId);
        } else {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.toggle('hidden');
                // Basic Aria toggle if helper not found
                const isHidden = modal.classList.contains('hidden');
                modal.setAttribute('aria-hidden', isHidden ? 'true' : 'false');
            }
        }
    }

     // Called when a product card is clicked.
    window.addToCart = function(cardElement) {
        const productId = parseInt(cardElement.dataset.id);
        const name = cardElement.dataset.name;
        const price = parseFloat(cardElement.dataset.price);
        const maxStock = parseInt(cardElement.dataset.stock);

        const existingItem = cart.find(item => item.id === productId);

        if (existingItem) {
            if (existingItem.quantity + 1 > maxStock) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Out of Stock',
                    text: `Cannot add more ${name}. Only ${maxStock} available.`,
                    confirmButtonColor: '#af6223'
                });
                return;
            }
            window.setQuantity(productId, existingItem.quantity + 1);
        } else {
            if (1 > maxStock) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Out of Stock',
                    text: `Cannot add ${name}. This item is out of stock.`,
                    confirmButtonColor: '#af6223'
                });
                return;
            }
            cart.push({ id: productId, name: name, price: price, quantity: 1, maxStock: maxStock });
            renderCart();
        }
    }

    // UPDATED: Made global
    window.setQuantity = function(productId, newQuantity) {
        const item = cart.find(item => item.id === productId);
        if (!item) return;

        if (isNaN(newQuantity) || newQuantity < 0) {
            newQuantity = 0;
        }
        
        if (newQuantity === 0) {
            cart = cart.filter(item => item.id !== productId);
        } else if (newQuantity > item.maxStock) {
            newQuantity = item.maxStock;
            Swal.fire({
                icon: 'warning',
                title: 'Stock Limit',
                text: `Only ${item.maxStock} ${item.name} available.`,
                confirmButtonColor: '#af6223'
            });
            item.quantity = newQuantity;
        } else {
            item.quantity = newQuantity;
        }
        renderCart();
    }

    // UPDATED: Made global
    window.updateQuantity = function(productId, change) {
        const item = cart.find(item => item.id === productId);
        if (!item) return;
        
        const newQuantity = item.quantity + change;
        window.setQuantity(productId, newQuantity);
    }

    if(clearButton) {
        clearButton.addEventListener('click', () => {
            cart = [];
            discountPercent = 0; 
            if (discountInput) discountInput.value = ''; 
            renderCart(); 
        });
    }

    function renderCart() {
        orderItemsContainer.innerHTML = '';
        let subTotal = 0;
        let finalTotal = 0;
        let discountAmount = 0;

        if (cart.length === 0) {
            orderItemsContainer.innerHTML = `
                <div class="h-full flex flex-col items-center justify-center text-gray-400 opacity-60 py-10">
                    <i class='bx bx-basket text-6xl mb-2'></i>
                    <p>Select products to begin</p>
                </div>`;
            totalPriceEl.textContent = 'P0.00';
            payButton.disabled = true;
            payButton.classList.add('opacity-50', 'cursor-not-allowed');
            
            // Reset Mobile UI if it exists
            const mobileTotal = document.getElementById('mobile-cart-total');
            const mobileCount = document.getElementById('mobile-cart-count');
            const mobileBtn = document.getElementById('pay-button-mobile');
            
            if(mobileTotal) mobileTotal.textContent = 'P0.00';
            if(mobileCount) mobileCount.textContent = '0 Items';
            if(mobileBtn) mobileBtn.disabled = true;
            
        } else {
            cart.forEach(item => {
                const itemTotal = item.price * item.quantity;
                subTotal += itemTotal;
                
                const itemEl = document.createElement('div');
                // Tailwind styling for Cart Item container
                itemEl.className = 'flex justify-between items-center bg-white p-3 rounded-xl border border-gray-100 shadow-sm hover:shadow-md transition-shadow mb-2';
                
                itemEl.innerHTML = `
                <div class="flex flex-col overflow-hidden mr-3">
                    <span class="font-semibold text-gray-800 truncate">${item.name}</span>
                    <div class="text-xs text-gray-500 mt-1">
                        <span class="bg-gray-100 px-1.5 py-0.5 rounded text-gray-600">${item.quantity}</span> x P${item.price.toFixed(2)}
                    </div>
                    <div class="text-breadly-btn font-bold text-sm mt-0.5">P${itemTotal.toFixed(2)}</div>
                </div>
                
                <div class="flex items-center gap-1 flex-shrink-0">
                    <button class="w-8 h-8 flex items-center justify-center rounded-lg bg-gray-50 text-gray-600 border border-gray-200 hover:bg-gray-100 hover:border-gray-300 transition-all active:scale-95 btn-dec" data-id="${item.id}">
                        <i class='bx bx-minus'></i>
                    </button>
                    
                    <input type="number" class="w-10 text-center text-sm font-semibold bg-transparent outline-none cart-quantity-input appearance-none m-0" 
                           value="${item.quantity}" data-id="${item.id}" min="0" max="${item.maxStock}">
                    
                    <button class="w-8 h-8 flex items-center justify-center rounded-lg bg-gray-50 text-gray-600 border border-gray-200 hover:bg-gray-100 hover:border-gray-300 transition-all active:scale-95 btn-inc" data-id="${item.id}">
                        <i class='bx bx-plus'></i>
                    </button>
                    
                    <div class="w-px h-6 bg-gray-200 mx-1"></div>
                    
                    <button class="w-8 h-8 flex items-center justify-center rounded-lg bg-red-50 text-red-500 border border-red-100 hover:bg-red-100 hover:border-red-200 transition-all active:scale-95 btn-remove" data-id="${item.id}">
                        <i class='bx bx-trash'></i>
                    </button>
                </div>
            `;
                
                orderItemsContainer.appendChild(itemEl);
            });

            // Mobile Modal is handled by the Observer in script_pos_mobile.js
            
            // Attach listeners to desktop buttons
            attachCartListeners(orderItemsContainer);

            // --- Calculate discount ---
            discountAmount = subTotal * (discountPercent / 100);
            finalTotal = subTotal - discountAmount;
            
            payButton.disabled = false;
            payButton.classList.remove('opacity-50', 'cursor-not-allowed');
            
            // Update Mobile UI
            const mobileTotal = document.getElementById('mobile-cart-total');
            const mobileCount = document.getElementById('mobile-cart-count');
            const mobileBtn = document.getElementById('pay-button-mobile');
            const mobileSub = document.getElementById('subtotal-price-mobile');
            const mobileDisc = document.getElementById('discount-amount-mobile');
            const mobileTotalFull = document.getElementById('total-price-mobile');

            if(mobileTotal) mobileTotal.textContent = `P${finalTotal.toFixed(2)}`;
            if(mobileCount) mobileCount.textContent = `${cart.length} Item${cart.length !== 1 ? 's' : ''}`;
            if(mobileBtn) {
                mobileBtn.disabled = false;
                mobileBtn.textContent = `Pay P${finalTotal.toFixed(2)}`;
            }
            if(mobileSub) {
                mobileSub.textContent = `P${subTotal.toFixed(2)}`;
                document.getElementById('subtotal-line-mobile').style.display = 'flex';
            }
            if(mobileDisc) {
                 mobileDisc.textContent = `-P${discountAmount.toFixed(2)}`;
                 document.getElementById('discount-line-mobile').style.display = 'flex';
            }
            if(mobileTotalFull) mobileTotalFull.textContent = `P${finalTotal.toFixed(2)}`;
        }
        
        // Update Desktop Summary
        if (subtotalLine) subtotalLine.classList.remove('hidden');
        if (subtotalLine) subtotalLine.style.display = 'flex';
        
        if (discountLine) discountLine.classList.remove('hidden');
        if (discountLine) discountLine.style.display = 'flex';
        
        if (subtotalPriceEl) subtotalPriceEl.textContent = `P${subTotal.toFixed(2)}`;
        if (discountLineText) discountLineText.innerHTML = `<i class='bx bxs-discount'></i> Discount (${discountPercent}%):`;
        if (discountAmountEl) discountAmountEl.textContent = `-P${discountAmount.toFixed(2)}`;
        
        if (totalPriceEl) totalPriceEl.textContent = `P${finalTotal.toFixed(2)}`;
    }

    function attachCartListeners(container) {
        container.querySelectorAll('.btn-dec').forEach(btn => {
            btn.addEventListener('click', () => window.updateQuantity(parseInt(btn.dataset.id), -1));
        });
        container.querySelectorAll('.btn-inc').forEach(btn => {
            btn.addEventListener('click', () => window.updateQuantity(parseInt(btn.dataset.id), 1));
        });
        container.querySelectorAll('.btn-remove').forEach(btn => {
            btn.addEventListener('click', () => {
                window.setQuantity(parseInt(btn.dataset.id), 0);
            });
        });
        container.querySelectorAll('.cart-quantity-input').forEach(input => {
            input.addEventListener('change', (e) => {
                const newQty = parseInt(e.target.value, 10);
                const productId = parseInt(e.target.dataset.id, 10);
                window.setQuantity(productId, newQty);
            });
            input.addEventListener('click', (e) => e.target.select()); // Auto-select text on click
        });
    }

    // Handles the 'Complete Sale' button click.
    if(payButton) {
        payButton.addEventListener('click', confirmSale);
    }
    
    function confirmSale() {
        if (cart.length === 0) return;
        
        Swal.fire({
            title: 'Confirm Sale',
            text: `Total amount is ${totalPriceEl.textContent}. Proceed?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#15803d', // Tailwind green-700
            cancelButtonColor: '#6b7280',  // Tailwind gray-500
            confirmButtonText: 'Yes, complete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                processSale();
            }
        });
    }

    // Sends the cart data to the server
    function processSale() {
        Swal.fire({
            title: 'Processing...',
            text: 'Please wait.',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        fetch('pos.php', {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/json',
                'Accept': 'application/json' 
            },
            body: JSON.stringify({
                cart: cart,
                discount: discountPercent 
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: data.message,
                    confirmButtonColor: '#af6223'
                }).then(() => {
                    location.reload(); 
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: data.message,
                    confirmButtonColor: '#af6223'
                });
            }
        })
        .catch(error => {
            console.error('Fetch Error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Network Error',
                text: 'Could not complete the sale. Please check connection.',
                confirmButtonColor: '#af6223'
            });
        });
    }

    // Attach click handlers to product cards
    document.querySelectorAll('.product-card').forEach(card => {
        card.addEventListener('click', () => window.addToCart(card));
    });

    const searchHandler = () => { 
        const searchTerm = searchInput.value.toLowerCase();
        const searchType = searchTypeSelect.value; 
        let itemsFound = 0;

        if (!productListContainer) return; // Guard clause

        // Updated Selector to match Tailwind layout
        productListContainer.querySelectorAll('.col-product[data-product-name]').forEach(col => {
            let dataToSearch = '';
            
            if (searchType === 'name') {
                dataToSearch = col.dataset.productName || '';
            } else { 
                dataToSearch = col.dataset.productCode || '';
            }

            if (dataToSearch.includes(searchTerm)) {
                col.style.display = '';
                itemsFound++;
            } else {
                col.style.display = 'none';
            }
        });

        if (noResultsMessage) {
            if (itemsFound === 0 && (productListContainer.querySelectorAll('.col-product').length > 0)) {
                noResultsMessage.classList.remove('hidden');
            } else {
                noResultsMessage.classList.add('hidden');
            }
        }
    };

    if (searchInput && searchTypeSelect && productListContainer) {
        searchInput.addEventListener('keyup', searchHandler);
        searchTypeSelect.addEventListener('change', searchHandler); 
    }

    function getSortableValue(col, sortBy) {
        let value = col.dataset[sortBy]; 
        switch (sortBy) {
            case 'productName': return value || ''; 
            case 'productPrice':
            case 'productStock':
            case 'productCode': return parseFloat(value) || 0; 
            default: return value || '';
        }
    }

    function sortProducts() {
        if (!sortTypeSelect || !productListContainer) return;

        const sortValue = sortTypeSelect.value; 
        const [sortKey, sortDir] = sortValue.split('-'); 
        
        const sortByMap = {
            'name': 'productName',
            'price': 'productPrice',
            'stock': 'productStock',
            'code': 'productCode'
        };
        const sortBy = sortByMap[sortKey]; 

        if (!sortBy) return; 

        const allProductCols = Array.from(productListContainer.querySelectorAll('.col-product[data-product-name]'));

        allProductCols.sort((a, b) => {
            const valA = getSortableValue(a, sortBy);
            const valB = getSortableValue(b, sortBy);

            if (valA < valB) return sortDir === 'asc' ? -1 : 1;
            if (valA > valB) return sortDir === 'asc' ? 1 : -1;
            return 0; 
        });

        allProductCols.forEach(col => {
            productListContainer.appendChild(col);
        });
    }

    if (sortTypeSelect) {
        sortTypeSelect.addEventListener('change', sortProducts);
    }

    sortProducts();

    // --- Modal Logic (No Bootstrap) ---
    if (applyDiscountBtn) {
        applyDiscountBtn.addEventListener('click', () => {
            let percent = parseFloat(discountInput.value);
            if (isNaN(percent) || percent < 0) percent = 0;
            else if (percent > 100) percent = 100;
            
            discountPercent = percent;
            discountInput.value = percent; 
            
            renderCart();
            safeToggleModal('discountModal'); 
        });
    }
    
    if (removeDiscountBtn) {
        removeDiscountBtn.addEventListener('click', () => {
            discountPercent = 0;
            discountInput.value = ''; 
            renderCart();
            safeToggleModal('discountModal'); 
        });
    }
});