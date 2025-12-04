document.addEventListener('DOMContentLoaded', () => {

    // --- SEARCH LOGIC ---

    // 1. Product Search
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
                if (name.includes(term)) {
                    item.style.display = '';
                    found++;
                } else {
                    item.style.display = 'none';
                }
            });
            
            if(productNoResults) {
                if(found === 0 && items.length > 0) {
                     productNoResults.classList.remove('hidden');
                } else {
                     productNoResults.classList.add('hidden');
                }
            }
        });
    }

    // 2. Ingredient Search
    const ingredientSearch = document.getElementById('ingredient-search-input');
    const ingredientTable = document.getElementById('ingredient-table-body');
    const ingredientNoResults = document.getElementById('ingredient-no-results');
    
    if (ingredientSearch && ingredientTable) {
        ingredientSearch.addEventListener('keyup', (e) => {
            const term = e.target.value.toLowerCase();
            // Filter out non-data rows for search purposes
            const rows = ingredientTable.querySelectorAll('tr:not(#ingredient-no-results)');
            let found = 0;

            rows.forEach(row => {
                // Check if the row contains actual data
                if (row.cells.length < 2 && !row.dataset.name) return; 

                // Use data-name for robust filtering, fall back to cell content
                const name = row.dataset.name || (row.cells[0] ? row.cells[0].textContent.toLowerCase() : '');
                
                if (name.includes(term)) {
                    row.style.display = '';
                    row.dataset.paginatedHidden = 'false'; // Ensure pagination does not hide it if search found it
                    found++;
                } else {
                    row.style.display = 'none';
                    row.dataset.paginatedHidden = 'true';
                }
            });

            if(ingredientNoResults) {
                 if(found === 0 && rows.length > 0) {
                     ingredientNoResults.classList.remove('hidden');
                } else {
                     ingredientNoResults.classList.add('hidden');
                }
            }
            
            // Re-apply pagination logic after filtering
            const select = document.getElementById('ingredient-rows-select');
            if (select) select.dispatchEvent(new Event('change'));
        });
    }

    // --- MODAL DATA POPULATION LOGIC ---

    function onModalOpen(modalId, callback) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.addEventListener('open-modal', function (event) {
                callback(event.detail.relatedTarget);
            });
        }
    }

    // Add Ingredient (Reset form)
    onModalOpen('addIngredientModal', (button) => {
        const form = document.querySelector('#addIngredientModal form');
        if(form) form.reset();
    });

    // Add Product (Reset form)
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
            }
        });
    }

    // Adjust Product Modal
    const adjustProductModal = document.getElementById('adjustProductModal');
    if (adjustProductModal) {
        const qtyInput = adjustProductModal.querySelector('#adjust_adjustment_qty');
        const typeSelect = adjustProductModal.querySelector('#adjust_type');
        const qtyHelper = adjustProductModal.querySelector('#adjust_qty_helper');

        const updateHelperText = () => {
            const qty = parseInt(qtyInput.value, 10);
            const type = typeSelect.value;

            if (!qty || isNaN(qty)) {
                qtyHelper.textContent = 'Enter a whole number.';
                qtyHelper.className = 'text-xs text-gray-500 mt-1';
                return;
            }

            if (type === 'Production' && qty < 0) {
                qtyHelper.textContent = 'Production quantity should be positive.';
                qtyHelper.className = 'text-xs text-red-500 mt-1';
            } else if (type === 'Recall' && qty > 0) { 
                qtyHelper.textContent = 'Recall quantity should be negative (e.g., -5).'; 
                qtyHelper.className = 'text-xs text-red-500 mt-1';
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
                qtyHelper.className = (qty > 0) ? 'text-xs text-green-600 mt-1' : 'text-xs text-red-500 mt-1';
            }
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

    
    // --- BATCH DETAILS MODAL LOGIC ---
    
    const batchesModalEl = document.getElementById('batchesModal');
    if (batchesModalEl) {
        // A. LOAD DATA ON OPEN
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

        // B. HANDLE BUTTON CLICKS IN TABLE
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

            // C. HANDLE INPUT CHANGES
            tbody.addEventListener('input', function(e) {
                if (e.target.classList.contains('qty-adjustment-input')) {
                    const row = e.target.closest('tr');
                    const current = parseFloat(row.dataset.originalQty);
                    const adjustment = parseFloat(e.target.value);
                    const newTotalSpan = row.querySelector('.new-total-display');
                    
                    if (!isNaN(adjustment)) {
                        const newTotal = current + adjustment;
                        newTotalSpan.textContent = newTotal.toFixed(2);
                        newTotalSpan.className = 'new-total-display font-bold ' + (newTotal < 0 ? 'text-red-500' : 'text-green-600');
                    } else {
                        newTotalSpan.textContent = current.toFixed(2);
                        newTotalSpan.className = 'new-total-display font-bold text-gray-500';
                    }
                }
            });
        }
    }

    // --- Helper: Load Batch Data ---
    function loadBatchData(id, unit) {
        const tbody = document.getElementById('batches_table_body');
        if(!tbody) return;
        
        tbody.innerHTML = '<tr><td colspan="5" class="py-4 text-center text-gray-500">Loading batches...</td></tr>';
        
        fetch(`get_ingredient_batches.php?ingredient_id=${id}`)
            .then(res => res.json())
            .then(data => {
                tbody.innerHTML = '';
                if(!data || data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" class="py-4 text-center text-gray-400">No active batches found.</td></tr>';
                    return;
                }
                
                data.forEach(batch => {
                    const tr = document.createElement('tr');
                    tr.dataset.batchId = batch.batch_id;
                    tr.dataset.originalQty = batch.quantity;
                    tr.dataset.originalExpiry = batch.expiration_date || '';
                    
                    const receivedDate = new Date(batch.date_received).toLocaleDateString();
                    const expiryDate = batch.expiration_date ? new Date(batch.expiration_date).toLocaleDateString() : 'N/A';
                    const expiryValue = batch.expiration_date || '';
                    
                    // Determine Status
                    let statusHtml = '<span class="px-2 py-0.5 rounded text-xs bg-green-100 text-green-800">OK</span>';
                    if(batch.expiration_date) {
                         const today = new Date();
                         const exp = new Date(batch.expiration_date);
                         const diffTime = exp - today;
                         const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)); 
                         
                         if(diffDays < 0) statusHtml = '<span class="px-2 py-0.5 rounded text-xs bg-red-100 text-red-800">Expired</span>';
                         else if(diffDays <= 7) statusHtml = '<span class="px-2 py-0.5 rounded text-xs bg-yellow-100 text-yellow-800">Expiring Soon</span>';
                    }

                    tr.innerHTML = `
                        <td class="px-4 py-3 border-b text-gray-600">${receivedDate}</td>
                        <td class="px-4 py-3 border-b">
                            <div class="view-expiry">
                                <span class="expiry-text font-medium text-gray-800">${expiryDate}</span>
                            </div>
                            <div class="edit-expiry hidden flex items-center justify-center gap-1">
                                <input type="date" class="expiry-input text-xs border rounded p-1 w-28" value="${expiryValue}">
                                <button class="clear-expiry-btn text-gray-400 hover:text-gray-600"><i class='bx bx-x'></i></button>
                            </div>
                        </td>
                        <td class="px-4 py-3 border-b">
                            <div class="view-qty">
                                <span class="font-bold text-gray-800">${parseFloat(batch.quantity).toFixed(2)}</span> <span class="text-xs text-gray-500">${unit}</span>
                            </div>
                            <div class="edit-qty hidden flex flex-col items-center gap-1">
                                <div class="text-xs text-gray-500">Current: ${parseFloat(batch.quantity).toFixed(2)}</div>
                                <div class="flex items-center gap-1">
                                    <span class="text-xs font-bold">+</span>
                                    <input type="number" step="0.01" class="qty-adjustment-input text-xs border rounded p-1 w-20 text-center" placeholder="0">
                                </div>
                                <div class="text-xs text-gray-500">New Total: <span class="new-total-display font-bold text-gray-800">${parseFloat(batch.quantity).toFixed(2)}</span></div>
                                <input type="text" class="qty-reason-input text-xs border rounded p-1 w-32 mt-1" placeholder="Reason (Required)">
                            </div>
                        </td>
                        <td class="px-4 py-3 border-b">${statusHtml}</td>
                        <td class="px-4 py-3 border-b">
                            <div class="view-actions flex justify-center gap-2">
                                <button class="edit-expiry-btn text-blue-600 hover:bg-blue-50 p-1 rounded" title="Edit Expiry"><i class='bx bx-calendar-edit'></i></button>
                                <button class="correction-batch-btn text-orange-600 hover:bg-orange-50 p-1 rounded" title="Correct Qty"><i class='bx bx-slider-alt'></i></button>
                                <button class="delete-batch-btn text-red-600 hover:bg-red-50 p-1 rounded" title="Delete Batch"><i class='bx bx-trash'></i></button>
                            </div>
                            <div class="edit-expiry-actions hidden flex justify-center gap-2">
                                <button class="save-expiry-btn text-green-600 hover:bg-green-50 p-1 rounded"><i class='bx bx-check'></i></button>
                                <button class="cancel-expiry-btn text-red-600 hover:bg-red-50 p-1 rounded"><i class='bx bx-x'></i></button>
                            </div>
                            <div class="edit-qty-actions hidden flex justify-center gap-2 mt-1">
                                <button class="save-qty-btn text-green-600 hover:bg-green-50 p-1 rounded text-xs border border-green-200 bg-green-50 px-2">Save</button>
                                <button class="cancel-qty-btn text-red-600 hover:bg-red-50 p-1 rounded text-xs border border-red-200 bg-red-50 px-2">Cancel</button>
                            </div>
                        </td>
                    `;
                    tbody.appendChild(tr);
                });
            })
            .catch(err => {
                console.error(err);
                tbody.innerHTML = '<tr><td colspan="5" class="py-4 text-center text-red-500">Error loading batches.</td></tr>';
            });
    }

    // --- Helper: Toggle View/Edit Mode ---
    function toggleEditMode(row, type, isEditing) {
        if (type === 'expiry') {
            const viewDiv = row.querySelector('.view-expiry');
            const editDiv = row.querySelector('.edit-expiry');
            const viewActions = row.querySelector('.view-actions');
            const editActions = row.querySelector('.edit-expiry-actions');
            
            if (isEditing) {
                viewDiv.classList.add('hidden');
                editDiv.classList.remove('hidden');
                viewActions.classList.add('hidden');
                editActions.classList.remove('hidden');
            } else {
                viewDiv.classList.remove('hidden');
                editDiv.classList.add('hidden');
                viewActions.classList.remove('hidden');
                editActions.classList.add('hidden');
                // Reset input
                row.querySelector('.expiry-input').value = row.dataset.originalExpiry;
            }
        } else if (type === 'qty') {
            const viewDiv = row.querySelector('.view-qty');
            const editDiv = row.querySelector('.edit-qty');
            const viewActions = row.querySelector('.view-actions');
            const editActions = row.querySelector('.edit-qty-actions');
            
            if (isEditing) {
                viewDiv.classList.add('hidden');
                editDiv.classList.remove('hidden');
                viewActions.classList.add('hidden');
                editActions.classList.remove('hidden');
            } else {
                viewDiv.classList.remove('hidden');
                editDiv.classList.add('hidden');
                viewActions.classList.remove('hidden');
                editActions.classList.add('hidden');
                // Reset inputs
                row.querySelector('.qty-adjustment-input').value = '';
                row.querySelector('.qty-reason-input').value = '';
                row.querySelector('.new-total-display').textContent = parseFloat(row.dataset.originalQty).toFixed(2);
                row.querySelector('.new-total-display').className = 'new-total-display font-bold text-gray-800';
            }
        }
    }

    // --- Helper: API Call ---
    function callAPI(action, payload, onSuccess) {
        fetch('inventory_management.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, ...payload })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                onSuccess(data);
            } else {
                Swal.fire('Error', data.message || 'Action failed', 'error');
            }
        })
        .catch(err => {
            console.error(err);
            Swal.fire('Error', 'Connection error', 'error');
        });
    }

    function updateMainTableStock(adjustmentAmount) {
        const batchesModal = document.getElementById('batchesModal');
        const ingredientId = batchesModal.dataset.currentIngredientId;
        
        // Find the main table row
        const editBtn = document.querySelector(`button[data-ingredient-id="${ingredientId}"][onclick*="editIngredientModal"]`);
        if (editBtn) {
            const row = editBtn.closest('tr');
            if (row) {
                const stockCell = row.querySelector('td:nth-child(3)'); // 3rd column is Stock
                if (stockCell) {
                    const currentStock = parseFloat(stockCell.dataset.sortValue || stockCell.textContent);
                    const newStock = currentStock + adjustmentAmount;
                    stockCell.textContent = newStock.toFixed(2);
                    stockCell.dataset.sortValue = newStock;
                    
                    // Update highlight if needed
                    if (newStock <= 0) stockCell.classList.add('text-red-600');
                    else stockCell.classList.remove('text-red-600');
                }
            }
        }
    }

    function saveExpiry(row, batchId) {
        const newDate = row.querySelector('.expiry-input').value;
        callAPI('update_expiry', { batch_id: batchId, expiration_date: newDate }, (data) => {
            Swal.fire('Updated', data.message, 'success');
            // Update UI
            row.dataset.originalExpiry = newDate;
            row.querySelector('.expiry-text').textContent = newDate ? new Date(newDate).toLocaleDateString() : 'N/A';
            toggleEditMode(row, 'expiry', false);
            // Optionally reload to update status badges
            const batchesModal = document.getElementById('batchesModal');
            loadBatchData(batchesModal.dataset.currentIngredientId, batchesModal.dataset.currentUnit);
        });
    }

    function saveQuantity(row, batchId) {
        const adjustment = parseFloat(row.querySelector('.qty-adjustment-input').value);
        const reason = row.querySelector('.qty-reason-input').value.trim();
        const currentQty = parseFloat(row.dataset.originalQty);
        
        if (isNaN(adjustment) || adjustment === 0) {
            Swal.fire('Warning', 'Please enter a valid adjustment amount.', 'warning');
            return;
        }
        if (!reason) {
            Swal.fire('Warning', 'Reason is required for stock correction.', 'warning');
            return;
        }
        
        const newQty = currentQty + adjustment;
        if (newQty < 0) {
            Swal.fire('Error', 'Quantity cannot be negative.', 'error');
            return;
        }

        callAPI('update_quantity', { batch_id: batchId, new_quantity: newQty, reason: reason }, (data) => {
            Swal.fire('Updated', data.message, 'success');
            toggleEditMode(row, 'qty', false);
            updateMainTableStock(adjustment);
            // Reload batches
            const batchesModal = document.getElementById('batchesModal');
            loadBatchData(batchesModal.dataset.currentIngredientId, batchesModal.dataset.currentUnit);
        });
    }

    function deleteBatch(row, batchId) {
        Swal.fire({
            title: 'Delete Batch?',
            text: "This will remove the batch quantity from total stock. Reason required:",
            input: 'text',
            inputPlaceholder: 'Reason for deletion...',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, delete it'
        }).then((result) => {
            if (result.isConfirmed) {
                const reason = result.value;
                if (!reason) {
                    Swal.fire('Error', 'Reason is required.', 'error');
                    return;
                }
                
                // Calculate amount to remove for main table update
                const qtyToRemove = -parseFloat(row.dataset.originalQty);
                
                callAPI('delete_batch', { batch_id: batchId, reason: reason }, (data) => {
                    Swal.fire('Deleted', data.message, 'success');
                    updateMainTableStock(qtyToRemove);
                    // Reload batches
                    const batchesModal = document.getElementById('batchesModal');
                    loadBatchData(batchesModal.dataset.currentIngredientId, batchesModal.dataset.currentUnit);
                });
            }
        });
    }
    
    // --- Reusable Table Functions for Sorting and Pagination ---

    function getSortableValue(cell, type = 'text') {
        const textValue = cell.innerText;
        
        // 1. Use data-sort-value if available for explicit number sorting 
        if (type === 'number' && cell.dataset.sortValue !== undefined) {
             const num = parseFloat(cell.dataset.sortValue);
             return isNaN(num) ? 0 : num;
        }
        
        let cleaned = textValue.trim();
        switch (type) {
            case 'number':
                // 2. Clean common non-numeric characters for general number columns
                // Added ₱ (Peso sign) and P to the regex
                cleaned = cleaned.replace(/₱|P|kg|g|L|ml|pcs|pack|tray|can|bottle|\+|\(|\)/gi, '');
                cleaned = cleaned.replace(/,/g, '');
                const num = parseFloat(cleaned);
                return isNaN(num) ? 0 : num;
            case 'date':
                // 3. Date sorting
                let dateVal = Date.parse(cleaned);
                return isNaN(dateVal) ? 0 : dateVal;
            default: // 'text'
                // 4. Text sorting, with prefixes for predictable sorting of status/type columns
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

        let currentPage = 0; // 0-indexed page

        const updateTableRows = () => {
            const selectedValue = select.value;
            
            // Exclude non-data rows like "no results" placeholders and the total footer
            const all_rows = tableBody.querySelectorAll('tr:not([id$="-no-results"]):not(tfoot tr)');
            const visibleRows = [];
            
            // Only consider rows that were NOT hidden by an external filter (like search input)
            all_rows.forEach(row => {
                if (row.dataset.paginatedHidden !== 'true') {
                    visibleRows.push(row);
                }
            });

            // If a search filter is active, it handles hiding. If not, show all.
            if (selectedValue === 'all') {
                visibleRows.forEach(row => row.style.display = '');
                prevBtn.disabled = true;
                nextBtn.disabled = true;
                return;
            }

            const limit = parseInt(selectedValue, 10);
            const totalRows = visibleRows.length;
            const totalPages = Math.ceil(totalRows / limit);
            
            // Adjust currentPage if it exceeds the new page count
            if (currentPage >= totalPages) {
                currentPage = Math.max(0, totalPages - 1);
            }

            const start = currentPage * limit;
            const end = start + limit;

            all_rows.forEach(row => row.style.display = 'none'); // Hide all first

            visibleRows.forEach((row, index) => {
                if (index >= start && index < end) {
                    row.style.display = '';
                }
            });

            prevBtn.disabled = currentPage === 0;
            nextBtn.disabled = (currentPage >= totalPages - 1) || (totalRows === 0);
        };

        // Event listeners for controls
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
        
        // Initial run
        updateTableRows();
    }
    
    // Updated Sorting Logic for <select> elements
    function setupSortSelect(selectId) {
        const select = document.getElementById(selectId);
        if (!select) return;

        select.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const sortBy = selectedOption.dataset.sortBy;
            const sortType = selectedOption.dataset.sortType;
            const sortDir = selectedOption.dataset.sortDir; // 'ASC' or 'DESC'

            if (!sortBy) return;

            // FIX: Using .shadow-sm (which belongs to the container card) to correctly scope the table search.
            const container = select.closest('.shadow-sm');
            if (!container) return;

            const tbody = container.querySelector('tbody');
            const thead = container.querySelector('thead');
            if (!tbody || !thead) return;

            // Find column index based on data-sort-by in <th>
            let colIndex = -1;
            Array.from(thead.querySelectorAll('th')).forEach((th, index) => {
                if (th.dataset.sortBy === sortBy) {
                    colIndex = index;
                }
            });
            
            if (colIndex === -1) {
                console.error(`Sort Error: Could not find column index for data-sort-by="${sortBy}"`);
                return;
            }

            // Only sort actual data rows
            const rows = Array.from(tbody.querySelectorAll('tr:not([id$="-no-results"]):not(tfoot tr)'));
            
            rows.sort((a, b) => {
                if (a.cells.length <= colIndex || b.cells.length <= colIndex) return 0;
                const valA = getSortableValue(a.cells[colIndex], sortType); 
                const valB = getSortableValue(b.cells[colIndex], sortType);
                
                let comparison = 0;
                if (valA > valB) comparison = 1;
                else if (valA < valB) comparison = -1;
                
                // Apply direction
                return sortDir === 'DESC' ? (comparison * -1) : comparison;
            });

            // Re-append sorted rows
            rows.forEach(row => tbody.appendChild(row));
            
            // Re-apply pagination after sorting (reset to page 1)
            const paginationSelect = container.querySelector('select[id$="-rows-select"]');
            if (paginationSelect) {
                paginationSelect.dispatchEvent(new Event('change'));
            }
        });
    }

    function initTableFeatures() {
        // Initialize Pagination and Sorting for ALL tables
        
        // 1. Ingredients Table
        addTablePagination('ingredient-rows-select', 'ingredient-table-body');
        setupSortSelect('ingredient-sort-select');
        
        // 2. Discontinued Products Table
        addTablePagination('discontinued-rows-select', 'discontinued-table-body');
        setupSortSelect('discontinued-sort-select');

        // 3. Recall Log
        addTablePagination('recall-rows-select', 'recall-table-body');
        setupSortSelect('recall-sort-select');

        // 4. Adjustment History
        addTablePagination('history-rows-select', 'history-table-body');
        setupSortSelect('history-sort-select');
    }

    initTableFeatures();
});