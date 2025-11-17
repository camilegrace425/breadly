<?php
// --- 1. SECURE LOGIN LOGIC ---
session_start();
require_once '../src/UserManager.php'; // Include your secure user manager class

// --- ADDED: Success message from reset ---
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']); // Clear it so it only shows once

// --- ADDED: Helper function to get device type ---
function getDeviceType() {
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobile))/i', $user_agent)) {
        return 'Tablet';
    }
    if (preg_match('/(mobi|ipod|phone|blackberry|opera mini|fennec|minimo|symbian|psp|nintendo ds)/i', $user_agent)) {
        return 'Mobile';
    }
    if (preg_match('/(windows nt|macintosh|linux)/i', $user_agent)) {
        return 'Desktop';
    }
    return 'Unknown';
}
// --- END HELPER ---


// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'manager') {
         header('Location: index.php');
    } else {
         header('Location: index.php');
    }
    exit();
}

$error_message = '';

// --- ::: MODIFIED: All logging logic is now handled here ::: ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $login_type = $_POST['login_type'] ?? 'manager'; // 'manager' or 'cashier'

    $userManager = new UserManager();
    $device_type = getDeviceType(); // Get device type
    
    // Step 1: Verify credentials (this function no longer logs)
    $user = $userManager->login($username, $password);

    if ($user) {
        // Step 2: Credentials are correct. Now check if they used the right form.
        $actual_role = $user['role'];

        if ($login_type === $actual_role) {
            // --- SUCCESS: Role matches form type ---
            
            // Step 3: Log the successful attempt
            $userManager->logLoginAttempt($username, 'success', $device_type);
            
            $_SESSION['logged_in'] = true;
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            session_regenerate_id(true);

            if ($user['role'] === 'manager') {
                 header('Location: index.php');
            } else {
                 header('Location: index.php'); // Cashier
            }
            exit();

        } else {
            // --- FAILURE: Wrong Form (e.g., cashier on admin form) ---
            
            // Step 3: Log this as a 'failure'
            $userManager->logLoginAttempt($username, 'failure', $device_type);
            
            if ($login_type === 'manager') {
                $error_message = "Access Denied. Please use the Cashier Login form.";
            } else {
                $error_message = "Access Denied. Please use the Admin Login form.";
            }
        }
        
    } else {
        // --- FAILURE: Invalid username or password ---
        
        // Step 3: Log the failed attempt
        $userManager->logLoginAttempt($username, 'failure', $device_type);
        
        $error_message = "Invalid username or password. Please try again.";
    }
}
// --- ::: END OF MODIFIED LOGIC ::: ---

?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BREADLY Login</title>
    <link rel="icon" href="../images/kzklogo.png" type="image/x-icon"> 
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <link rel="stylesheet" href="../styles/global.css"> 
    <link rel="stylesheet" href="../styles/login.css"> 
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
</head>
<body class="page-login">
    <div class="main-wrapper">
        <div class="form-side">
            <div class="form-container" id="formContainer">
                
                <div class="login-form admin-form active" id="adminLoginForm">
                    <h2>Admin Login</h2>
                    <form action="login.php" method="POST">
                        <input type="hidden" name="login_type" value="manager">

                        <?php 
                          // --- Display success/error message ---
                          if (!empty($success_message)) { 
                            echo '<div class="alert alert-success" style="width:100%; font-size: 0.9rem;">' . htmlspecialchars($success_message) . '</div>'; 
                          } 
                          if (!empty($error_message)) { 
                            echo '<div class="alert alert-danger" style="width:100%; font-size: 0.9rem;">' . htmlspecialchars($error_message) . '</div>'; 
                          } 
                        ?>
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

                        <?php 
                          // --- Display success/error message (mirrored) ---
                          if (!empty($success_message)) { 
                            echo '<div class="alert alert-success" style="width:100%; font-size: 0.9rem;">' . htmlspecialchars($success_message) . '</div>'; 
                          } 
                          if (!empty($error_message)) { 
                            echo '<div class="alert alert-danger" style="width:100%; font-size: 0.9rem;">' . htmlspecialchars($error_message) . '</div>'; 
                          } 
                        ?>
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
                
                <button type="button" class="btn switch-btn d-none d-lg-block mx-auto" id="switch-btn">Cashier Login?</button>

                <div class="mobile-toggle-wrapper d-lg-none" role="group">
                    <button type="button" class="btn toggle-btn active" id="admin-toggle-btn">Admin</button>
                    <button type="button" class="btn toggle-btn" id="cashier-toggle-btn">Cashier</button>
                </div>
                
            </div>
        </div>
    </div>

    <script src="../js/script_login.js"></script>
    <script>
        // --- This script ensures the correct error message is shown on the correct tab ---
        document.addEventListener('DOMContentLoaded', function() {
            const body = document.body;
            const switchBtn = document.getElementById('switch-btn');
            
            // Check if an error message exists and which form it belongs to
            <?php if (!empty($error_message)): ?>
                const loginType = '<?php echo $login_type; ?>';
                if (loginType === 'cashier') {
                    // If error was on cashier form, switch to that view
                    body.classList.add('cashier-mode');
                    
                    // --- ALSO SYNC THE BUTTONS ---
                    if (switchBtn) {
                        switchBtn.textContent = 'Admin Login?';
                    }
                    const adminBtn = document.getElementById('admin-toggle-btn');
                    const cashierBtn = document.getElementById('cashier-toggle-btn');
                    if(adminBtn) adminBtn.classList.remove('active');
                    if(cashierBtn) cashierBtn.classList.add('active');
                }
            <?php endif; ?>
        });
    </script>
</body>
</html>