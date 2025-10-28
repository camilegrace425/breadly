document.addEventListener('DOMContentLoaded', () => {

    const restockModal = document.getElementById('restockModal');
    if (restockModal) {
        restockModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const label = restockModal.querySelector('.modal-title'); // Get title element
            if(label) label.textContent = 'Restock ' + button.dataset.ingredientName; // Use Label ID
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
            const label = adjustProductModal.querySelector('.modal-title'); // Get title element
             if(label) label.textContent = 'Adjust Stock for ' + button.dataset.productName; // Use Label ID
            document.getElementById('adjust_product_id').value = button.dataset.productId;
            document.getElementById('adjust_product_name').textContent = button.dataset.productName;
        });
    }

    // Populate Edit Ingredient Modal
    const editIngredientModal = document.getElementById('editIngredientModal');
    if (editIngredientModal) {
        editIngredientModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
             const label = editIngredientModal.querySelector('.modal-title'); // Get title element
             if(label) label.textContent = 'Edit ' + button.dataset.ingredientName; // Use Label ID
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
            const label = editProductModal.querySelector('.modal-title'); // Get title element
             if(label) label.textContent = 'Edit ' + button.dataset.productName; // Use Label ID
            document.getElementById('edit_product_id').value = button.dataset.productId;
            document.getElementById('edit_product_name').value = button.dataset.productName;
            document.getElementById('edit_product_price').value = button.dataset.productPrice;
            document.getElementById('edit_product_status').value = button.dataset.productStatus;

            // Determine active tab based on button's parent tab-pane
            const currentTabPane = button.closest('.tab-pane');
            let activeTabValue = 'products'; // Default
            if (currentTabPane) {
                if (currentTabPane.id === 'discontinued-pane') {
                    activeTabValue = 'discontinued';
                } 
                // --- REMOVED 'recalled-pane' check ---
            }
            
            const activeTabInput = document.getElementById('edit_product_active_tab');
            if (activeTabInput) {
                activeTabInput.value = activeTabValue;
            }
        });
    }

    // Populate Delete Ingredient Modal
    const deleteIngredientModal = document.getElementById('deleteIngredientModal');
    if (deleteIngredientModal) {
        deleteIngredientModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const label = deleteIngredientModal.querySelector('.modal-title'); // Get title element
             if(label) label.textContent = 'Delete ' + button.dataset.ingredientName + '?'; // Use Label ID
            document.getElementById('delete_ingredient_id').value = button.dataset.ingredientId;
            document.getElementById('delete_ingredient_name').textContent = button.dataset.ingredientName;
        });
    }

    // Populate Delete Product Modal
    const deleteProductModal = document.getElementById('deleteProductModal');
    if (deleteProductModal) {
        deleteProductModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
             const label = deleteProductModal.querySelector('.modal-title'); // Get title element
            if(label) label.textContent = 'Delete ' + button.dataset.productName + '?'; // Use Label ID
            document.getElementById('delete_product_id').value = button.dataset.productId;
            document.getElementById('delete_product_name').textContent = button.dataset.productName;

            // Determine active tab based on button's parent tab-pane
            const currentTabPane = button.closest('.tab-pane');
            let activeTabValue = 'products'; // Default
            if (currentTabPane) {
                if (currentTabPane.id === 'discontinued-pane') {
                    activeTabValue = 'discontinued';
                }
                // --- REMOVED 'recalled-pane' check ---
            }
            
             const activeTabInput = document.getElementById('delete_product_active_tab');
            if (activeTabInput) {
                activeTabInput.value = activeTabValue;
            }
        });
    }


    // --- JavaScript for Active Tab Persistence ---
    const allTabButtons = document.querySelectorAll('#inventoryTabs .nav-link');
    allTabButtons.forEach(tabButton => {
        tabButton.addEventListener('click', function(event) {
            const paneId = event.target.dataset.bsTarget; // e.g., #products-pane
            let activeTabValue = 'products'; // Default

            if (paneId === '#ingredients-pane') {
                activeTabValue = 'ingredients';
            } else if (paneId === '#recall-log-pane') { // <-- MODIFIED
                 activeTabValue = 'recall_log';
            } else if (paneId === '#discontinued-pane') {
                 activeTabValue = 'discontinued';
            } else if (paneId === '#history-pane') {
                 activeTabValue = 'history';
            }

            document.querySelectorAll('form input[name="active_tab"]').forEach(input => {
                 if (!input.id || (input.id !== 'edit_product_active_tab' && input.id !== 'delete_product_active_tab')) {
                      input.value = activeTabValue;
                 }
            });
        });
    });

    function getSortableValue(value, type = 'text') {
        if (value === null || value === undefined) return ''; // Handle null/undefined

        let cleaned = value.trim().replace(/P|kg|g|L|ml|pcs|pack|tray|can|bottle|\+/gi, ''); // Remove units and '+'
        cleaned = cleaned.replace(/,/g, ''); // Remove thousands separator

        switch (type) {
            case 'number':
                // For ingredients, stock/reorder might have decimals
                 const num = parseFloat(cleaned);
                return isNaN(num) ? 0 : num; // Return 0 if parsing fails
            case 'date':
                // Attempt to parse 'M d, Y h:i A' format
                let dateVal = Date.parse(cleaned);
                return isNaN(dateVal) ? 0 : dateVal; // Return 0 if parsing fails
            default: // 'text'
                // Special sort order for status strings
                const lowerVal = cleaned.toLowerCase();
                // Ingredient Status
                if (lowerVal.includes('low stock')) return 'a_low_stock'; // Prefix to ensure correct alphabetical sort
                if (lowerVal.includes('in stock')) return 'b_in_stock';
                 // Adjustment History Type
                if (lowerVal.includes('ingredient')) return 'a_ingredient';
                if (lowerVal.includes('product')) return 'b_product';
                return lowerVal; // Default alphabetical sort
        }
    }

    function sortTableByDropdown(sortLink) {
        const { sortBy, sortDir, sortType } = sortLink.dataset; // Get sorting parameters from data attributes

        // Find the table associated with the clicked dropdown
        const table = sortLink.closest('.card').querySelector('table');
        if (!table) return; // Exit if no table found
        const tbody = table.querySelector('tbody');
        if (!tbody) return; // Exit if no table body found

        // Find the header cell (TH) corresponding to the column to sort by
        const th = table.querySelector(`thead th[data-sort-by="${sortBy}"]`);
        if (!th) {
            console.error(`Sort Error: No table header found with data-sort-by="${sortBy}"`);
            return; // Exit if the header isn't found
        }
        // Determine the index of the column to sort
        const colIndex = Array.from(th.parentNode.children).indexOf(th);

        // Get all rows from the table body and convert to an array for sorting
        const rows = Array.from(tbody.querySelectorAll('tr'));

        // Sort the rows array
        rows.sort((a, b) => {
            // Basic check for valid cells
            if (!a.cells[colIndex] || !b.cells[colIndex]) return 0;

            // Get the cleaned, comparable values from the cells
            const valA = getSortableValue(a.cells[colIndex].innerText, sortType);
            const valB = getSortableValue(b.cells[colIndex].innerText, sortType);

            // Perform comparison based on sort direction
            if (valA < valB) {
                return sortDir === 'asc' ? -1 : 1;
            }
            if (valA > valB) {
                return sortDir === 'asc' ? 1 : -1;
            }
            return 0; // Values are equal
        });

        // Re-append the sorted rows back into the table body
        tbody.append(...rows);

        // --- Update UI ---
        // Update the text of the dropdown button to show the current sort
        const buttonTextSpan = sortLink.closest('.dropdown').querySelector('.current-sort-text');
        if (buttonTextSpan) {
            buttonTextSpan.innerText = sortLink.innerText; // Set button text to the clicked link's text
        }

        // Update the 'active' class on dropdown items
        const dropdownItems = sortLink.closest('.dropdown-menu').querySelectorAll('.dropdown-item');
        dropdownItems.forEach(item => item.classList.remove('active')); // Remove active from all items
        sortLink.classList.add('active'); // Add active to the clicked item
    }

    // --- Attach Event Listeners ---
    // Find all dropdown links with the class 'sort-trigger' and attach the sort function
    document.querySelectorAll('.sort-trigger').forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault(); // Prevent the link from navigating
            sortTableByDropdown(e.target); // Call the sort function
        });
    });

    // --- Initial Sort on Page Load ---
    // Find each sort dropdown and trigger a click on its initially 'active' item
    document.querySelectorAll('.card .dropdown').forEach(dropdown => {
        // Find the link that should be active by default (could be the first or one marked 'active')
        const defaultSortLink = dropdown.querySelector('.dropdown-item.active') || dropdown.querySelector('.dropdown-item');
        if (defaultSortLink) {
            sortTableByDropdown(defaultSortLink); // Apply the default sort
        }
    });

}); // End DOMContentLoaded