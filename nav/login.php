<?php
session_start();
require_once '../src/UserManager.php';

// --- Helper Function ---
function getDeviceType() {
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobile))/i', $user_agent)) return 'Tablet';
    if (preg_match('/(mobi|ipod|phone|blackberry|opera mini|fennec|minimo|symbian|psp|nintendo ds)/i', $user_agent)) return 'Mobile';
    if (preg_match('/(windows nt|macintosh|linux)/i', $user_agent)) return 'Desktop';
    return 'Unknown';
}

// --- Redirect if already logged in ---
if (isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);
$error_message = '';
$login_type = $_POST['login_type'] ?? 'manager'; // Default to manager view

// --- Form Handling ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $login_type = $_POST['login_type'] ?? 'manager';

    $userManager = new UserManager();
    $device_type = getDeviceType();

    // Attempt Login
    $user = $userManager->login($username, $password);

    if ($user) {
        $actual_role = $user['role'];
        $role_matches_form = false;

        // Check if role matches the specific form used
        if ($login_type === 'manager' && ($actual_role === 'manager' || $actual_role === 'assistant_manager')) {
            $role_matches_form = true;
        } elseif ($login_type === 'cashier' && $actual_role === 'cashier') {
            $role_matches_form = true;
        }

        if ($role_matches_form) {
            // Login Success
            $userManager->logLoginAttempt($username, 'success', $device_type);
            $_SESSION['logged_in'] = true;
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            session_regenerate_id(true);
            header('Location: ../index.php');
            exit();
        } else {
            // Role Mismatch
            $userManager->logLoginAttempt($username, 'failure', $device_type);
            $error_message = ($login_type === 'manager') 
                ? "Access Denied. Please use the Cashier Login form."
                : "Access Denied. Please use the Manager Login form.";
        }
    } else {
        // Invalid Credentials
        $userManager->logLoginAttempt($username, 'failure', $device_type);
        $error_message = "Invalid username or password. Please try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BREADLY Login</title>
    <link rel="icon" href="../images/kzklogo.png" type="image/x-icon">
    
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    
    <script src="https://cdn.tailwindcss.com"></script>
    
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Lora:wght@400;700&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Poppins', 'sans-serif'],
                        serif: ['Lora', 'serif'],
                        display: ['Playfair Display', 'serif'],
                    },
                    colors: {
                        breadly: {
                            bg: '#F8F1E7',
                            panel: '#E4A26C',
                            btn: '#af6223',
                            'btn-hover': '#9b4a10',
                            dark: '#333333',
                            cream: '#FFFBF5',
                            input: '#F0F0F0'
                        }
                    }
                }
            }
        }
    </script>
    <style>
        /* Custom Scrollbar for consistency if needed, though Tailwind handles most */
        body { -webkit-font-smoothing: antialiased; }
    </style>
