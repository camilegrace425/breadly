<?php
session_start();
require_once "../src/UserManager.php"; 

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}
if ($_SESSION["role"] !== "manager") {
    header("Location: ../index.php"); 
    exit();
}

$userManager = new UserManager();
$login_history = $userManager->getLoginHistory();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login History</title>
    <link rel="icon" href="../images/kzklogo.png" type="image/x-icon"> 
    
    <link rel="stylesheet" href="../styles/global.css">

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
            <a href="account_management.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors text-breadly-secondary hover:bg-orange-100 hover:text-breadly-dark">
                <i class='bx bxs-user-account text-xl'></i><span class="font-medium">User Management</span>
            </a>
            <a href="login_history.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors bg-breadly-dark text-white shadow-md">
                <i class='bx bxs-time-five text-xl'></i><span class="font-medium">Login History</span>
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
                    <p class="text-sm font-bold text-breadly-dark truncate"><?php echo htmlspecialchars($_SESSION["username"]); ?></p>
                    <p class="text-xs text-breadly-secondary">Manager</p>
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
            <a href="account_management.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-breadly-secondary">
                <i class='bx bxs-user-account text-xl'></i> User Management
            </a>
            <a href="login_history.php" class="flex items-center gap-3 px-4 py-3 rounded-xl bg-breadly-dark text-white">
                <i class='bx bxs-time-five text-xl'></i> Login History
            </a>
            <a href="../index.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-breadly-secondary mt-4 border-t border-orange-200 pt-4">
                <i class='bx bx-arrow-back text-xl'></i> Main Menu
            </a>
        </nav>
        <div class="p-4 border-t border-orange-200">
            <a href="logout.php" class="block w-full py-2 text-center text-sm bg-red-50 text-red-600 rounded-lg">Logout</a>
        </div>
    </div>

    <main class="flex-1 flex flex-col h-full overflow-hidden relative w-full">
        
        <div class="p-6 pb-2 flex flex-col md:flex-row md:items-center justify-between gap-4 bg-breadly-bg z-10">
            <div class="flex items-center gap-3">
                <button onclick="toggleSidebar()" class="lg:hidden text-breadly-dark text-2xl"><i class='bx bx-menu'></i></button>
                <h1 class="text-2xl font-bold text-breadly-dark">Login History</h1>
            </div>
        </div>
        
        <div class="flex-1 overflow-y-auto p-6 pb-20">
            <div class="bg-white rounded-xl shadow-sm border border-orange-100 overflow-hidden flex flex-col h-full max-h-[85vh] animate-slide-in delay-100" id="login-history-card">
                
                <div class="p-4 border-b border-orange-100 bg-gray-50 flex flex-wrap justify-between items-center gap-3">
                    <h5 class="font-bold text-gray-800">Access Logs</h5>
                    
                    <div class="flex flex-wrap justify-end items-center gap-3">
                        <div class="flex items-center gap-1">
                            <label for="login-rows-select" class="text-xs font-bold text-gray-500 uppercase mr-1">Show</label>
                            <select id="login-rows-select" class="bg-white border border-gray-300 rounded-lg text-sm py-1.5 px-2 focus:ring-2 focus:ring-breadly-btn outline-none">
                                <option value="10" selected>10</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="all">All</option>
                            </select>
                            
                            <div class="flex items-center ml-2 gap-1">
                                <button id="login-prev-btn" disabled class="p-1.5 bg-white border border-gray-300 rounded-lg hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                                    <i class='bx bx-chevron-left text-lg'></i>
                                </button>
                                <button id="login-next-btn" class="p-1.5 bg-white border border-gray-300 rounded-lg hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                                    <i class='bx bx-chevron-right text-lg'></i>
                                </button>
                            </div>
                        </div>

                        <div class="relative dropdown">
                            <button onclick="toggleSortDropdown()" id="sortDropdownBtn" class="flex items-center gap-2 px-3 py-1.5 bg-white border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none">
                                Sort By: <span class="current-sort-text">Timestamp (Newest)</span> <i class='bx bx-chevron-down'></i>
                            </button>
                            
                            <div id="sortDropdownMenu" class="absolute right-0 mt-1 w-56 bg-white border border-gray-100 rounded-lg shadow-lg hidden z-20 dropdown-menu">
                                <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-orange-50 sort-trigger active" data-sort-by="timestamp" data-sort-dir="desc" data-sort-type="date">Timestamp (Newest First)</a>
                                <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-orange-50 sort-trigger" data-sort-by="timestamp" data-sort-dir="asc" data-sort-type="date">Timestamp (Oldest First)</a>
                                <div class="border-t border-gray-100 my-1"></div>
                                <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-orange-50 sort-trigger" data-sort-by="status" data-sort-dir="asc" data-sort-type="text">Status (Failure First)</a>
                                <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-orange-50 sort-trigger" data-sort-by="status" data-sort-dir="desc" data-sort-type="text">Status (Success First)</a>
                                <div class="border-t border-gray-100 my-1"></div>
                                <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-orange-50 sort-trigger" data-sort-by="user" data-sort-dir="asc" data-sort-type="text">Username (A-Z)</a>
                                <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-orange-50 sort-trigger" data-sort-by="user" data-sort-dir="desc" data-sort-type="text">Username (Z-A)</a>
                                <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-orange-50 sort-trigger" data-sort-by="role" data-sort-dir="asc" data-sort-type="text">Role (A-Z)</a>
                                <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-orange-50 sort-trigger" data-sort-by="device" data-sort-dir="asc" data-sort-type="text">Device (A-Z)</a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="flex-1 overflow-y-auto p-0">
                    <table class="w-full text-left border-collapse">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500 font-semibold sticky top-0">
                            <tr>
                                <th class="px-6 py-3" data-sort-by="timestamp">Timestamp</th>
                                <th class="px-6 py-3" data-sort-by="user">Username (Attempt)</th>
                                <th class="px-6 py-3" data-sort-by="role">Role</th>
                                <th class="px-6 py-3" data-sort-by="device">Device</th>
                                <th class="px-6 py-3" data-sort-by="status">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100" id="login-table-body">
                            <?php if (empty($login_history)): ?>
                                <tr><td colspan="5" class="px-6 py-8 text-center text-gray-400">No login history found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($login_history as $log): ?>
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-3 text-sm text-gray-600" data-label="Timestamp"><?php echo htmlspecialchars(date("M d, Y h:i A", strtotime($log["timestamp"]))); ?></td>
                                    <td class="px-6 py-3 font-medium text-gray-800" data-label="Username (Attempt)"><?php echo htmlspecialchars($log["username_attempt"]); ?></td>
                                    <td class="px-6 py-3" data-label="Role">
                                        <?php if ($log["role"] == 'manager'): ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">Manager</span>
                                        <?php elseif ($log["role"] == 'cashier'): ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Cashier</span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">Assistant Manager</span>  
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-3 text-sm text-gray-600" data-label="Device">
                                        <?php
                                        $icon = 'bx-question-mark';
                                        if ($log["device_type"] == 'Desktop') $icon = 'bx-desktop';
                                        if ($log["device_type"] == 'Mobile') $icon = 'bx-mobile';
                                        if ($log["device_type"] == 'Tablet') $icon = 'bx-tab';
                                        ?>
                                        <div class="flex items-center gap-2">
                                            <i class='bx <?php echo $icon; ?> text-lg text-gray-400'></i>
                                            <?php echo htmlspecialchars($log["device_type"] ?? 'Unknown'); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-3" data-label="Status">
                                        <?php if ($log["status"] == 'success'): ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-50 text-green-700 border border-green-200">Success</span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-50 text-red-700 border border-red-200">Failure</span>
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

    <?php $js_version = file_exists("../js/script_login_history.js") ? filemtime("../js/script_login_history.js") : "1"; ?>
    <script src="../js/script_login_history.js?v=<?php echo $js_version; ?>"></script>

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

        function toggleSortDropdown() {
            const menu = document.getElementById('sortDropdownMenu');
            menu.classList.toggle('hidden');
        }

        window.addEventListener('click', function(e) {
            const btn = document.getElementById('sortDropdownBtn');
            const menu = document.getElementById('sortDropdownMenu');
            if (btn && menu && !btn.contains(e.target) && !menu.contains(e.target)) {
                menu.classList.add('hidden');
            }
        });
        
        document.addEventListener('click', function(e) {
            if(e.target.matches('.sort-trigger')) {
                 const menu = document.getElementById('sortDropdownMenu');
                 if(menu) menu.classList.add('hidden');
            }
        });
    </script>
</body>
</html>