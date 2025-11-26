document.addEventListener('DOMContentLoaded', () => {
    const style = document.createElement('style');
    style.innerHTML = `
        /* Smooth fade for table transitions */
        .table-body-transition {
            transition: opacity 0.2s ease, transform 0.2s ease;
            opacity: 1;
            transform: translateY(0);
        }
        .table-loading {
            opacity: 0.4;
            transform: translateY(3px);
            pointer-events: none; /* Prevent clicks while loading */
        }

        /* Card Entrance Animation */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-enter {
            animation: fadeInUp 0.4s cubic-bezier(0.165, 0.84, 0.44, 1) forwards;
        }

        /* Number Pulse (Green) for Positive Updates */
        @keyframes pulseGreen {
            0% { transform: scale(1); }
            50% { transform: scale(1.15); color: #198754; text-shadow: 0 0 5px rgba(25, 135, 84, 0.2); }
            100% { transform: scale(1); }
        }
        .pulse-update-green {
            animation: pulseGreen 0.4s ease-out;
        }

        /* Number Pulse (Red) for Negative Updates */
        @keyframes pulseRed {
            0% { transform: scale(1); }
            50% { transform: scale(1.15); color: #dc3545; text-shadow: 0 0 5px rgba(220, 53, 69, 0.2); }
            100% { transform: scale(1); }
        }
        .pulse-update-red {
            animation: pulseRed 0.4s ease-out;
        }
        
        /* Helper Text Slide */
        .helper-text-transition {
            transition: all 0.3s ease;
            height: auto;
            opacity: 1;
        }
    `;
    document.head.appendChild(style);
    // ==========================================
    // 1. Product Search (With Entrance Animations)
    // ==========================================
    const productSearch = document.getElementById('product-search-input');
    const productList = document.getElementById('product-card-list');
    const productNoResults = document.getElementById('product-no-results');
    
    if (productSearch && productList) {
        productSearch.addEventListener('keyup', (e) => {
            const term = e.target.value.toLowerCase();
            const items = productList.querySelectorAll('.product-item');
            let found = 0;
            
            items.forEach(item => {
                const name = item.dataset.productName ? item.dataset.productName.toLowerCase() : '';
                const isMatch = name.includes(term);

                if (isMatch) {
                    // **ANIMATION**: If it was hidden, add animation class
                    if (item.style.display === 'none') {
                        item.classList.remove('animate-enter'); // Reset
                        void item.offsetWidth; // Trigger reflow
                        item.classList.add('animate-enter');
                    }
                    item.style.display = '';
                    found++;
                } else {
                    item.style.display = 'none';
                }
            });
            
            // **ANIMATION**: Smooth fade for "No Results"
            if(productNoResults) {
                if(found === 0 && items.length > 0) {
                     productNoResults.classList.remove('hidden');
                     productNoResults.classList.add('animate-enter');
                } else {
                     productNoResults.classList.add('hidden');
                }
            }
        });
    }

    // ==========================================
    // 2. Ingredient Search
    // ==========================================
    const ingredientSearch = document.getElementById('ingredient-search-input');
    const ingredientTable = document.getElementById('ingredient-table-body');
    const ingredientNoResults = document.getElementById('ingredient-no-results');
    
    if (ingredientSearch && ingredientTable) {
        // Apply transition class to table immediately
        ingredientTable.classList.add('table-body-transition');

        ingredientSearch.addEventListener('keyup', (e) => {
            const term = e.target.value.toLowerCase();
            const rows = ingredientTable.querySelectorAll('tr:not(#ingredient-no-results)');
            let found = 0;

            rows.forEach(row => {
                if (row.cells.length < 2 && !row.dataset.name) return; 

                const name = row.dataset.name || (row.cells[0] ? row.cells[0].textContent.toLowerCase() : '');
                
                if (name.includes(term)) {
                    row.style.display = '';
                    row.dataset.paginatedHidden = 'false'; 
                    found++;
                } else {
                    row.style.display = 'none';
                    row.dataset.paginatedHidden = 'true';
                }
            });

            if(ingredientNoResults) {
                 if(found === 0 && rows.length > 0) {
                     ingredientNoResults.classList.remove('hidden');
                     // Simple fade in for no results row
                     ingredientNoResults.style.opacity = '0';
                     setTimeout(() => ingredientNoResults.style.opacity = '1', 50);
                } else {
                     ingredientNoResults.classList.add('hidden');
                }
            }
            
            const select = document.getElementById('ingredient-rows-select');
            if (select) select.dispatchEvent(new Event('change'));
        });
    }

    // ==========================================
    // 3. Modal Data Logic (Unchanged)
    // ==========================================

    function onModalOpen(modalId, callback) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.addEventListener('open-modal', function (event) {
                callback(event.detail.relatedTarget);
            });
        }
    }

    // Add Ingredient
    onModalOpen('addIngredientModal', (button) => {
        const form = document.querySelector('#addIngredientModal form');
        if(form) form.reset();
    });

    // Add Product
    onModalOpen('addProductModal', (button) => {
        const form = document.querySelector('#addProductModal form');
        if(form) form.reset();
    });
    
    // Restock Ingredient Modal
    const restockIngredientModal = document.getElementById('restockIngredientModal');
    if (restockIngredientModal) {
        const expiryInput = restockIngredientModal.querySelector('#restock_ing_expiration');
        const noExpiryCheck = restockIngredientModal.querySelector('#restock_no_expiry');

        if (noExpiryCheck && expiryInput) {
            noExpiryCheck.addEventListener('change', function() {
                expiryInput.value = '';
                expiryInput.disabled = this.checked;
                expiryInput.required = !this.checked;
                // **ANIMATION**: Opacity change on disable
                expiryInput.style.opacity = this.checked ? '0.5' : '1';
                expiryInput.style.transition = 'opacity 0.2s';
            });
        }

        onModalOpen('restockIngredientModal', (button) => {
            if (!button) return;
            
            const ingredientId = button.dataset.ingredientId;
            const ingredientName = button.dataset.ingredientName;

            restockIngredientModal.querySelector('#restock_ingredient_id').value = ingredientId;
            restockIngredientModal.querySelector('#restock_ingredient_name').textContent = ingredientName;
            
            const qtyInput = restockIngredientModal.querySelector('#restock_ing_qty');
            const noteInput = restockIngredientModal.querySelector('#restock_ing_reason_note');
            if(qtyInput) qtyInput.value = '';
            if(noteInput) noteInput.value = '';
            
            if (noExpiryCheck) noExpiryCheck.checked = false;
            if (expiryInput) {
                expiryInput.value = '';
                expiryInput.disabled = false;
                expiryInput.required = true;
                expiryInput.style.opacity = '1';
            }
        });
    }

    // Adjust Product Modal (With animated Helper Text)
    const adjustProductModal = document.getElementById('adjustProductModal');
    if (adjustProductModal) {
        const qtyInput = adjustProductModal.querySelector('#adjust_adjustment_qty');
        const typeSelect = adjustProductModal.querySelector('#adjust_type');
        const qtyHelper = adjustProductModal.querySelector('#adjust_qty_helper');

        if(qtyHelper) qtyHelper.classList.add('helper-text-transition'); // Add transition class

        const updateHelperText = () => {
            const qty = parseInt(qtyInput.value, 10);
            const type = typeSelect.value;
            
            // **ANIMATION**: Fade out slightly before changing
            qtyHelper.style.opacity = '0.7';

            setTimeout(() => {
                if (!qty || isNaN(qty)) {
                    qtyHelper.textContent = 'Enter a whole number.';
                    qtyHelper.className = 'text-xs text-gray-500 mt-1 helper-text-transition';
                    return;
                }

                if (type === 'Production' && qty < 0) {
                    qtyHelper.textContent = 'Production quantity should be positive.';
                    qtyHelper.className = 'text-xs text-red-500 mt-1 helper-text-transition';
                } else if (type === 'Recall' && qty > 0) { 
                    qtyHelper.textContent = 'Recall quantity should be negative (e.g., -5).'; 
                    qtyHelper.className = 'text-xs text-red-500 mt-1 helper-text-transition';
                } else {
                    let actionText = '';
                    if (type === 'Production') {
                        actionText = `ADD ${qty} pcs to stock and DEDUCT ingredients.`;
                    } else if (type === 'Recall') { 
                        actionText = `REMOVE ${Math.abs(qty)} pcs from stock.`;
                    } else if (type === 'Correction') {
                        actionText = (qty > 0) ? `ADD ${qty} pcs.` : `REMOVE ${Math.abs(qty)} pcs.`;
                    }
                    qtyHelper.textContent = actionText;
                    qtyHelper.className = (qty > 0) ? 'text-xs text-green-600 mt-1 helper-text-transition' : 'text-xs text-red-500 mt-1 helper-text-transition';
                }
                // **ANIMATION**: Fade back in
                qtyHelper.style.opacity = '1';
            }, 100);
        };

        if(qtyInput) qtyInput.addEventListener('input', updateHelperText);
        if(typeSelect) typeSelect.addEventListener('change', updateHelperText);

        onModalOpen('adjustProductModal', (button) => {
            if (!button) return;
            adjustProductModal.querySelector('#adjust_product_id').value = button.dataset.productId;
            adjustProductModal.querySelector('#adjust_product_name').textContent = button.dataset.productName;

            if(qtyInput) qtyInput.value = ''; 
            if(qtyHelper) qtyHelper.textContent = '';
        });
    }

    // Edit Ingredient Modal
    const editIngredientModal = document.getElementById('editIngredientModal');
    if (editIngredientModal) {
        onModalOpen('editIngredientModal', (button) => {
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
        onModalOpen('editProductModal', (button) => {
            if (!button) return;
            editProductModal.querySelector('#edit_product_id').value = button.dataset.productId;
            editProductModal.querySelector('#edit_product_name').value = button.dataset.productName;
            editProductModal.querySelector('#edit_product_price').value = button.dataset.productPrice;
            editProductModal.querySelector('#edit_product_status').value = button.dataset.productStatus;
            editProductModal.querySelector('#edit_product_current_image').value = button.dataset.productImage || '';

            const status = button.dataset.productStatus;
            const activeTabInput = editProductModal.querySelector('#edit_product_active_tab');
            if(activeTabInput) {
                activeTabInput.value = (status === 'discontinued') ? 'discontinued' : 'products';
            }
        });
    }
    
    // Delete Ingredient Modal
    const deleteIngredientModal = document.getElementById('deleteIngredientModal');
    if (deleteIngredientModal) {
        onModalOpen('deleteIngredientModal', (button) => {
            if (!button) return;
            deleteIngredientModal.querySelector('#delete_ingredient_id').value = button.dataset.ingredientId;
            deleteIngredientModal.querySelector('#delete_ingredient_name').textContent = button.dataset.ingredientName;
        });
    }
    
    // Delete Product Modal
    const deleteProductModal = document.getElementById('deleteProductModal');
    if (deleteProductModal) {
        onModalOpen('deleteProductModal', (button) => {
            if (!button) return;
            deleteProductModal.querySelector('#delete_product_id').value = button.dataset.productId;
            deleteProductModal.querySelector('#delete_product_name').textContent = button.dataset.productName;
            
            const activeTabInput = deleteProductModal.querySelector('#delete_product_active_tab');
            if(activeTabInput) {
                activeTabInput.value = 'products';
            }
        });
    }

    
    // ==========================================
    // 4. Batch Details Logic (With Pulse Animations)
    // ==========================================
    
    const batchesModalEl = document.getElementById('batchesModal');
    if (batchesModalEl) {
        onModalOpen('batchesModal', (button) => {
            if (!button) return;

            const id = button.dataset.id;
            const name = button.dataset.name;
            const unit = button.dataset.unit;
            
            const titleEl = document.getElementById('batch_modal_title');
            if(titleEl) titleEl.textContent = name;
            
            batchesModalEl.dataset.currentIngredientId = id;
            batchesModalEl.dataset.currentUnit = unit;

            loadBatchData(id, unit);
        });

        const tbody = document.getElementById('batches_table_body');
        if (tbody) {
            tbody.addEventListener('click', function(e) {
                const target = e.target.closest('button');
                if (!target) return;
                
                const row = target.closest('tr');
                const batchId = row.dataset.batchId;

                if (target.classList.contains('edit-expiry-btn')) {
                    toggleEditMode(row, 'expiry', true);
                } 
                else if (target.classList.contains('save-expiry-btn')) {
                    saveExpiry(row, batchId);
                } 
                else if (target.classList.contains('cancel-expiry-btn')) {
                    toggleEditMode(row, 'expiry', false);
                }
                else if (target.classList.contains('clear-expiry-btn')) {
                    const dateInput = row.querySelector('.expiry-input');
                    if(dateInput) dateInput.value = '';
                }

                else if (target.classList.contains('correction-batch-btn')) {
                     toggleEditMode(row, 'qty', true);
                }
                else if (target.classList.contains('save-qty-btn')) {
                     saveQuantity(row, batchId);
                }
                else if (target.classList.contains('cancel-qty-btn')) {
                     toggleEditMode(row, 'qty', false);
                }

                else if (target.classList.contains('delete-batch-btn')) {
                    deleteBatch(row, batchId);
                }
            });

            // **ANIMATION**: Pulse effect when number changes
            tbody.addEventListener('input', function(e) {
                if (e.target.classList.contains('qty-adjustment-input')) {
                    const row = e.target.closest('tr');
                    const current = parseFloat(row.dataset.originalQty);
                    const adjustment = parseFloat(e.target.value);
                    const newTotalSpan = row.querySelector('.new-total-display');
                    
                    if (!isNaN(adjustment)) {
                        const newTotal = current + adjustment;
                        newTotalSpan.textContent = newTotal.toFixed(2);
                        
                        // Decide color
                        const isNegative = newTotal < 0; // Though total shouldn't be negative usually
                        newTotalSpan.className = 'new-total-display font-bold ' + (isNegative ? 'text-red-500' : 'text-green-600');
                        
                        // Add Pulse Class based on adjustment
                        newTotalSpan.classList.remove('pulse-update-green', 'pulse-update-red');
                        void newTotalSpan.offsetWidth; // Trigger reflow
                        newTotalSpan.classList.add(adjustment >= 0 ? 'pulse-update-green' : 'pulse-update-red');

                    } else {
                        newTotalSpan.textContent = current.toFixed(2);
                        newTotalSpan.className = 'new-total-display font-bold text-gray-500';
                    }
                }
            });
        }
    }

    // Helper stubs (unchanged)
    function loadBatchData(id, unit) { /* ... */ }
    function toggleEditMode(row, type, isEditing) { /* ... */ }
    function callAPI(action, payload, onSuccess) { /* ... */ }
    function updateMainTableStock(adjustmentAmount) { /* ... */ }
    function saveExpiry(row, batchId) { /* ... */ }
    function saveQuantity(row, batchId) { /* ... */ }
    function deleteBatch(row, batchId) { /* ... */ }
    
    // ==========================================
    // 5. Reusable Table Functions (With Smooth Transitions)
    // ==========================================

    function getSortableValue(cell, type = 'text') {
        const textValue = cell.innerText;
        if (type === 'number' && cell.dataset.sortValue !== undefined) {
             const num = parseFloat(cell.dataset.sortValue);
             return isNaN(num) ? 0 : num;
        }
        
        let cleaned = textValue.trim();
        switch (type) {
            case 'number':
                cleaned = cleaned.replace(/P|kg|g|L|ml|pcs|pack|tray|can|bottle|\+|\(|\)/gi, '');
                cleaned = cleaned.replace(/,/g, '');
                const num = parseFloat(cleaned);
                return isNaN(num) ? 0 : num;
            case 'date':
                let dateVal = Date.parse(cleaned);
                return isNaN(dateVal) ? 0 : dateVal;
            default: // 'text'
                const lowerVal = cleaned.toLowerCase();
                if (lowerVal.includes('low stock')) return 'a_low_stock';
                if (lowerVal.includes('in stock')) return 'b_in_stock';
                if (lowerVal.includes('available')) return 'c_available'; 
                if (lowerVal.includes('discontinued')) return 'd_discontinued'; 
                if (lowerVal.includes('ingredient')) return 'e_ingredient';
                if (lowerVal.includes('product')) return 'f_product';
                return lowerVal;
        }
    }

    function addTablePagination(selectId, tableBodyId) {
        const select = document.getElementById(selectId);
        const tableBody = document.getElementById(tableBodyId);
        const baseId = selectId.replace('-rows-select', '');
        const prevBtn = document.getElementById(`${baseId}-prev-btn`);
        const nextBtn = document.getElementById(`${baseId}-next-btn`);
        
        if (!select || !tableBody || !prevBtn || !nextBtn) {
            return;
        }

        // Add transition class to body
        tableBody.classList.add('table-body-transition');

        let currentPage = 0;

        const updateTableRows = () => {
            // **ANIMATION**: Start "Loading" state (fade out slightly, move down)
            tableBody.classList.add('table-loading');

            // Wait 200ms for fade out, then swap data and fade in
            setTimeout(() => {
                const selectedValue = select.value;
                const all_rows = tableBody.querySelectorAll('tr:not([id$="-no-results"]):not(tfoot tr)');
                const visibleRows = [];
                
                all_rows.forEach(row => {
                    if (row.dataset.paginatedHidden !== 'true') {
                        visibleRows.push(row);
                    }
                });

                if (selectedValue === 'all') {
                    visibleRows.forEach(row => row.style.display = '');
                    prevBtn.disabled = true;
                    nextBtn.disabled = true;
                    // **ANIMATION**: Remove loading state
                    tableBody.classList.remove('table-loading');
                    return;
                }

                const limit = parseInt(selectedValue, 10);
                const totalRows = visibleRows.length;
                const totalPages = Math.ceil(totalRows / limit);
                
                if (currentPage >= totalPages) {
                    currentPage = Math.max(0, totalPages - 1);
                }

                const start = currentPage * limit;
                const end = start + limit;

                all_rows.forEach(row => row.style.display = 'none'); 

                visibleRows.forEach((row, index) => {
                    if (index >= start && index < end) {
                        row.style.display = '';
                    }
                });

                prevBtn.disabled = currentPage === 0;
                nextBtn.disabled = (currentPage >= totalPages - 1) || (totalRows === 0);

                // **ANIMATION**: Remove loading state (fade in, move up)
                tableBody.classList.remove('table-loading');

            }, 200); // 200ms delay matches CSS transition
        };

        prevBtn.addEventListener('click', () => {
            if (currentPage > 0) {
                currentPage--;
                updateTableRows();
            }
        });

        nextBtn.addEventListener('click', () => {
            if (select.value === 'all') return;
            currentPage++;
            updateTableRows();
        });
        
        select.addEventListener('change', () => {
            currentPage = 0; 
            updateTableRows();
        });
        
        // Initial run (no delay needed for first run)
        const initialRun = () => {
             // ... same logic without timeout ...
             // Simplified: just trigger the event or call function but we want instant load
             select.dispatchEvent(new Event('change')); 
        };
        // Use timeout 0 to let other scripts finish first
        setTimeout(initialRun, 0); 
    }
    
    function sortTableByDropdown(sortLink) {
        const sortBy = sortLink.dataset.sortBy;
        const sortType = sortLink.dataset.sortType;
        const sortDir = sortLink.dataset.sortDir; 

        const tableBody = sortLink.closest('.dropdown').closest('.bg-white').querySelector('tbody');
        if (!tableBody) return;
        
        // **ANIMATION**: Start Transition
        tableBody.classList.add('table-loading');

        setTimeout(() => {
            const table = tableBody.closest('table');
            const headerRow = table.querySelector('thead tr');
            let colIndex = -1;
            Array.from(headerRow.querySelectorAll('th')).forEach((th, index) => {
                if (th.dataset.sortBy === sortBy) {
                    colIndex = index;
                }
            });
            
            if (colIndex === -1) {
                console.error(`Sort Error: Could not find column index for data-sort-by="${sortBy}"`);
                tableBody.classList.remove('table-loading'); // Reset if error
                return;
            }

            const rows = Array.from(tableBody.querySelectorAll('tr:not([id$="-no-results"]):not(tfoot tr)'));
            
            rows.sort((a, b) => {
                if (a.cells.length <= colIndex || b.cells.length <= colIndex) return 0;
                const valA = getSortableValue(a.cells[colIndex], sortType); 
                const valB = getSortableValue(b.cells[colIndex], sortType);
                
                let comparison = 0;
                if (valA > valB) comparison = 1;
                else if (valA < valB) comparison = -1;
                
                return sortDir === 'DESC' ? (comparison * -1) : comparison;
            });

            // Detach and re-append sorted rows
            rows.forEach(row => tableBody.appendChild(row));
            
            const dropdown = sortLink.closest('.dropdown');
            if (dropdown) {
                const buttonTextSpan = dropdown.querySelector('.current-sort-text');
                buttonTextSpan.textContent = sortLink.textContent.trim();
                
                const dropdownItems = dropdown.querySelectorAll('.sort-trigger');
                dropdownItems.forEach(item => item.classList.remove('active'));
                sortLink.classList.add('active');
            }
            
            // Re-apply pagination
            const paginationSelectId = table.closest('.bg-white').querySelector('select[id$="-rows-select"]')?.id;
            if (paginationSelectId) {
                const paginationSelect = document.getElementById(paginationSelectId);
                if (paginationSelect) {
                    paginationSelect.dispatchEvent(new Event('change'));
                }
            }
            
            // **ANIMATION**: End Transition
            tableBody.classList.remove('table-loading');

        }, 200); // Wait for transition
    }
    
    function setupDropdown(dropdownId) {
        const dropdownEl = document.getElementById(dropdownId);
        const sortButton = document.getElementById(dropdownId.replace('-dropdown', '-btn'));
        const sortMenu = document.getElementById(dropdownId.replace('-dropdown', '-menu'));
        
        if (dropdownEl && sortButton && sortMenu) {
            sortButton.addEventListener('click', (e) => {
                e.stopPropagation(); 
                // Simple opacity toggle handled by CSS or class
                if (sortMenu.classList.contains('hidden')) {
                    sortMenu.classList.remove('hidden');
                    // Add simple entrance animation
                    sortMenu.style.opacity = '0';
                    sortMenu.style.transform = 'translateY(-5px)';
                    setTimeout(() => {
                         sortMenu.style.transition = 'opacity 0.1s, transform 0.1s';
                         sortMenu.style.opacity = '1';
                         sortMenu.style.transform = 'translateY(0)';
                    }, 10);
                } else {
                    sortMenu.classList.add('hidden');
                }
            });

            document.addEventListener('click', (e) => {
                if (!sortMenu.contains(e.target) && !sortButton.contains(e.target)) {
                    sortMenu.classList.add('hidden');
                }
            });
            
            document.querySelectorAll(`#${dropdownId} .sort-trigger`).forEach(link => {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    sortTableByDropdown(e.target);
                    sortMenu.classList.add('hidden'); 
                });
            });

            const defaultSortLink = dropdownEl.querySelector('.sort-trigger.active');
            if (defaultSortLink) {
                 setTimeout(() => sortTableByDropdown(defaultSortLink), 50);
            }
        }
    }


    function initTableFeatures() {
        // 1. Ingredients Table
        addTablePagination('ingredient-rows-select', 'ingredient-table-body');
        setupDropdown('ingredient-sort-dropdown');
        
        // 2. Discontinued Products Table
        addTablePagination('discontinued-rows-select', 'discontinued-table-body');
        setupDropdown('discontinued-sort-dropdown');

        // 3. Recall Log
        addTablePagination('recall-rows-select', 'recall-table-body');
        setupDropdown('recall-sort-dropdown');

        // 4. Adjustment History
        addTablePagination('history-rows-select', 'history-table-body');
        setupDropdown('history-sort-dropdown');
    }

    initTableFeatures();

});