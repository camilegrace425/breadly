document.addEventListener('DOMContentLoaded', () => {

    const addIngredientModal = document.getElementById('addIngredientModal');
    if (addIngredientModal) {
        addIngredientModal.addEventListener('show.bs.modal', function (event) {
        });
    }

    const addProductModal = document.getElementById('addProductModal');
    if (addProductModal) {
        addProductModal.addEventListener('show.bs.modal', function (event) {
        });
    }
    
    // Restock Ingredient Modal
    const restockIngredientModal = document.getElementById('restockIngredientModal');
    if (restockIngredientModal) {
        const expiryInput = restockIngredientModal.querySelector('#restock_ing_expiration');
        const noExpiryCheck = restockIngredientModal.querySelector('#restock_no_expiry');

        // Handle N/A Expiration Checkbox
        if (noExpiryCheck && expiryInput) {
            noExpiryCheck.addEventListener('change', function() {
                if (this.checked) {
                    expiryInput.value = '';
                    expiryInput.disabled = true;
                    expiryInput.required = false;
                } else {
                    expiryInput.disabled = false;
                    expiryInput.required = true;
                }
            });
        }

        restockIngredientModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            if (!button) return;
            
            const ingredientId = button.dataset.ingredientId;
            const ingredientName = button.dataset.ingredientName;
            const ingredientUnit = button.dataset.ingredientUnit;

            restockIngredientModal.querySelector('#restock_ingredient_id').value = ingredientId;
            restockIngredientModal.querySelector('#restock_ingredient_name').textContent = ingredientName;
            restockIngredientModal.querySelector('#restock_ingredient_unit').textContent = ingredientUnit;
            
            // Reset inputs
            restockIngredientModal.querySelector('#restock_ing_qty').value = '';
            restockIngredientModal.querySelector('#restock_ing_reason_note').value = '';
            
            // Reset Expiration logic
            if (noExpiryCheck) noExpiryCheck.checked = false;
            if (expiryInput) {
                expiryInput.value = '';
                expiryInput.disabled = false;
                expiryInput.required = true;
            }
        });
    }

    // Adjust Product Modal
    const adjustProductModal = document.getElementById('adjustProductModal');
    if (adjustProductModal) {
        adjustProductModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            if (!button) return;
            adjustProductModal.querySelector('#adjust_product_id').value = button.dataset.productId;
            adjustProductModal.querySelector('#adjust_product_name').textContent = button.dataset.productName;

            const qtyInput = adjustProductModal.querySelector('#adjust_adjustment_qty');
            const qtyHelper = adjustProductModal.querySelector('#adjust_qty_helper');
            qtyInput.value = ''; 
            qtyHelper.textContent = '';
            
            const typeSelect = adjustProductModal.querySelector('#adjust_type');
            const updateHelperText = () => {
                const qty = parseInt(qtyInput.value, 10);
                const type = typeSelect.value;

                if (!qty || isNaN(qty)) {
                    qtyHelper.textContent = 'Enter a whole number.';
                    qtyHelper.className = 'form-text';
                    return;
                }

                if (type === 'Production' && qty < 0) {
                    qtyHelper.textContent = 'Production quantity should be positive.';
                    qtyHelper.className = 'form-text text-danger';
                } else if (type === 'Recall' && qty > 0) { 
                    qtyHelper.textContent = 'Recall quantity should be negative (e.g., -5).'; 
                    qtyHelper.className = 'form-text text-danger';
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
                    qtyHelper.className = (qty > 0) ? 'form-text text-success' : 'form-text text-danger';
                }
            };

            qtyInput.addEventListener('input', updateHelperText);
            typeSelect.addEventListener('change', updateHelperText);
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
            editProductModal.querySelector('#edit_product_current_image').value = button.dataset.productImage || '';

            const status = button.dataset.productStatus;
            const activeTabInput = editProductModal.querySelector('#edit_product_active_tab');
            activeTabInput.value = (status === 'discontinued') ? 'discontinued' : 'products';
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
            
            const activeTabInput = deleteProductModal.querySelector('#delete_product_active_tab');
            const pane = button.closest('.tab-pane');
            activeTabInput.value = (pane && pane.id === 'discontinued-pane') ? 'discontinued' : 'products';
        });
    }

    
    // 2. BATCH DETAILS MODAL LOGIC (Load, Edit, Delete)
    
    const batchesModalEl = document.getElementById('batchesModal');
    if (batchesModalEl) {
        // A. LOAD DATA ON OPEN
        batchesModalEl.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            if (!button) return;

            const id = button.dataset.id;
            const name = button.dataset.name;
            const unit = button.dataset.unit;
            
            document.getElementById('batch_modal_title').textContent = name;
            document.getElementById('batch_unit_display').textContent = unit;
            
            batchesModalEl.dataset.currentIngredientId = id;
            batchesModalEl.dataset.currentUnit = unit;

            loadBatchData(id, unit);
        });

        // B. HANDLE BUTTON CLICKS IN TABLE
        const tbody = document.getElementById('batches_table_body');
        tbody.addEventListener('click', function(e) {
            const target = e.target.closest('button');
            if (!target) return;
            
            const row = target.closest('tr');
            const batchId = row.dataset.batchId;

            // 1. Expiration Actions
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
                // Clear the date input for N/A
                const dateInput = row.querySelector('.expiry-input');
                if(dateInput) dateInput.value = '';
            }

            // 2. Correction Actions (Renamed from Adjust)
            else if (target.classList.contains('correction-batch-btn')) {
                 toggleEditMode(row, 'qty', true);
            }
            else if (target.classList.contains('save-qty-btn')) {
                 saveQuantity(row, batchId);
            }
            else if (target.classList.contains('cancel-qty-btn')) {
                 toggleEditMode(row, 'qty', false);
            }

            // 3. Delete Action
            else if (target.classList.contains('delete-batch-btn')) {
                deleteBatch(row, batchId);
            }
        });

        // C. HANDLE INPUT CHANGES FOR CORRECTION CALCULATION
        tbody.addEventListener('input', function(e) {
            if (e.target.classList.contains('qty-adjustment-input')) {
                const row = e.target.closest('tr');
                const current = parseFloat(row.dataset.originalQty);
                const adjustment = parseFloat(e.target.value);
                const newTotalSpan = row.querySelector('.new-total-display');
                
                if (!isNaN(adjustment)) {
                    const newTotal = current + adjustment;
                    newTotalSpan.textContent = newTotal.toFixed(2);
                    // Optional: Visual styling for negative/positive
                    newTotalSpan.className = 'new-total-display fw-bold ' + (newTotal < 0 ? 'text-danger' : 'text-primary');
                } else {
                    newTotalSpan.textContent = current.toFixed(2);
                    newTotalSpan.className = 'new-total-display fw-bold text-muted';
                }
            }
        });
    }

    // --- Helper: Load Batch Data ---
    function loadBatchData(id, unit) {
        const tbody = document.getElementById('batches_table_body');
        tbody.innerHTML = '<tr><td colspan="5" class="py-3"><div class="spinner-border spinner-border-sm text-primary" role="status"></div> Loading...</td></tr>';

        fetch(`get_ingredient_batches.php?id=${id}`)
            .then(response => response.json())
            .then(data => {
                tbody.innerHTML = '';
                if (data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" class="text-muted fst-italic py-3">No active batches. Use "Restock" to add one.</td></tr>';
                    return;
                }
                
                const today = new Date();
                today.setHours(0,0,0,0);

                data.forEach(batch => {
                    let expDisplay = 'N/A';
                    let expDateVal = '';
                    let statusHtml = '<span class="badge bg-secondary">No Expiry</span>';
                    
                    if (batch.expiration_date) {
                        const exp = new Date(batch.expiration_date);
                        expDisplay = exp.toLocaleDateString();
                        expDateVal = batch.expiration_date.split(' ')[0]; 

                        const diffTime = exp - today;
                        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)); 
                        
                        if (diffDays < 0) {
                            statusHtml = '<span class="badge bg-danger">Expired</span>';
                        } else if (diffDays <= 7) {
                            statusHtml = `<span class="badge bg-warning text-dark">Expiring (${diffDays} days)</span>`;
                        } else {
                            statusHtml = '<span class="badge bg-success">Good</span>';
                        }
                    }

                    const recDate = new Date(batch.date_received).toLocaleDateString();
                    const currentQty = parseFloat(batch.quantity);

                    const row = `
                        <tr data-batch-id="${batch.batch_id}" data-original-date="${expDateVal}" data-original-qty="${currentQty}">
                            <td>${recDate}</td>
                            
                            <td class="expiry-cell">
                                <div class="view-mode d-flex justify-content-center align-items-center gap-2">
                                    <span class="text-value">${expDisplay}</span>
                                    <button class="btn btn-link p-0 text-primary edit-expiry-btn" title="Edit Date"><i class="bi bi-pencil"></i></button>
                                </div>
                                <div class="edit-mode d-none flex-column gap-1">
                                    <div class="input-group input-group-sm">
                                        <input type="date" class="form-control expiry-input" value="${expDateVal}">
                                        <button class="btn btn-outline-secondary clear-expiry-btn" type="button" title="Set N/A"><i class="bi bi-x-circle"></i></button>
                                    </div>
                                    <div class="d-flex justify-content-center gap-1">
                                        <button class="btn btn-success btn-sm py-0 px-1 save-expiry-btn" title="Save"><i class="bi bi-check"></i></button>
                                        <button class="btn btn-secondary btn-sm py-0 px-1 cancel-expiry-btn" title="Cancel"><i class="bi bi-x"></i></button>
                                    </div>
                                </div>
                            </td>

                            <td class="qty-cell">
                                <div class="view-mode d-flex justify-content-center align-items-center gap-2">
                                    <span class="fw-bold text-value">${currentQty}</span>
                                </div>
                                <div class="edit-mode d-none flex-column gap-1">
                                    <input type="number" step="0.01" class="form-control form-control-sm qty-adjustment-input" placeholder="Adjust (+/-)">
                                    <div class="small text-muted">New Total: <span class="new-total-display">${currentQty}</span></div>
                                    <div class="d-flex justify-content-center gap-1">
                                        <button class="btn btn-success btn-sm py-0 px-1 save-qty-btn" title="Save"><i class="bi bi-check"></i></button>
                                        <button class="btn btn-secondary btn-sm py-0 px-1 cancel-qty-btn" title="Cancel"><i class="bi bi-x"></i></button>
                                    </div>
                                </div>
                            </td>

                            <td>${statusHtml}</td>
                            
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-warning correction-batch-btn" title="Correct Stock"><i class="bi bi-sliders"></i> Correction</button>
                                    <button class="btn btn-outline-danger delete-batch-btn" title="Delete Batch"><i class="bi bi-trash"></i></button>
                                </div>
                            </td>
                        </tr>`;
                    tbody.insertAdjacentHTML('beforeend', row);
                });
            })
            .catch(err => {
                tbody.innerHTML = '<tr><td colspan="5" class="text-danger py-3">Error loading batches.</td></tr>';
                console.error(err);
            });
    }

    // --- Helper: Toggle View/Edit Mode ---
    function toggleEditMode(row, type, isEditing) {
        const cell = row.querySelector(`.${type}-cell`);
        const view = cell.querySelector('.view-mode');
        const edit = cell.querySelector('.edit-mode');
        
        if (isEditing) {
            view.classList.remove('d-flex');
            view.classList.add('d-none');
            edit.classList.remove('d-none');
            edit.classList.add('d-flex');
            
            // Focus logic
            if (type === 'qty') {
                const input = edit.querySelector('.qty-adjustment-input');
                input.value = ''; // Reset adjustment to 0/empty
                row.querySelector('.new-total-display').textContent = row.dataset.originalQty;
                input.focus();
            } else {
                const input = edit.querySelector('input');
                if(input) input.focus();
            }

        } else {
            view.classList.remove('d-none');
            view.classList.add('d-flex');
            edit.classList.remove('d-flex');
            edit.classList.add('d-none');
            
            // Reset value to original for Expiry
            if (type === 'expiry') {
                cell.querySelector('input').value = row.dataset.originalDate;
            }
        }
    }

    // --- Helper: API Call ---
    function callAPI(action, payload, onSuccess) {
        fetch('inventory_management.php', { 
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: action, ...payload })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                onSuccess();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(err => {
            console.error(err);
            alert('An error occurred while processing your request.');
        });
    }

    function updateMainTableStock(adjustmentAmount) {
        const modal = document.getElementById('batchesModal');
        if (!modal) return;
        
        const ingredientId = modal.dataset.currentIngredientId;
        
        const editBtn = document.querySelector(`button[data-bs-target="#editIngredientModal"][data-ingredient-id="${ingredientId}"]`);
        
        if (editBtn) {
            const row = editBtn.closest('tr');
            if (!row) return;

            // Select cells based on data-label
            const stockCell = row.querySelector('td[data-label="Current Stock"]');
            const reorderCell = row.querySelector('td[data-label="Reorder Level"]');
            const statusCell = row.querySelector('td[data-label="Status"]');
            
            if (stockCell) {
                // Handle the <strong> tag inside the stock cell if it exists
                const stockTextTarget = stockCell.querySelector('strong') || stockCell;

                // 1. Calculate New Stock
                // Remove commas before parsing
                let currentStock = parseFloat(stockTextTarget.textContent.replace(/,/g, ''));
                if (isNaN(currentStock)) currentStock = 0;
                
                const newStock = currentStock + adjustmentAmount;
                
                // Update text (formatted to 2 decimal places)
                stockTextTarget.textContent = newStock.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                
                // Visual feedback: Flash green text
                stockTextTarget.classList.add('text-success');
                setTimeout(() => stockTextTarget.classList.remove('text-success'), 1500);

                // 2. Update Status Badge and Row Color
                if (reorderCell && statusCell) {
                    let reorderLevel = parseFloat(reorderCell.textContent.replace(/,/g, ''));
                    if (isNaN(reorderLevel)) reorderLevel = 0;

                    // Logic: Low Stock if Stock <= Reorder Level
                    if (newStock <= reorderLevel) {
                        // Add red row class if not present
                        if (!row.classList.contains('table-danger')) {
                            row.classList.add('table-danger');
                        }
                        // Update badge
                        statusCell.innerHTML = '<span class="badge bg-danger">Low Stock</span>';
                    } else {
                        // Remove red row class
                        row.classList.remove('table-danger');
                        // Update badge
                        statusCell.innerHTML = '<span class="badge bg-success">In Stock</span>';
                    }
                }
            }
        } else {
            console.warn("Could not find ingredient row to update for ID:", ingredientId);
        }
    }


    function saveExpiry(row, batchId) {
        const input = row.querySelector('.expiry-input');
        const newDate = input.value;
        
        // Basic validation
        if (!newDate) {
             Swal.fire({
                icon: 'warning',
                title: 'Invalid Date',
                text: 'Please select a valid expiration date.'
            });
            return;
        }

        callAPI('update_expiry', { 
            batch_id: batchId, 
            expiration_date: newDate 
        }, () => {
            // Reload the table to show the new date and recalculate status (e.g., "Good" or "Expiring")
            reloadTable();
            
            Swal.fire({
                icon: 'success',
                title: 'Updated!',
                text: 'The expiration date has been updated.',
                timer: 1500,
                showConfirmButton: false
            });
        });
    }

    function saveQuantity(row, batchId) {
        const input = row.querySelector('.qty-adjustment-input');
        // REMOVED: const reasonSelect = row.querySelector('.reason-select');
        
        const adjustment = parseFloat(input.value);
        if (isNaN(adjustment) || adjustment === 0) {
            alert("Please enter a valid adjustment amount (e.g., 5 or -2).");
            return;
        }

        const currentQty = parseFloat(row.dataset.originalQty);
        const newQty = currentQty + adjustment; 
        
        // REMOVED: const type = reasonSelect.value;
        
        // UPDATED: Default strictly to Correction format
        const reasonText = `[Correction] ${adjustment > 0 ? '+' : ''}${adjustment}`;

        callAPI('update_quantity', { 
            batch_id: batchId, 
            new_quantity: newQty, 
            reason: reasonText 
        }, () => {
            updateMainTableStock(adjustment); 
            reloadTable();
        });
    }

    function deleteBatch(row, batchId) {
        if (!confirm('Are you sure you want to delete this batch? This will remove the stock from the total inventory.')) {
            return;
        }
        
        const qtyToRemove = parseFloat(row.dataset.originalQty);

        callAPI('delete_batch', { 
            batch_id: batchId, 
            reason: '[Delete] Manual batch deletion' 
        }, () => {
            updateMainTableStock(-qtyToRemove); // Deduct total batch amount from main table
            reloadTable();
        });
    }

    function reloadTable() {
        const modal = document.getElementById('batchesModal');
        const id = modal.dataset.currentIngredientId;
        const unit = modal.dataset.currentUnit;
        loadBatchData(id, unit);
    }
    
    // 3. TABLE SORTING & PAGINATION UTILITIES
    function setupCardGrid(gridId, searchInputId, rowsSelectId, prevBtnId, nextBtnId) {
        const gridContainer = document.getElementById(gridId);
        if (!gridContainer) return;

        let allCards = Array.from(gridContainer.querySelectorAll('.product-item'));
        let filteredCards = [...allCards];
        let currentPage = 1;

        const rowsSelect = document.getElementById(rowsSelectId);
        let rowsPerPage = (rowsSelect && rowsSelect.value) ? (rowsSelect.value === 'all' ? 'all' : parseInt(rowsSelect.value, 10)) : 10;
        
        let currentSort = { by: 'productName', dir: 'asc', type: 'text' }; 
        
        const searchInput = document.getElementById(searchInputId);
        const prevBtn = document.getElementById(prevBtnId);
        const nextBtn = document.getElementById(nextBtnId);
        const noResultsMessage = document.getElementById(gridId.replace('-list', '-no-results'));
        
        const parentCard = gridContainer.closest('.card');

        function renderGrid() {
            // Sort
            if (currentSort.by) {
                filteredCards.sort((a, b) => {
                    let valA = a.dataset[currentSort.by];
                    let valB = b.dataset[currentSort.by];

                    if (currentSort.type === 'number') {
                        valA = parseFloat(valA) || 0;
                        valB = parseFloat(valB) || 0;
                    } else {
                        valA = valA ? valA.toLowerCase() : '';
                        valB = valB ? valB.toLowerCase() : '';
                    }
                    
                    if (valA < valB) return currentSort.dir === 'asc' ? -1 : 1;
                    if (valA > valB) return currentSort.dir === 'asc' ? 1 : -1;
                    return 0;
                });
            }

            // Paginate
            gridContainer.innerHTML = ''; 
            const totalPages = (rowsPerPage === 'all') ? 1 : Math.ceil(filteredCards.length / rowsPerPage);
            currentPage = Math.max(1, Math.min(currentPage, totalPages));

            const start = (rowsPerPage === 'all') ? 0 : (currentPage - 1) * rowsPerPage;
            const end = (rowsPerPage === 'all') ? filteredCards.length : start + rowsPerPage;
            const pageCards = filteredCards.slice(start, end);

            pageCards.forEach(card => gridContainer.appendChild(card));
            
            if (prevBtn) prevBtn.disabled = (currentPage === 1);
            if (nextBtn) nextBtn.disabled = (currentPage === totalPages || filteredCards.length === 0);
            
            if (noResultsMessage) {
                if (filteredCards.length === 0) {
                    gridContainer.appendChild(noResultsMessage);
                    noResultsMessage.style.display = 'block';
                } else {
                    noResultsMessage.style.display = 'none';
                }
            }
        }

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
                    const sortBy = trigger.dataset.sortBy; 
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
        
        // Initial Sort
        const activeSort = parentCard ? parentCard.querySelector('.dropdown-menu .sort-trigger.active') : null;
        if (activeSort) {
            const sortBy = activeSort.dataset.sortBy;
            currentSort.by = 'product' + sortBy.charAt(0).toUpperCase() + sortBy.slice(1);
            currentSort.dir = activeSort.dataset.sortDir;
            currentSort.type = activeSort.dataset.sortType || 'text';
        }
        renderGrid();
    }

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

    function setupSortableTable(tableBodyId, searchInputId, rowsSelectId, prevBtnId, nextBtnId) {
        const tableBody = document.getElementById(tableBodyId);
        if (!tableBody) return;

        let rows = Array.from(tableBody.querySelectorAll('tr:not([id$="-no-results"])'));
        let filteredRows = [...rows];
        let currentPage = 1;
        
        const rowsSelect = document.getElementById(rowsSelectId);
        let rowsPerPage = (rowsSelect && rowsSelect.value) ? (rowsSelect.value === 'all' ? 'all' : parseInt(rowsSelect.value, 10)) : 10;
        
        let currentSort = { by: 'name', dir: 'asc', type: 'text' }; 
        
        const searchInput = document.getElementById(searchInputId);
        const prevBtn = document.getElementById(prevBtnId);
        const nextBtn = document.getElementById(nextBtnId);
        const noResultsRow = document.getElementById(tableBodyId.replace('-body', '-no-results'));

        const parentCard = tableBody.closest('.card');
        
        function renderTable() {
            if (currentSort.by) {
                filteredRows.sort((a, b) => {
                    const valA = getTableCellValue(a, currentSort.by, currentSort.type);
                    const valB = getTableCellValue(b, currentSort.by, currentSort.type);
                    
                    if (valA < valB) return currentSort.dir === 'asc' ? -1 : 1;
                    if (valA > valB) return currentSort.dir === 'asc' ? 1 : -1;
                    return 0;
                });
            }

            tableBody.innerHTML = ''; 
            const totalPages = (rowsPerPage === 'all') ? 1 : Math.ceil(filteredRows.length / rowsPerPage);
            currentPage = Math.max(1, Math.min(currentPage, totalPages));

            const start = (rowsPerPage === 'all') ? 0 : (currentPage - 1) * rowsPerPage;
            const end = (rowsPerPage === 'all') ? filteredRows.length : start + rowsPerPage;
            const pageRows = filteredRows.slice(start, end);

            pageRows.forEach(row => tableBody.appendChild(row));
            
            if (prevBtn) prevBtn.disabled = (currentPage === 1);
            if (nextBtn) nextBtn.disabled = (currentPage === totalPages || filteredRows.length === 0);
            
            if (noResultsRow) {
                if (filteredRows.length === 0) {
                    tableBody.appendChild(noResultsRow);
                    noResultsRow.style.display = ''; 
                } else {
                    noResultsRow.style.display = 'none';
                }
            }
        }

        if (searchInput) {
            searchInput.addEventListener('keyup', () => {
                const searchTerm = searchInput.value.toLowerCase();
                filteredRows = rows.filter(row => {
                    const firstCell = row.querySelector('td:first-child');
                    return firstCell.textContent.toLowerCase().includes(searchTerm);
                });
                currentPage = 1;
                renderTable();
            });
        }

        if (rowsSelect) {
            rowsSelect.addEventListener('change', () => {
                const val = rowsSelect.value;
                rowsPerPage = (val === 'all') ? 'all' : parseInt(val);
                currentPage = 1;
                renderTable();
            });
        }
        
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
    
    // Initialize All Tabs
    setupCardGrid('product-card-list', 'product-search-input', 'product-rows-select', 'product-prev-btn', 'product-next-btn');
    setupSortableTable('ingredient-table-body', 'ingredient-search-input', 'ingredient-rows-select', 'ingredient-prev-btn', 'ingredient-next-btn');
    setupSortableTable('discontinued-table-body', null, 'discontinued-rows-select', 'discontinued-prev-btn', 'discontinued-next-btn');
    setupSortableTable('recall-table-body', null, 'recall-rows-select', 'recall-prev-btn', 'recall-next-btn');
    setupSortableTable('history-table-body', null, 'history-rows-select', 'history-prev-btn', 'history-next-btn');

    // In breadly/js/script_inventory.js

    function saveQuantity(row, batchId) {
        const input = row.querySelector('.qty-adjustment-input');
        
        const adjustment = parseFloat(input.value);
        if (isNaN(adjustment) || adjustment === 0) {
            Swal.fire({
                icon: 'warning',
                title: 'Invalid Quantity',
                text: 'Please enter a valid adjustment amount (e.g., 5 or -2).'
            });
            return;
        }

        const currentQty = parseFloat(row.dataset.originalQty);
        const newQty = currentQty + adjustment; 
        
        // Hardcoded 'Correction' reason as requested previously
        const reasonText = `[Correction] ${adjustment > 0 ? '+' : ''}${adjustment}`;

        Swal.fire({
            title: 'Confirm Correction?',
            text: `This will adjust the stock by ${adjustment > 0 ? '+' : ''}${adjustment}.`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, apply it!'
        }).then((result) => {
            if (result.isConfirmed) {
                callAPI('update_quantity', { 
                    batch_id: batchId, 
                    new_quantity: newQty, 
                    reason: reasonText 
                }, () => {
                    updateMainTableStock(adjustment); 
                    reloadTable();
                    Swal.fire({
                        icon: 'success',
                        title: 'Updated!',
                        text: 'The batch quantity has been corrected.',
                        timer: 1500,
                        showConfirmButton: false
                    });
                });
            }
        });
    }

    function deleteBatch(row, batchId) {
        Swal.fire({
            title: 'Are you sure?',
            text: "You won't be able to revert this! This will remove the stock from the total inventory.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                const qtyToRemove = parseFloat(row.dataset.originalQty);

                callAPI('delete_batch', { 
                    batch_id: batchId, 
                    reason: '[Delete] Manual batch deletion' 
                }, () => {
                    updateMainTableStock(-qtyToRemove); 
                    reloadTable();
                    Swal.fire(
                        'Deleted!',
                        'The batch has been deleted.',
                        'success'
                    );
                });
            }
        });
    }
});

