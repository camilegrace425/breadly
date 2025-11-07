document.addEventListener('DOMContentLoaded', () => {
    let cart = []; // Stores cart items as { id, name, price, quantity, maxStock }
    let discountPercent = 0; // Global state for discount

    // DOM References
    const orderItemsContainer = document.getElementById('order-items-container');
    const totalPriceEl = document.getElementById('total-price');
    const payButton = document.getElementById('pay-button');
    const clearButton = document.getElementById('clear-button');
    const productListContainer = document.getElementById('product-list');
    
    const searchInput = document.getElementById('product-search');
    const searchTypeSelect = document.getElementById('search-type'); // Get the search dropdown
    const sortTypeSelect = document.getElementById('sort-type');   // NEW: Get the sort dropdown
    const noResultsMessage = document.getElementById('no-results-message');

    // --- Discount DOM References ---
    const discountModalEl = document.getElementById('discountModal');
    const discountModal = discountModalEl ? new bootstrap.Modal(discountModalEl) : null;
    const discountInput = document.getElementById('discount-input');
    const applyDiscountBtn = document.getElementById('apply-discount-btn-modal'); 
    const removeDiscountBtn = document.getElementById('remove-discount-btn-modal'); 
    
    const subtotalLine = document.getElementById('subtotal-line');
    const discountLine = document.getElementById('discount-line');
    const subtotalPriceEl = document.getElementById('subtotal-price');
    const discountAmountEl = document.getElementById('discount-amount');
    const discountLineText = document.querySelector('#discount-line .text-discount');
    // ------------------------------------

    /**
     * Called when a product card is clicked.
     */
    window.addToCart = function(cardElement) {
        const productId = parseInt(cardElement.dataset.id);
        const name = cardElement.dataset.name;
        const price = parseFloat(cardElement.dataset.price);
        const maxStock = parseInt(cardElement.dataset.stock);

        const existingItem = cart.find(item => item.id === productId);

        if (existingItem) {
            if (existingItem.quantity + 1 > maxStock) {
                Swal.fire('Out of Stock', `Cannot add more ${name}. Only ${maxStock} available.`, 'warning');
                return;
            }
            setQuantity(productId, existingItem.quantity + 1);
        } else {
            if (1 > maxStock) {
                Swal.fire('Out of Stock', `Cannot add ${name}. This item is out of stock.`, 'warning');
                return;
            }
            cart.push({ id: productId, name: name, price: price, quantity: 1, maxStock: maxStock });
            renderCart();
        }
    }

    /**
     * Sets an item's quantity to a specific value.
     */
    function setQuantity(productId, newQuantity) {
        const item = cart.find(item => item.id === productId);
        if (!item) return;

        if (isNaN(newQuantity) || newQuantity < 0) {
            newQuantity = 0;
        }
        
        if (newQuantity === 0) {
            cart = cart.filter(item => item.id !== productId);
        } else if (newQuantity > item.maxStock) {
            newQuantity = item.maxStock;
            Swal.fire('Stock Limit', `Only ${item.maxStock} ${item.name} available.`, 'warning');
            item.quantity = newQuantity;
        } else {
            item.quantity = newQuantity;
        }
        renderCart();
    }

    /**
     * Updates an item's quantity (from + / - buttons).
     */
    window.updateQuantity = function(productId, change) {
        const item = cart.find(item => item.id === productId);
        if (!item) return;
        
        const newQuantity = item.quantity + change;
        setQuantity(productId, newQuantity);
    }

    if(clearButton) {
        clearButton.addEventListener('click', () => {
            cart = [];
            
            // --- FIX: EXPLICITLY RESET DISCOUNT ---
            discountPercent = 0; // Set the variable to 0
            if (discountInput) discountInput.value = ''; // Clear the modal input
            
            renderCart(); // This will now call renderCart to show the P0.00 state
        });
    }


    function renderCart() {
        orderItemsContainer.innerHTML = '';
        let subTotal = 0;
        let finalTotal = 0;
        let discountAmount = 0;

        if (cart.length === 0) {
            orderItemsContainer.innerHTML = '<p class="text-center text-muted mt-5">Select products to begin</p>';
            totalPriceEl.textContent = 'P0.00';
            payButton.disabled = true;
            
            subTotal = 0;
            finalTotal = 0;
            discountAmount = 0;
            
        } else {
            cart.forEach(item => {
                const itemTotal = item.price * item.quantity;
                subTotal += itemTotal;
                
                const itemEl = document.createElement('div');
                itemEl.className = 'cart-item';
                
                // --- MODIFICATION IS HERE ---
                itemEl.innerHTML = `
                <div class="cart-item-details">
                    <div class="text-muted">(ID: ${item.id})</div> <strong>${item.name}</strong>
                    <div class="text-muted">${item.quantity} x P${item.price.toFixed(2)} = P${itemTotal.toFixed(2)}</div>
                </div>
                <div class="cart-item-controls">
                    <button class="btn btn-outline-secondary btn-sm btn-dec" data-id="${item.id}">-</button>
                    <input type="number" class="form-control form-control-sm cart-quantity-input mx-1" 
                           value="${item.quantity}" data-id="${item.id}" min="0" max="${item.maxStock}">
                    <button class="btn btn-outline-secondary btn-sm btn-inc" data-id="${item.id}">+</button>
                    <button class="btn btn-outline-danger btn-sm btn-remove" data-id="${item.id}"><i class="bi bi-dash"></i></button>
                </div>
            `;
                // --- END MODIFICATION ---
                
                orderItemsContainer.appendChild(itemEl);
            });

            // Add event listeners to new buttons
            orderItemsContainer.querySelectorAll('.btn-dec').forEach(btn => {
                btn.addEventListener('click', () => updateQuantity(parseInt(btn.dataset.id), -1));
            });
            orderItemsContainer.querySelectorAll('.btn-inc').forEach(btn => {
                btn.addEventListener('click', () => updateQuantity(parseInt(btn.dataset.id), 1));
            });

            // --- ADD THIS BLOCK ---
            orderItemsContainer.querySelectorAll('.btn-remove').forEach(btn => {
                btn.addEventListener('click', () => {
                    const productId = parseInt(btn.dataset.id);
                    setQuantity(productId, 0); // Calling setQuantity with 0 removes the item
                });
            });
            // --- END OF ADDED BLOCK ---

            orderItemsContainer.querySelectorAll('.cart-quantity-input').forEach(input => {
                input.addEventListener('change', (e) => {
                    const newQty = parseInt(e.target.value, 10);
                    const productId = parseInt(e.target.dataset.id, 10);
                    setQuantity(productId, newQty);
                });
                input.addEventListener('keydown', (e) => {
                    if (['e', '+', '-', '.'].includes(e.key)) {
                        e.preventDefault();
                    }
                });
            });

            // --- Calculate discount ---
            discountAmount = subTotal * (discountPercent / 100);
            finalTotal = subTotal - discountAmount;
            payButton.disabled = false;
        }
        
        // Always show all summary lines
        subtotalLine.style.display = 'flex';
        discountLine.style.display = 'flex';
        
        // Update the text content
        subtotalPriceEl.textContent = `P${subTotal.toFixed(2)}`;
        discountLineText.textContent = `Discount (${discountPercent}%):`;
        discountAmountEl.textContent = `-P${discountAmount.toFixed(2)}`;
        
        // Update total price
        totalPriceEl.textContent = `P${finalTotal.toFixed(2)}`;
    }

    /**
     * Handles the 'Complete Sale' button click.
     */
    if(payButton) {
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
                    processSale();
                }
            });
        });
    }

    /**
     * Sends the cart data to the server (pos.php) to be processed.
     */
    function processSale() {
        Swal.fire({
            title: 'Processing Sale...',
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
                Swal.fire('Success!', data.message, 'success')
                .then(() => {
                    location.reload(); 
                });
            } else {
                Swal.fire('Error!', data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Fetch Error:', error);
            Swal.fire('Network Error', 'Could not complete the sale. Please check connection.', 'error');
        });
    }

    // Attach click handlers to product cards
    document.querySelectorAll('.product-card').forEach(card => {
        card.addEventListener('click', () => addToCart(card));
    });

    // --- MODIFIED: Search Filter Logic ---
    const searchHandler = () => { // Create a reusable handler
        const searchTerm = searchInput.value.toLowerCase();
        const searchType = searchTypeSelect.value; // Get selected search type: 'name' or 'code'
        let itemsFound = 0;

        // Loop through all .col elements
        productListContainer.querySelectorAll('.col[data-product-name]').forEach(col => {
            let dataToSearch = '';
            
            // Check which data attribute to use
            if (searchType === 'name') {
                dataToSearch = col.dataset.productName || '';
            } else { // searchType === 'code'
                dataToSearch = col.dataset.productCode || '';
            }

            // Use startsWith for the "search as you type" feel
            if (dataToSearch.startsWith(searchTerm)) {
                col.style.display = '';
                itemsFound++;
            } else {
                col.style.display = 'none';
            }
        });

        if (noResultsMessage) {
            noResultsMessage.style.display = itemsFound === 0 ? '' : 'none';
        }
    };

    if (searchInput && searchTypeSelect && productListContainer) {
        // Add listeners to *both* the input and the select dropdown
        searchInput.addEventListener('keyup', searchHandler);
        searchTypeSelect.addEventListener('change', searchHandler); // Re-filter when dropdown changes
    }
    // --- END MODIFIED: Search Filter Logic ---


    // --- ::: NEW SORTING LOGIC ::: ---
    
    /**
     * Gets a comparable value from a product card's data attribute.
     * @param {HTMLElement} col - The .col element
     * @param {string} sortBy - The data attribute key (e.g., 'productPrice')
     */
    function getSortableValue(col, sortBy) {
        let value = col.dataset[sortBy]; // e.g., col.dataset['productPrice']
        
        // Handle different data types
        switch (sortBy) {
            case 'productName':
                return value || ''; // Already a string
            case 'productPrice':
            case 'productStock':
            case 'productCode':
                return parseFloat(value) || 0; // Convert to number
            default:
                return value || '';
        }
    }

    /**
     * Sorts the product cards in the grid based on the sort dropdown.
     */
    function sortProducts() {
        if (!sortTypeSelect || !productListContainer) return;

        const sortValue = sortTypeSelect.value; // e.g., "name-asc"
        const [sortKey, sortDir] = sortValue.split('-'); // ["name", "asc"]
        
        // Map the simple key to the full dataset attribute name
        const sortByMap = {
            'name': 'productName',
            'price': 'productPrice',
            'stock': 'productStock',
            'code': 'productCode'
        };
        const sortBy = sortByMap[sortKey]; // e.g., "productName"

        if (!sortBy) return; // Exit if sortKey is invalid

        // Get all .col elements from the container
        const allProductCols = Array.from(productListContainer.querySelectorAll('.col[data-product-name]'));

        // Sort the array
        allProductCols.sort((a, b) => {
            const valA = getSortableValue(a, sortBy);
            const valB = getSortableValue(b, sortBy);

            if (valA < valB) {
                return sortDir === 'asc' ? -1 : 1;
            }
            if (valA > valB) {
                return sortDir === 'asc' ? 1 : -1;
            }
            return 0; // They are equal
        });

        // Re-append the sorted elements back into the container
        // This physically moves the DOM elements
        allProductCols.forEach(col => {
            productListContainer.appendChild(col);
        });
    }

    // Add event listener for the sort dropdown
    if (sortTypeSelect) {
        sortTypeSelect.addEventListener('change', sortProducts);
    }

    // Apply initial default sort on page load
    sortProducts();
    
    // --- ::: END NEW SORTING LOGIC ::: ---


    // --- New Discount Modal Event Listeners ---
    
    // Listener for the "Apply" button INSIDE the modal
    if (applyDiscountBtn && discountModal) {
        applyDiscountBtn.addEventListener('click', () => {
            let percent = parseFloat(discountInput.value);
            if (isNaN(percent) || percent < 0) {
                percent = 0;
            } else if (percent > 100) {
                percent = 100;
            }
            
            discountPercent = percent;
            discountInput.value = percent; 
            
            renderCart();
            discountModal.hide(); 
        });
    }
    
    // Listener for the "No Discount" button INSIDE the modal
    if (removeDiscountBtn && discountModal) {
        removeDiscountBtn.addEventListener('click', () => {
            // 1. Set the discount value to 0
            discountPercent = 0;
            
            // 2. Clear the input box
            discountInput.value = ''; 
            
            // 3. Re-calculate the total and update the UI
            renderCart();
            
            // 4. Hide the modal
            discountModal.hide(); 
        });
    }
    
    // (Optional) Set the input value when modal is shown
    if (discountModalEl) {
        discountModalEl.addEventListener('show.bs.modal', () => {
            if (discountPercent === 0) {
                discountInput.value = '';
            } else {
                discountInput.value = discountPercent;
            }
            setTimeout(() => {
                if(discountInput) discountInput.focus();
            }, 100);
        });
    }

});