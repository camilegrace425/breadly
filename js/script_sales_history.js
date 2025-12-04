document.addEventListener('DOMContentLoaded', () => {

    // --- Reusable Table Pagination Function ---
    function addTablePagination(selectId, tableBodyId) {
        const select = document.getElementById(selectId);
        const tableBody = document.getElementById(tableBodyId);
        if (!select || !tableBody) return;

        const baseId = selectId.replace('-rows-select', '');
        const prevBtn = document.getElementById(`${baseId}-prev-btn`);
        const nextBtn = document.getElementById(`${baseId}-next-btn`);
        
        if (!prevBtn || !nextBtn) return;

        // Ensure we have a default current page set on the element dataset
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
            
            // Adjust current page if out of bounds (e.g., after filtering reduced rows)
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
                    // Hide details row if parent is hidden
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

        // --- Event Listener Management ---
        // Clone nodes to strip old event listeners before adding new ones
        const newPrevBtn = prevBtn.cloneNode(true);
        const newNextBtn = nextBtn.cloneNode(true);
        prevBtn.parentNode.replaceChild(newPrevBtn, prevBtn);
        nextBtn.parentNode.replaceChild(newNextBtn, nextBtn);
        
        // Re-select fresh buttons
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

        // Handle Change on Select Dropdown
        const newSelect = select.cloneNode(true);
        newSelect.dataset.currentPage = select.dataset.currentPage;
        newSelect.value = select.value;
        
        select.parentNode.replaceChild(newSelect, select);
        const freshSelect = document.getElementById(selectId);
        
        freshSelect.addEventListener('change', () => {
            freshSelect.dataset.currentPage = '0';
            updateTableRows();
        });
        
        // Initial run
        updateTableRows();
    }
    
    // --- JS Sorting ---
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

        // Remove existing listener by cloning
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
                // Sort Orders (maintaining row+detail pairs)
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
                // Sort Standard Rows
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

            // Trigger pagination update (reset to page 1) by dispatching change event on pagination select
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
            // Remove old listener if any
            const newForm = form.cloneNode(true);
            form.parentNode.replaceChild(newForm, form);
            
            // Re-select form for closure scope
            const activeForm = newForm;

            activeForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                const params = new URLSearchParams(formData);
                params.append('ajax', '1');
                
                // Note: We don't disable the "Today" button here generically since it's just a trigger
                // but we can add visual loading state if needed.

                fetch(`sales_history.php?${params.toString()}`)
                    .then(response => response.json())
                    .then(data => {
                        const activeTab = params.get('active_tab');
                        
                        if (activeTab === 'sales') {
                            const tbody = document.getElementById('sales-table-body');
                            tbody.innerHTML = data.html;
                            
                            // Update Totals
                            if (data.totals) {
                                if(document.getElementById('total-gross-revenue')) 
                                    document.getElementById('total-gross-revenue').innerText = '₱' + data.totals.gross;
                                if(document.getElementById('total-returns-value'))
                                    document.getElementById('total-returns-value').innerText = '(₱' + data.totals.returns + ')';
                                if(document.getElementById('total-net-revenue'))
                                    document.getElementById('total-net-revenue').innerText = '₱' + data.totals.net;
                            }
                            
                            // Re-init features for new elements
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

            // NEW: Auto-submit on date change
            const dateInputs = activeForm.querySelectorAll('input[type="date"]');
            dateInputs.forEach(input => {
                input.addEventListener('change', function() {
                    activeForm.dispatchEvent(new Event('submit'));
                });
            });
        });

        // NEW: Handle "Today" buttons logic
        const handleToday = (btnId, formSelector) => {
            const btn = document.getElementById(btnId);
            if(!btn) return;
            
            // Remove old listeners
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
                    // Trigger submit via the form event
                    form.dispatchEvent(new Event('submit'));
                }
            });
        };

        handleToday('sales-today-btn', '#pane-sales form');
        handleToday('returns-today-btn', '#pane-returns form');
    }

    // --- REMOVED URL UPDATING LOGIC HERE ---
    // The event listeners for history.replaceState have been removed.

    // Initial Setup
    addTablePagination('sales-rows-select', 'sales-table-body');
    addTablePagination('returns-rows-select', 'returns-table-body');
    setupSortSelect('sales-sort-select');
    setupSortSelect('returns-sort-select');
    attachAjaxFilters();
});