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
            
            // Get actual data rows (exclude message rows or hidden search results)
            // We filter out rows that might be "No results" messages if they don't have data-label attributes
            // or strictly rely on them not having an ID ending in -no-results
            const all_rows = Array.from(tableBody.querySelectorAll('tr'));
            
            // Filter rows that are actual data entries
            const visibleRows = all_rows.filter(row => {
                // Skip rows that are just "No history found" messages (usually have colspan)
                if (row.cells.length === 1 && row.cells[0].hasAttribute('colspan')) return false;
                if (row.id && row.id.endsWith('-no-results')) return false;
                
                // Skip rows hidden by search filter (if any)
                if (row.style.display === 'none' && row.dataset.paginatedHidden !== 'true') return false;
                
                return true;
            });

            // First, ensure filtered rows are flagged as visible before slicing
            visibleRows.forEach(row => {
                row.style.display = '';
                row.dataset.paginatedHidden = 'false';
            });

            if (selectedValue === 'all') {
                prevBtn.disabled = true;
                nextBtn.disabled = true;
                // Apply classes for disabled state if needed
                prevBtn.classList.add('opacity-50', 'cursor-not-allowed');
                nextBtn.classList.add('opacity-50', 'cursor-not-allowed');
                return;
            }

            const limit = parseInt(selectedValue, 10);
            const totalRows = visibleRows.length;
            const totalPages = Math.ceil(totalRows / limit);

            // Adjust current page if out of bounds
            if (currentPage >= totalPages && totalPages > 0) {
                currentPage = totalPages - 1;
            }

            const start = currentPage * limit;
            const end = start + limit;

            visibleRows.forEach((row, index) => {
                if (index >= start && index < end) {
                    row.style.display = '';
                    row.dataset.paginatedHidden = 'false';
                } else {
                    row.style.display = 'none';
                    row.dataset.paginatedHidden = 'true';
                }
            });

            // Update Button States
            prevBtn.disabled = currentPage === 0;
            nextBtn.disabled = (currentPage >= totalPages - 1) || (totalRows === 0);
            
            // Visual feedback for Tailwind buttons
            if (prevBtn.disabled) prevBtn.classList.add('opacity-50', 'cursor-not-allowed');
            else prevBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            
            if (nextBtn.disabled) nextBtn.classList.add('opacity-50', 'cursor-not-allowed');
            else nextBtn.classList.remove('opacity-50', 'cursor-not-allowed');
        };

        prevBtn.addEventListener('click', () => {
            if (!prevBtn.disabled) {
                currentPage--;
                updateTableRows();
            }
        });

        nextBtn.addEventListener('click', () => {
            if (!nextBtn.disabled) {
                currentPage++;
                updateTableRows();
            }
        });
        
        select.addEventListener('change', () => {
            currentPage = 0; 
            updateTableRows();
        });
        
        updateTableRows(); // Call once on initial load
    }
    
    // --- JS Sorting Function ---
    function getSortableValue(value, type = 'text') {
        if (value === null || value === undefined) return '';
        let cleaned = value.trim();
        switch (type) {
            case 'number':
                cleaned = cleaned.replace(/[^0-9.-]+/g, '');
                const num = parseFloat(cleaned);
                return isNaN(num) ? 0 : num;
            case 'date':
                let dateVal = Date.parse(cleaned);
                return isNaN(dateVal) ? 0 : dateVal;
            default: // 'text'
                const lowerVal = cleaned.toLowerCase();
                
                // Custom Sort Priorities
                if (lowerVal.includes('failure')) return '0_failure'; // Failures first
                if (lowerVal.includes('success')) return '1_success';
                
                if (lowerVal.includes('manager')) return 'a_manager';
                if (lowerVal.includes('cashier')) return 'b_cashier';
                if (lowerVal.includes('assistant')) return 'c_assistant';

                if (lowerVal.includes('desktop')) return 'a_desktop';
                if (lowerVal.includes('mobile')) return 'b_mobile';
                if (lowerVal.includes('tablet')) return 'c_tablet';
                
                return lowerVal;
        }
    }

    // --- JS Sorting Initializer ---
    function sortTableByDropdown(sortLink) {
        const { sortBy, sortDir, sortType } = sortLink.dataset;
        
        // Find the table wrapper card
        const card = sortLink.closest('#login-history-card') || document.getElementById('login-history-card');
        if (!card) return;
        
        const table = card.querySelector('table');
        if (!table) return;
        
        const tbody = table.querySelector('tbody');
        if (!tbody) return;
        
        // Find column index by matching header data-sort-by
        const th = table.querySelector(`thead th[data-sort-by="${sortBy}"]`);
        if (!th) {
            console.error(`Sort Error: No table header found with data-sort-by="${sortBy}"`);
            return;
        }
        
        const colIndex = Array.from(th.parentNode.children).indexOf(th);
        
        // Get rows that are actual data (skip empty messages)
        const rows = Array.from(tbody.querySelectorAll('tr')).filter(row => {
            return !(row.cells.length === 1 && row.cells[0].hasAttribute('colspan'));
        });

        rows.sort((a, b) => {
            // Get cell content, handle missing cells safely
            const cellA = a.cells[colIndex] ? a.cells[colIndex].innerText : '';
            const cellB = b.cells[colIndex] ? b.cells[colIndex].innerText : '';
            
            const valA = getSortableValue(cellA, sortType);
            const valB = getSortableValue(cellB, sortType);
            
            if (valA < valB) return sortDir === 'asc' ? -1 : 1;
            if (valA > valB) return sortDir === 'asc' ? 1 : -1;
            return 0;
        });

        // Re-append rows
        tbody.append(...rows);
        
        // Trigger pagination update if pagination exists
        const paginationSelect = card.querySelector('select[id$="-rows-select"]');
        if (paginationSelect) {
            paginationSelect.dispatchEvent(new Event('change'));
        }

        // Update Dropdown UI
        const buttonTextSpan = card.querySelector('.current-sort-text');
        if (buttonTextSpan) buttonTextSpan.innerText = sortLink.innerText;
        
        // Update active state in dropdown
        const dropdownItems = card.querySelectorAll('.sort-trigger');
        dropdownItems.forEach(item => {
            item.classList.remove('active', 'bg-orange-50', 'text-orange-700');
            item.classList.add('text-gray-700');
        });
        
        sortLink.classList.add('active', 'bg-orange-50', 'text-orange-700');
        sortLink.classList.remove('text-gray-700');
    }

    // Attach listeners to sort triggers
    const sortTriggers = document.querySelectorAll('#login-history-card .sort-trigger');
    sortTriggers.forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            sortTableByDropdown(e.target);
        });
    });

    // Initial sort
    const defaultSortLink = document.querySelector('#login-history-card .sort-trigger.active');
    if (defaultSortLink) {
        sortTableByDropdown(defaultSortLink);
    }

    // Initialize pagination
    addTablePagination('login-rows-select', 'login-table-body');

});