document.addEventListener('DOMContentLoaded', () => {
    const style = document.createElement('style');
    style.innerHTML = `
        /* Product Card Click Effect */
        .card-clicked {
            transform: scale(0.95);
            transition: transform 0.1s ease;
        }

        /* Cart Item Slide In (Enter) */
        @keyframes slideInRight {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        .cart-item-enter {
            animation: slideInRight 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
        }

        /* Cart Item Slide Out (Exit) */
        @keyframes slideOutLeft {
            to { opacity: 0; transform: translateX(-30px); margin-bottom: -50px; }
        }
        .cart-item-exit {
            animation: slideOutLeft 0.3s ease forwards !important;
            pointer-events: none;
        }

        /* Price Pulse */
        @keyframes pulseGreen {
            0% { transform: scale(1); color: inherit; }
            50% { transform: scale(1.2); color: #16a34a; text-shadow: 0 0 10px rgba(22, 163, 74, 0.3); }
            100% { transform: scale(1); color: inherit; }
        }
        .pulse-price {
            animation: pulseGreen 0.3s ease-out;
        }

        /* Search Results Stagger */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .search-enter {
            animation: fadeUp 0.4s ease-out forwards;
        }
    `;
    document.head.appendChild(style);

    let cart = []; 
    let discountPercent = 0; 
    let previousTotal = 0; // To track price changes for animation

    // DOM References
    const orderItemsContainer = document.getElementById('order-items-container');
    const totalPriceEl = document.getElementById('total-price');
    const payButton = document.getElementById('pay-button');
    const clearButton = document.getElementById('clear-button');
    
    const productListWrapper = document.getElementById('product-list');
    const productListContainer = productListWrapper ? productListWrapper.querySelector('.grid') || productListWrapper : null;

    const searchInput = document.getElementById('product-search');
    const searchTypeSelect = document.getElementById('search-type'); 
    const sortTypeSelect = document.getElementById('sort-type');   
    const noResultsMessage = document.getElementById('no-results-message');

    // Discount References
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
                const isHidden = modal.classList.contains('hidden');
                modal.setAttribute('aria-hidden', isHidden ? 'true' : 'false');
            }
        }
    }

    // --- Helper: Animate Price Change ---
    function animatePriceChange(currentTotal) {
        if (currentTotal !== previousTotal && totalPriceEl) {
            // Remove class to reset animation
            totalPriceEl.classList.remove('pulse-price');
            void totalPriceEl.offsetWidth; // Trigger reflow
            totalPriceEl.classList.add('pulse-price');
            previousTotal = currentTotal;
        }
    }

     // Called when a product card is clicked.
    window.addToCart = function(cardElement) {
        // **ANIMATION**: Visual feedback on card click
        cardElement.classList.add('card-clicked');
        setTimeout(() => cardElement.classList.remove('card-clicked'), 150);

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
            renderCart(true); // **Pass true to indicate a new item was added**
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
            // Item removed via logic (e.g. typing 0)
            cart = cart.filter(item => item.id !== productId);
            renderCart();
        } else if (newQuantity > item.maxStock) {
            newQuantity = item.maxStock;
            Swal.fire({
                icon: 'warning',
                title: 'Stock Limit',
                text: `Only ${item.maxStock} ${item.name} available.`,
                confirmButtonColor: '#af6223'
            });
            item.quantity = newQuantity;
            renderCart();
        } else {
            item.quantity = newQuantity;
            renderCart();
        }
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
            if(cart.length > 0) {
                // **ANIMATION**: Fade out container content before clearing
                orderItemsContainer.style.opacity = '0';
                orderItemsContainer.style.transition = 'opacity 0.2s';
                
                setTimeout(() => {
                    cart = [];
                    discountPercent = 0; 
                    if (discountInput) discountInput.value = ''; 
                    renderCart(); 
                    orderItemsContainer.style.opacity = '1'; // Fade back in (empty state)
                }, 200);
            }
        });
    }

    function renderCart(isNewItem = false) {
        const scrollTop = orderItemsContainer.scrollTop;

        orderItemsContainer.innerHTML = '';
        let subTotal = 0;
        let finalTotal = 0;
        let discountAmount = 0;

        if (cart.length === 0) {
            orderItemsContainer.innerHTML = `
                <div class="h-full flex flex-col items-center justify-center text-gray-400 opacity-60 py-10 cart-item-enter">
                    <i class='bx bx-basket text-6xl mb-2'></i>
                    <p>Select products to begin</p>
                </div>`;
            totalPriceEl.textContent = 'P0.00';
            payButton.disabled = true;
            payButton.classList.add('opacity-50', 'cursor-not-allowed');
            
            // Reset Mobile UI
            updateMobileUI(0, 0, true);
            
        } else {
            cart.forEach((item, index) => {
                const itemTotal = item.price * item.quantity;
                subTotal += itemTotal;
                
                const itemEl = document.createElement('div');
                itemEl.className = 'flex justify-between items-center bg-white p-3 rounded-xl border border-gray-100 shadow-sm hover:shadow-md transition-shadow mb-2';
                
                // **ANIMATION**: Only animate the LAST item if it was just added to prevent flashing
                if (isNewItem && index === cart.length - 1) {
                    itemEl.classList.add('cart-item-enter');
                }

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

            // Restore Scroll
            orderItemsContainer.scrollTop = scrollTop;
            if (isNewItem) {
                // Scroll to bottom if new item added
                orderItemsContainer.scrollTop = orderItemsContainer.scrollHeight;
            }

            attachCartListeners(orderItemsContainer);

            // --- Calculate discount ---
            discountAmount = subTotal * (discountPercent / 100);
            finalTotal = subTotal - discountAmount;
            
            payButton.disabled = false;
            payButton.classList.remove('opacity-50', 'cursor-not-allowed');
            
            updateMobileUI(finalTotal, subTotal, false, cart.length, discountAmount);
        }
        
        // Update Desktop Summary
        updateDesktopSummary(subTotal, discountAmount, finalTotal);
        
        // **ANIMATION**: Trigger Price Pulse
        animatePriceChange(finalTotal);
    }

    function attachCartListeners(container) {
        container.querySelectorAll('.btn-dec').forEach(btn => {
            btn.addEventListener('click', () => window.updateQuantity(parseInt(btn.dataset.id), -1));
        });
        container.querySelectorAll('.btn-inc').forEach(btn => {
            btn.addEventListener('click', () => window.updateQuantity(parseInt(btn.dataset.id), 1));
        });
        
        // **ANIMATION**: Smooth Remove
        container.querySelectorAll('.btn-remove').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const row = btn.closest('.flex.justify-between'); // Get the row container
                const id = parseInt(btn.dataset.id);
                
                // Add exit animation class
                row.classList.add('cart-item-exit');
                
                // Wait for animation to finish before updating data
                setTimeout(() => {
                    window.setQuantity(id, 0);
                }, 250); // Slightly faster than CSS time to feel snappy
            });
        });

        container.querySelectorAll('.cart-quantity-input').forEach(input => {
            input.addEventListener('change', (e) => {
                const newQty = parseInt(e.target.value, 10);
                const productId = parseInt(e.target.dataset.id, 10);
                window.setQuantity(productId, newQty);
            });
            input.addEventListener('click', (e) => e.target.select()); 
        });
    }
    
    // UI Update Helpers
    function updateMobileUI(total, subTotal, isEmpty, count = 0, discount = 0) {
        const mobileTotal = document.getElementById('mobile-cart-total');
        const mobileCount = document.getElementById('mobile-cart-count');
        const mobileBtn = document.getElementById('pay-button-mobile');
        const mobileSub = document.getElementById('subtotal-price-mobile');
        const mobileDisc = document.getElementById('discount-amount-mobile');
        const mobileTotalFull = document.getElementById('total-price-mobile');

        if(isEmpty) {
            if(mobileTotal) mobileTotal.textContent = 'P0.00';
            if(mobileCount) mobileCount.textContent = '0 Items';
            if(mobileBtn) mobileBtn.disabled = true;
        } else {
             if(mobileTotal) mobileTotal.textContent = `P${total.toFixed(2)}`;
             if(mobileCount) mobileCount.textContent = `${count} Item${count !== 1 ? 's' : ''}`;
             if(mobileBtn) {
                mobileBtn.disabled = false;
                mobileBtn.textContent = `Pay P${total.toFixed(2)}`;
             }
             if(mobileSub) {
                mobileSub.textContent = `P${subTotal.toFixed(2)}`;
                const subLine = document.getElementById('subtotal-line-mobile');
                if(subLine) subLine.style.display = 'flex';
             }
             if(mobileDisc) {
                 mobileDisc.textContent = `-P${discount.toFixed(2)}`;
                 const discLine = document.getElementById('discount-line-mobile');
                 if(discLine) discLine.style.display = 'flex';
             }
             if(mobileTotalFull) mobileTotalFull.textContent = `P${total.toFixed(2)}`;
        }
    }

    function updateDesktopSummary(subTotal, discountAmount, finalTotal) {
        if (subtotalLine) subtotalLine.classList.remove('hidden');
        if (subtotalLine) subtotalLine.style.display = 'flex';
        
        if (discountLine) discountLine.classList.remove('hidden');
        if (discountLine) discountLine.style.display = 'flex';
        
        if (subtotalPriceEl) subtotalPriceEl.textContent = `P${subTotal.toFixed(2)}`;
        if (discountLineText) discountLineText.innerHTML = `<i class='bx bxs-discount'></i> Discount (${discountPercent}%):`;
        if (discountAmountEl) discountAmountEl.textContent = `-P${discountAmount.toFixed(2)}`;
        
        if (totalPriceEl) totalPriceEl.textContent = `P${finalTotal.toFixed(2)}`;
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
            confirmButtonColor: '#15803d', 
            cancelButtonColor: '#6b7280',  
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

    // **ANIMATION**: Enhanced Search Handler
    const searchHandler = () => { 
        const searchTerm = searchInput.value.toLowerCase();
        const searchType = searchTypeSelect.value; 
        let itemsFound = 0;

        if (!productListContainer) return; 

        // Get all items
        const cols = productListContainer.querySelectorAll('.col-product[data-product-name]');
        
        cols.forEach((col, index) => {
            let dataToSearch = '';
            
            if (searchType === 'name') {
                dataToSearch = col.dataset.productName || '';
            } else { 
                dataToSearch = col.dataset.productCode || '';
            }

            if (dataToSearch.includes(searchTerm)) {
                // If it was previously hidden, animate it in
                if (col.style.display === 'none') {
                    col.classList.remove('search-enter');
                    void col.offsetWidth; // Trigger reflow
                    col.classList.add('search-enter');
                    // Add slight delay based on index for "cascade" effect
                    col.style.animationDelay = `${(itemsFound % 10) * 0.05}s`;
                }
                
                col.style.display = '';
                itemsFound++;
            } else {
                col.style.display = 'none';
            }
        });

        if (noResultsMessage) {
            if (itemsFound === 0 && (productListContainer.querySelectorAll('.col-product').length > 0)) {
                noResultsMessage.classList.remove('hidden');
                // Simple fade for no results
                noResultsMessage.style.opacity = '0';
                setTimeout(() => { 
                    noResultsMessage.style.transition = 'opacity 0.3s';
                    noResultsMessage.style.opacity = '1'; 
                }, 10);
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

        // **ANIMATION**: Fade out grid slightly while sorting
        productListContainer.style.opacity = '0.5';

        setTimeout(() => {
            allProductCols.sort((a, b) => {
                const valA = getSortableValue(a, sortBy);
                const valB = getSortableValue(b, sortBy);

                if (valA < valB) return sortDir === 'asc' ? -1 : 1;
                if (valA > valB) return sortDir === 'asc' ? 1 : -1;
                return 0; 
            });

            allProductCols.forEach(col => {
                productListContainer.appendChild(col);
                // Trigger animation on sorted items too
                col.classList.remove('search-enter');
                void col.offsetWidth;
                col.classList.add('search-enter');
                col.style.animationDelay = '0s'; // No stagger for sorting, just fade
            });
            
            // Fade grid back in
            productListContainer.style.opacity = '1';
            productListContainer.style.transition = 'opacity 0.2s';
        }, 150);
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