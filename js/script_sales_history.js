document.addEventListener('DOMContentLoaded', () => {

    function addTablePagination(selectId, tableBodyId) {
        const select = document.getElementById(selectId);
        const tableBody = document.getElementById(tableBodyId);
        if (!select || !tableBody) return;

        const baseId = selectId.replace('-rows-select', '');
        const prevBtn = document.getElementById(`${baseId}-prev-btn`);
        const nextBtn = document.getElementById(`${baseId}-next-btn`);
        
        if (!prevBtn || !nextBtn) return;

        select.dataset.currentPage = select.dataset.currentPage || '0';

        const updateTableRows = () => {
            const selectedValue = select.value;
            let currentPage = parseInt(select.dataset.currentPage);
            
            let dataRows;
            if (tableBodyId === 'sales-table-body') {
                dataRows = Array.from(tableBody.querySelectorAll('tr.order-row'));
            } else {
                dataRows = Array.from(tableBody.querySelectorAll('tr:not([id$="-no-results"])'));
            }

            const totalRows = dataRows.length;
            
            if (selectedValue === 'all') {
                dataRows.forEach(row => {
                    row.style.display = '';
                });
                prevBtn.disabled = true;
                nextBtn.disabled = true;
                return;
            }

            const limit = parseInt(selectedValue, 10);
            const totalPages = (totalRows === 0) ? 1 : Math.ceil(totalRows / limit);
            
            if (currentPage >= totalPages) {
                currentPage = Math.max(0, totalPages - 1);
                select.dataset.currentPage = currentPage;
            }

            const start = currentPage * limit;
            const end = start + limit;
            
            dataRows.forEach((row, index) => {
                if (index >= start && index < end) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                    if (row.classList.contains('order-row')) {
                        const nextRow = row.nextElementSibling;
                        if (nextRow && nextRow.classList.contains('details-row')) {
                            nextRow.style.display = 'none'; 
                        }
                    }
                }
            });

            prevBtn.disabled = currentPage === 0;
            nextBtn.disabled = (currentPage >= totalPages - 1) || (totalRows === 0);
        };

        const newPrevBtn = prevBtn.cloneNode(true);
        const newNextBtn = nextBtn.cloneNode(true);
        prevBtn.parentNode.replaceChild(newPrevBtn, prevBtn);
        nextBtn.parentNode.replaceChild(newNextBtn, nextBtn);
        
        const freshPrevBtn = document.getElementById(`${baseId}-prev-btn`);
        const freshNextBtn = document.getElementById(`${baseId}-next-btn`);

        freshPrevBtn.addEventListener('click', () => {
            let cur = parseInt(select.dataset.currentPage);
            if (cur > 0) {
                select.dataset.currentPage = cur - 1;
                updateTableRows();
            }
        });

        freshNextBtn.addEventListener('click', () => {
            if (select.value === 'all') return;
            let cur = parseInt(select.dataset.currentPage);
            select.dataset.currentPage = cur + 1;
            updateTableRows();
        });

        const newSelect = select.cloneNode(true);
        newSelect.dataset.currentPage = select.dataset.currentPage;
        newSelect.value = select.value;
        
        select.parentNode.replaceChild(newSelect, select);
        const freshSelect = document.getElementById(selectId);
        
        freshSelect.addEventListener('change', () => {
            freshSelect.dataset.currentPage = '0';
            updateTableRows();
        });
        updateTableRows();
    }
    
    function getSortableValue(cell, type = 'text') {
        if(!cell) return '';
        const textValue = cell.innerText;
        if ((type === 'number' || type === 'date') && cell.dataset.sortValue !== undefined) {
             const num = parseFloat(cell.dataset.sortValue);
             return isNaN(num) ? 0 : num;
        }
        let cleaned = textValue.trim();
        switch (type) {
            case 'number':
                cleaned = cleaned.replace(/₱|P|kg|g|L|ml|pcs|pack|tray|can|bottle|\+|\(|\)/gi, '');
                cleaned = cleaned.replace(/,/g, '');
                const num = parseFloat(cleaned);
                return isNaN(num) ? 0 : num;
            case 'date':
                let dateVal = Date.parse(cleaned);
                return isNaN(dateVal) ? 0 : dateVal;
            default: 
                return cleaned.toLowerCase();
        }
    }

    function setupSortSelect(selectId) {
        const select = document.getElementById(selectId);
        if (!select) return;

        const newSelect = select.cloneNode(true);
        select.parentNode.replaceChild(newSelect, select);
        const freshSelect = document.getElementById(selectId);

        freshSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const sortBy = selectedOption.dataset.sortBy;
            const sortType = selectedOption.dataset.sortType;
            const sortDir = selectedOption.dataset.sortDir; 

            if (!sortBy) return;

            const container = freshSelect.closest('.shadow-sm');
            if (!container) return;

            const tbody = container.querySelector('tbody');
            const thead = container.querySelector('thead');
            
            let colIndex = -1;
            Array.from(thead.querySelectorAll('th')).forEach((th, index) => {
                if (th.dataset.sortBy === sortBy) colIndex = index;
            });
            
            if (colIndex === -1) return;

            let isOrderTable = (tbody.id === 'sales-table-body');

            if (isOrderTable) {
                let currentPairs = [];
                let currentOrderRows = Array.from(tbody.querySelectorAll('tr.order-row'));
                
                currentOrderRows.forEach(oRow => {
                    let dRow = oRow.nextElementSibling; 
                    if (dRow && dRow.classList.contains('details-row')) {
                        currentPairs.push({ order: oRow, details: dRow });
                    } else {
                        currentPairs.push({ order: oRow, details: null });
                    }
                });

                currentPairs.sort((a, b) => {
                    if (a.order.cells.length <= colIndex || b.order.cells.length <= colIndex) return 0;
                    const valA = getSortableValue(a.order.cells[colIndex], sortType);
                    const valB = getSortableValue(b.order.cells[colIndex], sortType);
                    let comparison = (valA > valB) ? 1 : ((valA < valB) ? -1 : 0);
                    return sortDir === 'DESC' ? (comparison * -1) : comparison;
                });

                currentPairs.forEach(pair => {
                    tbody.appendChild(pair.order);
                    if(pair.details) tbody.appendChild(pair.details);
                });
            } else {
                let rows = Array.from(tbody.querySelectorAll('tr:not([id$="-no-results"])'));
                rows.sort((a, b) => {
                    if (a.cells.length <= colIndex || b.cells.length <= colIndex) return 0;
                    const valA = getSortableValue(a.cells[colIndex], sortType);
                    const valB = getSortableValue(b.cells[colIndex], sortType);
                    let comparison = (valA > valB) ? 1 : ((valA < valB) ? -1 : 0);
                    return sortDir === 'DESC' ? (comparison * -1) : comparison;
                });
                rows.forEach(row => tbody.appendChild(row));
            }

            const paginationSelect = container.querySelector('select[id$="-rows-select"]');
            if (paginationSelect) {
                paginationSelect.dataset.currentPage = '0'; 
                paginationSelect.dispatchEvent(new Event('change'));
            }
        });
    }

    // --- AJAX Handling ---
    function attachAjaxFilters() {
        const forms = document.querySelectorAll('#pane-sales form, #pane-returns form');
        
        forms.forEach(form => {
            const newForm = form.cloneNode(true);
            form.parentNode.replaceChild(newForm, form);
            
            const activeForm = newForm;

            activeForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                const params = new URLSearchParams(formData);
                params.append('ajax', '1');
                
                fetch(`sales_history.php?${params.toString()}`)
                    .then(response => response.json())
                    .then(data => {
                        const activeTab = params.get('active_tab');
                        
                        if (activeTab === 'sales') {
                            const tbody = document.getElementById('sales-table-body');
                            tbody.innerHTML = data.html;
                            
                            if (data.totals) {
                                if(document.getElementById('total-gross-revenue')) 
                                    document.getElementById('total-gross-revenue').innerText = '₱' + data.totals.gross;
                                if(document.getElementById('total-returns-value'))
                                    document.getElementById('total-returns-value').innerText = '(₱' + data.totals.returns + ')';
                                if(document.getElementById('total-net-revenue'))
                                    document.getElementById('total-net-revenue').innerText = '₱' + data.totals.net;
                            }
                            
                            addTablePagination('sales-rows-select', 'sales-table-body');
                            setupSortSelect('sales-sort-select');
                            
                        } else if (activeTab === 'returns') {
                            const tbody = document.getElementById('returns-table-body');
                            tbody.innerHTML = data.html;
                            
                            addTablePagination('returns-rows-select', 'returns-table-body');
                            setupSortSelect('returns-sort-select');
                        }
                    })
                    .catch(err => console.error('Error:', err));
            });

            const dateInputs = activeForm.querySelectorAll('input[type="date"]');
            dateInputs.forEach(input => {
                input.addEventListener('change', function() {
                    activeForm.dispatchEvent(new Event('submit'));
                });
            });
        });

        const handleToday = (btnId, formSelector) => {
            const btn = document.getElementById(btnId);
            if(!btn) return;
            
            const newBtn = btn.cloneNode(true);
            btn.parentNode.replaceChild(newBtn, btn);

            newBtn.addEventListener('click', () => {
                const today = new Date().toISOString().split('T')[0];
                const form = document.querySelector(formSelector);
                if(!form) return;
                
                const start = form.querySelector('input[name="date_start"]');
                const end = form.querySelector('input[name="date_end"]');
                
                if(start && end) {
                    start.value = today;
                    end.value = today;
                    form.dispatchEvent(new Event('submit'));
                }
            });
        };

        handleToday('sales-today-btn', '#pane-sales form');
        handleToday('returns-today-btn', '#pane-returns form');
    }
    addTablePagination('sales-rows-select', 'sales-table-body');
    addTablePagination('returns-rows-select', 'returns-table-body');
    setupSortSelect('sales-sort-select');
    setupSortSelect('returns-sort-select');
    attachAjaxFilters();
});

