// Store chart instances globally to allow updates
window.dashboardCharts = {
    topProducts: null,
    trend: null
};

// Global functions called by onclick attributes
function toggleSidebar() {
    const sidebar = document.getElementById('mobileSidebar');
    const overlay = document.getElementById('mobileSidebarOverlay');
    
    if (sidebar.classList.contains('-translate-x-full')) {
        sidebar.classList.remove('-translate-x-full');
        overlay.classList.remove('hidden');
    } else {
        sidebar.classList.add('-translate-x-full');
        overlay.classList.add('hidden');
    }
}

function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.classList.remove('hidden');
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.classList.add('hidden');
}

function switchTab(tabName) {
    const paneSales = document.getElementById('pane-sales');
    const paneInventory = document.getElementById('pane-inventory');
    
    if(paneSales) paneSales.classList.add('hidden');
    if(paneInventory) paneInventory.classList.add('hidden');
    
    const targetPane = document.getElementById('pane-' + tabName);
    if(targetPane) targetPane.classList.remove('hidden');
    
    const salesBtn = document.getElementById('tab-sales');
    const invBtn = document.getElementById('tab-inventory');
    
    const activeClasses = ['border-breadly-btn', 'text-breadly-btn'];
    const inactiveClasses = ['border-transparent', 'text-gray-500', 'hover:text-gray-700'];
    
    if (tabName === 'sales') {
        if(salesBtn) {
            salesBtn.classList.add(...activeClasses);
            salesBtn.classList.remove(...inactiveClasses);
        }
        if(invBtn) {
            invBtn.classList.remove(...activeClasses);
            invBtn.classList.add(...inactiveClasses);
        }
    } else {
        if(invBtn) {
            invBtn.classList.add(...activeClasses);
            invBtn.classList.remove(...inactiveClasses);
        }
        if(salesBtn) {
            salesBtn.classList.remove(...activeClasses);
            salesBtn.classList.add(...inactiveClasses);
        }
    }

    // --- REMOVED URL UPDATE LOGIC HERE ---
    // The code that updated window.history has been removed 
    // to prevent the URL from changing.

    const activeTabInput = document.getElementById('active_tab_input');
    if (activeTabInput) activeTabInput.value = tabName;

    updateFormActionsWithTab(tabName);
}

function updateFormActionsWithTab(tabName) {
    const currentParams = new URLSearchParams(window.location.search);
    currentParams.set('active_tab', tabName);
    const newQueryString = currentParams.toString();

    const smsReportForm = document.getElementById('sms-report-form');
    if (smsReportForm) smsReportForm.action = `dashboard_panel.php?${newQueryString}`;
}

function toggleEmailField(show, type) {
    let containerId, formId;
    if (type === 'pdf') {
        containerId = 'pdfEmailContainer';
        formId = 'pdfReportForm';
    } else {
        containerId = 'csvEmailContainer';
        formId = 'csvReportForm';
    }
    const container = document.getElementById(containerId);
    const emailInput = container ? container.querySelector('input') : null;
    const form = document.getElementById(formId);
    
    if (container && emailInput && form) {
        if (show) {
            container.classList.remove('hidden');
            emailInput.required = true;
            form.removeAttribute('target');
        } else {
            container.classList.add('hidden');
            emailInput.required = false;
            form.setAttribute('target', '_blank');
        }
    }
}

function toggleSortDropdown(button) {
    const dropdown = button.nextElementSibling;
    if (!dropdown) return;

    const isHidden = dropdown.classList.contains('hidden');
    
    document.querySelectorAll('.sort-dropdown-menu').forEach(menu => {
        menu.classList.add('hidden');
    });

    if (isHidden) {
        dropdown.classList.remove('hidden');
    } else {
        dropdown.classList.add('hidden');
    }
    
    if (window.event) {
        window.event.stopPropagation();
    }
}

