document.addEventListener('DOMContentLoaded', () => {

    // --- ::: NEW Reusable Table Pagination Function (REWRITTEN) ::: ---
    function addTablePagination(selectId, tableBodyId) {
        const select = document.getElementById(selectId);
        const tableBody = document.getElementById(tableBodyId);

        // --- ::: MODIFICATION: Get button IDs from selectId ::: ---
        const baseId = selectId.replace('-rows-select', ''); // e.g., "product"
        const prevBtn = document.getElementById(`${baseId}-prev-btn`);
        const nextBtn = document.getElementById(`${baseId}-next-btn`);
        
        if (!select || !tableBody || !prevBtn || !nextBtn) {
            // console.warn('Pagination elements not found for:', selectId, tableBodyId);
            return;
        }

        let currentPage = 0; // 0-indexed page

        const updateTableRows = () => {
            const selectedValue = select.value;
            
            // Get all rows, but ONLY count those visible by the search filter
            // (Search filter sets style.display = 'none')
            const all_rows = tableBody.querySelectorAll('tr:not([id$="-no-results"])');
            const visibleRows = [];
            all_rows.forEach(row => {
                // Check if row is hidden by search
                const isHiddenBySearch = row.style.display === 'none' && row.dataset.paginatedHidden !== 'true';
                if (!isHiddenBySearch) {
                    // If it's not hidden by search (or only hidden by us), consider it
                    visibleRows.push(row);
                }
            });

            // Reset all rows we manage to be visible (so search filter can re-hide them)
            visibleRows.forEach(row => {
                row.style.display = '';
                row.dataset.paginatedHidden = 'false';
            });

            if (selectedValue === 'all') {
                // "Show All" is selected, disable buttons and exit
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
                if (index >= start && index < end) {
                    row.style.display = ''; // Show
                    row.dataset.paginatedHidden = 'false';
                } else {
                    row.style.display = 'none'; // Hide
                    row.dataset.paginatedHidden = 'true';
                }
            });

            // --- Update button states ---
            prevBtn.disabled = currentPage === 0;
            nextBtn.disabled = (currentPage >= totalPages - 1) || (totalRows === 0);
        };

        // --- ::: ADDED: Event Listeners for buttons ::: ---
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
        
        // Re-apply pagination when search happens
        const searchInputId = select.id.replace('-rows-select', '-search-input');
        const searchInput = document.getElementById(searchInputId);
        if (searchInput) {
            searchInput.addEventListener('keyup', () => {
                currentPage = 0; // Reset to first page on search
                updateTableRows();
            });
        }

        // Add event listener for changes
        select.addEventListener('change', () => {
            currentPage = 0; // Reset to first page on limit change
            updateTableRows();
        });
        
        // Call once on initial load
        updateTableRows();
    }
    // --- ::: END NEW PAGINATION FUNCTION ::: ---
    
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
                // --- ::: ADDED FOR LOGIN TAB ::: ---
                if (lowerVal.includes('failure')) return 'a_failure';
                if (lowerVal.includes('success')) return 'b_success';
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
        
        // After sorting, re-apply pagination
        const paginationSelectId = table.closest('.card').querySelector('select[id$="-rows-select"]')?.id;
        if (paginationSelectId) {
             // Find the select element and trigger its change event to re-apply pagination
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

    // ::: MODIFIED: Attach listeners to returns AND login panes :::
    document.querySelectorAll('#returns-pane .sort-trigger, #logins-pane .sort-trigger').forEach(link => { // <-- MODIFY THIS LINE
        link.addEventListener('click', (e) => {
            e.preventDefault();
            sortTableByDropdown(e.target);
        });
    });

    // ::: MODIFIED: Initial sort for returns AND login panes :::
    document.querySelectorAll('#returns-pane .dropdown, #logins-pane .dropdown').forEach(dropdown => { // <-- MODIFY THIS LINE
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
                    // --- MODIFIED: Added 'logins' ---
                    if (paneId === '#returns-pane') {
                        tabValue = 'returns';
                    } else if (paneId === '#logins-pane') { // <-- ADD THIS ELSE IF
                        tabValue = 'logins';
                    }
                    activeTabInput.value = tabValue;
                });
            });
            // On page load, set it to the correct active tab
            activeTabInput.value = activeTabFromURL;
        }
    }

    // --- Logic to update URL with active tab for state persistence ---
    allTabButtons.forEach(tabButton => {
        tabButton.addEventListener('click', function(event) {
            const paneId = event.target.dataset.bsTarget;
            let activeTabValue = 'sales'; // Default
            // --- MODIFIED: Added 'logins' ---
            if (paneId === '#returns-pane') {
                activeTabValue = 'returns';
            } else if (paneId === '#logins-pane') { // <-- ADD THIS ELSE IF
                activeTabValue = 'logins';
            }
            
            const url = new URL(window.location);
            url.searchParams.set('active_tab', activeTabValue);
            window.history.replaceState({}, '', url);
        });
    });

    // --- ::: MODIFIED: Event Listener for Return Sale Modal ::: ---
    const returnSaleModal = document.getElementById('returnSaleModal');
    if (returnSaleModal) {
        returnSaleModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            
            // Get data from the button
            const saleId = button.dataset.saleId;
            const productName = button.dataset.productName;
            const qtyAvailable = button.dataset.qtyAvailable; // <-- Use the new attribute
            const saleDate = button.dataset.saleDate;

            // Populate the modal fields
            document.getElementById('return_sale_id').value = saleId;
            document.getElementById('return_product_name').textContent = productName;
            document.getElementById('return_sale_date').textContent = saleDate;
            
            // Set the quantity input
            const qtyInput = document.getElementById('return_qty');
            qtyInput.value = qtyAvailable; // Default to the max available
            qtyInput.max = qtyAvailable; // Set max
            document.getElementById('return_max_qty').value = qtyAvailable;
            document.getElementById('return_qty_sold_text').textContent = qtyAvailable; // Update helper text

            // Clear the reason
            document.getElementById('return_reason').value = '';
        });
    }

    // --- ::: NEWLY ADDED: Initialize table pagination ::: ---
    addTablePagination('sales-rows-select', 'sales-table-body');
    addTablePagination('returns-rows-select', 'returns-table-body');
    addTablePagination('login-rows-select', 'login-table-body'); // <-- ADD THIS LINE
    // --- ::: END ::: ---

});