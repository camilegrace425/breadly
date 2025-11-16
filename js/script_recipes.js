document.addEventListener('DOMContentLoaded', () => {

    // Populate Delete Recipe Item Modal
    const deleteRecipeItemModal = document.getElementById('deleteRecipeItemModal');
    if (deleteRecipeItemModal) {
        deleteRecipeItemModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            // Ensure button is not null (can happen if modal is opened via JS)
            if (!button) return; 

            const ingredientName = button.dataset.ingredientName;
            
            const modalTitle = deleteRecipeItemModal.querySelector('.modal-title');
            if(modalTitle) modalTitle.textContent = 'Remove ' + ingredientName + '?';
            
            // Check if the button and elements exist before setting values
            const recipeIdInput = document.getElementById('delete_recipe_id');
            const ingredientNameSpan = document.getElementById('delete_ingredient_name');
            
            if (button && recipeIdInput) {
                recipeIdInput.value = button.dataset.recipeId;
            }
            if (button && ingredientNameSpan) {
                ingredientNameSpan.textContent = ingredientName;
            }
        });
    }

    // --- ::: NEW Product Search Filter (Adapted from script_pos.js) ::: ---
    const searchInput = document.getElementById('recipe-product-search');
    const productListContainer = document.getElementById('recipe-product-list');
    const noResultsMessage = document.getElementById('recipe-no-results');

    if (searchInput && productListContainer && noResultsMessage) {
        searchInput.addEventListener('keyup', (e) => {
            const searchTerm = e.target.value.toLowerCase();
            let itemsFound = 0;

            // Loop through all product columns in the grid
            productListContainer.querySelectorAll('.col[data-product-name]').forEach(col => {
                const productName = col.dataset.productName; // Get name from data attribute

                if (productName.startsWith(searchTerm)) {
                    col.style.display = ''; // Show column
                    itemsFound++;
                } else {
                    col.style.display = 'none'; // Hide column
                }
            });

            // Show or hide the 'no results' message
            noResultsMessage.style.display = itemsFound === 0 ? '' : 'none';
        });
    }
    // --- ::: END NEW SCRIPT ::: ---


    // --- ::: NEW: Mobile Modal Logic for Recipe Page ::: ---
    const recipeModal = document.getElementById('recipeModal');
    const recipeModalInstance = recipeModal ? new bootstrap.Modal(recipeModal) : null;
    const recipeModalTitle = document.getElementById('recipeModalLabel');
    const recipeModalBody = document.getElementById('recipeModalBody');

    // Function to show a loading spinner in the modal
    const showModalLoading = () => {
        if (recipeModalBody) {
            recipeModalBody.innerHTML = `
                <div class="d-flex justify-content-center p-5">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>`;
        }
    };

    // Function to fetch and display recipe content
    const loadRecipeIntoModal = async (href, productName) => {
        if (!recipeModalInstance || !recipeModalTitle || !recipeModalBody) return;

        // Set title and show loading spinner
        recipeModalTitle.textContent = 'Loading ' + productName + '...';
        showModalLoading();
        recipeModalInstance.show();

        try {
            const response = await fetch(href);
            if (!response.ok) throw new Error('Network response was not ok');
            
            const htmlText = await response.text();
            
            // Parse the fetched HTML
            const parser = new DOMParser();
            const doc = parser.parseFromString(htmlText, 'text/html');
            
            // Find the recipe content column in the fetched HTML
            const recipeContent = doc.querySelector('.recipe-content-col');
            
            if (recipeContent) {
                recipeModalTitle.textContent = productName;
                recipeModalBody.innerHTML = recipeContent.innerHTML;
                
                // --- IMPORTANT: Hijack form submissions ---
                hijackModalForms(recipeModalBody, href, productName);
            } else {
                recipeModalBody.innerHTML = '<p class="text-danger">Could not load recipe content.</p>';
            }

        } catch (error) {
            console.error('Fetch error:', error);
            recipeModalTitle.textContent = 'Error';
            recipeModalBody.innerHTML = '<p class="text-danger">Could not load recipe. Please check your connection and try again.</p>';
        }
    };

    // Add click listeners to all product links
    if (productListContainer && recipeModalInstance) {
        productListContainer.querySelectorAll('a.product-card').forEach(link => {
            link.addEventListener('click', (e) => {
                // If on desktop (lg breakpoint or wider), let the link work normally
                if (window.innerWidth >= 992) {
                    return true;
                }

                // --- On Mobile ---
                e.preventDefault(); // Stop the page from reloading
                
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
                e.preventDefault(); // Stop the form from submitting normally
                
                // Show a mini-spinner on the submit button
                const submitButton = form.querySelector('button[type="submit"]');
                const originalButtonText = submitButton.innerHTML;
                submitButton.innerHTML = `
                    <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                    Saving...`;
                submitButton.disabled = true;

                try {
                    // Submit the form data using fetch
                    await fetch(form.action, {
                        method: 'POST',
                        body: new FormData(form),
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest' // Identify as AJAX
                        }
                    });
                    
                    // On success, just reload the modal content
                    // This will show the updated recipe list and any success/error messages
                    loadRecipeIntoModal(currentHref, productName);

                } catch (error) {
                    console.error('Modal form submit error:', error);
                    // Restore button if fetch fails
                    submitButton.innerHTML = originalButtonText;
                    submitButton.disabled = false;
                }
            });
        });

        // Also hijack the "Delete" button links (which are inside another modal)
        // We need to re-initialize the delete modal logic for the *newly loaded* buttons
        modalBody.querySelectorAll('button[data-bs-target="#deleteRecipeItemModal"]').forEach(button => {
            button.addEventListener('click', () => {
                const ingredientName = button.dataset.ingredientName;
                const modalTitle = deleteRecipeItemModal.querySelector('.modal-title');
                if(modalTitle) modalTitle.textContent = 'Remove ' + ingredientName + '?';
                
                document.getElementById('delete_recipe_id').value = button.dataset.recipeId;
                document.getElementById('delete_ingredient_name').textContent = ingredientName;
            });
        });
    };

    // This handles the *existing* delete modal form on the main page (for desktop)
    // and also for the one inside our new modal.
    const deleteForm = deleteRecipeItemModal.querySelector('form');
    if (deleteForm) {
        deleteForm.addEventListener('submit', async (e) => {
            // Check if the recipe modal is currently open
            if (recipeModalInstance && recipeModal._element.classList.contains('show')) {
                e.preventDefault(); // Stop default submit
                
                // Manually submit the delete form
                await fetch(deleteForm.action, {
                    method: 'POST',
                    body: new FormData(deleteForm),
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                // Hide the delete modal
                const deleteModalInstance = bootstrap.Modal.getInstance(deleteRecipeItemModal);
                deleteModalInstance.hide();
                
                // Reload the *recipe* modal
                const activeLink = productListContainer.querySelector('a.product-card.active');
                if (activeLink) {
                    loadRecipeIntoModal(activeLink.href, activeLink.dataset.productName);
                }
            }
            // If recipe modal is not open, it's a desktop request, let it submit normally.
        });
    }

}); // End DOMContentLoaded