// --- AJAX Dashboard Logic ---
function attachDashboardListeners() {
    const form = document.getElementById('dashboard-filter-form');
    if (!form) return;

    // Remove old listeners by cloning
    const newForm = form.cloneNode(true);
    form.parentNode.replaceChild(newForm, form);
    
    const activeForm = newForm;

    // Handle Form Submit
    activeForm.addEventListener('submit', function(e) {
        e.preventDefault();
        fetchDashboardData(new FormData(activeForm));
    });

    // Auto-submit on date change
    const dateInputs = activeForm.querySelectorAll('input[type="date"]');
    dateInputs.forEach(input => {
        input.addEventListener('change', function() {
            fetchDashboardData(new FormData(activeForm));
        });
    });

    // Handle "Today" button
    const todayBtn = document.getElementById('btn-today');
    if (todayBtn) {
        // Clone to clear listeners
        const newTodayBtn = todayBtn.cloneNode(true);
        todayBtn.parentNode.replaceChild(newTodayBtn, todayBtn);

        newTodayBtn.addEventListener('click', function() {
            const today = new Date().toISOString().split('T')[0];
            const startInput = activeForm.querySelector('input[name="date_start"]');
            const endInput = activeForm.querySelector('input[name="date_end"]');
            
            if (startInput && endInput) {
                startInput.value = today;
                endInput.value = today;
                fetchDashboardData(new FormData(activeForm));
            }
        });
    }
}

function fetchDashboardData(formData) {
    const params = new URLSearchParams(formData);
    params.append('ajax', '1');

    fetch(`dashboard_panel.php?${params.toString()}`)
        .then(response => response.json())
        .then(data => {
            if (data.summary) {
                // Update Summary Cards
                updateText('summary-net-revenue', '₱' + data.summary.netRevenue);
                updateText('summary-gross-revenue', '₱' + data.summary.grossRevenue);
                updateText('summary-less-returns', '-₱' + data.summary.totalReturnsValue);
                
                updateText('summary-total-sold', data.summary.totalSales);
                updateText('summary-date-label-1', data.summary.dateRangeText);
                
                const returnsCountEl = document.getElementById('summary-returns-count');
                if (returnsCountEl) {
                    returnsCountEl.innerText = data.summary.totalReturnsCount;
                    returnsCountEl.className = `text-3xl font-bold ${data.summary.totalReturnsCount > 0 ? 'text-red-600' : 'text-green-700'}`;
                }
                
                const returnsValEl = document.getElementById('summary-returns-value');
                if (returnsValEl) {
                    returnsValEl.innerText = '₱' + data.summary.totalReturnsValue;
                    returnsValEl.className = `text-3xl font-bold ${data.summary.totalReturnsValue > 0 ? 'text-red-600' : 'text-green-700'}`;
                }
                updateText('summary-date-label-2', data.summary.dateRangeText);

                // Update Recall Card
                const recallCountEl = document.getElementById('summary-recalled-count');
                if (recallCountEl) {
                    recallCountEl.innerText = data.summary.recallCount;
                    recallCountEl.className = `text-4xl font-bold ${data.summary.recallCount > 0 ? 'text-red-600' : 'text-green-700'}`;
                }
                
                const recallValEl = document.getElementById('summary-recalled-value');
                if (recallValEl) {
                    recallValEl.innerText = '₱' + data.summary.recallValue;
                    recallValEl.className = `font-medium ${data.summary.recallValue > 0 ? 'text-red-700' : 'text-green-700'}`;
                }
            }

            if (data.charts) {
                updateCharts(data.charts);
            }
        })
        .catch(err => console.error('Error fetching dashboard data:', err));
}

function updateText(id, text) {
    const el = document.getElementById(id);
    if (el) el.innerText = text;
}