// --- MOVED GLOBAL FUNCTIONS ---

function toggleSidebar() {
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

function openModal(modalId) {
    const modal = document.getElementById(modalId);
    const backdrop = document.getElementById('modalBackdrop');
    if (modal) {
        modal.classList.remove('hidden');
        if(backdrop) backdrop.classList.remove('hidden');
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    const backdrop = document.getElementById('modalBackdrop');
    if (modal) modal.classList.add('hidden');
    if(backdrop) backdrop.classList.add('hidden');
    
    // Clear iframe src when closing preview to stop memory leaks or stale data
    if(modalId === 'pdfPreviewModal') {
        document.getElementById('pdfPreviewFrame').src = 'about:blank';
    }
}

function closeAllModals() {
    document.querySelectorAll('.fixed.z-50').forEach(el => el.classList.add('hidden'));
    const backdrop = document.getElementById('modalBackdrop');
    if(backdrop) backdrop.classList.add('hidden');
}

// Only for Return Modal (Specific to Sales History Page logic)
function openReturnModal(button) {
    // Stop propagation if clicked from a table row that expands
    if(window.event) window.event.stopPropagation();

    const modal = document.getElementById('returnSaleModal');
    document.getElementById('return_sale_id').value = button.dataset.saleId;
    document.getElementById('return_product_name').textContent = button.dataset.productName;
    document.getElementById('return_sale_date').textContent = button.dataset.saleDate;
    
    const qtyAvailable = parseInt(button.dataset.qtyAvailable);
    const qtyInput = document.getElementById('return_qty');
    
    qtyInput.value = qtyAvailable;
    qtyInput.max = qtyAvailable;
    document.getElementById('return_max_qty').value = qtyAvailable;
    document.getElementById('return_qty_sold_text').textContent = qtyAvailable;
    document.getElementById('return_reason').value = '';

    openModal('returnSaleModal');
}

function switchTab(tabName) {
    document.querySelectorAll('[id^="pane-"]').forEach(el => el.classList.add('hidden'));
    document.getElementById('pane-' + tabName).classList.remove('hidden');
    
    const salesBtn = document.getElementById('tab-sales');
    const returnsBtn = document.getElementById('tab-returns');
    
    salesBtn.className = 'pb-3 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 border-transparent text-gray-500 hover:text-gray-700';
    returnsBtn.className = 'pb-3 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 border-transparent text-gray-500 hover:text-gray-700';
    
    if (tabName === 'sales') {
        salesBtn.className = 'pb-3 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 border-breadly-btn text-breadly-btn';
    } else {
        returnsBtn.className = 'pb-3 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 border-blue-500 text-blue-600';
    }
}

function toggleOrderDetails(orderId) {
    const detailsRow = document.getElementById('details-' + orderId);
    const icon = document.getElementById('icon-' + orderId);
    if (detailsRow) {
        if (detailsRow.classList.contains('hidden')) {
            detailsRow.classList.remove('hidden');
            icon.classList.add('rotate-90');
        } else {
            detailsRow.classList.add('hidden');
            icon.classList.remove('rotate-90');
        }
    }
}

// --- NEW REPORT GENERATION LOGIC ---
function getModalDates() {
    const start = document.getElementById('modal_date_start').value;
    const end = document.getElementById('modal_date_end').value;
    return { start, end };
}

function openReportPreview() {
    // Set default dates to TODAY regardless of main filter
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('modal_date_start').value = today;
    document.getElementById('modal_date_end').value = today;
    
    updatePreview();
    openModal('pdfPreviewModal');
}

function updatePreview() {
    const { start, end } = getModalDates();
    const previewUrl = `generate_pdf_report.php?date_start=${start}&date_end=${end}&report_action=preview`;
    
    // Show loader while iframe loads
    const loader = document.getElementById('pdfLoader');
    const frame = document.getElementById('pdfPreviewFrame');
    
    if(loader) loader.classList.remove('hidden');
    
    frame.onload = function() {
        if(loader) loader.classList.add('hidden');
    };
    frame.src = previewUrl;
}

function downloadReport() {
    const { start, end } = getModalDates();
    // Trigger download in main window
    window.location.href = `generate_pdf_report.php?date_start=${start}&date_end=${end}&report_action=download`;
}

function emailReport() {
    const email = document.getElementById('preview_email_input').value.trim();
    if (!email) {
        Swal.fire('Error', 'Please enter an email address.', 'warning');
        return;
    }

    const { start, end } = getModalDates();
    const btn = document.getElementById('send_email_btn');
    const originalContent = btn.innerHTML;
    
    // Disable button & show spinner
    btn.disabled = true;
    btn.innerHTML = '<i class="bx bx-loader-alt animate-spin"></i>';

    // Send via AJAX
    fetch(`generate_pdf_report.php?date_start=${start}&date_end=${end}&report_action=email&recipient_email=${encodeURIComponent(email)}&ajax=1`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Swal.fire('Sent!', data.message, 'success');
                document.getElementById('preview_email_input').value = ''; // Clear input
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(err => {
            console.error(err);
            Swal.fire('Error', 'Failed to send email. Check console.', 'error');
        })
        .finally(() => {
            // Restore button
            btn.disabled = false;
            btn.innerHTML = originalContent;
        });
}

function toggleEmailField(show, type) {
    let containerId, formId;
    if (type === 'pdf') {
        containerId = 'pdfEmailContainer';
        formId = 'pdfReportForm';
    } else {
        containerId = 'csvEmailContainer';
        formId = 'csvReportForm';
    }
    const container = document.getElementById(containerId);
    const emailInput = container ? container.querySelector('input') : null;
    const form = document.getElementById(formId);
    
    if (container && emailInput && form) {
        if (show) {
            container.classList.remove('hidden');
            emailInput.required = true;
            form.removeAttribute('target');
        } else {
            container.classList.add('hidden');
            emailInput.required = false;
            form.setAttribute('target', '_blank');
        }
    }
}