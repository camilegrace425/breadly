document.addEventListener('DOMContentLoaded', () => {

    // --- ::: NEW Reusable Table Filter Function ::: ---
    function addTableFilter(inputId, tableBodyId, noResultsRowId) {
        const searchInput = document.getElementById(inputId);
        const tableBody = document.getElementById(tableBodyId);
        const noResultsRow = document.getElementById(noResultsRowId);

        if (!searchInput || !tableBody || !noResultsRow) {
            console.warn('Filter elements not found for:', inputId, tableBodyId, noResultsRowId);
            return;
        }

        searchInput.addEventListener('keyup', (e) => {
            const searchTerm = e.target.value.toLowerCase();
            let itemsFound = 0;

            const rows = tableBody.querySelectorAll('tr:not([id$="-no-results"])');

            rows.forEach(row => {
                const cell = row.cells[0];
                if (cell) {
                    const cellText = cell.innerText.toLowerCase();
                    if (cellText.startsWith(searchTerm)) {
                        row.style.display = ''; // Show row
                        itemsFound++;
                    } else {
                        row.style.display = 'none'; // Hide row
                    }
                }
            });
            noResultsRow.style.display = itemsFound === 0 ? '' : 'none';
        });
    }
    // --- ::: END NEW FUNCTION ::: ---

    // --- ::: NEW Reusable Table Pagination Function (REWRITTEN) ::: ---
    function addTablePagination(selectId, tableBodyId) {
        const select = document.getElementById(selectId);
        const tableBody = document.getElementById(tableBodyId);

        // --- ::: MODIFICATION: Get button IDs from selectId ::: ---
        const baseId = selectId.replace('-rows-select', ''); // e.g., "product"
        const prevBtn = document.getElementById(`${baseId}-prev-btn`);
        const nextBtn = document.getElementById(`${baseId}-next-btn`);

        if (!select || !tableBody || !prevBtn || !nextBtn) {
            // console.warn('Pagination elements not found for:', selectId, tableBodyId);
            return;
        }

        let currentPage = 0; // 0-indexed page

        const updateTableRows = () => {
            const selectedValue = select.value;

            // Get all rows, but ONLY count those visible by the search filter
            // (Search filter sets style.display = 'none')
            const all_rows = tableBody.querySelectorAll('tr:not([id$="-no-results"])');
            const visibleRows = [];
            all_rows.forEach(row => {
                // Check if row is hidden by search
                const isHiddenBySearch = row.style.display === 'none' && row.dataset.paginatedHidden !== 'true';
                if (!isHiddenBySearch) {
                    // If it's not hidden by search (or only hidden by us), consider it
                    visibleRows.push(row);
                }
            });

            // Reset all rows we manage to be visible (so search filter can re-hide them)
            visibleRows.forEach(row => {
                row.style.display = '';
                row.dataset.paginatedHidden = 'false';
            });

            if (selectedValue === 'all') {
                // "Show All" is selected, disable buttons and exit
                prevBtn.disabled = true;
                nextBtn.disabled = true;
                // Re-apply search filter visibility
                const searchInputId = select.id.replace('-rows-select', '-search-input');
                const searchInput = document.getElementById(searchInputId);
                if (searchInput && searchInput.value !== '') {
                    searchInput.dispatchEvent(new Event('keyup'));
                }
                return;
            }

            const limit = parseInt(selectedValue, 10);
            const totalRows = visibleRows.length;
            const totalPages = Math.ceil(totalRows / limit);

            // --- Apply pagination ---
            const start = currentPage * limit;
            const end = start + limit;

            visibleRows.forEach((row, index) => {
                if (index >= start && index < end) {
                    row.style.display = ''; // Show
                    row.dataset.paginatedHidden = 'false';
                } else {
                    row.style.display = 'none'; // Hide
                    row.dataset.paginatedHidden = 'true';
                }
            });

            // --- Update button states ---
            prevBtn.disabled = currentPage === 0;
            nextBtn.disabled = (currentPage >= totalPages - 1) || (totalRows === 0);
        };

        // --- ::: ADDED: Event Listeners for buttons ::: ---
        prevBtn.addEventListener('click', () => {
            if (currentPage > 0) {
                currentPage--;
                updateTableRows();
            }
        });

        nextBtn.addEventListener('click', () => {
            const selectedValue = select.value;
            if (selectedValue === 'all') return;

            const limit = parseInt(selectedValue, 10);
            const all_rows = tableBody.querySelectorAll('tr:not([id$="-no-results"])');
            const visibleRows = [];
            all_rows.forEach(row => {
                const isHiddenBySearch = row.style.display === 'none' && row.dataset.paginatedHidden !== 'true';
                if (!isHiddenBySearch) {
                    visibleRows.push(row);
                }
            });
            const totalRows = visibleRows.length;
            const totalPages = Math.ceil(totalRows / limit);

            if (currentPage < totalPages - 1) {
                currentPage++;
                updateTableRows();
            }
        });

        // Re-apply pagination when search happens
        const searchInputId = select.id.replace('-rows-select', '-search-input');
        const searchInput = document.getElementById(searchInputId);
        if (searchInput) {
            searchInput.addEventListener('keyup', () => {
                currentPage = 0; // Reset to first page on search
                updateTableRows();
            });
        }

        // Add event listener for changes
        select.addEventListener('change', () => {
            currentPage = 0; // Reset to first page on limit change
            updateTableRows();
        });

        // Call once on initial load
        updateTableRows();
    }
    // --- ::: END NEW PAGINATION FUNCTION ::: ---

    // ::: REMOVED restockModal listener :::


    // Populate Adjust Product Stock Modal
    const adjustProductModal = document.getElementById('adjustProductModal');
    if (adjustProductModal) {
        adjustProductModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const label = adjustProductModal.querySelector('.modal-title');
            if (label) label.textContent = 'Adjust Stock for ' + button.dataset.productName;
            document.getElementById('adjust_product_id').value = button.dataset.productId;
            document.getElementById('adjust_product_name').textContent = button.dataset.productName;

            document.getElementById('adjust_adjustment_qty').value = '';
            document.getElementById('adjust_type').value = 'Production'; // Set default
            document.getElementById('adjust_reason_note').value = '';

            setTimeout(() => {
                const adjustTypeSelect = document.getElementById('adjust_type');
                if (adjustTypeSelect) {
                    adjustTypeSelect.dispatchEvent(new Event('change'));
                }
            }, 100);
        });
    }

    // ::: NEW: Populate Adjust Ingredient Stock Modal :::
    const adjustIngredientModal = document.getElementById('adjustIngredientModal');
    if (adjustIngredientModal) {
        adjustIngredientModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const label = adjustIngredientModal.querySelector('.modal-title');
            if (label) label.textContent = 'Adjust Stock for ' + button.dataset.ingredientName;
            document.getElementById('adjust_ingredient_id').value = button.dataset.ingredientId;
            document.getElementById('adjust_ingredient_name').textContent = button.dataset.ingredientName;
            document.getElementById('adjust_ingredient_unit').textContent = button.dataset.ingredientUnit;

            // Clear fields
            document.getElementById('adjust_ing_qty').value = '';
            document.getElementById('adjust_ing_type').value = 'Restock'; // Set default
            document.getElementById('adjust_ing_reason_note').value = '';

            // Trigger change to set helper text
            setTimeout(() => {
                const adjustIngTypeSelect = document.getElementById('adjust_ing_type');
                if (adjustIngTypeSelect) {
                    adjustIngTypeSelect.dispatchEvent(new Event('change'));
                }
            }, 100);
        });
    }
    // ::: END NEW LISTENER :::

    // Populate Edit Ingredient Modal
    const editIngredientModal = document.getElementById('editIngredientModal');
    if (editIngredientModal) {
        editIngredientModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const label = editIngredientModal.querySelector('.modal-title');
            if (label) label.textContent = 'Edit ' + button.dataset.ingredientName;
            document.getElementById('edit_ingredient_id').value = button.dataset.ingredientId;
            document.getElementById('edit_ingredient_name').value = button.dataset.ingredientName;
            document.getElementById('edit_ingredient_unit').value = button.dataset.ingredientUnit;
            document.getElementById('edit_ingredient_reorder').value = button.dataset.ingredientReorder;
        });
    }

    // Populate Edit Product Modal
    const editProductModal = document.getElementById('editProductModal');
    if (editProductModal) {
        editProductModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const label = editProductModal.querySelector('.modal-title');
            if (label) label.textContent = 'Edit ' + button.dataset.productName;
            document.getElementById('edit_product_id').value = button.dataset.productId;
            document.getElementById('edit_product_name').value = button.dataset.productName;
            document.getElementById('edit_product_price').value = button.dataset.productPrice;
            document.getElementById('edit_product_status').value = button.dataset.productStatus;
            
            // --- ::: ADD THIS LINE ::: ---
            document.getElementById('edit_product_current_image').value = button.dataset.productImage;

            const currentTabPane = button.closest('.tab-pane');
            let activeTabValue = 'products'; // Default
            if (currentTabPane) {
                if (currentTabPane.id === 'discontinued-pane') {
                    activeTabValue = 'discontinued';
                }
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
        deleteIngredientModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const label = deleteIngredientModal.querySelector('.modal-title');
            if (label) label.textContent = 'Delete ' + button.dataset.ingredientName + '?';
            document.getElementById('delete_ingredient_id').value = button.dataset.ingredientId;
            document.getElementById('delete_ingredient_name').textContent = button.dataset.ingredientName;
        });
    }

    // Populate Delete Product Modal
    const deleteProductModal = document.getElementById('deleteProductModal');
    if (deleteProductModal) {
        deleteProductModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const label = deleteProductModal.querySelector('.modal-title');
            if (label) label.textContent = 'Delete ' + button.dataset.productName + '?';
            document.getElementById('delete_product_id').value = button.dataset.productId;
            document.getElementById('delete_product_name').textContent = button.dataset.productName;

            const currentTabPane = button.closest('.tab-pane');
            let activeTabValue = 'products'; // Default
            if (currentTabPane) {
                if (currentTabPane.id === 'discontinued-pane') {
                    activeTabValue = 'discontinued';
                }
            }

            const activeTabInput = document.getElementById('delete_product_active_tab');
            if (activeTabInput) {
                activeTabInput.value = activeTabValue;
            }
        });
    }

    // --- ::: NEWLY ADDED: Initialize table filters ::: ---
    addTableFilter('product-search-input', 'product-table-body', 'product-no-results');
    addTableFilter('ingredient-search-input', 'ingredient-table-body', 'ingredient-no-results');
    // --- ::: END ::: ---

    // --- ::: NEWLY ADDED: Initialize table pagination ::: ---
    addTablePagination('product-rows-select', 'product-table-body');
    addTablePagination('ingredient-rows-select', 'ingredient-table-body');
    addTablePagination('discontinued-rows-select', 'discontinued-table-body');
    addTablePagination('recall-rows-select', 'recall-table-body');
    addTablePagination('history-rows-select', 'history-table-body');
    // --- ::: END ::: ---


    // --- JavaScript for Active Tab Persistence ---
    const allTabButtons = document.querySelectorAll('#inventoryTabs .nav-link');

    const urlParams = new URLSearchParams(window.location.search);
    const tabFromUrl = urlParams.get('active_tab');
    if (tabFromUrl) {
        const tabButton = document.querySelector(`[data-bs-target="#${tabFromUrl}-pane"]`);
        if (tabButton) {
            allTabButtons.forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.remove('active', 'show'));

            tabButton.classList.add('active');
            const paneToShow = document.getElementById(`${tabFromUrl}-pane`);
            if (paneToShow) {
                paneToShow.classList.add('active', 'show');
            }
        }
    }


    allTabButtons.forEach(tabButton => {
        tabButton.addEventListener('click', function(event) {
            const paneId = event.target.dataset.bsTarget;
            let activeTabValue = 'products'; // Default

            // --- MODIFIED: Added 'recall-pane' ---
            if (paneId === '#ingredients-pane') {
                activeTabValue = 'ingredients';
            } else if (paneId === '#discontinued-pane') {
                activeTabValue = 'discontinued';
            } else if (paneId === '#recall-pane') {
                activeTabValue = 'recall';
            } else if (paneId === '#history-pane') {
                activeTabValue = 'history';
            }
            // --- END MODIFICATION ---

            document.querySelectorAll('form input[name="active_tab"]').forEach(input => {
                if (!input.id || (input.id !== 'edit_product_active_tab' && input.id !== 'delete_product_active_tab')) {
                    input.value = activeTabValue;
                }
            });
        });
    });

    // --- THIS IS THE SORTING LOGIC ---
    function getSortableValue(value, type = 'text') {
        if (value === null || value === undefined) return '';

        let cleaned = value.trim();

        switch (type) {
            case 'number':
                cleaned = cleaned.replace(/P|kg|g|L|ml|pcs|pack|tray|can|bottle|\+/gi, '');
                cleaned = cleaned.replace(/,/g, '');
                const num = parseFloat(cleaned);
                return isNaN(num) ? 0 : num;

            case 'date':
                let dateVal = Date.parse(cleaned);
                return isNaN(dateVal) ? 0 : dateVal;

            default: // 'text'
                cleaned = cleaned.replace(/P|kg|g|L|ml|pcs|pack|tray|can|bottle|\+/gi, '');
                cleaned = cleaned.replace(/,/g, '');

                const lowerVal = cleaned.toLowerCase();
                if (lowerVal.includes('low stock')) return 'a_low_stock';
                if (lowerVal.includes('in stock')) return 'b_in_stock';
                if (lowerVal.includes('ingredient')) return 'a_ingredient';
                if (lowerVal.includes('product')) return 'b_product';
                return lowerVal;
        }
    }

    function sortTableByDropdown(sortLink) {
        const {
            sortBy,
            sortDir,
            sortType
        } = sortLink.dataset;

        const table = sortLink.closest('.card').querySelector('table');
        if (!table) return;
        const tbody = table.querySelector('tbody');
        if (!tbody) return;

        const th = table.querySelector(`thead th[data-sort-by="${sortBy}"]`);
        if (!th) {
            console.error(`Sort Error: No table header found with data-sort-by="${sortBy}"`);
            return;
        }
        const colIndex = Array.from(th.parentNode.children).indexOf(th);

        const rows = Array.from(tbody.querySelectorAll('tr:not([id$="-no-results"])')); // Exclude no-results row

        rows.sort((a, b) => {
            if (!a.cells[colIndex] || !b.cells[colIndex]) return 0;

            const valA = getSortableValue(a.cells[colIndex].innerText, sortType);
            const valB = getSortableValue(b.cells[colIndex].innerText, sortType);

            if (valA < valB) {
                return sortDir === 'asc' ? -1 : 1;
            }
            if (valA > valB) {
                return sortDir === 'asc' ? 1 : -1;
            }
            return 0;
        });

        tbody.append(...rows);

        // After sorting, re-apply pagination
        const paginationSelectId = table.querySelector('select[id$="-rows-select"]')?.id;
        if (paginationSelectId) {
            const tableBodyId = tbody.id;
            // Find the select element and trigger its change event to re-apply pagination
            const paginationSelect = document.getElementById(paginationSelectId);
            if (paginationSelect) {
                paginationSelect.dispatchEvent(new Event('change'));
            }
        }


        const buttonTextSpan = sortLink.closest('.dropdown').querySelector('.current-sort-text');
        if (buttonTextSpan) {
            buttonTextSpan.innerText = sortLink.innerText;
        }
        const dropdownItems = sortLink.closest('.dropdown-menu').querySelectorAll('.dropdown-item');
        dropdownItems.forEach(item => item.classList.remove('active'));
        sortLink.classList.add('active');
    }

    document.querySelectorAll('.sort-trigger').forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            sortTableByDropdown(e.target);
        });
    });

    document.querySelectorAll('.card .dropdown').forEach(dropdown => {
        const defaultSortLink = dropdown.querySelector('.dropdown-item.active') || dropdown.querySelector('.dropdown-item');
        if (defaultSortLink) {
            // Sort, but don't apply pagination here, let the initial load do it
            sortTableByDropdown(defaultSortLink);
        }
    });
    // --- END OF SORTING LOGIC ---


    // --- ::: MODIFIED: Dynamic Helper & Validation for Adjust Product Stock Modal ::: ---
    const adjustProdModal = document.getElementById('adjustProductModal');
    if (adjustProdModal) {
        const adjustTypeSelect = document.getElementById('adjust_type');
        const adjustQtyInput = document.getElementById('adjust_adjustment_qty');
        const adjustQtyHelper = document.getElementById('adjust_qty_helper');
        const adjustForm = adjustProdModal.querySelector('form');

        const updateAdjustHelperAndValidation = () => {
            const selectedType = adjustTypeSelect.value;
            const qtyValue = adjustQtyInput.value;
            const qty = parseFloat(qtyValue);

            adjustQtyInput.classList.remove('is-invalid', 'is-valid');
            adjustQtyHelper.classList.remove('text-success', 'text-danger', 'text-warning');

            let isValid = false;

            switch (selectedType) {
                case 'Production':
                    adjustQtyInput.placeholder = "e.g., 10 (Must be POSITIVE)";
                    adjustQtyHelper.innerHTML = "<strong>Positive number:</strong> Adds stock AND deducts ingredients.";
                    adjustQtyHelper.classList.add('text-success');
                    if (!isNaN(qty) && qty > 0) isValid = true;
                    break;
                case 'Correction':
                    adjustQtyInput.placeholder = "e.g., 5 or -5 (Cannot be 0)";
                    adjustQtyHelper.innerHTML = "<strong>Positive:</strong> Adds stock, deducts ingredients.<br><strong>Negative:</strong> Removes stock, adds ingredients back.";
                    adjustQtyHelper.classList.add('text-warning');
                    if (!isNaN(qty) && qty !== 0) isValid = true;
                    break;
                case 'Spoilage':
                    adjustQtyInput.placeholder = "e.g., -2 (Must be NEGATIVE)";
                    adjustQtyHelper.innerHTML = "<strong>Negative number:</strong> Removes stock. Does NOT affect ingredients.";
                    adjustQtyHelper.classList.add('text-danger');
                    if (!isNaN(qty) && qty < 0) isValid = true;
                    break;
                case 'Recall':
                    adjustQtyInput.placeholder = "e.g., -10 (Must be NEGATIVE)";
                    adjustQtyHelper.innerHTML = "<strong>Negative number:</strong> Removes stock. Does NOT affect ingredients. Logs to Recall Report.";
                    adjustQtyHelper.classList.add('text-danger');
                    if (!isNaN(qty) && qty < 0) isValid = true;
                    break;
            }

            if (qtyValue !== '') {
                if (isValid) {
                    adjustQtyInput.classList.add('is-valid');
                } else {
                    adjustQtyInput.classList.add('is-invalid');
                }
            }

            return isValid;
        };

        adjustTypeSelect.addEventListener('change', updateAdjustHelperAndValidation);
        adjustQtyInput.addEventListener('keyup', updateAdjustHelperAndValidation);

        if (adjustForm) {
            adjustForm.addEventListener('submit', (e) => {
                const isValid = updateAdjustHelperAndValidation();

                if (!isValid) {
                    e.preventDefault();
                    e.stopPropagation();
                    adjustQtyInput.classList.add('is-invalid');
                    adjustQtyInput.classList.remove('is-valid');
                    adjustQtyInput.focus();
                }
            });
        }

        adjustProdModal.addEventListener('show.bs.modal', () => {
            setTimeout(updateAdjustHelperAndValidation, 100);
        });
    }
    // --- ::: END MODIFIED DYNAMIC HELPER ::: ---


    // --- ::: NEW: Dynamic Helper & Validation for Adjust Ingredient Stock Modal ::: ---
    const adjustIngModal = document.getElementById('adjustIngredientModal');
    if (adjustIngModal) {
        const adjustTypeSelect = document.getElementById('adjust_ing_type');
        const adjustQtyInput = document.getElementById('adjust_ing_qty');
        const adjustQtyHelper = document.getElementById('adjust_ing_qty_helper');
        const adjustForm = adjustIngModal.querySelector('form');

        const updateAdjustHelperAndValidation = () => {
            const selectedType = adjustTypeSelect.value;
            const qtyValue = adjustQtyInput.value;
            const qty = parseFloat(qtyValue);

            adjustQtyInput.classList.remove('is-invalid', 'is-valid');
            adjustQtyHelper.classList.remove('text-success', 'text-danger', 'text-warning');

            let isValid = false;

            switch (selectedType) {
                case 'Restock':
                    adjustQtyInput.placeholder = "e.g., 10 (Must be POSITIVE)";
                    adjustQtyHelper.innerHTML = "<strong>Positive number:</strong> Adds stock to inventory.";
                    adjustQtyHelper.classList.add('text-success');
                    if (!isNaN(qty) && qty > 0) isValid = true;
                    break;
                case 'Correction':
                    adjustQtyInput.placeholder = "e.g., 5.5 or -2 (Cannot be 0)";
                    adjustQtyHelper.innerHTML = "<strong>Positive or Negative number:</strong> Corrects stock count.";
                    adjustQtyHelper.classList.add('text-warning');
                    if (!isNaN(qty) && qty !== 0) isValid = true;
                    break;
                case 'Spoilage':
                    adjustQtyInput.placeholder = "e.g., -2.5 (Must be NEGATIVE)";
                    adjustQtyHelper.innerHTML = "<strong>Negative number:</strong> Removes stock from inventory.";
                    adjustQtyHelper.classList.add('text-danger');
                    if (!isNaN(qty) && qty < 0) isValid = true;
                    break;
            }

            if (qtyValue !== '') {
                if (isValid) {
                    adjustQtyInput.classList.add('is-valid');
                } else {
                    adjustQtyInput.classList.add('is-invalid');
                }
            }

            return isValid;
        };

        adjustTypeSelect.addEventListener('change', updateAdjustHelperAndValidation);
        adjustQtyInput.addEventListener('keyup', updateAdjustHelperAndValidation);

        if (adjustForm) {
            adjustForm.addEventListener('submit', (e) => {
                const isValid = updateAdjustHelperAndValidation();
                if (!isValid) {
                    e.preventDefault();
                    e.stopPropagation();
                    adjustQtyInput.classList.add('is-invalid');
                    adjustQtyInput.classList.remove('is-valid');
                    adjustQtyInput.focus();
                }
            });
        }

        adjustIngModal.addEventListener('show.bs.modal', () => {
            setTimeout(updateAdjustHelperAndValidation, 100);
        });
    }
    // --- ::: END NEW DYNAMIC HELPER ::: ---


}); // End DOMContentLoaded