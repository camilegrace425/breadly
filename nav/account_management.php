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

// --- AJAX HANDLERS ---
if (isset($_POST['ajax_action']) || isset($_GET['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_REQUEST['ajax_action'];

    try {
        // 1. Fetch Users (Returns HTML for Table Body)
        if ($action === 'fetch_users') {
            $users = $userManager->getAllUsers();
            ob_start();
            foreach ($users as $user): 
                $badgeClass = 'bg-gray-100 text-gray-800';
                if ($user['role'] == 'manager') $badgeClass = 'bg-blue-100 text-blue-800';
                if ($user['role'] == 'assistant_manager') $badgeClass = 'bg-purple-100 text-purple-800';
                if ($user['role'] == 'cashier') $badgeClass = 'bg-green-100 text-green-800';
            ?>
            <tr class="hover:bg-gray-50 transition-colors animate-slide-in">
                <td class="px-6 py-3">
                    <div class="font-semibold text-gray-800">
                        <?php echo htmlspecialchars($user['username']); ?>
                        <?php if($user['user_id'] == $_SESSION['user_id']): ?>
                            <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-yellow-100 text-yellow-800">YOU</span>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="px-6 py-3">
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
                            <button onclick="editUser(<?php echo $user['user_id']; ?>)" class="p-1.5 text-blue-600 bg-blue-50 rounded hover:bg-blue-100 transition-colors" title="Edit">
                                <i class='bx bx-edit text-lg'></i>
                            </button>
                            <button onclick="deleteUser(<?php echo $user['user_id']; ?>)" class="p-1.5 text-red-600 bg-red-50 rounded hover:bg-red-100 transition-colors" title="Delete">
                                <i class='bx bx-trash text-lg'></i>
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="flex justify-end gap-2">
                            <button onclick="editUser(<?php echo $user['user_id']; ?>)" class="p-1.5 text-blue-600 bg-blue-50 rounded hover:bg-blue-100 transition-colors" title="Edit">
                                <i class='bx bx-edit text-lg'></i>
                            </button>
                            <button class="p-1.5 text-gray-400 bg-gray-100 rounded cursor-not-allowed" title="Cannot delete self">
                                <i class='bx bx-trash text-lg'></i>
                            </button>
                        </div>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; 
            $html = ob_get_clean();
            echo json_encode(['success' => true, 'html' => $html, 'count' => count($users)]);
            exit();
        }

        // 2. Get Single User (Returns JSON)
        if ($action === 'get_user') {
            $id = intval($_REQUEST['id']);
            $data = $userManager->getUserById($id);
            if ($data) {
                // Don't send password back
                unset($data['password_hash']);
                echo json_encode(['success' => true, 'data' => $data]);
            } else {
                echo json_encode(['success' => false, 'message' => 'User not found']);
            }
            exit();
        }

        // 3. Save User (Create/Update)
        if ($action === 'save_user') {
            $id = intval($_POST['user_id'] ?? 0);
            $username = trim($_POST['username']);
            $password = $_POST['password'];
            $role = $_POST['role'];
            $phone = trim($_POST['phone']);
            $email = trim($_POST['email']);
            if ($email === '') $email = null;

            if (empty($username) || empty($phone)) {
                echo json_encode(['success' => false, 'message' => 'Username and Phone are required.']);
                exit();
            }

            if ($id > 0) {
                // Update
                // Check last manager logic
                if ($id == $current_user_id && $role !== 'manager') {
                    $all_users = $userManager->getAllUsers();
                    $manager_count = 0;
                    foreach ($all_users as $u) if ($u['role'] === 'manager') $manager_count++;
                    
                    if ($manager_count <= 1) {
                        echo json_encode(['success' => false, 'message' => 'Cannot remove the last Manager account.']);
                        exit();
                    }
                }
                $res = $userManager->updateUser($id, $username, $password, $role, $email, $phone);
            } else {
                // Create
                if (empty($password)) {
                    echo json_encode(['success' => false, 'message' => 'Password is required for new users.']);
                    exit();
                }
                $res = $userManager->createUser($username, $password, $role, $email, $phone);
            }

            if ($res === true) {
                echo json_encode(['success' => true, 'message' => $id > 0 ? 'User updated!' : 'User created!']);
            } else {
                echo json_encode(['success' => false, 'message' => $res]);
            }
            exit();
        }

        // 4. Delete User
        if ($action === 'delete_user') {
            $id = intval($_POST['id']);
            if ($id == $current_user_id) {
                echo json_encode(['success' => false, 'message' => 'Cannot delete yourself.']);
                exit();
            }
            if ($userManager->deleteUser($id)) {
                echo json_encode(['success' => true, 'message' => 'User deleted.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Delete failed.']);
            }
            exit();
        }

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }
}

// --- Standard Page Load ---
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
    
    <link rel="stylesheet" href="../styles/global.css">
    <link rel="stylesheet" href="../styles/account_management.css">

    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <script src="https://cdn.tailwindcss.com"></script>
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

        <div class="flex-1 overflow-y-auto p-6 pb-20">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                
                <div class="lg:col-span-2 animate-slide-in delay-100">
                    <div class="bg-white rounded-xl shadow-sm border border-orange-100 overflow-hidden h-full flex flex-col">
                        <div class="p-4 border-b border-orange-100 flex justify-between items-center bg-gray-50">
                            <h5 class="font-bold text-gray-800">Existing Accounts</h5>
                            <span class="bg-gray-200 text-gray-700 px-2 py-1 rounded-lg text-xs font-bold" id="user-count"><?php echo count($users); ?> Users</span>
                        </div>
                        <div class="flex-1 overflow-y-auto p-0">
                            <table class="w-full text-left border-collapse">
                                <thead class="bg-gray-50 text-xs uppercase text-gray-500 font-semibold sticky top-0 z-10">
                                    <tr>
                                        <th class="px-6 py-3">Username</th>
                                        <th class="px-6 py-3">Role</th>
                                        <th class="px-6 py-3">Contact Info</th>
                                        <th class="px-6 py-3 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100" id="user-table-body">
                                    <?php foreach ($users as $user): 
                                        $badgeClass = 'bg-gray-100 text-gray-800';
                                        if ($user['role'] == 'manager') $badgeClass = 'bg-blue-100 text-blue-800';
                                        if ($user['role'] == 'assistant_manager') $badgeClass = 'bg-purple-100 text-purple-800';
                                        if ($user['role'] == 'cashier') $badgeClass = 'bg-green-100 text-green-800';
                                    ?>
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
                                                    <button onclick="editUser(<?php echo $user['user_id']; ?>)" class="p-1.5 text-blue-600 bg-blue-50 rounded hover:bg-blue-100 transition-colors" title="Edit">
                                                        <i class='bx bx-edit text-lg'></i>
                                                    </button>
                                                    <button onclick="deleteUser(<?php echo $user['user_id']; ?>)" class="p-1.5 text-red-600 bg-red-50 rounded hover:bg-red-100 transition-colors" title="Delete">
                                                        <i class='bx bx-trash text-lg'></i>
                                                    </button>
                                                </div>
                                            <?php else: ?>
                                                <div class="flex justify-end gap-2">
                                                    <button onclick="editUser(<?php echo $user['user_id']; ?>)" class="p-1.5 text-blue-600 bg-blue-50 rounded hover:bg-blue-100 transition-colors" title="Edit">
                                                        <i class='bx bx-edit text-lg'></i>
                                                    </button>
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

                <div class="lg:col-span-1 animate-slide-in delay-200">
                    <div class="bg-white rounded-xl shadow-sm border border-orange-100 overflow-hidden sticky top-4">
                        <div class="p-4 border-b border-orange-100 bg-blue-50 flex items-center gap-2 transition-colors" id="form-header">
                            <i class='bx bx-user-plus text-blue-600 text-xl' id="form-icon"></i>
                            <h5 class="font-bold text-blue-800" id="form-title">Create Account</h5>
                        </div>
                        <div class="p-6">
                            <form id="user-form" onsubmit="handleSaveUser(event)" class="space-y-4">
                                <input type="hidden" name="user_id" id="user_id" value="0">
                                
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 mb-1 uppercase tracking-wider">Role</label>
                                    <select name="role" id="role" required class="w-full p-2.5 bg-white border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-breadly-btn outline-none">
                                        <option value="manager">Manager</option>
                                        <option value="assistant_manager">Assistant Manager</option>
                                        <option value="cashier">Cashier</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-gray-500 mb-1 uppercase tracking-wider">Username</label>
                                    <input type="text" name="username" id="username" placeholder="e.g. juan123" required
                                           class="w-full p-2.5 bg-white border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-breadly-btn outline-none">
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-gray-500 mb-1 uppercase tracking-wider">
                                        Password 
                                        <span id="password-hint" class="hidden text-red-500 font-normal normal-case text-[10px] ml-1">(Leave blank to keep)</span>
                                        <span id="password-error" class="text-red-500 text-[10px] font-normal normal-case hidden ml-2 animate-pulse">Min 6 chars, letters & numbers</span>
                                    </label>
                                    <div class="relative">
                                        <input type="password" name="password" id="password" placeholder="Strong password" required
                                               class="w-full p-2.5 pr-10 bg-white border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-breadly-btn outline-none transition-colors">
                                        <button type="button" onclick="togglePassword()" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-500 hover:text-gray-700 focus:outline-none">
                                            <i class='bx bx-show text-xl' id="password-toggle-icon"></i>
                                        </button>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-gray-500 mb-1 uppercase tracking-wider">
                                        Phone Number <span class="text-red-500">*</span> 
                                        <span id="phone-error" class="text-red-500 text-[10px] font-normal normal-case hidden ml-2 animate-pulse">Must be 11 digits</span>
                                    </label>
                                    <input type="text" name="phone" id="phone" placeholder="0917..." maxlength="11" required
                                           class="w-full p-2.5 bg-white border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-breadly-btn outline-none transition-colors">
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-gray-500 mb-1 uppercase tracking-wider">Email Address (Optional)</label>
                                    <input type="email" name="email" id="email" placeholder="For recovery"
                                           class="w-full p-2.5 bg-white border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-breadly-btn outline-none">
                                </div>

                                <button type="submit" id="form-btn" class="w-full py-2.5 text-white font-medium rounded-lg shadow transition-colors mt-2 bg-blue-600 hover:bg-blue-700">
                                    Create User
                                </button>
                                
                                <div id="cancel-btn-container" class="hidden">
                                    <button type="button" onclick="resetForm()" class="block w-full text-center py-2 text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 text-sm transition-colors">
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <div id="modalBackdrop" class="fixed inset-0 bg-black/50 z-40 hidden transition-opacity" onclick="closeAllModals()"></div>

    <div id="settingsModal" class="fixed inset-0 z-50 hidden flex items-center justify-center modal-animate-in">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm m-4 overflow-hidden relative z-50">
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

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../js/script_account_management.js"></script>
</body>
</html>