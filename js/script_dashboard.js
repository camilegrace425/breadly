document.addEventListener('DOMContentLoaded', () => {

    const topProductsCtx = document.getElementById('topProductsChart');
    if (topProductsCtx) {
        const topProductsData = JSON.parse(topProductsCtx.dataset.products);
        const dateRangeText = topProductsCtx.dataset.dateRange || 'the selected period';
        
        const productLabels = Array.isArray(topProductsData) ? topProductsData.map(product => product.name) : [];
        const productSalesData = Array.isArray(topProductsData) ? topProductsData.map(product => product.total_units_sold) : [];

        if (productLabels.length > 0) {
            new Chart(topProductsCtx, {
                type: 'bar',
                data: {
                    labels: productLabels,
                    datasets: [{
                        label: 'Units Sold',
                        data: productSalesData,
                        backgroundColor: '#E5A26A',
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        } else {
            const ctx = topProductsCtx.getContext('2d');
            ctx.font = '16px Segoe UI';
            ctx.fillStyle = '#6c757d';
            ctx.textAlign = 'center';
            ctx.fillText(`No sales data available for ${dateRangeText}.`, topProductsCtx.width / 2, topProductsCtx.height / 2);
        }
    }

    // --- Current Stock Modal Logic (Unchanged) ---
    const modal = document.getElementById('stockListModal');
    if (modal) {
        const sortTriggers = modal.querySelectorAll('.sort-trigger');
        const sortText = modal.querySelector('.current-sort-text');
        const tableBody = modal.querySelector('#stock-list-tbody'); // Target the <tbody>

        if (tableBody) { // Only run if there are items to sort
            
            sortTriggers.forEach(trigger => {
                trigger.addEventListener('click', (e) => {
                    e.preventDefault();
                    
                    const sortBy = trigger.dataset.sortBy; // 'name' or 'stock'
                    const sortDir = trigger.dataset.sortDir; // 'asc' or 'desc'
                    const sortType = trigger.dataset.sortType; // 'text' or 'number'

                    // Get all table rows (tr)
                    const rows = Array.from(tableBody.querySelectorAll('tr'));
                    
                    // Sort them
                    rows.sort((a, b) => {
                        let valA = a.dataset[sortBy];
                        let valB = b.dataset[sortBy];

                        if (sortType === 'number') {
                            valA = parseFloat(valA) || 0;
                            valB = parseFloat(valB) || 0;
                        }

                        if (valA < valB) return sortDir === 'asc' ? -1 : 1;
                        if (valA > valB) return sortDir === 'asc' ? 1 : -1;
                        return 0;
                    });
                    
                    // Re-append sorted rows
                    rows.forEach(row => tableBody.appendChild(row));

                    // Update button text
                    if (sortText) sortText.textContent = trigger.textContent;
                    sortTriggers.forEach(t => t.classList.remove('active'));
                    trigger.classList.add('active');
                });
            });

            // Trigger initial sort
            const activeSort = modal.querySelector('.sort-trigger.active');
            if (activeSort) {
                // Use click() to run the sort logic
                activeSort.click();
            }
        }
    }
    
    const allTabButtons = document.querySelectorAll('#dashboardTabs .nav-link');
    const dateFilterForm = document.getElementById('date-filter-form');
    const activeTabInput = document.getElementById('active_tab_input');
    
    const smsReportForm = document.getElementById('sms-report-form');
    const settingsForm = document.getElementById('settings-form');

    allTabButtons.forEach(tabButton => {
        tabButton.addEventListener('click', function(event) {
            const paneId = event.target.dataset.bsTarget;
            let activeTabValue = 'sales'; // Default
            if (paneId === '#inventory-pane') {
                activeTabValue = 'inventory';
            }
            
            // 1. Update the URL in the browser bar
            const url = new URL(window.location);
            url.searchParams.set('active_tab', activeTabValue);
            window.history.replaceState({}, '', url);

            // 2. Update the hidden input in the main date filter form
            if (activeTabInput) {
                activeTabInput.value = activeTabValue;
            }

            // 3. Update the 'action' attribute on the modal forms
            //    This ensures that when you submit a modal, you return to the correct tab.
            const currentParams = new URLSearchParams(window.location.search);
            currentParams.set('active_tab', activeTabValue); // Set the new tab
            const newQueryString = currentParams.toString();

            if (smsReportForm) {
                smsReportForm.action = `dashboard_panel.php?${newQueryString}`;
            }
            if (settingsForm) {
                settingsForm.action = `dashboard_panel.php?${newQueryString}`;
            }
        });
    });

    // On page load, ensure the modal forms have the correct active_tab
    // This is for the case where the page is loaded with GET params
    if (activeTabInput && activeTabInput.value) {
        const activeTabValue = activeTabInput.value;
        const currentParams = new URLSearchParams(window.location.search);
        currentParams.set('active_tab', activeTabValue);
        const newQueryString = currentParams.toString();
        
        if (smsReportForm) {
            smsReportForm.action = `dashboard_panel.php?${newQueryString}`;
        }
        if (settingsForm) {
            settingsForm.action = `dashboard_panel.php?${newQueryString}`;
        }
    }

});