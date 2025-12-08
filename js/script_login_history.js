document.addEventListener('DOMContentLoaded', () => {

    
    function addTablePagination(selectId, tableBodyId) {
        const select = document.getElementById(selectId);
        const tableBody = document.getElementById(tableBodyId);
        
        if (!select || !tableBody) return;

        const baseId = selectId.replace('-rows-select', '');
        const prevBtn = document.getElementById(`${baseId}-prev-btn`);
        const nextBtn = document.getElementById(`${baseId}-next-btn`);
        
        if (!prevBtn || !nextBtn) return;

        let currentPage = 0; 

        const updateTableRows = () => {
            const selectedValue = select.value;
            const all_rows = Array.from(tableBody.querySelectorAll('tr'));
            const visibleRows = all_rows.filter(row => {
                if (row.cells.length === 1 && row.cells[0].hasAttribute('colspan')) return false;
                if (row.id && row.id.endsWith('-no-results')) return false;
                if (row.style.display === 'none' && row.dataset.paginatedHidden !== 'true') return false;
                return true;
            });

            
            visibleRows.forEach(row => {
                row.style.display = '';
                row.dataset.paginatedHidden = 'false';
            });

            if (selectedValue === 'all') {
                prevBtn.disabled = true;
                nextBtn.disabled = true;
                
                prevBtn.classList.add('opacity-50', 'cursor-not-allowed');
                nextBtn.classList.add('opacity-50', 'cursor-not-allowed');
                return;
            }

            const limit = parseInt(selectedValue, 10);
            const totalRows = visibleRows.length;
            const totalPages = Math.ceil(totalRows / limit);

            
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

            
            prevBtn.disabled = currentPage === 0;
            nextBtn.disabled = (currentPage >= totalPages - 1) || (totalRows === 0);
            
            
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
        
        updateTableRows(); 
    }
    
    
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
            default: 
                const lowerVal = cleaned.toLowerCase();
                
                
                if (lowerVal.includes('failure')) return '0_failure'; 
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

    
    function sortTableByDropdown(sortLink) {
        const { sortBy, sortDir, sortType } = sortLink.dataset;
        
        
        const card = sortLink.closest('#login-history-card') || document.getElementById('login-history-card');
        if (!card) return;
        
        const table = card.querySelector('table');
        if (!table) return;
        
        const tbody = table.querySelector('tbody');
        if (!tbody) return;
        
        
        const th = table.querySelector(`thead th[data-sort-by="${sortBy}"]`);
        if (!th) {
            console.error(`Sort Error: No table header found with data-sort-by="${sortBy}"`);
            return;
        }
        
        const colIndex = Array.from(th.parentNode.children).indexOf(th);
        
        
        const rows = Array.from(tbody.querySelectorAll('tr')).filter(row => {
            return !(row.cells.length === 1 && row.cells[0].hasAttribute('colspan'));
        });

        rows.sort((a, b) => {
            
            const cellA = a.cells[colIndex] ? a.cells[colIndex].innerText : '';
            const cellB = b.cells[colIndex] ? b.cells[colIndex].innerText : '';
            
            const valA = getSortableValue(cellA, sortType);
            const valB = getSortableValue(cellB, sortType);
            
            if (valA < valB) return sortDir === 'asc' ? -1 : 1;
            if (valA > valB) return sortDir === 'asc' ? 1 : -1;
            return 0;
        });

        
        tbody.append(...rows);
        
        
        const paginationSelect = card.querySelector('select[id$="-rows-select"]');
        if (paginationSelect) {
            paginationSelect.dispatchEvent(new Event('change'));
        }

        
        const buttonTextSpan = card.querySelector('.current-sort-text');
        if (buttonTextSpan) buttonTextSpan.innerText = sortLink.innerText;
        
        
        const dropdownItems = card.querySelectorAll('.sort-trigger');
        dropdownItems.forEach(item => {
            item.classList.remove('active', 'bg-orange-50', 'text-orange-700');
            item.classList.add('text-gray-700');
        });
        
        sortLink.classList.add('active', 'bg-orange-50', 'text-orange-700');
        sortLink.classList.remove('text-gray-700');
    }

    
    const sortTriggers = document.querySelectorAll('#login-history-card .sort-trigger');
    sortTriggers.forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            sortTableByDropdown(e.target);
        });
    });

    
    const defaultSortLink = document.querySelector('#login-history-card .sort-trigger.active');
    if (defaultSortLink) {
        sortTableByDropdown(defaultSortLink);
    }

    
    addTablePagination('login-rows-select', 'login-table-body');

});