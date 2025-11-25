document.addEventListener('DOMContentLoaded', () => {

    // --- Reusable Table Pagination Function ---
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
            
            // Get all data rows in their current DOM order (which is sorted)
            const all_data_rows = tableBody.querySelectorAll('tr:not([id$="-no-results"])');
            const totalRows = all_data_rows.length;
            
            // 1. Handle 'All' case first
            if (selectedValue === 'all') {
                all_data_rows.forEach(row => row.style.display = '');
                prevBtn.disabled = true;
                nextBtn.disabled = true;
                return;
            }

            const limit = parseInt(selectedValue, 10);
            const totalPages = (totalRows === 0) ? 1 : Math.ceil(totalRows / limit);
            
            // Adjust currentPage if it exceeds the new page count
            if (currentPage >= totalPages) {
                currentPage = Math.max(0, totalPages - 1);
            }

            // 2. Handle Pagination case
            const start = currentPage * limit;
            const end = start + limit;
            
            // Iterate over all rows and set visibility based on index
            all_data_rows.forEach((row, index) => {
                if (index >= start && index < end) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });

            // 3. Update buttons
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

        // Trigger update when select value changes
        select.addEventListener('change', () => {
            currentPage = 0; 
            updateTableRows();
        });
        
        // Initial run
        updateTableRows();
    }
    
    // --- JS Sorting for Sales and Returns Tables ---
    
    function getSortableValue(cell, type = 'text') {
        const textValue = cell.innerText;
        
        // 1. Use data-sort-value if available for explicit sorting (dates as timestamps, net total as number)
        if ((type === 'number' || type === 'date') && cell.dataset.sortValue !== undefined) {
             const num = parseFloat(cell.dataset.sortValue);
             return isNaN(num) ? 0 : num;
        }
        
        let cleaned = textValue.trim();
        switch (type) {
            case 'number':
                // 2. Clean common non-numeric characters for general number columns
                cleaned = cleaned.replace(/P|kg|g|L|ml|pcs|pack|tray|can|bottle|\+|\(|\)/gi, '');
                cleaned = cleaned.replace(/,/g, '');
                const num = parseFloat(cleaned);
                return isNaN(num) ? 0 : num;
            case 'date':
                // 3. Date parsing (fallback if no data-sort-value)
                let dateVal = Date.parse(cleaned);
                return isNaN(dateVal) ? 0 : dateVal;
            default: // 'text'
                const lowerVal = cleaned.toLowerCase();
                return lowerVal;
        }
    }

    function sortTableByDropdown(sortLink) {
        const sortBy = sortLink.dataset.sortBy;
        const sortType = sortLink.dataset.sortType;
        const sortDir = sortLink.dataset.sortDir;
        
        const tableBody = sortLink.closest('.dropdown').closest('.bg-white').querySelector('tbody');
        if (!tableBody) return;
        
        const table = tableBody.closest('table');
        const headerRow = table.querySelector('thead tr');
        let colIndex = -1;
        
        // Find the column index by matching the TH data-sort-by attribute
        Array.from(headerRow.querySelectorAll('th')).forEach((th, index) => {
            if (th.dataset.sortBy === sortBy) {
                colIndex = index;
            }
        });
        
        if (colIndex === -1) {
            console.error(`Sort Error: Could not find column index for data-sort-by="${sortBy}"`);
            return;
        }

        // Get all data rows
        const rows = Array.from(tableBody.querySelectorAll('tr:not([id$="-no-results"])'));

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

        tableBody.innerHTML = '';
        rows.forEach(row => tableBody.appendChild(row));
        
        const dropdown = sortLink.closest('.dropdown');
        if (dropdown) {
            const buttonTextSpan = dropdown.querySelector('.current-sort-text');
            // Use the text content directly from the link since it includes the direction
            buttonTextSpan.textContent = sortLink.textContent.trim();
            
            const dropdownItems = dropdown.querySelectorAll('.sort-trigger');
            dropdownItems.forEach(item => item.classList.remove('active'));
            sortLink.classList.add('active');
        }
        
        // Re-apply pagination after sorting
        const paginationSelect = table.closest('.bg-white').querySelector('select[id$="-rows-select"]');
        if (paginationSelect) {
            paginationSelect.dispatchEvent(new Event('change'));
        }
    }

    function setupDropdown(dropdownId) {
        const dropdownEl = document.getElementById(dropdownId);
        const sortButton = document.getElementById(dropdownId.replace('-dropdown', '-btn'));
        const sortMenu = document.getElementById(dropdownId.replace('-dropdown', '-menu'));
        
        if (dropdownEl && sortButton && sortMenu) {
            // Toggle menu on button click
            sortButton.addEventListener('click', (e) => {
                e.stopPropagation(); 
                sortMenu.classList.toggle('hidden');
            });

            // Close menu when clicking outside
            document.addEventListener('click', (e) => {
                if (!sortMenu.contains(e.target) && !sortButton.contains(e.target)) {
                    sortMenu.classList.add('hidden');
                }
            });
            
            // Set up click listener for sort links
            document.querySelectorAll(`#${dropdownId} .sort-trigger`).forEach(link => {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    sortTableByDropdown(e.target);
                    sortMenu.classList.add('hidden'); // Close after sorting
                });
            });

            // Apply default sort (active link) on load
            const defaultSortLink = dropdownEl.querySelector('.sort-trigger.active');
            if (defaultSortLink) {
                 setTimeout(() => sortTableByDropdown(defaultSortLink), 50);
            }
        }
    }


    // --- Initialization ---

    // 1. Logic to set active_tab in the main filter form
    const mainFilterForm = document.querySelector('#pane-sales form');
    const allTabButtons = document.querySelectorAll('#historyTabs button');
    const urlParams = new URLSearchParams(window.location.search);
    const activeTabFromURL = urlParams.get('active_tab') || 'sales';

    if (mainFilterForm) {
        const activeTabInput = mainFilterForm.querySelector('input[name="active_tab"]');
        if (activeTabInput) {
            allTabButtons.forEach(tabButton => {
                tabButton.addEventListener('click', () => {
                    const tabValue = tabButton.id.replace('tab-', '');
                    activeTabInput.value = tabValue;
                });
            });
            activeTabInput.value = activeTabFromURL;
        }
    }
    
    const returnsFilterForm = document.querySelector('#pane-returns form');
    if (returnsFilterForm) {
        const activeTabInput = returnsFilterForm.querySelector('input[name="active_tab"]');
        if (activeTabInput) {
            activeTabInput.value = 'returns';
        }
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

    // 2. Initialize Sorting and Pagination
    setupDropdown('sales-sort-dropdown');
    setupDropdown('returns-sort-dropdown');
    
    addTablePagination('sales-rows-select', 'sales-table-body');
    addTablePagination('returns-rows-select', 'returns-table-body');
});