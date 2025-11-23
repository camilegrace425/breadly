document.addEventListener('DOMContentLoaded', () => {

    // Helper to listen to custom 'open-modal' event (replaces show.bs.modal)
    function onModalOpen(modalId, callback) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.addEventListener('open-modal', function (event) {
                // event.detail.relatedTarget contains the button that was clicked
                callback(event.detail.relatedTarget);
            });
        }
    }

    // 1. MODAL DATA POPULATION LOGIC

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

        // Handle N/A Expiration Checkbox logic
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

        onModalOpen('restockIngredientModal', (button) => {
            if (!button) return;
            
            const ingredientId = button.dataset.ingredientId;
            const ingredientName = button.dataset.ingredientName;
            const ingredientUnit = button.dataset.ingredientUnit;

            restockIngredientModal.querySelector('#restock_ingredient_id').value = ingredientId;
            restockIngredientModal.querySelector('#restock_ingredient_name').textContent = ingredientName;
            restockIngredientModal.querySelector('#restock_ingredient_unit').textContent = ingredientUnit;
            
            // Reset inputs
            const qtyInput = restockIngredientModal.querySelector('#restock_ing_qty');
            const noteInput = restockIngredientModal.querySelector('#restock_ing_reason_note');
            if(qtyInput) qtyInput.value = '';
            if(noteInput) noteInput.value = '';
            
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
                // Find which tab we are currently in
                const pane = button.closest('.tab-pane'); // Note: .tab-pane might not exist in Tailwind version if structure changed, check logic
                // Fallback logic: check status
                // Simple fallback: default to products
                activeTabInput.value = 'products';
            }
        });
    }

    
    // 2. BATCH DETAILS MODAL LOGIC
    
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
            
            // We store context on the modal element itself for reloads
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

                // 2. Correction Actions
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
        
        tbody.innerHTML = '<tr><td colspan="5" class="py-4 text-center"><div class="animate-spin inline-block w-5 h-5 border-2 border-current border-t-transparent text-blue-600 rounded-full" role="status"></div> Loading...</td></tr>';

        fetch(`get_ingredient_batches.php?id=${id}`)
            .then(response => response.json())
            .then(data => {
                tbody.innerHTML = '';
                if (data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" class="text-gray-400 italic py-4 text-center">No active batches. Use "Restock" to add one.</td></tr>';
                    return;
                }
                
                const today = new Date();
                today.setHours(0,0,0,0);

                data.forEach(batch => {
                    let expDisplay = 'N/A';
                    let expDateVal = '';
                    let statusHtml = '<span class="px-2 py-1 rounded-full text-xs font-semibold bg-gray-200 text-gray-700">No Expiry</span>';
                    
                    if (batch.expiration_date) {
                        const exp = new Date(batch.expiration_date);
                        expDisplay = exp.toLocaleDateString();
                        expDateVal = batch.expiration_date.split(' ')[0]; 

                        const diffTime = exp - today;
                        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)); 
                        
                        if (diffDays < 0) {
                            statusHtml = '<span class="px-2 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800">Expired</span>';
                        } else if (diffDays <= 7) {
                            statusHtml = `<span class="px-2 py-1 rounded-full text-xs font-semibold bg-yellow-100 text-yellow-800">Expiring (${diffDays} days)</span>`;
                        } else {
                            statusHtml = '<span class="px-2 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800">Good</span>';
                        }
                    }

                    const recDate = new Date(batch.date_received).toLocaleDateString();
                    const currentQty = parseFloat(batch.quantity);

                    const row = `
                        <tr class="hover:bg-gray-50 transition-colors border-b border-gray-50" data-batch-id="${batch.batch_id}" data-original-date="${expDateVal}" data-original-qty="${currentQty}">
                            <td class="py-3">${recDate}</td>
                            
                            <td class="expiry-cell py-3">
                                <div class="view-mode flex justify-center items-center gap-2">
                                    <span class="text-value text-gray-800">${expDisplay}</span>
                                    <button class="text-blue-600 hover:text-blue-800 edit-expiry-btn" title="Edit Date"><i class='bx bx-pencil'></i></button>
                                </div>
                                <div class="edit-mode hidden flex-col gap-1 items-center">
                                    <div class="flex items-center">
                                        <input type="date" class="form-input py-1 px-2 text-xs border rounded-l-lg w-24 expiry-input" value="${expDateVal}">
                                        <button class="bg-gray-100 border border-l-0 rounded-r-lg px-2 py-1 text-gray-600 hover:bg-gray-200 clear-expiry-btn" type="button" title="Set N/A"><i class='bx bx-x-circle'></i></button>
                                    </div>
                                    <div class="flex justify-center gap-1">
                                        <button class="bg-green-500 text-white px-2 py-0.5 rounded text-xs hover:bg-green-600 save-expiry-btn"><i class='bx bx-check'></i></button>
                                        <button class="bg-gray-300 text-gray-700 px-2 py-0.5 rounded text-xs hover:bg-gray-400 cancel-expiry-btn"><i class='bx bx-x'></i></button>
                                    </div>
                                </div>
                            </td>

                            <td class="qty-cell py-3">
                                <div class="view-mode flex justify-center items-center gap-2">
                                    <span class="font-bold text-gray-800 text-value">${currentQty}</span>
                                </div>
                                <div class="edit-mode hidden flex-col gap-1 items-center">
                                    <input type="number" step="0.01" class="form-input py-1 px-2 text-xs border rounded w-20 text-center qty-adjustment-input" placeholder="+/-">
                                    <div class="text-[10px] text-gray-500">New: <span class="new-total-display">${currentQty}</span></div>
                                    <div class="flex justify-center gap-1">
                                        <button class="bg-green-500 text-white px-2 py-0.5 rounded text-xs hover:bg-green-600 save-qty-btn"><i class='bx bx-check'></i></button>
                                        <button class="bg-gray-300 text-gray-700 px-2 py-0.5 rounded text-xs hover:bg-gray-400 cancel-qty-btn"><i class='bx bx-x'></i></button>
                                    </div>
                                </div>
                            </td>

                            <td class="py-3">${statusHtml}</td>
                            
                            <td class="py-3">
                                <div class="flex justify-center gap-2">
                                    <button class="text-xs bg-orange-50 text-orange-600 border border-orange-100 px-2 py-1 rounded hover:bg-orange-100 correction-batch-btn"><i class='bx bx-slider-alt'></i></button>
                                    <button class="text-xs bg-red-50 text-red-600 border border-red-100 px-2 py-1 rounded hover:bg-red-100 delete-batch-btn"><i class='bx bx-trash'></i></button>
                                </div>
                            </td>
                        </tr>`;
                    tbody.insertAdjacentHTML('beforeend', row);
                });
            })
            .catch(err => {
                tbody.innerHTML = '<tr><td colspan="5" class="text-red-500 py-4 text-center">Error loading batches.</td></tr>';
                console.error(err);
            });
    }

    // --- Helper: Toggle View/Edit Mode ---
    function toggleEditMode(row, type, isEditing) {
        const cell = row.querySelector(`.${type}-cell`);
        const view = cell.querySelector('.view-mode');
        const edit = cell.querySelector('.edit-mode');
        
        if (isEditing) {
            view.classList.remove('flex');
            view.classList.add('hidden');
            edit.classList.remove('hidden');
            edit.classList.add('flex');
            
            if (type === 'qty') {
                const input = edit.querySelector('.qty-adjustment-input');
                if(input) {
                    input.value = ''; 
                    row.querySelector('.new-total-display').textContent = row.dataset.originalQty;
                    input.focus();
                }
            } else {
                const input = edit.querySelector('input');
                if(input) input.focus();
            }

        } else {
            view.classList.remove('hidden');
            view.classList.add('flex');
            edit.classList.remove('flex');
            edit.classList.add('hidden');
            
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
                Swal.fire({
                    icon: 'error',
                    title: 'Action Failed',
                    text: data.message
                });
            }
        })
        .catch(err => {
            console.error(err);
            Swal.fire({
                icon: 'error',
                title: 'Network Error',
                text: 'An error occurred while processing your request.'
            });
        });
    }

    function updateMainTableStock(adjustmentAmount) {
        const modal = document.getElementById('batchesModal');
        if (!modal) return;
        
        const ingredientId = modal.dataset.currentIngredientId;
        
        // Find the restock button that corresponds to this ingredient to locate the row
        const restockBtn = document.querySelector(`button[onclick*="openModal('restockIngredientModal'"][data-ingredient-id="${ingredientId}"]`);
        
        if (restockBtn) {
            const row = restockBtn.closest('tr');
            if (!row) return;

            // Column indices based on new table structure
            // Name | Unit | Stock (Right) | Reorder (Right) | Status (Center) | Actions
            // Stock is 3rd column (index 2)
            const cells = row.querySelectorAll('td');
            const stockCell = cells[2]; 
            const reorderCell = cells[3];
            const statusCell = cells[4];
            
            if (stockCell) {
                let currentStock = parseFloat(stockCell.textContent.replace(/,/g, ''));
                if (isNaN(currentStock)) currentStock = 0;
                
                const newStock = currentStock + adjustmentAmount;
                
                stockCell.textContent = newStock.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                stockCell.classList.add('text-green-600', 'font-bold');
                setTimeout(() => stockCell.classList.remove('text-green-600', 'font-bold'), 2000);

                if (reorderCell && statusCell) {
                    let reorderLevel = parseFloat(reorderCell.textContent.replace(/,/g, ''));
                    if (isNaN(reorderLevel)) reorderLevel = 0;

                    if (newStock <= reorderLevel) {
                        statusCell.innerHTML = '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">Low Stock</span>';
                    } else {
                        statusCell.innerHTML = '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">In Stock</span>';
                    }
                }
            }
        }
    }

    function saveExpiry(row, batchId) {
        const input = row.querySelector('.expiry-input');
        const newDate = input.value;
        
        if (!newDate) {
             Swal.fire({
                icon: 'warning',
                title: 'Invalid Date',
                text: 'Please select a valid expiration date or clear it using the X button.'
            });
            return;
        }

        callAPI('update_expiry', { 
            batch_id: batchId, 
            expiration_date: newDate 
        }, () => {
            const modal = document.getElementById('batchesModal');
            const id = modal.dataset.currentIngredientId;
            const unit = modal.dataset.currentUnit;
            loadBatchData(id, unit);
            
            const toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000
            });
            toast.fire({
                icon: 'success',
                title: 'Expiration updated'
            });
        });
    }

    function saveQuantity(row, batchId) {
        const input = row.querySelector('.qty-adjustment-input');
        const adjustment = parseFloat(input.value);
        
        if (isNaN(adjustment) || adjustment === 0) {
            Swal.fire({
                icon: 'info',
                text: "Please enter a valid adjustment amount (e.g., 5 or -2)."
            });
            return;
        }

        const currentQty = parseFloat(row.dataset.originalQty);
        const newQty = currentQty + adjustment; 
        const reasonText = `[Correction] ${adjustment > 0 ? '+' : ''}${adjustment}`;

        callAPI('update_quantity', { 
            batch_id: batchId, 
            new_quantity: newQty, 
            reason: reasonText 
        }, () => {
            updateMainTableStock(adjustment); 
            const modal = document.getElementById('batchesModal');
            const id = modal.dataset.currentIngredientId;
            const unit = modal.dataset.currentUnit;
            loadBatchData(id, unit);
        });
    }

    function deleteBatch(row, batchId) {
        Swal.fire({
            title: 'Delete Batch?',
            text: "This will remove the stock from inventory permanently.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                const qtyToRemove = parseFloat(row.dataset.originalQty);

                callAPI('delete_batch', { 
                    batch_id: batchId, 
                    reason: '[Delete] Manual batch deletion' 
                }, () => {
                    updateMainTableStock(-qtyToRemove); 
                    const modal = document.getElementById('batchesModal');
                    const id = modal.dataset.currentIngredientId;
                    const unit = modal.dataset.currentUnit;
                    loadBatchData(id, unit);
                    
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