<?php
// This file (header.php) can be included at the top of each page.
// Set $active_page on each page before including this file.
// Example: <?php $active_page = 'dashboard'; include 'header.php'; ?>

$user_full_name = $_SESSION['user_full_name'] ?? 'Admin User';
$user_role = $_SESSION['user_role'] ?? 'Full Access';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BREADLY - <?php echo htmlspecialchars(ucfirst($active_page ?? 'Management')); ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    
    <link rel="stylesheet" href="css/global.css">
    
    <!-- Load Page-Specific CSS -->
    <?php if ($active_page == 'dashboard'): ?>
        <link rel="stylesheet" href="css/dashboard.css">
    <?php elseif ($active_page == 'pos'): ?>
        <link rel="stylesheet" href="css/pos.css">
    <?php elseif ($active_page == 'inventory'): ?>
        <!-- You can add an inventory.css or just use dashboard.css styles -->
        <link rel="stylesheet" href="css/dashboard.css"> 
    <?php endif; ?>

</head>
<body class="<?php echo htmlspecialchars($active_page ?? ''); ?>">

<nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom shadow-sm sticky-top">
    <div class="container-fluid" style="max-width: 1400px;">
        <!-- Brand -->
        <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
            <!-- Icon placeholder (you can use an <img> or <svg>) -->
            <i class="bi bi-cake fs-3" style="color: var(--color-accent);"></i>
            <span class="ms-2 fs-5 fw-bold" style="color: var(--color-text-primary);">BREADLY</span>
        </a>

        <!-- Toggler -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Nav Links -->
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav ms-4">
                <li class="nav-item">
                    <a class="nav-link px-3 py-2 rounded-3 <?php echo ($active_page == 'dashboard') ? 'active' : ''; ?>" href="dashboard.php">
                        <i class="bi bi-grid-fill me-1"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link px-3 py-2 rounded-3 <?php echo ($active_page == 'inventory') ? 'active' : ''; ?>" href="inventory.php">
                        <i class="bi bi-box-seam-fill me-1"></i> Inventory
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link px-3 py-2 rounded-3 <?php echo ($active_page == 'pos') ? 'active' : ''; ?>" href="pos.php">
                        <i class="bi bi-cart-fill me-1"></i> Point of Sale
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link px-3 py-2 rounded-3 <?php echo ($active_page == 'sales') ? 'active' : ''; ?>" href="sales.php">
                        <i class="bi bi-graph-up-arrow me-1"></i> Sales & Transactions
                    </a>
                </li>
            </ul>
            
            <!-- User Info & Logout -->
            <ul class="navbar-nav ms-auto d-flex align-items-center">
                <li class="nav-item me-3">
                    <div class="text-end">
                        <div class="fw-semibold" style="color: var(--color-text-primary);"><?php echo htmlspecialchars($user_full_name); ?></div>
                        <div class="small text-muted"><?php echo htmlspecialchars($user_role); ?></div>
                    </div>
                </li>
                <li class="nav-item">
                    <a href="login.php?logout=true" class="btn btn-outline-secondary d-flex align-items-center">
                        <i class="bi bi-box-arrow-right me-2"></i> Logout
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- Custom style for active nav link (to match screenshots) -->
<style>
    .navbar-nav .nav-link {
        font-weight: 500;
        color: var(--color-text-secondary);
        transition: all 0.2s ease-in-out;
        margin-right: 0.5rem;
    }
    .navbar-nav .nav-link.active {
        background-color: var(--color-accent);
        color: white;
    }
    .navbar-nav .nav-link:not(.active):hover {
        background-color: #f5f5f5;
        color: var(--color-text-primary);
    }
</style>

<!-- Start Main Content Wrapper -->
<!-- We add a container-fluid here to match the max-width of the navbar -->
<main class="container-fluid py-4" style="max-width: 1400px;"></main>