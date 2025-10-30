document.addEventListener('DOMContentLoaded', () => {
    let cart = []; // Stores cart items as { id, name, price, quantity, maxStock }

    // DOM References
    const orderItemsContainer = document.getElementById('order-items-container');
    const totalPriceEl = document.getElementById('total-price');
    const payButton = document.getElementById('pay-button');
    const clearButton = document.getElementById('clear-button');
    const productListContainer = document.getElementById('product-list');
    
    // --- ADDED: Search Bar References ---
    const searchInput = document.getElementById('product-search');
    const noResultsMessage = document.getElementById('no-results-message');
    // ------------------------------------

    // --- Core Cart Functions ---

    /**
     * Called when a product card is clicked.
     * Adds item to cart or increments quantity, respecting stock limits.
     */
    window.addToCart = function(cardElement) {
        const productId = parseInt(cardElement.dataset.id);
        const name = cardElement.dataset.name;
        const price = parseFloat(cardElement.dataset.price);
        const maxStock = parseInt(cardElement.dataset.stock);

        const existingItem = cart.find(item => item.id === productId);

        if (existingItem) {
            // Check if adding one more exceeds stock
            if (existingItem.quantity + 1 > maxStock) {
                Swal.fire('Out of Stock', `Cannot add more ${name}. Only ${maxStock} available.`, 'warning');
                return;
            }
            existingItem.quantity++;
        } else {
            // Check stock before adding new item
            if (1 > maxStock) {
                Swal.fire('Out of Stock', `Cannot add ${name}. This item is out of stock.`, 'warning');
                return;
            }
            cart.push({ id: productId, name: name, price: price, quantity: 1, maxStock: maxStock });
        }
        renderCart();
    }

    /**
     * Updates an item's quantity or removes it from the cart.
     */
    window.updateQuantity = function(productId, change) {
        const item = cart.find(item => item.id === productId);
        if (!item) return;

        // Check stock limit when increasing quantity
        if (change > 0 && (item.quantity + change > item.maxStock)) {
            Swal.fire('Out of Stock', `Cannot add more ${item.name}. Only ${item.maxStock} available.`, 'warning');
            return;
        }

        // Adjust quantity
        item.quantity += change;

        // Remove item if quantity drops to 0 or below
        if (item.quantity <= 0) {
            cart = cart.filter(item => item.id !== productId);
        }
        renderCart();
    }

    /**
     * Clears the cart and re-renders it.
     */
    if(clearButton) {
        clearButton.addEventListener('click', () => {
            cart = [];
            renderCart();
        });
    }

    /**
     * Re-draws the entire cart UI based on the `cart` array.
     */
    function renderCart() {
        // Clear previous items
        orderItemsContainer.innerHTML = '';

        if (cart.length === 0) {
            orderItemsContainer.innerHTML = '<p class="text-center text-muted mt-5">Select products to begin</p>';
            totalPriceEl.textContent = 'P0.00';
            payButton.disabled = true;
            return;
        }

        let total = 0;
        cart.forEach(item => {
            const itemTotal = item.price * item.quantity;
            total += itemTotal;
            
            const itemEl = document.createElement('div');
            itemEl.className = 'cart-item';
            // Note: Switched to addEventListener for buttons, removed inline onclick
            itemEl.innerHTML = `
                <div class="cart-item-details">
                    <strong>${item.name}</strong>
                    <div class="text-muted">${item.quantity} x P${item.price.toFixed(2)} = P${itemTotal.toFixed(2)}</div>
                </div>
                <div class="cart-item-controls">
                    <button class="btn btn-outline-secondary btn-sm btn-dec" data-id="${item.id}">-</button>
                    <span class="mx-2">${item.quantity}</span>
                    <button class="btn btn-outline-secondary btn-sm btn-inc" data-id="${item.id}">+</button>
                </div>
            `;
            orderItemsContainer.appendChild(itemEl);
        });

        // Add event listeners to new buttons
        orderItemsContainer.querySelectorAll('.btn-dec').forEach(btn => {
            btn.addEventListener('click', () => updateQuantity(parseInt(btn.dataset.id), -1));
        });
        orderItemsContainer.querySelectorAll('.btn-inc').forEach(btn => {
            btn.addEventListener('click', () => updateQuantity(parseInt(btn.dataset.id), 1));
        });

        // Update total price and enable pay button
        totalPriceEl.textContent = `P${total.toFixed(2)}`;
        payButton.disabled = false;
    }

    /**
     * Handles the 'Complete Sale' button click.
     */
    if(payButton) {
        payButton.addEventListener('click', () => {
            if (cart.length === 0) return;
            
            // Confirm the sale with the user
            Swal.fire({
                title: 'Confirm Sale',
                text: `Total amount is ${totalPriceEl.textContent}. Proceed?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#198754',
                confirmButtonText: 'Yes, complete it!'
            }).then((result) => {
                // If user confirmed, proceed to payment
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
        // Show a loading popup
        Swal.fire({
            title: 'Processing Sale...',
            text: 'Please wait.',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        // Send the cart data to the PHP backend
        fetch('pos.php', {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/json',
                'Accept': 'application/json' 
            },
            body: JSON.stringify(cart)
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                // Show success message and reload the page
                Swal.fire('Success!', data.message, 'success')
                .then(() => {
                    // Reload the page to show new stock levels
                    location.reload(); 
                });
            } else {
                // Show the specific error message from the server
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

    // --- ADDED: Search Filter Logic ---
    if (searchInput) {
        searchInput.addEventListener('keyup', (e) => {
            const searchTerm = e.target.value.toLowerCase();
            let itemsFound = 0;

            // Loop through all product columns in the grid
            productListContainer.querySelectorAll('.col[data-product-name]').forEach(col => {
                const productName = col.dataset.productName; // Get name from data attribute

                // Use startsWith() as requested
                if (productName.startsWith(searchTerm)) {
                    col.style.display = ''; // Show column
                    itemsFound++;
                } else {
                    col.style.display = 'none'; // Hide column
                }
            });

            // Show or hide the 'no results' message
            if (noResultsMessage) {
                noResultsMessage.style.display = itemsFound === 0 ? '' : 'none';
            }
        });
    }
});