</head>
<body class="bg-breadly-bg min-h-screen flex items-center justify-center p-4 <?php echo ($login_type === 'cashier' && !empty($error_message)) ? 'cashier-mode' : ''; ?>">
    
    <div class="relative w-full max-w-[450px] lg:max-w-[850px] h-auto lg:h-[550px] bg-white rounded-[30px] shadow-2xl flex flex-col-reverse lg:flex-row overflow-hidden transition-all duration-600 ease-in-out [.cashier-mode_&]:lg:flex-row-reverse">
        
        <div class="w-full lg:w-1/2 h-full relative overflow-hidden">
            <div id="formContainer" class="flex w-[200%] h-full transition-transform duration-600 ease-in-out [.cashier-mode_&]:-translate-x-1/2">
                
                <div class="w-1/2 flex flex-col justify-center items-center px-8 py-10 lg:px-10 text-center" id="adminLoginForm">
                    <h2 class="text-3xl font-semibold mb-5 text-breadly-dark">Manager Login</h2>
                    
                    <form action="login.php" method="POST" class="w-full max-w-xs">
                        <input type="hidden" name="login_type" value="manager">
                        
                        <?php if (!empty($success_message)): ?>
                            <div class="mb-4 p-3 bg-green-100 text-green-700 rounded-lg text-sm font-medium border border-green-200"><?php echo htmlspecialchars($success_message); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($error_message) && $login_type === 'manager'): ?>
                            <div class="mb-4 p-3 bg-red-100 text-red-700 rounded-lg text-sm font-medium border border-red-200"><?php echo htmlspecialchars($error_message); ?></div>
                        <?php endif; ?>

                        <div class="relative mb-6">
                            <input type="text" name="username" placeholder="Username" required 
                                   class="w-full p-4 bg-breadly-input rounded-lg border border-transparent focus:border-breadly-btn focus:bg-white outline-none transition-colors font-medium placeholder-gray-400">
                        </div>
                        <div class="relative mb-8 input-box">
                            <input type="password" name="password" id="adminPassword" placeholder="Password" required 
                                   class="w-full p-4 pr-12 bg-breadly-input rounded-lg border border-transparent focus:border-breadly-btn focus:bg-white outline-none transition-colors font-medium placeholder-gray-400">
                            <i class='bx bx-hide password-toggle absolute right-4 top-1/2 -translate-y-1/2 text-xl text-gray-400 cursor-pointer hover:text-breadly-btn transition-colors' data-target="adminPassword"></i>
                        </div>
                        
                        <button type="submit" class="w-[60%] py-3 bg-breadly-btn text-white font-semibold rounded shadow-md hover:bg-breadly-btn-hover hover:shadow-lg transition-all duration-300">
                            LOGIN
                        </button>
                        
                        <div class="mt-4 text-center">
                            <a href="forgot_password.php" class="text-sm text-breadly-dark font-medium hover:text-breadly-btn transition-colors">Forgot password?</a>
                        </div>
                    </form>
                </div>

                <div class="w-1/2 flex flex-col justify-center items-center px-8 py-10 lg:px-10 text-center" id="cashierLoginForm">
                    <h2 class="text-3xl font-semibold mb-5 text-breadly-dark">Cashier Login</h2>
                    
                    <form action="login.php" method="POST" class="w-full max-w-xs">
                        <input type="hidden" name="login_type" value="cashier">
                        
                        <?php if (!empty($success_message)): ?>
                            <div class="mb-4 p-3 bg-green-100 text-green-700 rounded-lg text-sm font-medium border border-green-200"><?php echo htmlspecialchars($success_message); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($error_message) && $login_type === 'cashier'): ?>
                            <div class="mb-4 p-3 bg-red-100 text-red-700 rounded-lg text-sm font-medium border border-red-200"><?php echo htmlspecialchars($error_message); ?></div>
                        <?php endif; ?>

                        <div class="relative mb-6">
                             <input type="text" name="username" placeholder="Username" required 
                                    class="w-full p-4 bg-breadly-input rounded-lg border border-transparent focus:border-breadly-btn focus:bg-white outline-none transition-colors font-medium placeholder-gray-400">
                        </div>
                        <div class="relative mb-8 input-box">
                            <input type="password" name="password" id="cashierPassword" placeholder="Password" required 
                                   class="w-full p-4 pr-12 bg-breadly-input rounded-lg border border-transparent focus:border-breadly-btn focus:bg-white outline-none transition-colors font-medium placeholder-gray-400">
                            <i class='bx bx-hide password-toggle absolute right-4 top-1/2 -translate-y-1/2 text-xl text-gray-400 cursor-pointer hover:text-breadly-btn transition-colors' data-target="cashierPassword"></i>
                        </div>
                        
                        <button type="submit" class="w-[60%] py-3 bg-breadly-btn text-white font-semibold rounded shadow-md hover:bg-breadly-btn-hover hover:shadow-lg transition-all duration-300">
                            LOGIN
                        </button>
                        
                        <div class="mt-4 text-center">
                             <a href="forgot_password.php" class="text-sm text-breadly-dark font-medium hover:text-breadly-btn transition-colors">Forgot password?</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div id="brandSide" class="w-full lg:w-1/2 bg-breadly-panel flex flex-col justify-center items-center p-8 lg:p-10 
            rounded-t-[30px] lg:rounded-t-none lg:rounded-r-[30px] 
            transition-all duration-600 ease-in-out
            [.cashier-mode_&]:lg:rounded-r-none [.cashier-mode_&]:lg:rounded-l-[30px]">
            
            <div class="text-center w-full text-white">
                <h1 class="font-display text-4xl lg:text-5xl font-bold leading-tight mb-6 text-black">WELCOME TO <br>BREADLY</h1>
                
                <div class="bg-transparent p-2 rounded-xl mx-auto w-fit mb-8">
                    <img src="../images/kzklogo.png" alt="Kz & Khyle's Logo" class="w-[250px] lg:w-[300px] h-auto block">
                </div>
                
                <button type="button" id="switch-btn" class="hidden lg:block w-40 py-3 mx-auto bg-breadly-btn-hover text-white font-semibold rounded shadow-none hover:bg-gray-100 hover:text-breadly-btn transition-colors duration-300">
                    <?php echo ($login_type === 'cashier' && !empty($error_message)) ? 'Manager Login?' : 'Cashier Login?'; ?>
                </button>

                <div class="lg:hidden flex rounded-lg overflow-hidden border border-breadly-btn-hover w-[200px] mx-auto mt-4 bg-transparent" role="group">
                    <button type="button" id="admin-toggle-btn" class="flex-1 py-2 text-breadly-btn-hover font-semibold transition-all duration-300 hover:bg-white/10 [&.active]:bg-breadly-btn-hover [&.active]:text-white active">Manager</button>
                    <button type="button" id="cashier-toggle-btn" class="flex-1 py-2 text-breadly-btn-hover font-semibold transition-all duration-300 hover:bg-white/10 [&.active]:bg-breadly-btn-hover [&.active]:text-white">Cashier</button>
                </div>
            </div>
        </div>
    </div>

    <script src="../js/script_login.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const body = document.body;
            const switchBtn = document.getElementById('switch-btn');
            
            // Force UI update if PHP rendered cashier mode due to error
            <?php if (!empty($error_message) && $login_type === 'cashier'): ?>
                const adminBtn = document.getElementById('admin-toggle-btn');
                const cashierBtn = document.getElementById('cashier-toggle-btn');
                if(adminBtn) adminBtn.classList.remove('active');
                if(cashierBtn) cashierBtn.classList.add('active');
            <?php endif; ?>
            
            if (switchBtn) {
                switchBtn.addEventListener('click', function() {
                    setTimeout(() => {
                        if (body.classList.contains('cashier-mode')) {
                            switchBtn.textContent = 'Manager Login?';
                        } else {
                            switchBtn.textContent = 'Cashier Login?';
                        }
                    }, 10);
                });
            }
        });
    </script>
</body>
</html>