function updateCharts(chartData) {
    // 1. Top Products Chart
    if (window.dashboardCharts.topProducts) {
        const topProd = chartData.topProducts || [];
        window.dashboardCharts.topProducts.data.labels = topProd.map(p => p.name);
        window.dashboardCharts.topProducts.data.datasets[0].data = topProd.map(p => p.total_units_sold);
        window.dashboardCharts.topProducts.update();
    }

    // 2. Trend Chart
    if (window.dashboardCharts.trend) {
        const trend = chartData.trendData || [];
        window.dashboardCharts.trend.data.labels = trend.map(t => t.date);
        window.dashboardCharts.trend.data.datasets[0].data = trend.map(t => t.sales);
        window.dashboardCharts.trend.data.datasets[1].data = trend.map(t => t.returns);
        window.dashboardCharts.trend.update();
    }
}

// Initialization Logic
document.addEventListener('DOMContentLoaded', () => {

    // 1. Top Products Bar Chart
    const topProductsCtx = document.getElementById('topProductsChart');
    if (topProductsCtx) {
        const topProductsData = JSON.parse(topProductsCtx.dataset.products);
        
        const productLabels = Array.isArray(topProductsData) ? topProductsData.map(product => product.name) : [];
        const productSalesData = Array.isArray(topProductsData) ? topProductsData.map(product => product.total_units_sold) : [];

        if (productLabels.length > 0) {
            window.dashboardCharts.topProducts = new Chart(topProductsCtx, {
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
                    animation: {
                        delay: (context) => {
                            let delay = 0;
                            if (context.type === 'data' && context.mode === 'default' && !context.dropped) {
                                delay = context.dataIndex * 100; 
                            }
                            return delay;
                        }
                    },
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true } }
                }
            });
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
            window.dashboardCharts.trend = new Chart(trendCtx, {
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
                    animation: {
                        x: {
                            type: 'number',
                            easing: 'linear',
                            duration: 300, 
                            from: NaN, 
                            delay: function(ctx) {
                                if (ctx.type !== 'data' || ctx.xStarted) return 0;
                                ctx.xStarted = true;
                                return ctx.index * 30; 
                            }
                        },
                        y: {
                            type: 'number',
                            easing: 'linear',
                            duration: 300, 
                            from: function(ctx) {
                                return ctx.index === 0 ? ctx.chart.scales.y.getPixelForValue(0) : ctx.chart.getDatasetMeta(ctx.datasetIndex).data[ctx.index - 1].getProps(['y'], true).y;
                            },
                            delay: function(ctx) {
                                if (ctx.type !== 'data' || ctx.yStarted) return 0;
                                ctx.yStarted = true;
                                return ctx.index * 30; 
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
                            ticks: {
                                callback: function(value) { return '₱' + value; }
                            }
                        }
                    }
                }
            });
        }
    }

    // Initialize Helpers
    enableModalSorting('stockListModal');
    enableModalSorting('ingredientStockModal');
    enableModalSorting('recallModal');
    
    const filterCheckbox = document.getElementById('filterLowStock');
    if (filterCheckbox) {
        filterCheckbox.addEventListener('change', function() {
            const showLowOnly = this.checked;
            const rows = document.querySelectorAll('#ingredientStockModal tbody tr');
            
            rows.forEach(row => {
                if (showLowOnly) {
                    const isLow = row.getAttribute('data-is-low') === '1';
                    if (!isLow) {
                        row.classList.add('hidden');
                    } else {
                        row.classList.remove('hidden');
                    }
                } else {
                     row.classList.remove('hidden');
                }
            });
        });
    }

    window.addEventListener('click', function(e) {
        if (!e.target.closest('.sort-dropdown-container')) {
            document.querySelectorAll('.sort-dropdown-menu').forEach(menu => {
                menu.classList.add('hidden');
            });
        }
    });

    // --- Attach AJAX Logic ---
    attachDashboardListeners();
    
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
                        
                        const dropdownMenu = trigger.closest('.sort-dropdown-menu');
                        if (dropdownMenu) {
                            dropdownMenu.classList.add('hidden');
                        }
                    });
                });
            }
        }
    }
});