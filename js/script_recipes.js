document.addEventListener('DOMContentLoaded', () => {

    // Populate Delete Recipe Item Modal
    const deleteRecipeItemModal = document.getElementById('deleteRecipeItemModal');
    if (deleteRecipeItemModal) {
        deleteRecipeItemModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const ingredientName = button.dataset.ingredientName;
            
            const modalTitle = deleteRecipeItemModal.querySelector('.modal-title');
            if(modalTitle) modalTitle.textContent = 'Remove ' + ingredientName + '?';
            
            document.getElementById('delete_recipe_id').value = button.dataset.recipeId;
            document.getElementById('delete_ingredient_name').textContent = ingredientName;
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

}); // End DOMContentLoaded