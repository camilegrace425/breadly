document.addEventListener('DOMContentLoaded', () => {
    const style = document.createElement('style');
    style.innerHTML = `
        /* Search Results Stagger (Same as POS) */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .search-enter {
            animation: fadeUp 0.4s ease-out forwards;
        }

        /* Modal Content Fade In */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(5px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .modal-content-fade {
            animation: fadeIn 0.3s ease-out forwards;
        }

        /* Spinner Pulse */
        @keyframes spin-pulse {
            0% { transform: rotate(0deg); opacity: 1; }
            50% { opacity: 0.6; }
            100% { transform: rotate(360deg); opacity: 1; }
        }
        .spinner-enhanced {
            border: 3px solid rgba(0, 0, 0, 0.1);
            border-left-color: #af6223; /* Breadly Brand Color */
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin-pulse 1s linear infinite;
        }
    `;
    document.head.appendChild(style);

    // ==========================================
    // 1. SEARCH FILTER LOGIC (Enhanced)
    // ==========================================
    const searchInput = document.getElementById('recipe-product-search');
    const productListContainer = document.getElementById('recipe-product-list');
    const noResultsMessage = document.getElementById('recipe-no-results');

    if (searchInput && productListContainer && noResultsMessage) {
        searchInput.addEventListener('keyup', (e) => {
            const searchTerm = e.target.value.toLowerCase();
            let itemsFound = 0;

            // FIX 1: Updated selector to match the PHP class '.col-product'
            productListContainer.querySelectorAll('.col-product').forEach((col, index) => {
                const productName = col.dataset.productName ? col.dataset.productName.toLowerCase() : '';
                const isMatch = productName.includes(searchTerm);

                if (isMatch) {
                    // **ANIMATION**: If it was hidden, animate it in
                    if (col.style.display === 'none') {
                        col.classList.remove('search-enter');
                        void col.offsetWidth; // Trigger reflow
                        col.classList.add('search-enter');
                        // Stagger effect: items load one after another
                        col.style.animationDelay = `${(itemsFound % 10) * 0.05}s`;
                    }
                    col.style.display = ''; // Show
                    itemsFound++;
                } else {
                    col.style.display = 'none'; // Hide
                }
            });

            // FIX 2: Properly toggle Tailwind 'hidden' class
            if (itemsFound === 0) {
                noResultsMessage.classList.remove('hidden');
                // Simple fade in for message
                noResultsMessage.style.opacity = '0';
                setTimeout(() => {
                    noResultsMessage.style.transition = 'opacity 0.3s';
                    noResultsMessage.style.opacity = '1';
                }, 10);
            } else {
                noResultsMessage.classList.add('hidden');
            }
        });
    }

    // ==========================================
    // 2. MOBILE MODAL LOGIC (Enhanced)
    // ==========================================
    const recipeModal = document.getElementById('recipeModal');
    const recipeModalTitle = document.getElementById('recipeModalLabel');
    const recipeModalBody = document.getElementById('recipeModalBody');

    // Function to show a loading spinner
    const showModalLoading = () => {
        if (recipeModalBody) {
            recipeModalBody.innerHTML = `
                <div class="flex flex-col items-center justify-center p-10 fade-in">
                    <div class="spinner-enhanced mb-3"></div>
                    <p class="text-gray-500 text-sm">Fetching ingredients...</p>
                </div>`;
        }
    };

    // Function to fetch and display recipe content
    const loadRecipeIntoModal = async (href, productName) => {
        if (!recipeModal || !recipeModalTitle || !recipeModalBody) return;

        recipeModalTitle.textContent = productName; // Set title immediately for better UX
        showModalLoading();
        
        // Use the global custom modal function
        if(window.openModal) window.openModal('recipeModal');

        try {
            // Artificial delay (optional - 300ms) to let the loading spinner be seen briefly
            // This prevents "flickering" if the server is too fast
            await new Promise(r => setTimeout(r, 300)); 

            const response = await fetch(href);
            if (!response.ok) throw new Error('Network response was not ok');
            
            const htmlText = await response.text();
            const parser = new DOMParser();
            const doc = parser.parseFromString(htmlText, 'text/html');
            const recipeContent = doc.querySelector('.recipe-content-col');
            
            if (recipeContent) {
                recipeModalBody.innerHTML = ''; // Clear spinner
                
                // **ANIMATION**: Wrap content in a fade div
                const wrapper = document.createElement('div');
                wrapper.className = 'modal-content-fade';
                wrapper.innerHTML = recipeContent.innerHTML;
                recipeModalBody.appendChild(wrapper);
                
                // Hijack form submissions to prevent full page reload on mobile
                hijackModalForms(recipeModalBody, href, productName);
            } else {
                recipeModalBody.innerHTML = '<p class="text-red-500 text-center p-4">Could not load recipe content.</p>';
            }

        } catch (error) {
            console.error('Fetch error:', error);
            recipeModalTitle.textContent = 'Error';
            recipeModalBody.innerHTML = '<p class="text-red-500 text-center p-4">Could not load recipe. Please check your connection.</p>';
        }
    };

    // Add click listeners to product links
    if (productListContainer) {
        // Selector matches the class added in PHP
        productListContainer.querySelectorAll('a.product-card').forEach(link => {
            link.addEventListener('click', (e) => {
                // Check if we are on mobile (using Tailwind's lg breakpoint logic roughly)
                if (window.innerWidth >= 1024) { 
                    return true; // Desktop: follow link normally
                }

                e.preventDefault(); 
                const href = link.href;
                const productName = link.dataset.productName || 'Recipe';
                loadRecipeIntoModal(href, productName);
            });
        });
    }

    // Function to hijack forms inside the modal to use AJAX
    const hijackModalForms = (modalBody, currentHref, productName) => {
        modalBody.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', async (e) => {
                e.preventDefault(); 
                
                const submitButton = form.querySelector('button[type="submit"]');
                const originalButtonText = submitButton ? submitButton.innerHTML : 'Submit';
                
                if(submitButton) {
                    // **ANIMATION**: Smoother loading state on button
                    submitButton.style.transition = 'all 0.2s';
                    submitButton.style.opacity = '0.8';
                    submitButton.innerHTML = `<div class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin inline-block mr-2"></div> Saving...`;
                    submitButton.disabled = true;
                }

                try {
                    await fetch(form.action, {
                        method: 'POST',
                        body: new FormData(form),
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    
                    // Reload modal content to show changes
                    loadRecipeIntoModal(currentHref, productName);

                } catch (error) {
                    console.error('Modal form submit error:', error);
                    if(submitButton) {
                        submitButton.innerHTML = originalButtonText;
                        submitButton.disabled = false;
                        submitButton.style.opacity = '1';
                    }
                }
            });
        });
    };

    // Handle Delete Form submission via AJAX if in mobile mode
    const deleteRecipeItemModal = document.getElementById('deleteRecipeItemModal');
    const deleteForm = deleteRecipeItemModal ? deleteRecipeItemModal.querySelector('form') : null;

    if (deleteForm) {
        deleteForm.addEventListener('submit', async (e) => {
            // Check if the recipe modal is visible (implies mobile mode)
            const isRecipeModalOpen = recipeModal && !recipeModal.classList.contains('hidden');
            
            if (isRecipeModalOpen) {
                e.preventDefault();
                
                try {
                    const submitBtn = deleteForm.querySelector('button[type="submit"]');
                    if(submitBtn) {
                        submitBtn.innerHTML = 'Deleting...';
                        submitBtn.disabled = true;
                    }

                    await fetch(deleteForm.action, {
                        method: 'POST',
                        body: new FormData(deleteForm),
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });

                    // Close Delete Modal
                    if(window.closeModal) window.closeModal('deleteRecipeItemModal');

                    // Reset btn for next time
                    if(submitBtn) {
                        submitBtn.innerHTML = 'Delete';
                        submitBtn.disabled = false;
                    }

                    // Reload Recipe Modal content to remove the deleted item from view
                    const currentUrl = deleteForm.action;
                    const productName = recipeModalTitle.textContent;
                    
                    // **ANIMATION**: Fade out old content before reloading
                    if(recipeModalBody) {
                        recipeModalBody.style.opacity = '0.5';
                        recipeModalBody.style.transition = 'opacity 0.2s';
                    }

                    setTimeout(() => {
                        if(recipeModalBody) recipeModalBody.style.opacity = '1';
                        loadRecipeIntoModal(currentUrl, productName);
                    }, 200);
                    
                } catch (err) {
                    console.error("Delete error", err);
                }
            }
        });
    }
});