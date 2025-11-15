<?php
session_start();
// Set this page as the active page
$active_page = 'dashboard';

// Include PHP logic files
// require_once '../src/DashboardManager.php';
// $manager = new DashboardManager();
// $stats = $manager->getSalesSummaryByDateRange(...);
// $low_stock_items = $manager->getActiveLowStockAlerts(3);

// Include the header
include 'header.php';
?>

<!-- Page Title -->
<h1 class="h2 fw-bold" style="color: var(--color-text-primary);">Dashboard Overview</h1>
<p class="text-muted mb-4">Monitor your bakery's performance in real-time</p>

<!-- Low Stock Alert -->
<div class="card card-body mb-4 border-warning border-opacity-50">
    <div class="d-flex align-items-center">
        <i class="bi bi-exclamation-triangle-fill fs-4 text-warning me-3"></i>
        <h3 class="h5 mb-0 fw-semibold" style="color: #664d03;">Low Stock Alert</h3>
    </div>
    <div class="mt-3 ms-5">
        
        <!-- PHP loop for low stock items would go here -->
        <?php /*
        if (empty($low_stock_items)) {
            echo '<p class="text-muted">No items are critically low on stock.</p>';
        } else {
            foreach ($low_stock_items as $item) {
        */ ?>
        
        <!-- Placeholder Item 1 -->
        <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="text-dark">All-Purpose Flour</span>
            <span class="badge low-stock-item-tag">
                15 kg <span class="min-stock">(Min: 50)</span>
            </span>
        </div>
        <!-- Placeholder Item 2 -->
        <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="text-dark">Butter</span>
            <span class="badge low-stock-item-tag">
                8 kg <span class="min-stock">(Min: 20)</span>
            </span>
        </div>
        <!-- Placeholder Item 3 -->
        <div class="d-flex justify-content-between align-items-center">
            <span class="text-dark">Eggs</span>
            <span class="badge low-stock-item-tag">
                30 pcs <span class="min-stock">(Min: 100)</span>
            </span>
        </div>
        
        <?php /*
            }
        }
        */ ?>
    </div>
</div>

<!-- Stats Cards Grid -->
<div class="row g-4 mb-4">
    <!-- Today's Sales -->
    <div class="col-md-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <p>Today's Sales</p>
                    <i class="bi bi-cash-stack fs-4 text-muted"></i>
                </div>
                <h1 class="mb-0">$8,520</h1>
                <span class="percent-change positive">+12.5%</span>
                <span class="text-muted small">vs. yesterday</span>
            </div>
        </div>
    </div>
    <!-- Total Orders -->
    <div class="col-md-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div classd-flex justify-content-between align-items-start">
                    <p>Total Orders</p>
                    <i class="bi bi-cart fs-4 text-muted"></i>
                </div>
                <h1 class="mb-0">58</h1>
                <span class="percent-change positive">+8.2%</span>
                <span class="text-muted small">vs. yesterday</span>
            </div>
        </div>
    </div>
    <!-- Avg. Order Value -->
    <div class="col-md-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <p>Avg. Order Value</p>
                    <i class="bi bi-reception-4 fs-4 text-muted"></i>
                </div>
                <h1 class="mb-0">$146.90</h1>
                <span class="percent-change negative">-3.1%</span>
                <span class="text-muted small">vs. yesterday</span>
            </div>
        </div>
    </div>
    <!-- Low Stock Items -->
    <div class="col-md-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <p>Low Stock Items</p>
                    <i class="bi bi-exclamation-triangle fs-4 text-warning"></i>
                </div>
                <h1 class="mb-0">3</h1>
                <span class="text-muted small">Need restock attention</span>
            </div>
        </div>
    </div>
</div>

<!-- Charts Grid -->
<div class="row g-4">
    <!-- Weekly Sales Trend -->
    <div class="col-lg-8">
        <div class="card chart-container">
            <div class="card-body">
                <h3 class="chart-title">Weekly Sales Trend</h3>
                <p class="chart-subtitle">Sales performance over the last 7 days</p>
                <div style="height: 300px;">
                    <canvas id="salesChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <!-- Sales by Category -->
    <div class="col-lg-4">
        <div class="card chart-container">
            <div class="card-body">
                <h3 class="chart-title">Sales by Category</h3>
                <p class="chart-subtitle">Revenue distribution</p>
                <div style="height: 300px;">
                    <canvas id="categoryChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Include the footer
include 'footer.php';
?>