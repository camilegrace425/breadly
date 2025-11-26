document.addEventListener('DOMContentLoaded', () => {

    // ==========================================
    // 1. Top Products Bar Chart(Enhanced)
    // ==========================================
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
                        borderRadius: 4, 
                        borderSkipped: false, 
                        hoverBackgroundColor: '#d48648' 
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    // **NEW: Animation Configuration**
                    animation: {
                        duration: 2000, // Animation takes 2 seconds total
                        easing: 'easeOutQuart', // "Slow down at the end" effect
                        // **NEW: Stagger Effect** - Bars load one after another
                        delay: (context) => {
                            let delay = 0;
                            // Only animate data points, and only on initial load
                            if (context.type === 'data' && context.mode === 'default') {
                                delay = context.dataIndex * 300 + context.datasetIndex * 100;
                            }
                            return delay;
                        }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            // **NEW: Tooltip Animation**
                            animation: {
                                duration: 150
                            },
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            cornerRadius: 8,
                        }
                    },
                    scales: {
                        y: { 
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0,0,0,0.05)' // **NEW: Makes grid lines subtle**
                            }
                        },
                        x: {
                            grid: {
                                display: false // **NEW: Clean look by removing vertical lines**
                            }
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

    // ==========================================
    // 2. Daily Revenue & Returns (Enhanced)
    // ==========================================
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
                            backgroundColor: (context) => {
                                // **NEW: Gradient Fill** - Makes the area look shiny/modern
                                const ctx = context.chart.ctx;
                                const gradient = ctx.createLinearGradient(0, 0, 0, 400);
                                gradient.addColorStop(0, 'rgba(13, 110, 253, 0.4)'); // Darker at top
                                gradient.addColorStop(1, 'rgba(13, 110, 253, 0.0)'); // Fades out at bottom
                                return gradient;
                            },
                            borderWidth: 3, // Slightly thicker line for visibility
                            tension: 0.4, // **NEW: Curvier lines (0.4 is smoother than 0.3)**
                            fill: true,
                            pointRadius: 0, // **NEW: Hide points initially for a clean look...**
                            pointHoverRadius: 6, // **...but show them big on hover**
                            pointBackgroundColor: '#fff',
                            pointBorderColor: '#0d6efd',
                            pointBorderWidth: 2
                        },
                        {
                            label: 'Total Returns (₱)',
                            data: trendReturns,
                            borderColor: '#dc3545',
                            backgroundColor: (context) => {
                                // **NEW: Gradient Fill for Returns too**
                                const ctx = context.chart.ctx;
                                const gradient = ctx.createLinearGradient(0, 0, 0, 400);
                                gradient.addColorStop(0, 'rgba(220, 53, 69, 0.4)');
                                gradient.addColorStop(1, 'rgba(220, 53, 69, 0.0)');
                                return gradient;
                            },
                            borderWidth: 2,
                            tension: 0.4,
                            fill: true,
                            pointRadius: 0,
                            pointHoverRadius: 6,
                            pointBackgroundColor: '#fff',
                            pointBorderColor: '#dc3545',
                            pointBorderWidth: 2
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    // **NEW: Interaction Settings**
                    interaction: {
                        mode: 'index', // Hovering one point shows tooltips for BOTH lines
                        intersect: false, // You don't have to hit the exact dot to see info
                    },
                    // **NEW: Progressive Line Animation**
                    animation: {
                        x: {
                            type: 'number',
                            easing: 'linear',
                            duration: 1000,
                            from: NaN, // Draws the line from left to right
                            delay: (ctx) => {
                                if (ctx.type !== 'data' || ctx.xStarted) {
                                    return 0;
                                }
                                ctx.xStarted = true;
                                return ctx.index * 20; // Slight delay per point
                            }
                        },
                        y: {
                            type: 'number',
                            easing: 'linear',
                            duration: 1000,
                            from: (ctx) => {
                                return ctx.index === 0 ? ctx.chart.scales.y.getPixelForValue(0) : ctx.chart.getDatasetMeta(ctx.datasetIndex).data[ctx.index - 1].getProps(['y'], true).y;
                            },
                            delay: (ctx) => {
                                if (ctx.type !== 'data' || ctx.yStarted) {
                                    return 0;
                                }
                                ctx.yStarted = true;
                                return ctx.index * 20;
                            }
                        }
                    },
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
                            grid: { borderDash: [5, 5] }, // **NEW: Dashed grid lines look modern**
                            ticks: {
                                callback: function(value) { return '₱' + value; }
                            }
                        },
                        x: {
                            grid: { display: false } // Cleaner X axis
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

    // ==========================================
    // 3. Modal Sorting (Unchanged)
    // ==========================================
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
                        
                        // **Added small fade animation for sorting**
                        tableBody.style.opacity = '0.5'; 
                        
                        setTimeout(() => {
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
                            tableBody.style.opacity = '1'; // Restore opacity
                        }, 200);

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
    
    // ==========================================
    // 4. Tab State Handling (Unchanged)
    // ==========================================
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