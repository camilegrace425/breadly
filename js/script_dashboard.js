document.addEventListener('DOMContentLoaded', () => {

    // Initialize Top Products Bar Chart
    const topProductsCtx = document.getElementById('topProductsChart');
    
    if (topProductsCtx) {
        // Read the data from the canvas element's 'data-products' attribute
        const topProductsData = JSON.parse(topProductsCtx.dataset.products);

        // Prepare data for charts
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
            // Display a message if no data
            const ctx = topProductsCtx.getContext('2d');
            ctx.font = '16px Segoe UI';
            ctx.fillStyle = '#6c757d'; // Bootstrap text-muted color
            ctx.textAlign = 'center';
            ctx.fillText('No sales data available for the last 30 days.', topProductsCtx.width / 2, topProductsCtx.height / 2);
        }
    }
});