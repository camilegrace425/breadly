<?php
session_start();
// Set this page as the active page
$active_page = 'inventory';

// Include PHP logic files
// require_once '../src/InventoryManager.php';
// $manager = new InventoryManager();
// $products = $manager->getProductsInventory();

// Include the header
include 'header.php';
?>

<!-- Page Title & Add Button -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h2 fw-bold" style="color: var(--color-text-primary);">Inventory Management</h1>
        <p class="text-muted">Manage products, ingredients, and stock levels</p>
    </div>
    <a href="#" class="btn btn-primary" style="background-color: var(--color-accent); border-color: var(--color-accent);">
        <i class="bi bi-plus-lg me-1"></i> Add Product
    </a>
</div>

<!-- Sub Navigation Tabs -->
<ul class="nav nav-tabs border-bottom mb-4">
    <li class="nav-item">
        <a class="nav-link active" aria-current="page" href="#">All Products</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="#">Ingredients</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="#">Low Stock</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="#">Discontinued</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="#">History</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="#">Recalls</a>
    </li>
</ul>

<!-- Main Inventory Table Card -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white p-3 d-flex justify-content-between align-items-center">
        <h3 class="h5 mb-0 fw-semibold">Active Products</h3>
        <div class="w-25">
            <input type="text" class="form-control form-control-sm" placeholder="Search products...">
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="min-width: 800px;">
                <thead class="table-light">
                    <tr>
                        <th scope="col" class="px-3">Product Name</th>
                        <th scope="col" class="px-3">Category</th>
                        <th scope="col" class="px-3">Stock</th>
                        <th scope="col" class="px-3">Min Stock</th>
                        <th scope="col" class="px-3">Price</th>
                        <th scope="col" class="px-3">Status</th>
                        <th scope="col" class="px-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- PHP Loop would start here -->
                    <?php /*
                    foreach ($products as $product) {
                        $status_class = ($product['status'] == 'In Stock') ? 'in-stock' : 'low-stock';
                    */ ?>
                    <!-- Placeholder Row 1 -->
                    <tr>
                        <td class="px-3 fw-medium">Croissant</td>
                        <td class="px-3 text-muted">Pastries</td>
                        <td class="px-3 text-muted">85 pcs</td>
                        <td class="px-3 text-muted">30 pcs</td>
                        <td class="px-3 text-muted">$4.00</td>
                        <td class="px-3">
                            <span class="status-tag in-stock">In Stock</span>
                        </td>
                        <td class="px-3">
                            <button class="btn btn-sm btn-outline-primary me-1"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-down-up"></i></button>
                        </td>
                    </tr>
                    <!-- Placeholder Row 2 -->
                    <tr>
                        <td class="px-3 fw-medium">All-Purpose Flour</td>
                        <td class="px-3 text-muted">Ingredients</td>
                        <td class="px-3 text-muted">15 kg</td>
                        <td class="px-3 text-muted">50 kg</td>
                        <td class="px-3 text-muted">$2.50</td>
                        <td class="px-3">
                            <span class="status-tag low-stock">Low Stock</span>
                        </td>
                        <td class="px-3">
                            <button class="btn btn-sm btn-outline-primary me-1"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-down-up"></i></button>
                        </td>
                    </tr>
                    <!-- Placeholder Row 3 -->
                    <tr>
                        <td class="px-3 fw-medium">Baguette</td>
                        <td class="px-3 text-muted">Bread</td>
                        <td class="px-3 text-muted">67 pcs</td>
                        <td class="px-3 text-muted">25 pcs</td>
                        <td class="px-3 text-muted">$3.00</td>
                        <td class="px-3">
                            <span class="status-tag in-stock">In Stock</span>
                        </td>
                        <td class="px-3">
                            <button class="btn btn-sm btn-outline-primary me-1"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-down-up"></i></button>
                        </td>
                    </tr>
                    <?php /* } */ ?>
                    <!-- End of PHP Loop -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
// Include the footer
include 'footer.php';
?>