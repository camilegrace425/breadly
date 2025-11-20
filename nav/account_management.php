<?php
session_start();
require_once '../src/UserManager.php';

// Check authentication
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
    header('Location: index.php');
    exit();
}

$userManager = new UserManager();
$current_user_id = $_SESSION['user_id'];

// --- Flash Message Logic (From Structure) ---
$flash_message = $_SESSION['flash_message'] ?? '';
$flash_type = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_message']);
unset($_SESSION['flash_type']);

// --- Local Alert Logic (From Features) ---
$alert_message = '';
$alert_type = ''; 

// If we have a flash message, map it to the local alert variables if they are empty
if ($flash_message && empty($alert_message)) {
    $alert_message = $flash_message;
    $alert_type = $flash_type;
}

// --- User Management Logic (Retained) ---
$is_edit = false;
$edit_user_id = 0;
$edit_data = [];

if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $is_edit = true;
    $edit_user_id = intval($_GET['id']);
    $edit_data = $userManager->getUserById($edit_user_id);
    if (!$edit_data) {
        $alert_message = "User not found.";
        $alert_type = 'danger';
        $is_edit = false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle Settings Update (From Structure)
    if (isset($_POST['action']) && $_POST['action'] === 'update_settings') {
        $phone = $_POST['my_phone_number'] ?? '';
        $daily = isset($_POST['enable_daily_report']) ? 1 : 0;
        
        if ($userManager->updateMySettings($current_user_id, $phone, $daily)) {
            $_SESSION['flash_message'] = 'Settings updated successfully.';
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_message'] = 'Failed to update settings.';
            $_SESSION['flash_type'] = 'danger';
        }
        header("Location: account_management.php");
        exit();
    }

    // Handle User Management Actions
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? '';
    $email = trim($_POST['email'] ?? ''); 
    $phone = trim($_POST['phone'] ?? ''); 
    
    if ($email === '') $email = null;

    if (isset($_POST['action']) && $_POST['action'] === 'create_user') {
        if (empty($username) || empty($password) || empty($phone)) {
            $alert_message = "Username, Password, and Phone Number are required.";
            $alert_type = 'danger';
        } else {
            $result = $userManager->createUser($username, $password, $role, $email, $phone);
            if ($result === true) {
                $alert_message = "User account created successfully!";
                $alert_type = 'success';
            } else {
                $alert_message = $result;
                $alert_type = 'danger';
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'update_user') {
        $id_to_update = intval($_POST['user_id']);
        if (empty($username) || empty($phone)) {
            $alert_message = "Username and Phone Number are required.";
            $alert_type = 'danger';
        } else {
            $result = $userManager->updateUser($id_to_update, $username, $password, $role, $email, $phone);
            if ($result === true) {
                $_SESSION['flash_message'] = "User account updated successfully!";
                $_SESSION['flash_type'] = "success";
                header('Location: account_management.php');
                exit();
            } else {
                $alert_message = $result;
                $alert_type = 'danger';
                $is_edit = true;
                $edit_data = ['user_id' => $id_to_update, 'username' => $username, 'role' => $role, 'email' => $email, 'phone_number' => $phone];
            }
        }
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $delete_id = intval($_GET['id']);
    if ($delete_id == $_SESSION['user_id']) {
        $alert_message = "You cannot delete your own account.";
        $alert_type = 'danger';
    } else {
        if ($userManager->deleteUser($delete_id)) {
            $_SESSION['flash_message'] = "User deleted successfully.";
            $_SESSION['flash_type'] = "success";
            header('Location: account_management.php');
            exit();
        } else {
            $alert_message = "Failed to delete user.";
            $alert_type = 'danger';
        }
    }
}

if (isset($_GET['msg']) && $_GET['msg'] === 'updated' && empty($alert_message)) {
    $alert_message = "User account updated successfully!";
    $alert_type = 'success';
}

// Fetch Data
$users = $userManager->getAllUsers();
$userSettings = $userManager->getUserSettings($current_user_id);

// Set Active Nav Link
$active_nav_link = 'account_management';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management</title>
    <link rel="icon" href="../images/kzklogo.png" type="image/x-icon"> 
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    
    <link rel="stylesheet" href="../styles/global.css"> 
    <link rel="stylesheet" href="../styles/dashboard.css"> 
    <link rel="stylesheet" href="../styles/account_management.css"> 
    <link rel="stylesheet" href="../styles/responsive.css">
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
                        <a class="nav-link <?php echo ($active_nav_link == 'account_management') ? 'active' : ''; ?>" href="account_management.php">
                            <i class="bi bi-people me-2"></i> User Management
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="login_history.php">
                             <i class="bi bi-clock-history me-2"></i> Login History
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
                            <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark text-small shadow" aria-labelledby="userMenu">
                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#settingsModal">
                                <i class="bi bi-gear me-2"></i>My Settings
                            </a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php">Sign out</a></li>
                        </ul>
                    </div>
                </div>
            </div> 
        </aside>

        <main class="col-lg-10 col-md-9 main-content">
            <div class="header d-flex justify-content-between align-items-center mb-4">
                <button class="btn btn-outline-secondary d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarMenu" aria-controls="sidebarMenu">
                    <i class="bi bi-list"></i>
                </button>
                <h1>User Management</h1>
            </div>

            <?php if ($alert_message): ?>
            <div class="alert alert-<?php echo $alert_type; ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($alert_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-lg-8 mb-4">
                    <div class="card h-100 shadow-sm border-0">
                        <div class="card-header d-flex justify-content-between align-items-center bg-white border-bottom">
                            <h5 class="mb-0 text-dark">Existing Accounts</h5>
                            <span class="badge bg-secondary"><?php echo count($users); ?> Users</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0 align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="ps-4">Username</th>
                                            <th>Role</th>
                                            <th>Contact Info</th>
                                            <th class="text-end pe-4">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td class="ps-4 fw-bold text-dark">
                                                <?php echo htmlspecialchars($user['username']); ?>
                                                <?php if($user['user_id'] == $_SESSION['user_id']) echo '<span class="badge bg-warning text-dark ms-2" style="font-size:0.7em">YOU</span>'; ?>
                                            </td>
                                            <td>
                                                <?php 
                                                    $roleBadge = 'badge-cashier';
                                                    if ($user['role'] == 'manager') $roleBadge = 'badge-manager';
                                                    if ($user['role'] == 'assistant_manager') $roleBadge = 'badge-assistant_manager';
                                                ?>
                                                <span class="badge <?php echo $roleBadge; ?>">
                                                    <?php echo ucwords(str_replace('_', ' ', $user['role'])); ?>
                                                </span>
                                            </td>
                                            <td class="small text-muted">
                                                <?php if(!empty($user['phone_number'])): ?>
                                                    <div><i class="bi bi-phone me-1"></i> <?php echo htmlspecialchars($user['phone_number']); ?></div>
                                                <?php endif; ?>
                                                <?php if(!empty($user['email'])): ?>
                                                    <div><i class="bi bi-envelope me-1"></i> <?php echo htmlspecialchars($user['email']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end pe-4">
                                                <?php if($user['user_id'] != $_SESSION['user_id']): ?>
                                                    <a href="account_management.php?action=edit&id=<?php echo $user['user_id']; ?>" class="btn btn-sm btn-outline-primary me-1"><i class="bi bi-pencil-square"></i></a>
                                                    <a href="account_management.php?action=delete&id=<?php echo $user['user_id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure?');"><i class="bi bi-trash"></i></a>
                                                <?php else: ?>
                                                    <a href="account_management.php?action=edit&id=<?php echo $user['user_id']; ?>" class="btn btn-sm btn-outline-primary me-1"><i class="bi bi-pencil-square"></i></a>
                                                    <button class="btn btn-sm btn-outline-secondary" disabled><i class="bi bi-trash"></i></button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4 mb-4">
                    <div class="card shadow-sm border-0">
                        <div class="card-header py-3 <?php echo $is_edit ? 'bg-warning bg-opacity-10' : 'bg-primary bg-opacity-10'; ?>">
                            <h5 class="mb-0 <?php echo $is_edit ? 'text-warning text-opacity-75 text-dark' : 'text-primary text-dark'; ?>">
                                <i class="bi <?php echo $is_edit ? 'bi-pencil-fill' : 'bi-person-plus-fill'; ?> me-2"></i> 
                                <?php echo $is_edit ? 'Edit Account' : 'Create Account'; ?>
                            </h5>
                        </div>
                        <div class="card-body">
                            <form action="account_management.php" method="POST">
                                <input type="hidden" name="action" value="<?php echo $is_edit ? 'update_user' : 'create_user'; ?>">
                                <?php if($is_edit): ?>
                                    <input type="hidden" name="user_id" value="<?php echo $edit_user_id; ?>">
                                <?php endif; ?>
                                
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">Role</label>
                                        <select class="form-select" name="role" required>

                                        <option value="manager" <?php if($is_edit && $edit_data['role'] == 'manager') echo 'selected'; ?>>Manager</option>
                                        <option value="assistant_manager" <?php if($is_edit && $edit_data['role'] == 'assistant_manager') echo 'selected'; ?>>Assistant Manager</option>
                                        <option value="cashier" <?php if($is_edit && $edit_data['role'] == 'cashier') echo 'selected'; ?>>Cashier</option>

                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">Username</label>
                                    <input type="text" class="form-control" name="username" 
                                           value="<?php echo $is_edit ? htmlspecialchars($edit_data['username']) : ''; ?>" 
                                           placeholder="e.g. juan123" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">
                                        Password <?php if($is_edit) echo '<span class="fw-normal text-danger">(Leave blank to keep current)</span>'; ?>
                                    </label>
                                    <input type="password" class="form-control" name="password" 
                                           placeholder="<?php echo $is_edit ? 'New password (optional)' : 'Strong password'; ?>" 
                                           <?php echo $is_edit ? '' : 'required'; ?>>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">Phone Number (Required)</label>
                                    <input type="text" class="form-control" name="phone" 
                                           value="<?php echo $is_edit ? htmlspecialchars($edit_data['phone_number']) : ''; ?>"
                                           placeholder="0917..." maxlength="11" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">Email Address (Optional)</label>
                                    <input type="email" class="form-control" name="email" 
                                           value="<?php echo $is_edit ? htmlspecialchars($edit_data['email']) : ''; ?>"
                                           placeholder="For recovery">
                                </div>

                                <button type="submit" class="btn <?php echo $is_edit ? 'btn-warning' : 'btn-primary'; ?> w-100 mt-2">
                                    <?php echo $is_edit ? 'Update User' : 'Create User'; ?>
                                </button>
                                
                                <?php if($is_edit): ?>
                                    <a href="account_management.php" class="btn btn-outline-secondary w-100 mt-2">Cancel</a>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

        </main>
    </div>
</div>

<div class="modal fade" id="settingsModal" tabindex="-1" aria-labelledby="settingsModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="settingsModalLabel">My Settings</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="settings-form" action="account_management.php" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_settings">
                    <div class="mb-3">
                        <label for="my_phone_number" class="form-label">My Phone Number</label>
                        <input type="text" class="form-control" name="my_phone_number" id="my_phone_number" 
                               value="<?php echo htmlspecialchars($userSettings['phone_number'] ?? ''); ?>" 
                               placeholder="e.g., 09171234567"
                               maxlength="12">
                        <div class="form-text">Your number for receiving all notifications, including password resets and daily reports.</div>
                    </div>
                    <hr>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="enable_daily_report" 
                               name="enable_daily_report" value="1" 
                               <?php if (!empty($userSettings['enable_daily_report'])) echo 'checked'; ?>>
                        <label class="form-check-label" for="enable_daily_report">Receive Automatic Daily Reports</label>
                    </div>
                    <p class="text-muted small">
                        If checked, you will automatically receive the "Sales & Recall Report" via SMS at the end of each day. 
                        (Note: This requires a server Cron Job to be set up by the administrator).
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Settings</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>