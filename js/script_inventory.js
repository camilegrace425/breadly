document.addEventListener('DOMContentLoaded', () => {

    // --- Modal Event Listeners ---
    // (Ensure these IDs match your HTML file exactly)
    
    // Add Ingredient Modal
    const addIngredientModal = document.getElementById('addIngredientModal');
    if (addIngredientModal) {
        addIngredientModal.addEventListener('show.bs.modal', function (event) {
            // Nothing to pre-fill here, but good to have the hook
        });
    }

    // Add Product Modal
    const addProductModal = document.getElementById('addProductModal');
    if (addProductModal) {
        addProductModal.addEventListener('show.bs.modal', function (event) {
            // Nothing to pre-fill
        });
    }
    
    // Adjust Ingredient Modal
    const adjustIngredientModal = document.getElementById('adjustIngredientModal');
    if (adjustIngredientModal) {
        adjustIngredientModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            if (!button) return;
            const ingredientId = button.dataset.ingredientId;
            const ingredientName = button.dataset.ingredientName;
            const ingredientUnit = button.dataset.ingredientUnit;

            adjustIngredientModal.querySelector('#adjust_ingredient_id').value = ingredientId;
            adjustIngredientModal.querySelector('#adjust_ingredient_name').textContent = ingredientName;
            adjustIngredientModal.querySelector('#adjust_ingredient_unit').textContent = ingredientUnit;
            
            // --- Helper text for adjustment quantity ---
            const qtyInput = adjustIngredientModal.querySelector('#adjust_ing_qty');
            const qtyHelper = adjustIngredientModal.querySelector('#adjust_ing_qty_helper');
            qtyInput.value = ''; // Clear previous input
            
            const typeSelect = adjustIngredientModal.querySelector('#adjust_ing_type');
            
            const updateHelperText = () => {
                const qty = parseFloat(qtyInput.value);
                const type = typeSelect.value;
                if (!qty || isNaN(qty)) {
                    qtyHelper.textContent = 'Enter a positive or negative quantity.';
                    qtyHelper.className = 'form-text';
                    return;
                }
                
                if(type === 'Restock' && qty < 0) {
                     qtyHelper.textContent = 'Restock quantity should be positive.';
                     qtyHelper.className = 'form-text text-danger';
                } else if (type === 'Spoilage' && qty > 0) {
                     qtyHelper.textContent = 'Spoilage quantity should be negative (e.g., -1.5).';
                     qtyHelper.className = 'form-text text-danger';
                } else {
                     qtyHelper.textContent = (qty > 0) ? `This will ADD ${qty} ${ingredientUnit} to stock.` : `This will REMOVE ${Math.abs(qty)} ${ingredientUnit} from stock.`;
                     qtyHelper.className = (qty > 0) ? 'form-text text-success' : 'form-text text-danger';
                }
            };
            
            qtyInput.addEventListener('input', updateHelperText);
            typeSelect.addEventListener('change', updateHelperText);
            updateHelperText(); // Initial call
        });
    }

    // Adjust Product Modal
    const adjustProductModal = document.getElementById('adjustProductModal');
    if (adjustProductModal) {
        adjustProductModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            if (!button) return;
            const productId = button.dataset.productId;
            const productName = button.dataset.productName;
            
            adjustProductModal.querySelector('#adjust_product_id').value = productId;
            adjustProductModal.querySelector('#adjust_product_name').textContent = productName;

            // --- Helper text for adjustment quantity ---
            const qtyInput = adjustProductModal.querySelector('#adjust_adjustment_qty');
            const qtyHelper = adjustProductModal.querySelector('#adjust_qty_helper');
            qtyInput.value = ''; // Clear previous input
            
            const typeSelect = adjustProductModal.querySelector('#adjust_type');

            const updateHelperText = () => {
                const qty = parseInt(qtyInput.value, 10);
                const type = typeSelect.value;

                if (!qty || isNaN(qty)) {
                    qtyHelper.textContent = 'Enter a positive (add) or negative (remove) whole number.';
                    qtyHelper.className = 'form-text';
                    return;
                }

                if (type === 'Production' && qty < 0) {
                    qtyHelper.textContent = 'Production quantity should be positive.';
                    qtyHelper.className = 'form-text text-danger';
                } else if ((type === 'Spoilage' || type === 'Recall') && qty > 0) {
                    qtyHelper.textContent = 'Spoilage/Recall quantity should be negative (e.g., -5).';
                    qtyHelper.className = 'form-text text-danger';
                } else {
                    let actionText = '';
                    if (type === 'Production') {
                        actionText = `ADD ${qty} pcs to stock and DEDUCT ingredients.`;
                    } else if (type === 'Spoilage' || type === 'Recall') {
                        actionText = `REMOVE ${Math.abs(qty)} pcs from stock.`;
                    } else if (type === 'Correction') {
                        actionText = (qty > 0) ? `ADD ${qty} pcs to stock.` : `REMOVE ${Math.abs(qty)} pcs from stock.`;
                    }
                    qtyHelper.textContent = actionText;
                    qtyHelper.className = (qty > 0) ? 'form-text text-success' : 'form-text text-danger';
                }
            };

            qtyInput.addEventListener('input', updateHelperText);
            typeSelect.addEventListener('change', updateHelperText);
            updateHelperText(); // Initial call
        });
    }

    // Edit Ingredient Modal
    const editIngredientModal = document.getElementById('editIngredientModal');
    if (editIngredientModal) {
        editIngredientModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            if (!button) return;
            editIngredientModal.querySelector('#edit_ingredient_id').value = button.dataset.ingredientId;
            editIngredientModal.querySelector('#edit_ingredient_name').value = button.dataset.ingredientName;
            editIngredientModal.querySelector('#edit_ingredient_unit').value = button.dataset.ingredientUnit;
            editIngredientModal.querySelector('#edit_ingredient_reorder').value = button.dataset.ingredientReorder;
        });
    }

    // Edit Product Modal
    const editProductModal = document.getElementById('editProductModal');
    if (editProductModal) {
        editProductModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            if (!button) return;
            editProductModal.querySelector('#edit_product_id').value = button.dataset.productId;
            editProductModal.querySelector('#edit_product_name').value = button.dataset.productName;
            editProductModal.querySelector('#edit_product_price').value = button.dataset.productPrice;
            editProductModal.querySelector('#edit_product_status').value = button.dataset.productStatus;
            
            // --- ADDED: Handle image path ---
            editProductModal.querySelector('#edit_product_current_image').value = button.dataset.productImage || '';

            // Set the "active_tab" hidden input based on the product status
            const status = button.dataset.productStatus;
            const activeTabInput = editProductModal.querySelector('#edit_product_active_tab');
            if (status === 'discontinued') {
                activeTabInput.value = 'discontinued';
            } else {
                activeTabInput.value = 'products';
            }
        });
    }
    
    // Delete Ingredient Modal
    const deleteIngredientModal = document.getElementById('deleteIngredientModal');
    if (deleteIngredientModal) {
        deleteIngredientModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            if (!button) return;
            deleteIngredientModal.querySelector('#delete_ingredient_id').value = button.dataset.ingredientId;
            deleteIngredientModal.querySelector('#delete_ingredient_name').textContent = button.dataset.ingredientName;
        });
    }
    
    // Delete Product Modal
    const deleteProductModal = document.getElementById('deleteProductModal');
    if (deleteProductModal) {
        deleteProductModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            if (!button) return;
            deleteProductModal.querySelector('#delete_product_id').value = button.dataset.productId;
            deleteProductModal.querySelector('#delete_product_name').textContent = button.dataset.productName;
            
            // Set the "active_tab" hidden input
            const activeTabInput = deleteProductModal.querySelector('#delete_product_active_tab');
            // Check the button's ancestor for the tab pane
            const pane = button.closest('.tab-pane');
            if (pane && pane.id === 'discontinued-pane') {
                activeTabInput.value = 'discontinued';
            } else {
                activeTabInput.value = 'products';
            }
        });
    }

    // --- Table/Grid Sorting & Pagination Logic ---

    // --- NEW: Card Grid Sorter/Filter/Paginator ---
    function setupCardGrid(gridId, searchInputId, rowsSelectId, prevBtnId, nextBtnId) {
        const gridContainer = document.getElementById(gridId);
        if (!gridContainer) {
            // console.error('Failed to find card grid:', gridId);
            return;
        }

        let allCards = Array.from(gridContainer.querySelectorAll('.product-item'));
        let filteredCards = [...allCards];
        let currentPage = 1;

        const rowsSelect = document.getElementById(rowsSelectId);
        let rowsPerPage = (rowsSelect && rowsSelect.value) ? (rowsSelect.value === 'all' ? 'all' : parseInt(rowsSelect.value, 10)) : 10;
        
        let currentSort = { by: 'productName', dir: 'asc', type: 'text' }; // Default sort
        
        const searchInput = document.getElementById(searchInputId);
        const prevBtn = document.getElementById(prevBtnId);
        const nextBtn = document.getElementById(nextBtnId);
        const noResultsMessage = document.getElementById(gridId.replace('-list', '-no-results'));
        
        const parentCard = gridContainer.closest('.card');

        function renderGrid() {
            // 1. Sort
            if (currentSort.by) {
                filteredCards.sort((a, b) => {
                    let valA = a.dataset[currentSort.by];
                    let valB = b.dataset[currentSort.by];

                    if (currentSort.type === 'number') {
                        valA = parseFloat(valA) || 0;
                        valB = parseFloat(valB) || 0;
                    } else {
                        // Default to string comparison
                        valA = valA ? valA.toLowerCase() : '';
                        valB = valB ? valB.toLowerCase() : '';
                    }
                    
                    if (valA < valB) return currentSort.dir === 'asc' ? -1 : 1;
                    if (valA > valB) return currentSort.dir === 'asc' ? 1 : -1;
                    return 0;
                });
            }

            // 2. Paginate
            gridContainer.innerHTML = ''; // Clear grid
            const totalPages = (rowsPerPage === 'all') ? 1 : Math.ceil(filteredCards.length / rowsPerPage);
            currentPage = Math.max(1, Math.min(currentPage, totalPages));

            const start = (rowsPerPage === 'all') ? 0 : (currentPage - 1) * rowsPerPage;
            const end = (rowsPerPage === 'all') ? filteredCards.length : start + rowsPerPage;
            const pageCards = filteredCards.slice(start, end);

            pageCards.forEach(card => gridContainer.appendChild(card));
            
            // 3. Update Controls
            if (prevBtn) prevBtn.disabled = (currentPage === 1);
            if (nextBtn) nextBtn.disabled = (currentPage === totalPages || filteredCards.length === 0);
            
            // 4. Show 'no results' row if needed
            if (noResultsMessage) {
                if (filteredCards.length === 0) {
                    gridContainer.appendChild(noResultsMessage);
                    noResultsMessage.style.display = 'block';
                } else {
                    noResultsMessage.style.display = 'none';
                }
            }
        }

        // --- Event Listeners ---
        if (searchInput) {
            searchInput.addEventListener('keyup', () => {
                const searchTerm = searchInput.value.toLowerCase();
                filteredCards = allCards.filter(card => {
                    return card.dataset.productName.includes(searchTerm);
                });
                currentPage = 1;
                renderGrid();
            });
        }

        if (rowsSelect) {
            rowsSelect.addEventListener('change', () => {
                const val = rowsSelect.value;
                rowsPerPage = (val === 'all') ? 'all' : parseInt(val);
                currentPage = 1;
                renderGrid();
            });
        }
        
        if (prevBtn) {
            prevBtn.addEventListener('click', () => {
                if (currentPage > 1) {
                    currentPage--;
                    renderGrid();
                }
            });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', () => {
                const totalPages = (rowsPerPage === 'all') ? 1 : Math.ceil(filteredCards.length / rowsPerPage);
                if (currentPage < totalPages) {
                    currentPage++;
                    renderGrid();
                }
            });
        }

        if (parentCard) {
            const sortTriggers = parentCard.querySelectorAll('.dropdown-menu .sort-trigger');
            const sortText = parentCard.querySelector('.current-sort-text');

            sortTriggers.forEach(trigger => {
                trigger.addEventListener('click', (e) => {
                    e.preventDefault();
                    
                    // Convert data-sort-by to data-product-xxx
                    const sortBy = trigger.dataset.sortBy; // e.g., "name"
                    // Creates "productName", "productPrice", etc.
                    currentSort.by = 'product' + sortBy.charAt(0).toUpperCase() + sortBy.slice(1); 
                    currentSort.dir = trigger.dataset.sortDir;
                    currentSort.type = trigger.dataset.sortType || 'text';
                    
                    if (sortText) sortText.textContent = trigger.textContent;
                    sortTriggers.forEach(t => t.classList.remove('active'));
                    trigger.classList.add('active');
                    
                    currentPage = 1;
                    renderGrid();
                });
            });
        }
        
        // Initial render
        const activeSort = parentCard ? parentCard.querySelector('.dropdown-menu .sort-trigger.active') : null;
        if (activeSort) {
            const sortBy = activeSort.dataset.sortBy;
            currentSort.by = 'product' + sortBy.charAt(0).toUpperCase() + sortBy.slice(1);
            currentSort.dir = activeSort.dataset.sortDir;
            currentSort.type = activeSort.dataset.sortType || 'text';
        }
        renderGrid();
    }


    // --- Helper function to get cell value for sorting TABLES ---
    function getTableCellValue(row, dataLabel, sortType) {
        let cell = row.querySelector(`td[data-label="${dataLabel}"]`);
        
        if (!cell) {
             const headers = Array.from(row.closest('table').querySelectorAll('th'));
             const simpleLabel = dataLabel.toLowerCase().replace(' ', '');
             const index = headers.findIndex(th => {
                const sortBy = th.dataset.sortBy;
                if(sortBy) {
                    return sortBy === simpleLabel || sortBy === dataLabel;
                }
                return th.textContent.trim().toLowerCase() === simpleLabel;
             });

             if (index !== -1) {
                cell = row.querySelectorAll('td')[index];
             }
        }
        
        if (!cell && (dataLabel === 'Name' || dataLabel === 'Item Name' || dataLabel === 'Product')) {
             cell = row.querySelector('td:first-child');
        }

        let value = cell ? cell.textContent.trim() : '';

        if (sortType === 'number') {
            value = value.replace(/[₱(),]/g, '');
            return parseFloat(value) || 0;
        } else if (sortType === 'date') {
            return new Date(value).getTime() || 0;
        }
        
        return value.toLowerCase();
    }

    // --- Universal Table Paginator & Sorter (for other tabs) ---
    function setupSortableTable(tableBodyId, searchInputId, rowsSelectId, prevBtnId, nextBtnId) {
        const tableBody = document.getElementById(tableBodyId);
        if (!tableBody) {
             // This is not an error, it just means this tab isn't the card grid
             // console.log('Skipping table setup for:', tableBodyId);
            return;
        }

        let rows = Array.from(tableBody.querySelectorAll('tr:not([id$="-no-results"])'));
        let filteredRows = [...rows];
        let currentPage = 1;
        
        const rowsSelect = document.getElementById(rowsSelectId);
        let rowsPerPage = (rowsSelect && rowsSelect.value) ? (rowsSelect.value === 'all' ? 'all' : parseInt(rowsSelect.value, 10)) : 10;
        
        let currentSort = { by: 'name', dir: 'asc', type: 'text' }; // Default sort
        
        const searchInput = document.getElementById(searchInputId);
        const prevBtn = document.getElementById(prevBtnId);
        const nextBtn = document.getElementById(nextBtnId);
        const noResultsRow = document.getElementById(tableBodyId.replace('-body', '-no-results'));

        const parentCard = tableBody.closest('.card');
        
        function renderTable() {
            // 1. Sort
            if (currentSort.by) {
                filteredRows.sort((a, b) => {
                    const valA = getTableCellValue(a, currentSort.by, currentSort.type);
                    const valB = getTableCellValue(b, currentSort.by, currentSort.type);
                    
                    if (valA < valB) return currentSort.dir === 'asc' ? -1 : 1;
                    if (valA > valB) return currentSort.dir === 'asc' ? 1 : -1;
                    return 0;
                });
            }

            // 2. Paginate
            tableBody.innerHTML = ''; // Clear table
            const totalPages = (rowsPerPage === 'all') ? 1 : Math.ceil(filteredRows.length / rowsPerPage);
            currentPage = Math.max(1, Math.min(currentPage, totalPages));

            const start = (rowsPerPage === 'all') ? 0 : (currentPage - 1) * rowsPerPage;
            const end = (rowsPerPage === 'all') ? filteredRows.length : start + rowsPerPage;
            const pageRows = filteredRows.slice(start, end);

            pageRows.forEach(row => tableBody.appendChild(row));
            
            // 3. Update Controls
            if (prevBtn) prevBtn.disabled = (currentPage === 1);
            if (nextBtn) nextBtn.disabled = (currentPage === totalPages || filteredRows.length === 0);
            
            // 4. Show 'no results' row if needed
            if (noResultsRow) {
                if (filteredRows.length === 0) {
                    tableBody.appendChild(noResultsRow);
                    noResultsRow.style.display = ''; // Use default display (which is table-row)
                } else {
                    noResultsRow.style.display = 'none';
                }
            }
        }

        // --- Event Listeners ---
        
        // Search
        if (searchInput) {
            searchInput.addEventListener('keyup', () => {
                const searchTerm = searchInput.value.toLowerCase();
                filteredRows = rows.filter(row => {
                    // Check first cell (main data point) for search
                    const firstCell = row.querySelector('td:first-child');
                    return firstCell.textContent.toLowerCase().includes(searchTerm);
                });
                currentPage = 1;
                renderTable();
            });
        }

        // Rows per page
        if (rowsSelect) {
            rowsSelect.addEventListener('change', () => {
                const val = rowsSelect.value;
                rowsPerPage = (val === 'all') ? 'all' : parseInt(val);
                currentPage = 1;
                renderTable();
            });
        }
        
        // Pagination buttons
        if (prevBtn) {
            prevBtn.addEventListener('click', () => {
                if (currentPage > 1) {
                    currentPage--;
                    renderTable();
                }
            });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', () => {
                const totalPages = (rowsPerPage === 'all') ? 1 : Math.ceil(filteredRows.length / rowsPerPage);
                if (currentPage < totalPages) {
                    currentPage++;
                    renderTable();
                }
            });
        }

        // Sorting
        if (parentCard) {
            const sortTriggers = parentCard.querySelectorAll('.dropdown-menu .sort-trigger');
            const sortText = parentCard.querySelector('.current-sort-text');

            sortTriggers.forEach(trigger => {
                trigger.addEventListener('click', (e) => {
                    e.preventDefault();
                    
                    const sortBy = trigger.dataset.sortBy;
                    const sortDir = trigger.dataset.sortDir;
                    const sortType = trigger.dataset.sortType || 'text';
                    
                    let labelForSort = sortBy;
                    if(sortBy === 'name') labelForSort = 'Name';
                    if(sortBy === 'item') labelForSort = 'Item Name';
                    if(sortBy === 'timestamp') labelForSort = 'Timestamp';
                    if(sortBy === 'price') labelForSort = 'Price';
                    if(sortBy === 'stock') labelForSort = 'Current Stock';
                    if(sortBy === 'status') labelForSort = 'Status';
                    if(sortBy === 'unit') labelForSort = 'Unit';
                    if(sortBy === 'reorder') labelForSort = 'Reorder Level';
                    if(sortBy === 'type') labelForSort = 'Type';
                    if(sortBy === 'qty') labelForSort = 'Quantity';
                    if(sortBy === 'user') labelForSort = 'User';
                    if(sortBy === 'reason') labelForSort = 'Reason';

                    currentSort.by = labelForSort;
                    currentSort.dir = sortDir;
                    currentSort.type = sortType;
                    
                    if (sortText) sortText.textContent = trigger.textContent;
                    sortTriggers.forEach(t => t.classList.remove('active'));
                    trigger.classList.add('active');
                    
                    currentPage = 1;
                    renderTable();
                });
            });
        }
        
        // Initial render
        const activeSort = parentCard ? parentCard.querySelector('.dropdown-menu .sort-trigger.active') : null;
        if (activeSort) {
            let labelForSort = activeSort.dataset.sortBy;
            if(labelForSort === 'name') labelForSort = 'Name';
            if(labelForSort === 'item') labelForSort = 'Item Name';
            if(labelForSort === 'timestamp') labelForSort = 'Timestamp';

            currentSort.by = labelForSort;
            currentSort.dir = activeSort.dataset.sortDir;
            currentSort.type = activeSort.dataset.sortType || 'text';
        }
        
        renderTable();
    }
    
    // --- Initialize ALL tabs ---
    
    // TAB 1: Products (uses NEW card grid)
    setupCardGrid('product-card-list', 'product-search-input', 'product-rows-select', 'product-prev-btn', 'product-next-btn');
    
    // TAB 2: Ingredients (uses old table)
    setupSortableTable('ingredient-table-body', 'ingredient-search-input', 'ingredient-rows-select', 'ingredient-prev-btn', 'ingredient-next-btn');
    
    // TAB 3: Discontinued (uses old table)
    setupSortableTable('discontinued-table-body', null, 'discontinued-rows-select', 'discontinued-prev-btn', 'discontinued-next-btn');
    
    // TAB 4: Recall (uses old table)
    setupSortableTable('recall-table-body', null, 'recall-rows-select', 'recall-prev-btn', 'recall-next-btn');
    
    // TAB 5: History (uses old table)
    setupSortableTable('history-table-body', null, 'history-rows-select', 'history-prev-btn', 'history-next-btn');

});