document.addEventListener('DOMContentLoaded', () => {

    // 1. Top Products Bar Chart
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
                        legend: { display: false }
                    },
                    scales: {
                        y: { beginAtZero: true }
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

    // 2. Daily Revenue & Returns Trend Line Chart
    const trendCtx = document.getElementById('dailyTrendChart');
    if (trendCtx) {
        let trendData = [];
        try {
             trendData = JSON.parse(trendCtx.dataset.trend);
        } catch (e) {
             console.error("Error parsing trend data", e);
        }
        
        const trendLabels = Array.isArray(trendData) ? trendData.map(item => item.date) : [];
        const trendSales = Array.isArray(trendData) ? trendData.map(item => item.sales) : [];
        const trendReturns = Array.isArray(trendData) ? trendData.map(item => item.returns) : [];

        if (trendLabels.length > 0) {
            new Chart(trendCtx, {
                type: 'line', 
                data: {
                    labels: trendLabels,
                    datasets: [
                        {
                            label: 'Total Revenue (₱)',
                            data: trendSales,
                            borderColor: '#0d6efd', 
                            backgroundColor: 'rgba(13, 110, 253, 0.1)',
                            borderWidth: 2,
                            tension: 0.3,
                            fill: true,
                            pointRadius: 4,
                            pointBackgroundColor: '#fff',
                            pointBorderColor: '#0d6efd'
                        },
                        {
                            label: 'Total Returns (₱)',
                            data: trendReturns,
                            borderColor: '#dc3545',
                            backgroundColor: 'rgba(220, 53, 69, 0.1)',
                            borderWidth: 2,
                            tension: 0.3,
                            fill: true,
                            pointRadius: 4,
                            pointBackgroundColor: '#fff',
                            pointBorderColor: '#dc3545'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: true, position: 'top' },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label = label.split('(')[0].trim() + ': ';
                                    }
                                    if (context.parsed.y !== null) {
                                        label += new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(context.parsed.y);
                                    }
                                    return label;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) { return '₱' + value; }
                            }
                        }
                    }
                }
            });
        } else {
            const ctx = trendCtx.getContext('2d');
            ctx.font = '16px Segoe UI';
            ctx.fillStyle = '#6c757d';
            ctx.textAlign = 'center';
            ctx.fillText(`No trend data available.`, trendCtx.width / 2, trendCtx.height / 2);
        }
    }

    // 3. Modal Sorting Logic
    function enableModalSorting(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            const sortTriggers = modal.querySelectorAll('.sort-trigger');
            const sortText = modal.querySelector('.current-sort-text');
            const tableBody = modal.querySelector('tbody.sortable-tbody'); 

            if (tableBody) {
                sortTriggers.forEach(trigger => {
                    trigger.addEventListener('click', (e) => {
                        e.preventDefault();
                        
                        const sortBy = trigger.dataset.sortBy; 
                        const sortDir = trigger.dataset.sortDir; 
                        const sortType = trigger.dataset.sortType; 

                        const rows = Array.from(tableBody.querySelectorAll('tr'));
                        
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
                        
                        rows.forEach(row => tableBody.appendChild(row));

                        if (sortText) sortText.textContent = trigger.textContent;
                        sortTriggers.forEach(t => t.classList.remove('active'));
                        trigger.classList.add('active');
                    });
                });

                const activeSort = modal.querySelector('.sort-trigger.active');
                if (activeSort) {
                    activeSort.click();
                }
            }
        }
    }

    enableModalSorting('stockListModal');
    enableModalSorting('ingredientStockModal');
    
    // 4. Tab State Handling
    const allTabButtons = document.querySelectorAll('#dashboardTabs .nav-link');
    const activeTabInput = document.getElementById('active_tab_input');
    const smsReportForm = document.getElementById('sms-report-form');
    const settingsForm = document.getElementById('settings-form');

    allTabButtons.forEach(tabButton => {
        tabButton.addEventListener('click', function(event) {
            const paneId = event.target.dataset.bsTarget;
            let activeTabValue = (paneId === '#inventory-pane') ? 'inventory' : 'sales';
            
            const url = new URL(window.location);
            url.searchParams.set('active_tab', activeTabValue);
            window.history.replaceState({}, '', url);

            if (activeTabInput) activeTabInput.value = activeTabValue;

            const currentParams = new URLSearchParams(window.location.search);
            currentParams.set('active_tab', activeTabValue);
            const newQueryString = currentParams.toString();

            if (smsReportForm) smsReportForm.action = `dashboard_panel.php?${newQueryString}`;
            if (settingsForm) settingsForm.action = `dashboard_panel.php?${newQueryString}`;
        });
    });

    if (activeTabInput && activeTabInput.value) {
        const activeTabValue = activeTabInput.value;
        const currentParams = new URLSearchParams(window.location.search);
        currentParams.set('active_tab', activeTabValue);
        const newQueryString = currentParams.toString();
        
        if (smsReportForm) smsReportForm.action = `dashboard_panel.php?${newQueryString}`;
        if (settingsForm) settingsForm.action = `dashboard_panel.php?${newQueryString}`;
    }
});