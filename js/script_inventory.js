document.addEventListener('DOMContentLoaded', () => {

    // Populate Restock Ingredient Modal
    const restockModal = document.getElementById('restockModal');
    if (restockModal) {
        restockModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            document.getElementById('restockModalTitle').textContent = 'Restock ' + button.dataset.ingredientName;
            document.getElementById('restock_ingredient_id').value = button.dataset.ingredientId;
            document.getElementById('restock_ingredient_name').textContent = button.dataset.ingredientName;
            document.getElementById('restock_ingredient_unit').textContent = button.dataset.ingredientUnit;
        });
    }

    // Populate Adjust Product Stock Modal
    const adjustProductModal = document.getElementById('adjustProductModal');
    if (adjustProductModal) {
        adjustProductModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            document.getElementById('adjustProductModalTitle').textContent = 'Adjust Stock for ' + button.dataset.productName;
            document.getElementById('adjust_product_id').value = button.dataset.productId;
            document.getElementById('adjust_product_name').textContent = button.dataset.productName;
        });
    }

    // Populate Edit Ingredient Modal
    const editIngredientModal = document.getElementById('editIngredientModal');
    if (editIngredientModal) {
        editIngredientModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            document.getElementById('editIngredientModalTitle').textContent = 'Edit ' + button.dataset.ingredientName;
            document.getElementById('edit_ingredient_id').value = button.dataset.ingredientId;
            document.getElementById('edit_ingredient_name').value = button.dataset.ingredientName;
            document.getElementById('edit_ingredient_unit').value = button.dataset.ingredientUnit;
            document.getElementById('edit_ingredient_reorder').value = button.dataset.ingredientReorder;
        });
    }

    // Populate Edit Product Modal
    const editProductModal = document.getElementById('editProductModal');
    if (editProductModal) {
        editProductModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            document.getElementById('editProductModalTitle').textContent = 'Edit ' + button.dataset.productName;
            document.getElementById('edit_product_id').value = button.dataset.productId;
            document.getElementById('edit_product_name').value = button.dataset.productName;
            document.getElementById('edit_product_price').value = button.dataset.productPrice;
            document.getElementById('edit_product_status').value = button.dataset.productStatus;

            const currentTabPane = button.closest('.tab-pane');
            const activeTabValue = currentTabPane.id === 'discontinued-pane' ? 'discontinued' : 'products';
            document.getElementById('edit_product_active_tab').value = activeTabValue;
        });
    }

    // Populate Delete Ingredient Modal
    const deleteIngredientModal = document.getElementById('deleteIngredientModal');
    if (deleteIngredientModal) {
        deleteIngredientModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            document.getElementById('deleteIngredientModalTitle').textContent = 'Delete ' + button.dataset.ingredientName + '?';
            document.getElementById('delete_ingredient_id').value = button.dataset.ingredientId;
            document.getElementById('delete_ingredient_name').textContent = button.dataset.ingredientName;
        });
    }

    // Populate Delete Product Modal
    const deleteProductModal = document.getElementById('deleteProductModal');
    if (deleteProductModal) {
        deleteProductModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            document.getElementById('deleteProductModalTitle').textContent = 'Delete ' + button.dataset.productName + '?';
            document.getElementById('delete_product_id').value = button.dataset.productId;
            document.getElementById('delete_product_name').textContent = button.dataset.productName;

            const currentTabPane = button.closest('.tab-pane');
            const activeTabValue = currentTabPane.id === 'discontinued-pane' ? 'discontinued' : 'products';
            document.getElementById('delete_product_active_tab').value = activeTabValue;
        });
    }


    // --- JavaScript for Active Tab ---
    const allTabButtons = document.querySelectorAll('#inventoryTabs .nav-link');
    allTabButtons.forEach(tabButton => {
        tabButton.addEventListener('click', function(event) {
            const paneId = event.target.dataset.bsTarget;
            let activeTabValue = 'products'; // Default

            if (paneId === '#ingredients-pane') {
                activeTabValue = 'ingredients';
            } else if (paneId === '#discontinued-pane') {
                 activeTabValue = 'discontinued';
            }

            // Update hidden 'active_tab' inputs in ALL modals
            const allHiddenInputs = document.querySelectorAll('form input[name="active_tab"]');
            allHiddenInputs.forEach(input => {
                // Special handling for edit/delete product modals which need dynamic tab value
                if (input.id !== 'edit_product_active_tab' && input.id !== 'delete_product_active_tab') {
                     input.value = activeTabValue;
                }
                // For edit/delete product modals, set based on current click
                else {
                    input.value = activeTabValue;
                }
            });
        });
    });

});