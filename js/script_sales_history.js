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

        let currentPage = 0; // 0-indexed page

        const updateTableRows = () => {
            const selectedValue = select.value;
            
            // Determine what constitutes a "row" to paginate.
            // For 'sales-table-body', we paginate based on class '.order-row'
            // For 'returns-table-body', we paginate strictly on 'tr'
            let dataRows;
            if (tableBodyId === 'sales-table-body') {
                dataRows = Array.from(tableBody.querySelectorAll('tr.order-row'));
            } else {
                dataRows = Array.from(tableBody.querySelectorAll('tr:not([id$="-no-results"])'));
            }

            const totalRows = dataRows.length;
            
            // 1. Handle 'All' case
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
            }

            const start = currentPage * limit;
            const end = start + limit;
            
            // Iterate rows and set visibility
            dataRows.forEach((row, index) => {
                if (index >= start && index < end) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                    // If it's an order row and hidden, also hide its details just in case visual glitches occur? 
                    // CSS 'hidden' on parent usually suffices, but the details row is a sibling.
                    if (row.classList.contains('order-row')) {
                        const nextRow = row.nextElementSibling;
                        if (nextRow && nextRow.classList.contains('details-row')) {
                            nextRow.style.display = 'none'; 
                        }
                    }
                }
                
                // Re-check visibility for active items (handling the sibling details row)
                if (row.classList.contains('order-row') && index >= start && index < end) {
                    const nextRow = row.nextElementSibling;
                    // Restore detail row visibility logic: 
                    // The details row usually has 'hidden' class controlled by toggle.
                    // We just need to remove the 'display:none' inline style potentially added by pagination logic previously.
                    if (nextRow && nextRow.classList.contains('details-row')) {
                        nextRow.style.display = ''; 
                    }
                }
            });

            // Update buttons
            prevBtn.disabled = currentPage === 0;
            nextBtn.disabled = (currentPage >= totalPages - 1) || (totalRows === 0);
        };

        // Event listeners
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
    
    // --- JS Sorting ---
    
    function getSortableValue(cell, type = 'text') {
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

        select.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const sortBy = selectedOption.dataset.sortBy;
            const sortType = selectedOption.dataset.sortType;
            const sortDir = selectedOption.dataset.sortDir; // 'ASC' or 'DESC'

            if (!sortBy) return;

            // Use .shadow-sm (which belongs to the container card) to correctly scope the table search.
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

            // Check if we are sorting Order Rows (Sales table) or standard rows (Returns table)
            let isOrderTable = (tbody.id === 'sales-table-body');
            let rows = [];

            if (isOrderTable) {
                // For Orders: We must keep Order Row + Details Row pairs together
                
                // 1. Create a list of objects { orderRow, detailsRow }
                let currentPairs = [];
                let currentOrderRows = Array.from(tbody.querySelectorAll('tr.order-row'));
                
                currentOrderRows.forEach(oRow => {
                    let dRow = oRow.nextElementSibling; 
                    // Verify it is indeed the details row
                    if (dRow && dRow.classList.contains('details-row')) {
                        currentPairs.push({ order: oRow, details: dRow });
                    } else {
                        currentPairs.push({ order: oRow, details: null });
                    }
                });

                // 2. Sort that list
                currentPairs.sort((a, b) => {
                    // Check if columns exist
                    if (a.order.cells.length <= colIndex || b.order.cells.length <= colIndex) return 0;

                    const valA = getSortableValue(a.order.cells[colIndex], sortType);
                    const valB = getSortableValue(b.order.cells[colIndex], sortType);
                    
                    let comparison = 0;
                    if (valA > valB) comparison = 1;
                    else if (valA < valB) comparison = -1;
                    
                    return sortDir === 'DESC' ? (comparison * -1) : comparison;
                });

                // 3. Append back
                currentPairs.forEach(pair => {
                    tbody.appendChild(pair.order);
                    if(pair.details) tbody.appendChild(pair.details);
                });

            } else {
                // Standard Sorting (Returns Table)
                rows = Array.from(tbody.querySelectorAll('tr:not([id$="-no-results"])'));
                rows.sort((a, b) => {
                    if (a.cells.length <= colIndex || b.cells.length <= colIndex) return 0;
                    const valA = getSortableValue(a.cells[colIndex], sortType);
                    const valB = getSortableValue(b.cells[colIndex], sortType);
                    
                    let comparison = 0;
                    if (valA > valB) comparison = 1;
                    else if (valA < valB) comparison = -1;
                    
                    return sortDir === 'DESC' ? (comparison * -1) : comparison;
                });
                
                rows.forEach(row => tbody.appendChild(row));
            }

            // Re-apply pagination after sorting (reset to page 1)
            const paginationSelect = container.querySelector('select[id$="-rows-select"]');
            if (paginationSelect) {
                paginationSelect.dispatchEvent(new Event('change'));
            }
        });
    }

    // --- Initialization ---
    const mainFilterForm = document.querySelector('#pane-sales form');
    const returnsFilterForm = document.querySelector('#pane-returns form');
    const allTabButtons = document.querySelectorAll('#historyTabs button');
    const urlParams = new URLSearchParams(window.location.search);
    
    // Set initial active tab
    const activeTabFromURL = urlParams.get('active_tab') || 'sales';
    if (mainFilterForm) {
        const inp = mainFilterForm.querySelector('input[name="active_tab"]');
        if(inp) inp.value = activeTabFromURL;
    }
    if (returnsFilterForm) {
        const inp = returnsFilterForm.querySelector('input[name="active_tab"]');
        if(inp) inp.value = 'returns';
    }

    allTabButtons.forEach(tabButton => {
        tabButton.addEventListener('click', function(event) {
            const activeTabValue = event.currentTarget.id.replace('tab-', '');
            const url = new URL(window.location);
            url.searchParams.set('active_tab', activeTabValue);
            
            const form = activeTabValue === 'sales' ? mainFilterForm : returnsFilterForm;
            if (form) {
                const start = form.querySelector('[name="date_start"]').value;
                const end = form.querySelector('[name="date_end"]').value;
                url.searchParams.set('date_start', start);
                url.searchParams.set('date_end', end);
            }
            window.history.replaceState({}, '', url);
        });
    });

    setupSortSelect('sales-sort-select');
    setupSortSelect('returns-sort-select');
    
    addTablePagination('sales-rows-select', 'sales-table-body');
    addTablePagination('returns-rows-select', 'returns-table-body');
});