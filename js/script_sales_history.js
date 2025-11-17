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
            const visibleRows = [];
            all_rows.forEach(row => {
                const isHiddenBySearch = row.style.display === 'none' && row.dataset.paginatedHidden !== 'true';
                if (!isHiddenBySearch) {
                    visibleRows.push(row);
                }
            });

            visibleRows.forEach(row => {
                row.style.display = '';
                row.dataset.paginatedHidden = 'false';
            });

            if (selectedValue === 'all') {
                prevBtn.disabled = true;
                nextBtn.disabled = true;
                return;
            }

            const limit = parseInt(selectedValue, 10);
            const totalRows = visibleRows.length;
            const totalPages = Math.ceil(totalRows / limit);

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
            const all_rows = tableBody.querySelectorAll('tr:not([id$="-no-results"])');
            const visibleRows = [];
            all_rows.forEach(row => {
                const isHiddenBySearch = row.style.display === 'none' && row.dataset.paginatedHidden !== 'true';
                if (!isHiddenBySearch) {
                    visibleRows.push(row);
                }
            });
            const totalRows = visibleRows.length;
            const totalPages = Math.ceil(totalRows / limit);

            if (currentPage < totalPages - 1) {
                currentPage++;
                updateTableRows();
            }
        });
        
        const searchInputId = select.id.replace('-rows-select', '-search-input');
        const searchInput = document.getElementById(searchInputId);
        if (searchInput) {
            searchInput.addEventListener('keyup', () => {
                currentPage = 0; 
                updateTableRows();
            });
        }

        select.addEventListener('change', () => {
            currentPage = 0; 
            updateTableRows();
        });
        
        updateTableRows();
    }
    
    // --- JS Sorting for Recall & Return Tabs ---
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
                if (lowerVal.includes('low stock')) return 'a_low_stock';
                if (lowerVal.includes('in stock')) return 'b_in_stock';
                if (lowerVal.includes('ingredient')) return 'a_ingredient';
                if (lowerVal.includes('product')) return 'b_product';
                return lowerVal;
        }
    }

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

    // Attach listeners to returns pane
    document.querySelectorAll('#returns-pane .sort-trigger').forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            sortTableByDropdown(e.target);
        });
    });

    // Initial sort for returns pane
    document.querySelectorAll('#returns-pane .dropdown').forEach(dropdown => {
        const defaultSortLink = dropdown.querySelector('.dropdown-item.active') || dropdown.querySelector('.dropdown-item');
        if (defaultSortLink) {
            sortTableByDropdown(defaultSortLink);
        }
    });

    // --- Logic to set active_tab in the main filter form ---
    const mainFilterForm = document.querySelector('#sales-pane form');
    const allTabButtons = document.querySelectorAll('#historyTabs .nav-link');
    const urlParams = new URLSearchParams(window.location.search);
    const activeTabFromURL = urlParams.get('active_tab') || 'sales';

    if (mainFilterForm) {
        const activeTabInput = mainFilterForm.querySelector('input[name="active_tab"]');
        if (activeTabInput) {
            // Set the form's hidden input value based on which tab is clicked
            allTabButtons.forEach(tabButton => {
                tabButton.addEventListener('click', () => {
                    const paneId = tabButton.dataset.bsTarget;
                    let tabValue = 'sales';
                    if (paneId === '#returns-pane') {
                        tabValue = 'returns';
                    }
                    activeTabInput.value = tabValue;
                });
            });
            activeTabInput.value = activeTabFromURL;
        }
    }

    // --- Logic to update URL with active tab for state persistence ---
    allTabButtons.forEach(tabButton => {
        tabButton.addEventListener('click', function(event) {
            const paneId = event.target.dataset.bsTarget;
            let activeTabValue = 'sales'; // Default
            if (paneId === '#returns-pane') {
                activeTabValue = 'returns';
            }
            
            const url = new URL(window.location);
            url.searchParams.set('active_tab', activeTabValue);
            window.history.replaceState({}, '', url);
        });
    });

    // --- Event Listener for Return Sale Modal ---
    const returnSaleModal = document.getElementById('returnSaleModal');
    if (returnSaleModal) {
        returnSaleModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            if (!button) return; // Fix for modal being opened without a button
            
            // Get data from the button
            const saleId = button.dataset.saleId;
            const productName = button.dataset.productName;
            const qtyAvailable = button.dataset.qtyAvailable;
            const saleDate = button.dataset.saleDate;

            // Populate the modal fields
            document.getElementById('return_sale_id').value = saleId;
            document.getElementById('return_product_name').textContent = productName;
            document.getElementById('return_sale_date').textContent = saleDate;
            
            const qtyInput = document.getElementById('return_qty');
            qtyInput.value = qtyAvailable; 
            qtyInput.max = qtyAvailable; 
            document.getElementById('return_max_qty').value = qtyAvailable;
            document.getElementById('return_qty_sold_text').textContent = qtyAvailable;

            document.getElementById('return_reason').value = '';
        });
    }

    // --- Initialize table pagination ---
    addTablePagination('sales-rows-select', 'sales-table-body');
    addTablePagination('returns-rows-select', 'returns-table-body');
    // login-rows-select removed

});