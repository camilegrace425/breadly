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
    header('Location: index.php');
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
            header('Location: index.php');
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
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    
    <link rel="stylesheet" href="../styles/global.css">
    <link rel="stylesheet" href="../styles/login.css">
</head>
<body class="page-login <?php echo ($login_type === 'cashier' && !empty($error_message)) ? 'cashier-mode' : ''; ?>">
    
    <div class="main-wrapper">
        <div class="form-side">
            <div class="form-container" id="formContainer">
                
                <div class="login-form admin-form active" id="adminLoginForm">
                    <h2>Manager Login</h2>
                    <form action="login.php" method="POST">
                        <input type="hidden" name="login_type" value="manager">
                        
                        <?php if (!empty($success_message)): ?>
                            <div class="alert alert-success py-2"><?php echo htmlspecialchars($success_message); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($error_message) && $login_type === 'manager'): ?>
                            <div class="alert alert-danger py-2"><?php echo htmlspecialchars($error_message); ?></div>
                        <?php endif; ?>

                        <div class="input-box">
                            <input type="text" name="username" placeholder="Username" required>
                        </div>
                        <div class="input-box password-box">
                            <input type="password" name="password" id="adminPassword" placeholder="Password" required>
                            <i class='bx bx-hide password-toggle' data-target="adminPassword"></i>
                        </div>
                        <button type="submit" class="btn">LOGIN</button>
                        <div class="forgot-link">
                            <a href="forgot_password.php">Forgot password?</a>
                        </div>
                    </form>
                </div>

                <div class="login-form cashier-form" id="cashierLoginForm">
                    <h2>Cashier Login</h2>
                    <form action="login.php" method="POST">
                        <input type="hidden" name="login_type" value="cashier">
                        
                        <?php if (!empty($success_message)): ?>
                            <div class="alert alert-success py-2"><?php echo htmlspecialchars($success_message); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($error_message) && $login_type === 'cashier'): ?>
                            <div class="alert alert-danger py-2"><?php echo htmlspecialchars($error_message); ?></div>
                        <?php endif; ?>

                        <div class="input-box">
                             <input type="text" name="username" placeholder="Username" required>
                        </div>
                        <div class="input-box password-box">
                            <input type="password" name="password" id="cashierPassword" placeholder="Password" required>
                            <i class='bx bx-hide password-toggle' data-target="cashierPassword"></i>
                        </div>
                        <button type="submit" class="btn">LOGIN</button>
                        <div class="forgot-link">
                             <a href="forgot_password.php">Forgot password?</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="brand-side" id="brandSide">
            <div class="brand-content">
                <h1 class="welcome-text">WELCOME TO <br>BREADLY</h1>
                <div class="logo-wrapper">
                    <img src="../images/kzklogo.png" alt="Kz & Khyle's Logo" class="bakery-logo">
                </div>
                
                <button type="button" class="btn switch-btn d-none d-lg-block mx-auto" id="switch-btn">
                    <?php echo ($login_type === 'cashier' && !empty($error_message)) ? 'Manager Login?' : 'Cashier Login?'; ?>
                </button>

                <div class="mobile-toggle-wrapper d-lg-none" role="group">
                    <button type="button" class="btn toggle-btn active" id="admin-toggle-btn">Manager</button>
                    <button type="button" class="btn toggle-btn" id="cashier-toggle-btn">Cashier</button>
                </div>
            </div>
        </div>
    </div>

    <script src="../js/script_login.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const body = document.body;
            const switchBtn = document.getElementById('switch-btn');
            
            // Check if we need to force cashier mode UI on error
            <?php if (!empty($error_message) && $login_type === 'cashier'): ?>
                body.classList.add('cashier-mode');
                const adminBtn = document.getElementById('admin-toggle-btn');
                const cashierBtn = document.getElementById('cashier-toggle-btn');
                if(adminBtn) adminBtn.classList.remove('active');
                if(cashierBtn) cashierBtn.classList.add('active');
            <?php endif; ?>
            
            if (switchBtn) {
                switchBtn.addEventListener('click', function() {
                    // Small delay to allow class toggle to finish in external script
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