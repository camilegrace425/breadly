document.addEventListener('DOMContentLoaded', () => {

    // --- Search Filter Logic ---
    const searchInput = document.getElementById('recipe-product-search');
    const productListContainer = document.getElementById('recipe-product-list');
    const noResultsMessage = document.getElementById('recipe-no-results');

    if (searchInput && productListContainer && noResultsMessage) {
        searchInput.addEventListener('keyup', (e) => {
            const searchTerm = e.target.value.toLowerCase();
            let itemsFound = 0;

            // FIX 1: Updated selector to match the PHP class '.col-product'
            productListContainer.querySelectorAll('.col-product').forEach(col => {
                const productName = col.dataset.productName ? col.dataset.productName.toLowerCase() : '';

                if (productName.includes(searchTerm)) {
                    col.style.display = ''; // Show
                    itemsFound++;
                } else {
                    col.style.display = 'none'; // Hide
                }
            });

            // FIX 2: Properly toggle Tailwind 'hidden' class
            if (itemsFound === 0) {
                noResultsMessage.classList.remove('hidden');
            } else {
                noResultsMessage.classList.add('hidden');
            }
        });
    }

    // --- Mobile Modal Logic ---
    const recipeModal = document.getElementById('recipeModal');
    const recipeModalTitle = document.getElementById('recipeModalLabel');
    const recipeModalBody = document.getElementById('recipeModalBody');

    // Function to show a loading spinner
    const showModalLoading = () => {
        if (recipeModalBody) {
            recipeModalBody.innerHTML = `
                <div class="flex justify-center p-10">
                    <div class="spinner"></div>
                </div>`;
        }
    };

    // Function to fetch and display recipe content
    const loadRecipeIntoModal = async (href, productName) => {
        if (!recipeModal || !recipeModalTitle || !recipeModalBody) return;

        recipeModalTitle.textContent = 'Loading ' + productName + '...';
        showModalLoading();
        
        // Use the global custom modal function
        if(window.openModal) window.openModal('recipeModal');

        try {
            const response = await fetch(href);
            if (!response.ok) throw new Error('Network response was not ok');
            
            const htmlText = await response.text();
            const parser = new DOMParser();
            const doc = parser.parseFromString(htmlText, 'text/html');
            const recipeContent = doc.querySelector('.recipe-content-col');
            
            if (recipeContent) {
                recipeModalTitle.textContent = productName;
                recipeModalBody.innerHTML = recipeContent.innerHTML;
                
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
                    await fetch(deleteForm.action, {
                        method: 'POST',
                        body: new FormData(deleteForm),
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });

                    // Close Delete Modal
                    if(window.closeModal) window.closeModal('deleteRecipeItemModal');

                    // Reload Recipe Modal content to remove the deleted item from view
                    const currentUrl = deleteForm.action;
                    const productName = recipeModalTitle.textContent;
                    loadRecipeIntoModal(currentUrl, productName);
                    
                } catch (err) {
                    console.error("Delete error", err);
                }
            }
        });
    }
});