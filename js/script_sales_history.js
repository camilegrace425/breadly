document.addEventListener('DOMContentLoaded', () => {
    // ==========================================
    const style = document.createElement('style');
    style.innerHTML = `
        /* Smooth transitions for table body */
        .table-body-transition {
            transition: opacity 0.2s ease, transform 0.2s ease;
            opacity: 1;
            transform: translateY(0);
        }
        
        /* Loading state (faded out) */
        .table-loading {
            opacity: 0.4;
            transform: translateY(3px);
            pointer-events: none; 
        }

        /* Dropdown Menu Animation */
        .dropdown-menu-enter {
            animation: fadeInDrop 0.2s ease-out forwards;
        }
        @keyframes fadeInDrop {
            from { opacity: 0; transform: translateY(-5px); }
            to { opacity: 1; transform: translateY(0); }
        }
    `;
    document.head.appendChild(style);

    // --- Reusable Table Pagination Function ---
    function addTablePagination(selectId, tableBodyId) {
        const select = document.getElementById(selectId);
        const tableBody = document.getElementById(tableBodyId);
        if (!select || !tableBody) return;

        // **ANIMATION**: Add transition class
        tableBody.classList.add('table-body-transition');

        const baseId = selectId.replace('-rows-select', '');
        const prevBtn = document.getElementById(`${baseId}-prev-btn`);
        const nextBtn = document.getElementById(`${baseId}-next-btn`);
        
        if (!prevBtn || !nextBtn) return;

        let currentPage = 0; // 0-indexed page

        const updateTableRows = (isInitial = false) => {
            // **ANIMATION**: Start loading state (unless initial load)
            if (!isInitial) tableBody.classList.add('table-loading');

            // Small delay for animation to be visible
            const delay = isInitial ? 0 : 200;

            setTimeout(() => {
                const selectedValue = select.value;
                
                // Determine what constitutes a "row" to paginate.
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
                    if (!isInitial) tableBody.classList.remove('table-loading');
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
                        // If it's an order row and hidden, also hide its details
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
                        if (nextRow && nextRow.classList.contains('details-row')) {
                            nextRow.style.display = ''; 
                        }
                    }
                });

                // Update buttons
                prevBtn.disabled = currentPage === 0;
                nextBtn.disabled = (currentPage >= totalPages - 1) || (totalRows === 0);

                // **ANIMATION**: End loading state
                if (!isInitial) tableBody.classList.remove('table-loading');

            }, delay);
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
        updateTableRows(true);
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
                cleaned = cleaned.replace(/P|kg|g|L|ml|pcs|pack|tray|can|bottle|\+|\(|\)/gi, '');
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

    function sortTableByDropdown(sortLink) {
        const sortBy = sortLink.dataset.sortBy;
        const sortType = sortLink.dataset.sortType;
        const sortDir = sortLink.dataset.sortDir;
        
        const tableBody = sortLink.closest('.dropdown').closest('.bg-white').querySelector('tbody');
        if (!tableBody) return;

        // **ANIMATION**: Start Loading Transition
        tableBody.classList.add('table-loading');

        // Delay sorting to allow transition to be seen
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
                tableBody.classList.remove('table-loading');
                return;
            }

            // Check if we are sorting Order Rows or standard rows
            let isOrderTable = (tableBody.id === 'sales-table-body');
            let rows = [];

            if (isOrderTable) {
                // For Orders: We must keep Order Row + Details Row pairs together
                let currentPairs = [];
                let currentOrderRows = Array.from(tableBody.querySelectorAll('tr.order-row'));
                
                currentOrderRows.forEach(oRow => {
                    let dRow = oRow.nextElementSibling; 
                    // Verify it is indeed the details row
                    if (dRow && dRow.classList.contains('details-row')) {
                        currentPairs.push({ order: oRow, details: dRow });
                    } else {
                        currentPairs.push({ order: oRow, details: null });
                    }
                });

                currentPairs.sort((a, b) => {
                    const valA = getSortableValue(a.order.cells[colIndex], sortType);
                    const valB = getSortableValue(b.order.cells[colIndex], sortType);
                    let comparison = (valA > valB) ? 1 : (valA < valB ? -1 : 0);
                    return sortDir === 'DESC' ? (comparison * -1) : comparison;
                });

                currentPairs.forEach(pair => {
                    tableBody.appendChild(pair.order);
                    if(pair.details) tableBody.appendChild(pair.details);
                });

            } else {
                // Standard Sorting (Returns Table)
                rows = Array.from(tableBody.querySelectorAll('tr:not([id$="-no-results"])'));
                rows.sort((a, b) => {
                    if (a.cells.length <= colIndex || b.cells.length <= colIndex) return 0;
                    const valA = getSortableValue(a.cells[colIndex], sortType);
                    const valB = getSortableValue(b.cells[colIndex], sortType);
                    
                    let comparison = 0;
                    if (valA > valB) comparison = 1;
                    else if (valA < valB) comparison = -1;
                    
                    return sortDir === 'DESC' ? (comparison * -1) : comparison;
                });
                
                rows.forEach(row => tableBody.appendChild(row));
            }
            
            // UI Updates
            const dropdown = sortLink.closest('.dropdown');
            if (dropdown) {
                dropdown.querySelector('.current-sort-text').textContent = sortLink.textContent.trim();
                dropdown.querySelectorAll('.sort-trigger').forEach(item => item.classList.remove('active'));
                sortLink.classList.add('active');
            }
            
            // Re-trigger pagination to ensure correct view after sort
            const paginationSelect = table.closest('.bg-white').querySelector('select[id$="-rows-select"]');
            if (paginationSelect) {
                paginationSelect.dispatchEvent(new Event('change'));
            }

            // **ANIMATION**: End Loading Transition
            tableBody.classList.remove('table-loading');

        }, 200); // 200ms delay for smooth transition
    }

    function setupDropdown(dropdownId) {
        const dropdownEl = document.getElementById(dropdownId);
        const sortButton = document.getElementById(dropdownId.replace('-dropdown', '-btn'));
        const sortMenu = document.getElementById(dropdownId.replace('-dropdown', '-menu'));
        
        if (dropdownEl && sortButton && sortMenu) {
            sortButton.addEventListener('click', (e) => {
                e.stopPropagation(); 
                // **ANIMATION**: Toggle Class for slide animation
                if (sortMenu.classList.contains('hidden')) {
                    sortMenu.classList.remove('hidden');
                    sortMenu.classList.add('dropdown-menu-enter');
                } else {
                    sortMenu.classList.add('hidden');
                    sortMenu.classList.remove('dropdown-menu-enter');
                }
            });

            document.addEventListener('click', (e) => {
                if (!sortMenu.contains(e.target) && !sortButton.contains(e.target)) {
                    sortMenu.classList.add('hidden');
                    sortMenu.classList.remove('dropdown-menu-enter');
                }
            });
            
            document.querySelectorAll(`#${dropdownId} .sort-trigger`).forEach(link => {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    sortTableByDropdown(e.target);
                    sortMenu.classList.add('hidden'); 
                    sortMenu.classList.remove('dropdown-menu-enter');
                });
            });
        }
    }

    // --- Initialization (Unchanged) ---
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

    setupDropdown('sales-sort-dropdown');
    setupDropdown('returns-sort-dropdown');
    
    addTablePagination('sales-rows-select', 'sales-table-body');
    addTablePagination('returns-rows-select', 'returns-table-body');
});