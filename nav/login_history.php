<?php
session_start();
require_once "../src/UserManager.php"; 

// --- Security Check: MANAGERS ONLY ---
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}
if ($_SESSION["role"] !== "manager") {
    header("Location: index.php"); // Not authorized
    exit();
}

// --- Managers ---
$userManager = new UserManager();

// --- Data Fetching ---
$login_history = $userManager->getLoginHistory();

// --- Active Tab for Sidebar ---
$active_nav_link = 'login_history'; 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login History</title>
    <link rel="icon" href="../images/kzklogo.png" type="image/x-icon"> 
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../styles/global.css"> 
    <link rel="stylesheet" href="../styles/dashboard.css"> 
    <link rel="stylesheet" href="../styles/responsive.css?v=3"> 
</head>
<body class="dashboard">
<div class="container-fluid">
    <div class="row">
        <aside class="col-lg-2 col-md-3 sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="sidebarMenu" aria-labelledby="sidebarMenuLabel">
            
            <div class="offcanvas-header d-lg-none">
                <h5 class="offcanvas-title" id="sidebarMenuLabel">BREADLY</h5>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#sidebarMenu" aria-label="Close"></button>
            </div>

            <div class="offcanvas-body d-flex flex-column p-0">
                 <div class="sidebar-brand">
                    <img src="../images/kzklogo.png" alt="BREADLY Logo">
                    <h5>BREADLY</h5>
                    <p>Kz & Khyle's Bakery</p>
                </div>
                <ul class="nav flex-column sidebar-nav">
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($active_nav_link == 'dashboard') ? 'active' : ''; ?>" href="dashboard_panel.php">
                            <i class="bi bi-speedometer2 me-2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($active_nav_link == 'inventory') ? 'active' : ''; ?>" href="inventory_management.php">
                            <i class="bi bi-box me-2"></i> Inventory
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($active_nav_link == 'recipes') ? 'active' : ''; ?>" href="recipes.php">
                            <i class="bi bi-journal-bookmark me-2"></i> Recipes
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($active_nav_link == 'sales') ? 'active' : ''; ?>" href="sales_history.php">
                            <i class="bi bi-clock-history me-2"></i> Sales & Transactions
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($active_nav_link == 'login_history') ? 'active' : ''; ?>" href="login_history.php">
                            <i class="bi bi-person-check me-2"></i> Login History
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">
                            <i class="bi bi-arrow-left me-2"></i> Main Menu
                        </a>
                    </li>
                </ul>
                <div class="sidebar-user">
                    <hr>
                    <div class="dropdown">
                        <a href="#" class="d-flex align-items-center text-dark text-decoration-none dropdown-toggle" id="userMenu" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-person-circle me-2 fs-4"></i>
                            <strong><?php echo htmlspecialchars($_SESSION["username"]); ?></strong>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark text-small shadow" aria-labelledby="userMenu">
                            <li><a class="dropdown-item" href="logout.php">Sign out</a></li>
                        </ul>
                    </div>
                </div>
            </div> 
        </aside>

        <main class="col-lg-10 col-md-9 main-content">
            <div class="header d-flex justify-content-between align-items-center">
                <button class="btn btn-outline-secondary d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarMenu" aria-controls="sidebarMenu">
                    <i class="bi bi-list"></i>
                </button>
            
                <h1>Login History</h1>
            </div>
            
            <div class="card shadow-sm mt-3 border-secondary" id="login-history-card">
                <div class="card-header bg-light d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <span class="fs-5">Login History</span>
                    
                    <div class="d-flex flex-wrap justify-content-end align-items-center gap-2">
                        <div class="d-flex align-items-center gap-1">
                            <label for="login-rows-select" class="form-label mb-0 small text-muted flex-shrink-0">Show</label>
                            <select class="form-select form-select-sm" id="login-rows-select" style="width: auto;">
                                <option value="10" selected>10</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="all">All</option>
                            </select>
                            <div class="btn-group btn-group-sm ms-1" role="group">
                                <button type="button" class="btn btn-outline-secondary" id="login-prev-btn" disabled>
                                    <i class="bi bi-arrow-left"></i>
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="login-next-btn">
                                    <i class="bi bi-arrow-right"></i>
                                </button>
                            </div>
                        </div>
                        <div class="dropdown">
                            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                Sort By: <span class="current-sort-text">Timestamp (Newest First)</span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item sort-trigger active" data-sort-by="timestamp" data-sort-dir="desc" data-sort-type="date" href="#">Timestamp (Newest First)</a></li>
                                <li><a class="dropdown-item sort-trigger" data-sort-by="timestamp" data-sort-dir="asc" data-sort-type="date" href="#">Timestamp (Oldest First)</a></li>
                                <li><a class="dropdown-item sort-trigger" data-sort-by="status" data-sort-dir="asc" data-sort-type="text" href="#">Status (Failure First)</a></li>
                                <li><a class="dropdown-item sort-trigger" data-sort-by="status" data-sort-dir="desc" data-sort-type="text" href="#">Status (Success First)</a></li>
                                <li><a class="dropdown-item sort-trigger" data-sort-by="user" data-sort-dir="asc" data-sort-type="text" href="#">Username (A-Z)</a></li>
                                <li><a class="dropdown-item sort-trigger" data-sort-by="user" data-sort-dir="desc" data-sort-type="text" href="#">Username (Z-A)</a></li>
                                <li><a class="dropdown-item sort-trigger" data-sort-by="role" data-sort-dir="asc" data-sort-type="text" href="#">Role (A-Z)</a></li>
                                <li><a class="dropdown-item sort-trigger" data-sort-by="role" data-sort-dir="desc" data-sort-type="text" href="#">Role (Z-A)</a></li>
                                <li><a class="dropdown-item sort-trigger" data-sort-by="device" data-sort-dir="asc" data-sort-type="text" href="#">Device (A-Z)</a></li>
                                <li><a class="dropdown-item sort-trigger" data-sort-by="device" data-sort-dir="desc" data-sort-type="text" href="#">Device (Z-A)</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover align-middle">
                            <thead>
                                <tr>
                                    <th data-sort-by="timestamp" data-sort-type="date">Timestamp</th>
                                    <th data-sort-by="user">Username (Attempt)</th>
                                    <th data-sort-by="role">Role</th>
                                    <th data-sort-by="device">Device</th>
                                    <th data-sort-by="status">Status</th>
                                </tr>
                            </thead>
                            <tbody id="login-table-body">
                                <?php if (empty($login_history)): ?>
                                    <tr><td colspan="5" class="text-center text-muted">No login history found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($login_history as $log): ?>
                                    <tr>
                                        <td data-label="Timestamp"><?php echo htmlspecialchars(date("M d, Y h:i A", strtotime($log["timestamp"]))); ?></td>
                                        <td data-label="Username (Attempt)"><?php echo htmlspecialchars($log["username_attempt"]); ?></td>
                                        <td data-label="Role">
                                            <?php if ($log["role"] == 'manager'): ?>
                                                <span class="badge bg-primary">Manager</span>
                                            <?php elseif ($log["role"] == 'cashier'): ?>
                                                <span class="badge bg-success">Cashier</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Device">
                                            <?php
                                            $icon = 'bi-question-circle';
                                            if ($log["device_type"] == 'Desktop') $icon = 'bi-display';
                                            if ($log["device_type"] == 'Mobile') $icon = 'bi-phone';
                                            if ($log["device_type"] == 'Tablet') $icon = 'bi-tablet';
                                            ?>
                                            <i class="bi <?php echo $icon; ?> me-1"></i>
                                            <?php echo htmlspecialchars($log["device_type"] ?? 'Unknown'); ?>
                                        </td>
                                        <td data-label="Status">
                                            <?php if ($log["status"] == 'success'): ?>
                                                <span class="badge bg-success">Success</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Failure</span>
                                            <?php endif; ?>
                                        </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div> 
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<?php
$js_file = "../js/script_login_history.js";
$js_version = file_exists($js_file) ? filemtime($js_file) : "1";
?>
<script src="../js/script_login_history.js?v=<?php echo $js_version; ?>"></script>

</body>
</html>