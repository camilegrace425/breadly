<?php
// --- 1. SECURE LOGIN LOGIC ---
session_start();
require_once '../src/UserManager.php'; // Include your secure user manager class

// --- ADDED: Check for success message from reset password page ---
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']); // Clear it so it only shows once
// -----------------------------------------------------------------

// UPDATED: Redirect to correct page if already logged in
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'manager') {
         header('Location: index.php');
    } else {
         header('Location: index.php');
    }
    exit();
}

$error_message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $login_type = $_POST['login_type'] ?? 'manager'; // Get the form type (manager or cashier)

    $userManager = new UserManager();
    $user = $userManager->login($username, $password);

    if ($user) {
        // --- NEW: Role Validation Logic ---
        $actual_role = $user['role'];

        if ($login_type === 'manager' && $actual_role !== 'manager') {
            // Tried to log in as manager, but is a cashier
            $error_message = "Access Denied. Please use the Cashier Login form.";
        } elseif ($login_type === 'cashier' && $actual_role !== 'cashier') {
            // Tried to log in as cashier, but is a manager
            $error_message = "Access Denied. Please use the Admin Login form.";
        } else {
            // --- SUCCESS: Role matches form type ---
            $_SESSION['logged_in'] = true;
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            // --- ADDED: Prevent Session Hijacking ---
            // Regenerate the session ID to prevent session fixation.
            session_regenerate_id(true);
            // --- END ADDITION ---

            // UPDATED: Redirect to correct page based on role
            if ($user['role'] === 'manager') {
                 header('Location: index.php');
            } else {
                 header('Location: index.php'); // Cashier goes to main menu
            }
            exit();
        }
        // --- END: Role Validation Logic ---
        
    } else {
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
                          // --- ADDED: Display success/error message ---
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
                          // --- ADDED: Display success/error message (mirrored) ---
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
                <button type="button" class="btn switch-btn" id="switch-btn">Cashier Login?</button>
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
                    switchBtn.textContent = 'Admin Login?';
                }
            <?php endif; ?>
        });
    </script>
</body>
</html>