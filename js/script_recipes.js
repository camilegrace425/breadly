// --- Shared Functions (Global) ---
window.toggleSidebar = function() {
    const sidebar = document.getElementById('mobileSidebar');
    const overlay = document.getElementById('mobileSidebarOverlay');
    if (sidebar.classList.contains('-translate-x-full')) {
        sidebar.classList.remove('-translate-x-full');
        overlay.classList.remove('hidden');
    } else {
        sidebar.classList.add('-translate-x-full');
        overlay.classList.add('hidden');
    }
}

window.openModal = function(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('hidden');
    }
}

window.closeModal = function(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.classList.add('hidden');
}

window.closeAllModals = function() {
    document.querySelectorAll('.fixed.z-50, .fixed.z-[60]').forEach(el => el.classList.add('hidden'));
}

window.openDeleteModal = function(recipeId, ingredientName) {
    const nameSpan = document.getElementById('delete_ingredient_name');
    const idInput = document.getElementById('delete_recipe_id');
    
    if(nameSpan) nameSpan.textContent = ingredientName;
    if(idInput) idInput.value = recipeId;
    
    openModal('deleteRecipeItemModal');
}

// --- AJAX Loading Logic ---
window.loadRecipeView = function(productId, clickedElement) {
    window.currentProductId = productId;
    const detailsContainer = document.getElementById('recipe-details-container');
    const productName = clickedElement ? clickedElement.dataset.productName : 'Recipe';

    // UI: Update active class in list
    document.querySelectorAll('.product-card').forEach(c => {
        c.classList.remove('border-breadly-btn', 'bg-orange-50', 'ring-1', 'ring-breadly-btn');
        c.classList.add('border-gray-100', 'bg-white', 'hover:border-orange-200');
    });
    
    // Find card even if clickedElement isn't passed (e.g. programmatic reload)
    const card = clickedElement || document.querySelector(`.product-card[data-id="${productId}"]`);
    if(card) {
        card.classList.remove('border-gray-100', 'bg-white', 'hover:border-orange-200');
        card.classList.add('border-breadly-btn', 'bg-orange-50', 'ring-1', 'ring-breadly-btn');
    }

    // --- MOBILE LOGIC ---
    if (window.innerWidth < 1024) { // Check for mobile breakpoint
        const modalLabel = document.getElementById('recipeModalLabel');
        const modalBody = document.getElementById('recipeModalBody');
        
        if(modalLabel) modalLabel.textContent = productName;
        if(modalBody) {
            modalBody.innerHTML = `
                <div class="flex justify-center p-10">
                    <div class="spinner"></div>
                </div>
            `;
        }
        openModal('recipeModal');
        
        // Fetch logic for Modal
        fetch(`recipes.php?product_id=${productId}&ajax_render=true`)
            .then(response => response.text())
            .then(html => {
                if(modalBody) modalBody.innerHTML = html;
            })
            .catch(err => {
                if(modalBody) modalBody.innerHTML = '<p class="text-center text-red-500 p-4">Error loading data.</p>';
            });
            
    } else {
        // --- DESKTOP LOGIC ---
        detailsContainer.innerHTML = `
            <div class="h-full flex items-center justify-center text-gray-400 animate-pulse">
                <div class="text-center">
                    <div class="spinner mx-auto mb-4"></div>
                    <p>Loading Recipe...</p>
                </div>
            </div>
        `;

        fetch(`recipes.php?product_id=${productId}&ajax_render=true`)
            .then(response => {
                if(!response.ok) throw new Error('Network response was not ok');
                return response.text();
            })
            .then(html => {
                detailsContainer.innerHTML = html;
                // No URL pushState here to prevent address bar clutter
            })
            .catch(err => {
                console.error(err);
                detailsContainer.innerHTML = `
                    <div class="h-full flex items-center justify-center text-red-500">
                        <p>Error loading recipe. Please try again.</p>
                    </div>
                `;
            });
    }
}

// --- Form Handling ---
window.handleAddIngredient = function(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    
    fetch('recipes.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success' || data.status === 'warning') {
            Swal.fire({
                icon: data.status,
                title: data.status === 'success' ? 'Added' : 'Notice',
                text: data.message,
                timer: 1500,
                showConfirmButton: false
            });
            loadRecipeView(window.currentProductId);
        } else {
            Swal.fire('Error', data.message, 'error');
        }
    })
    .catch(err => Swal.fire('Error', 'Connection failed', 'error'));
}

window.handleUpdateBatch = function(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    const productId = formData.get('product_id');
    const newBatchSize = formData.get('batch_size');
    
    fetch('recipes.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            // Update the sidebar list item instantly
            const batchDisplay = document.getElementById('batch-display-' + productId);
            if (batchDisplay) {
                batchDisplay.textContent = `Batch: ${newBatchSize} pcs`;
            }

            Swal.fire({
                icon: 'success',
                title: 'Updated',
                text: data.message,
                timer: 1500,
                showConfirmButton: false
            });
            loadRecipeView(window.currentProductId);
        } else {
            Swal.fire('Error', data.message, 'error');
        }
    })
    .catch(err => Swal.fire('Error', 'Connection failed', 'error'));
}

window.handleDeleteIngredient = function(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    
    fetch('recipes.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            Swal.fire({
                icon: 'success',
                title: 'Removed',
                text: data.message,
                timer: 1000,
                showConfirmButton: false
            });
            closeModal('deleteRecipeItemModal');
            loadRecipeView(window.currentProductId);
        } else {
            Swal.fire('Error', data.message, 'error');
        }
    })
    .catch(err => Swal.fire('Error', 'Connection failed', 'error'));
}

// --- Search Filter & Popstate Logic ---
document.addEventListener('DOMContentLoaded', () => {
    
    // Search Logic
    const searchInput = document.getElementById('recipe-product-search');
    if(searchInput) {
        searchInput.addEventListener('input', function(e) {
            const term = e.target.value.toLowerCase();
            const items = document.querySelectorAll('.col-product');
            let visibleCount = 0;
            
            items.forEach(item => {
                const name = item.dataset.productName;
                if(name.includes(term)) {
                    item.style.display = '';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                }
            });
            
            const noResults = document.getElementById('recipe-no-results');
            if(noResults) {
                if (visibleCount > 0) {
                    noResults.classList.add('hidden');
                } else {
                    noResults.classList.remove('hidden');
                }
            }
        });
    }

    // Handle browser back/forward buttons if URL changes happen (legacy support)
    window.addEventListener('popstate', function(event) {
        location.reload();
    });
});