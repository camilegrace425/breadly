<?php
session_start();
require_once '../src/UserManager.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
    header('Location: ../index.php');
    exit();
}

$userManager = new UserManager();
$current_user_id = $_SESSION['user_id'];
$current_role = $_SESSION["role"];

// Handle Flash Messages
$flash_message = $_SESSION['flash_message'] ?? '';
$flash_type = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_message']);
unset($_SESSION['flash_type']);

$alert_message = '';
$alert_type = ''; 

if ($flash_message && empty($alert_message)) {
    $alert_message = $flash_message;
    $alert_type = $flash_type;
}

// User Management State
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
    // Handle Settings Update
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

    // Handle User Creation/Update
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
        $proceed_update = true;
        
        // Prevent removing the last manager
        if ($id_to_update == $current_user_id && $role !== 'manager') {
            $all_users_check = $userManager->getAllUsers();
            $manager_count = 0;
            foreach ($all_users_check as $u) {
                if ($u['role'] === 'manager') {
                    $manager_count++;
                }
            }
            
            if ($manager_count <= 1) {
                $alert_message = "Action Denied: You cannot remove the last Manager account.";
                $alert_type = 'danger';
                $proceed_update = false;
                
                $is_edit = true;
                $edit_data = [
                    'user_id' => $id_to_update, 
                    'username' => $username, 
                    'role' => 'manager', 
                    'email' => $email, 
                    'phone_number' => $phone
                ];
            }
        }

        if ($proceed_update) {
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

$users = $userManager->getAllUsers();
$userSettings = $userManager->getUserSettings($current_user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management</title>
    <link rel="icon" href="../images/kzklogo.png" type="image/x-icon"> 
    
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Poppins', 'sans-serif'] },
                    colors: {
                        breadly: {
                            bg: '#FFFBF5',
                            sidebar: '#FDEEDC',
                            dark: '#6a381f',
                            secondary: '#7a7a7a',
                            btn: '#af6223',
                            'btn-hover': '#9b4a10',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>
<body class="bg-breadly-bg text-breadly-dark font-sans h-screen flex overflow-hidden selection:bg-orange-200">

    <aside class="hidden lg:flex w-64 flex-col bg-breadly-sidebar h-full border-r border-orange-100 shrink-0 transition-all duration-300" id="sidebar">
        <div class="p-6 text-center border-b border-orange-100/50">
            <img src="../images/kzklogo.png" alt="BREADLY Logo" class="w-16 mx-auto mb-2">
            <h5 class="font-bold text-lg text-breadly-dark">BREADLY</h5>
            <p class="text-xs text-breadly-secondary">Kz & Khyle's Bakery</p>
        </div>
        <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-1">
            <a href="account_management.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors bg-breadly-dark text-white shadow-md">
                <i class='bx bxs-user-account text-xl'></i><span class="font-medium">User Management</span>
            </a>
            <a href="login_history.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors text-breadly-secondary hover:bg-orange-100 hover:text-breadly-dark">
                <i class='bx bx-history text-xl'></i><span class="font-medium">Login History</span>
            </a>
            <div class="my-4 border-t border-orange-200"></div>
            <a href="../index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-breadly-secondary hover:bg-orange-100 hover:text-breadly-dark transition-colors">
                <i class='bx bx-arrow-back text-xl'></i><span class="font-medium">Main Menu</span>
            </a>
        </nav>
        <div class="p-4 border-t border-orange-200">
            <div class="flex items-center gap-3 px-2 mb-3">
                <div class="w-10 h-10 rounded-full bg-orange-200 flex items-center justify-center text-breadly-dark font-bold"><i class='bx bxs-user'></i></div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-bold text-breadly-dark truncate"><?php echo htmlspecialchars($_SESSION['username']); ?></p>
                    <p class="text-xs text-breadly-secondary uppercase"><?php echo str_replace('_', ' ', $current_role); ?></p>
                </div>
            </div>
            <a href="logout.php" class="flex items-center justify-center gap-1 py-2 text-xs font-medium text-red-500 bg-white border border-red-100 rounded-lg hover:bg-red-50 transition-colors">
                <i class='bx bx-log-out'></i> Logout
            </a>
        </div>
    </aside>

    <div id="mobileSidebarOverlay" class="fixed inset-0 bg-black/50 z-40 hidden lg:hidden" onclick="toggleSidebar()"></div>
    <div id="mobileSidebar" class="fixed inset-y-0 left-0 w-64 bg-breadly-sidebar z-50 transform -translate-x-full transition-transform duration-300 lg:hidden flex flex-col h-full shadow-2xl">
        <div class="p-6 text-center border-b border-orange-100/50">
            <div class="flex justify-end mb-2">
                <button onclick="toggleSidebar()" class="text-breadly-secondary hover:text-breadly-dark"><i class='bx bx-x text-2xl'></i></button>
            </div>
            <img src="../images/kzklogo.png" alt="BREADLY Logo" class="w-16 mx-auto mb-2">
            <h5 class="font-bold text-lg text-breadly-dark">BREADLY</h5>
        </div>
        <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-1">
            <a href="account_management.php" class="flex items-center gap-3 px-4 py-3 rounded-xl bg-breadly-dark text-white">
                <i class='bx bxs-user-account text-xl'></i> User Management
            </a>
            <a href="login_history.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-breadly-secondary">
                <i class='bx bx-history text-xl'></i> Login History
            </a>
            <a href="../index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-breadly-secondary mt-4 border-t border-orange-200 pt-4">
                <i class='bx bx-arrow-back text-xl'></i> Main Menu
            </a>
        </nav>
        <div class="p-4 border-t border-orange-200">
            <button onclick="openModal('settingsModal')" class="w-full py-2 mb-2 text-sm bg-white border border-orange-200 rounded-lg text-breadly-secondary">Settings</button>
            <a href="logout.php" class="block w-full py-2 text-center text-sm bg-red-50 text-red-600 rounded-lg">Logout</a>
        </div>
    </div>

    <main class="flex-1 flex flex-col h-full overflow-hidden relative w-full">
        <div class="p-6 pb-2 flex flex-col md:flex-row md:items-center justify-between gap-4 bg-breadly-bg z-10">
            <div class="flex items-center gap-3">
                <button onclick="toggleSidebar()" class="lg:hidden text-breadly-dark text-2xl"><i class='bx bx-menu'></i></button>
                <h1 class="text-2xl font-bold text-breadly-dark">User Management</h1>
            </div>
        </div>

        <?php if ($alert_message): ?>
        <div class="px-6">
            <div class="<?php echo ($alert_type === 'success') ? 'bg-green-100 text-green-800 border-green-200' : 'bg-red-100 text-red-800 border-red-200'; ?> border px-4 py-3 rounded-lg flex justify-between items-center mb-4 shadow-sm">
                <span><?php echo htmlspecialchars($alert_message); ?></span>
                <button onclick="this.parentElement.remove()" class="text-lg font-bold">&times;</button>
            </div>
        </div>
        <?php endif; ?>

        <div class="flex-1 overflow-y-auto p-6 pb-20">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-xl shadow-sm border border-orange-100 overflow-hidden h-full flex flex-col">
                        <div class="p-4 border-b border-orange-100 flex justify-between items-center bg-gray-50">
                            <h5 class="font-bold text-gray-800">Existing Accounts</h5>
                            <span class="bg-gray-200 text-gray-700 px-2 py-1 rounded-lg text-xs font-bold"><?php echo count($users); ?> Users</span>
                        </div>
                        <div class="flex-1 overflow-y-auto p-0">
                            <table class="w-full text-left border-collapse">
                                <thead class="bg-gray-50 text-xs uppercase text-gray-500 font-semibold">
                                    <tr>
                                        <th class="px-6 py-3">Username</th>
                                        <th class="px-6 py-3">Role</th>
                                        <th class="px-6 py-3">Contact Info</th>
                                        <th class="px-6 py-3 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($users as $user): ?>
                                    <tr class="hover:bg-gray-50 transition-colors">
                                        <td class="px-6 py-3">
                                            <div class="font-semibold text-gray-800">
                                                <?php echo htmlspecialchars($user['username']); ?>
                                                <?php if($user['user_id'] == $_SESSION['user_id']): ?>
                                                    <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-yellow-100 text-yellow-800">YOU</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-3">
                                            <?php 
                                                $badgeClass = 'bg-gray-100 text-gray-800';
                                                if ($user['role'] == 'manager') $badgeClass = 'bg-blue-100 text-blue-800';
                                                if ($user['role'] == 'assistant_manager') $badgeClass = 'bg-purple-100 text-purple-800';
                                                if ($user['role'] == 'cashier') $badgeClass = 'bg-green-100 text-green-800';
                                            ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $badgeClass; ?>">
                                                <?php echo ucwords(str_replace('_', ' ', $user['role'])); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-3 text-sm text-gray-500 space-y-1">
                                            <?php if(!empty($user['phone_number'])): ?>
                                                <div class="flex items-center gap-1"><i class='bx bx-phone'></i> <?php echo htmlspecialchars($user['phone_number']); ?></div>
                                            <?php endif; ?>
                                            <?php if(!empty($user['email'])): ?>
                                                <div class="flex items-center gap-1"><i class='bx bx-envelope'></i> <?php echo htmlspecialchars($user['email']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-3 text-right">
                                            <?php if($user['user_id'] != $_SESSION['user_id']): ?>
                                                <div class="flex justify-end gap-2">
                                                    <a href="account_management.php?action=edit&id=<?php echo $user['user_id']; ?>" class="p-1.5 text-blue-600 bg-blue-50 rounded hover:bg-blue-100 transition-colors" title="Edit">
                                                        <i class='bx bx-edit text-lg'></i>
                                                    </a>
                                                    <a href="account_management.php?action=delete&id=<?php echo $user['user_id']; ?>" class="p-1.5 text-red-600 bg-red-50 rounded hover:bg-red-100 transition-colors" onclick="return confirm('Are you sure you want to delete this user?');" title="Delete">
                                                        <i class='bx bx-trash text-lg'></i>
                                                    </a>
                                                </div>
                                            <?php else: ?>
                                                <div class="flex justify-end gap-2">
                                                    <a href="account_management.php?action=edit&id=<?php echo $user['user_id']; ?>" class="p-1.5 text-blue-600 bg-blue-50 rounded hover:bg-blue-100 transition-colors" title="Edit">
                                                        <i class='bx bx-edit text-lg'></i>
                                                    </a>
                                                    <button class="p-1.5 text-gray-400 bg-gray-100 rounded cursor-not-allowed" title="Cannot delete self">
                                                        <i class='bx bx-trash text-lg'></i>
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-1">
                    <div class="bg-white rounded-xl shadow-sm border border-orange-100 overflow-hidden sticky top-4">
                        <div class="p-4 border-b border-orange-100 <?php echo $is_edit ? 'bg-orange-50' : 'bg-blue-50'; ?> flex items-center gap-2">
                            <i class='bx <?php echo $is_edit ? 'bx-edit text-orange-600' : 'bx-user-plus text-blue-600'; ?> text-xl'></i>
                            <h5 class="font-bold <?php echo $is_edit ? 'text-orange-800' : 'text-blue-800'; ?>">
                                <?php echo $is_edit ? 'Edit Account' : 'Create Account'; ?>
                            </h5>
                        </div>
                        <div class="p-6">
                            <form action="account_management.php" method="POST" class="space-y-4">
                                <input type="hidden" name="action" value="<?php echo $is_edit ? 'update_user' : 'create_user'; ?>">
                                <?php if($is_edit): ?>
                                    <input type="hidden" name="user_id" value="<?php echo $edit_user_id; ?>">
                                <?php endif; ?>
                                
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 mb-1 uppercase tracking-wider">Role</label>
                                    <select name="role" required class="w-full p-2.5 bg-white border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-breadly-btn outline-none">
                                        <option value="manager" <?php if($is_edit && $edit_data['role'] == 'manager') echo 'selected'; ?>>Manager</option>
                                        <option value="assistant_manager" <?php if($is_edit && $edit_data['role'] == 'assistant_manager') echo 'selected'; ?>>Assistant Manager</option>
                                        <option value="cashier" <?php if($is_edit && $edit_data['role'] == 'cashier') echo 'selected'; ?>>Cashier</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-gray-500 mb-1 uppercase tracking-wider">Username</label>
                                    <input type="text" name="username" 
                                           value="<?php echo $is_edit ? htmlspecialchars($edit_data['username']) : ''; ?>" 
                                           placeholder="e.g. juan123" required
                                           class="w-full p-2.5 bg-white border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-breadly-btn outline-none">
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-gray-500 mb-1 uppercase tracking-wider">
                                        Password <?php if($is_edit) echo '<span class="text-red-500 font-normal normal-case text-[10px] ml-1">(Leave blank to keep)</span>'; ?>
                                    </label>
                                    <input type="password" name="password" 
                                           placeholder="<?php echo $is_edit ? 'New password (optional)' : 'Strong password'; ?>" 
                                           <?php echo $is_edit ? '' : 'required'; ?>
                                           class="w-full p-2.5 bg-white border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-breadly-btn outline-none">
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-gray-500 mb-1 uppercase tracking-wider">Phone Number <span class="text-red-500">*</span></label>
                                    <input type="text" name="phone" 
                                           value="<?php echo $is_edit ? htmlspecialchars($edit_data['phone_number']) : ''; ?>"
                                           placeholder="0917..." maxlength="11" required
                                           class="w-full p-2.5 bg-white border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-breadly-btn outline-none">
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-gray-500 mb-1 uppercase tracking-wider">Email Address (Optional)</label>
                                    <input type="email" name="email" 
                                           value="<?php echo $is_edit ? htmlspecialchars($edit_data['email']) : ''; ?>"
                                           placeholder="For recovery"
                                           class="w-full p-2.5 bg-white border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-breadly-btn outline-none">
                                </div>

                                <button type="submit" class="w-full py-2.5 text-white font-medium rounded-lg shadow transition-colors mt-2 <?php echo $is_edit ? 'bg-orange-500 hover:bg-orange-600' : 'bg-blue-600 hover:bg-blue-700'; ?>">
                                    <?php echo $is_edit ? 'Update User' : 'Create User'; ?>
                                </button>
                                
                                <?php if($is_edit): ?>
                                    <a href="account_management.php" class="block w-full text-center py-2 text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 text-sm transition-colors">Cancel</a>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <div id="modalBackdrop" class="fixed inset-0 bg-black/50 z-40 hidden transition-opacity" onclick="closeAllModals()"></div>

    <div id="settingsModal" class="fixed inset-0 z-50 hidden flex items-center justify-center">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm m-4 overflow-hidden transform transition-all scale-100 relative z-50">
            <div class="p-4 border-b border-gray-100 bg-gray-50 flex justify-between items-center">
                <h5 class="font-bold text-gray-800 flex items-center gap-2"><i class='bx bx-cog'></i> My Settings</h5>
                <button onclick="closeModal('settingsModal')" class="text-gray-400 hover:text-gray-600"><i class='bx bx-x text-2xl'></i></button>
            </div>
            <form id="settings-form" action="account_management.php" method="POST" class="p-6">
                <input type="hidden" name="action" value="update_settings">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">My Phone Number</label>
                    <input type="text" name="my_phone_number" id="my_phone_number" 
                           value="<?php echo htmlspecialchars($userSettings['phone_number'] ?? ''); ?>" 
                           placeholder="e.g., 09171234567" maxlength="12"
                           class="w-full p-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-breadly-btn outline-none">
                    <p class="text-[10px] text-gray-500 mt-1">For receiving notifications and reports.</p>
                </div>
                
                <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg border border-gray-200">
                    <div class="relative inline-block w-10 align-middle select-none transition duration-200 ease-in">
                        <input type="checkbox" name="enable_daily_report" id="enable_daily_report" class="absolute block w-5 h-5 rounded-full bg-white border-4 appearance-none cursor-pointer checked:right-0 checked:border-green-500 right-full transform translate-x-full" value="1" <?php if (!empty($userSettings['enable_daily_report'])) echo 'checked'; ?>>
                        <label for="enable_daily_report" class="block overflow-hidden h-5 rounded-full bg-gray-300 cursor-pointer"></label>
                    </div>
                    <label for="enable_daily_report" class="text-sm font-medium text-gray-700 cursor-pointer select-none">Receive Daily Reports</label>
                </div>
                <p class="text-[10px] text-gray-400 mt-2">Requires server cron job.</p>

                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" onclick="closeModal('settingsModal')" class="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg text-sm">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-900 text-sm shadow">Save Settings</button>
                </div>
            </form>
        </div>
    </div>

    <script>
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
            const backdrop = document.getElementById('modalBackdrop');
            if (modal) {
                modal.classList.remove('hidden');
                if(backdrop) backdrop.classList.remove('hidden');
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            const backdrop = document.getElementById('modalBackdrop');
            if (modal) modal.classList.add('hidden');
            if(backdrop) backdrop.classList.add('hidden');
        }
        
        function closeAllModals() {
            document.querySelectorAll('.fixed.z-50').forEach(el => el.classList.add('hidden'));
            const backdrop = document.getElementById('modalBackdrop');
            if(backdrop) backdrop.classList.add('hidden');
        }
    </script>
</body>
</html>