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
            const all_rows = tableBody.querySelectorAll('tr:not([id$="-no-results"])');
            const visibleRows = Array.from(all_rows);

            if (selectedValue === 'all') {
                visibleRows.forEach(row => {
                    row.style.display = '';
                });
                prevBtn.disabled = true;
                nextBtn.disabled = true;
                return;
            }

            const limit = parseInt(selectedValue, 10);
            const totalRows = visibleRows.length;
            const totalPages = Math.ceil(totalRows / limit);

            // --- Apply pagination ---
            const start = currentPage * limit;
            const end = start + limit;

            visibleRows.forEach((row, index) => {
                row.style.display = (index >= start && index < end) ? '' : 'none';
            });

            // --- Update button states ---
            prevBtn.disabled = currentPage === 0;
            nextBtn.disabled = (currentPage >= totalPages - 1) || (totalRows === 0);
        };

        prevBtn.addEventListener('click', () => {
            if (currentPage > 0) {
                currentPage--;
                updateTableRows();
            }
        });

        nextBtn.addEventListener('click', () => {
            const selectedValue = select.value;
            if (selectedValue === 'all') return;
            const limit = parseInt(selectedValue, 10);
            const totalRows = tableBody.querySelectorAll('tr:not([id$="-no-results"])').length;
            const totalPages = Math.ceil(totalRows / limit);

            if (currentPage < totalPages - 1) {
                currentPage++;
                updateTableRows();
            }
        });
        
        select.addEventListener('change', () => {
            currentPage = 0; // Reset to first page on limit change
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
                cleaned = cleaned.replace(/P|kg|g|L|ml|pcs|pack|tray|can|bottle|\+/gi, '');
                cleaned = cleaned.replace(/,/g, '');
                const num = parseFloat(cleaned);
                return isNaN(num) ? 0 : num;
            case 'date':
                let dateVal = Date.parse(cleaned);
                return isNaN(dateVal) ? 0 : dateVal;
            default: // 'text'
                cleaned = cleaned.replace(/P|kg|g|L|ml|pcs|pack|tray|can|bottle|\+/gi, '');
                cleaned = cleaned.replace(/,/g, '');
                const lowerVal = cleaned.toLowerCase();
                
                // Login Status sorting
                if (lowerVal.includes('failure')) return 'a_failure';
                if (lowerVal.includes('success')) return 'b_success';
                
                // Role sorting
                if (lowerVal.includes('manager')) return 'a_manager';
                if (lowerVal.includes('cashier')) return 'b_cashier';

                // Device sorting
                if (lowerVal.includes('desktop')) return 'a_desktop';
                if (lowerVal.includes('mobile')) return 'b_mobile';
                if (lowerVal.includes('tablet')) return 'c_tablet';
                if (lowerVal.includes('unknown')) return 'd_unknown';
                
                return lowerVal;
        }
    }

    // --- JS Sorting Initializer ---
    function sortTableByDropdown(sortLink) {
        const { sortBy, sortDir, sortType } = sortLink.dataset;
        const table = sortLink.closest('.card').querySelector('table');
        if (!table) return;
        const tbody = table.querySelector('tbody');
        if (!tbody) return;
        const th = table.querySelector(`thead th[data-sort-by="${sortBy}"]`);
        if (!th) {
            console.error(`Sort Error: No table header found with data-sort-by="${sortBy}"`);
            return;
        }
        const colIndex = Array.from(th.parentNode.children).indexOf(th);
        const rows = Array.from(tbody.querySelectorAll('tr:not([id$="-no-results"])')); // Exclude no-results

        rows.sort((a, b) => {
            if (!a.cells[colIndex] || !b.cells[colIndex]) return 0;
            const valA = getSortableValue(a.cells[colIndex].innerText, sortType);
            const valB = getSortableValue(b.cells[colIndex].innerText, sortType);
            if (valA < valB) return sortDir === 'asc' ? -1 : 1;
            if (valA > valB) return sortDir === 'asc' ? 1 : -1;
            return 0;
        });

        tbody.append(...rows);
        
        const paginationSelectId = table.closest('.card').querySelector('select[id$="-rows-select"]')?.id;
        if (paginationSelectId) {
            const paginationSelect = document.getElementById(paginationSelectId);
            if (paginationSelect) {
                paginationSelect.dispatchEvent(new Event('change'));
            }
        }

        const buttonTextSpan = sortLink.closest('.dropdown').querySelector('.current-sort-text');
        if (buttonTextSpan) buttonTextSpan.innerText = sortLink.innerText;
        
        const dropdownItems = sortLink.closest('.dropdown-menu').querySelectorAll('.dropdown-item');
        dropdownItems.forEach(item => item.classList.remove('active'));
        sortLink.classList.add('active');
    }

    // Attach listeners to sort triggers
    document.querySelectorAll('#login-history-card .sort-trigger').forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            sortTableByDropdown(e.target);
        });
    });

    // Initial sort
    const defaultSortLink = document.querySelector('#login-history-card .dropdown-item.active');
    if (defaultSortLink) {
        sortTableByDropdown(defaultSortLink);
    }

    // Initialize pagination
    addTablePagination('login-rows-select', 'login-table-body');

});