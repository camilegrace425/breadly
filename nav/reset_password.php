<?php
session_start();
require_once '../src/UserManager.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// If a reset was not initiated, redirect away.
if (!isset($_SESSION['reset_in_progress']) || $_SESSION['reset_in_progress'] !== true) {
    header('Location: login.php');
    exit();
}

// --- MODIFIED: Get flash messages from session ---
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['error_message']); // Clear the message so it only shows once

// Get the method from URL to customize text
$method = $_GET['method'] ?? 'phone';
$code_label = $method === 'email' ? 'Token (from email)' : 'OTP Code (from phone)';
$placeholder_text = $method === 'email' ? 'Enter token from email' : 'Enter 6-digit code';


// --- MODIFIED: POST logic now redirects ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $otp_code = $_POST['otp_code'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // 1. Check if passwords match
    if ($new_password !== $confirm_password) {
        $_SESSION['error_message'] = "The new passwords do not match. Please try again.";
        header('Location: reset_password.php?method=' . htmlspecialchars($method)); // Redirect back
        exit();
    } 
        
    // 2. Try to reset the password
    $userManager = new UserManager();
    $success = $userManager->resetPassword($otp_code, $new_password);

    if ($success) {
        // On success, send message to LOGIN page and redirect there
        $_SESSION['success_message'] = "Password reset successfully! You can now log in.";
        unset($_SESSION['reset_in_progress']); // Clear the session flag
        header('Location: login.php'); // Redirect to login
        exit();
    } else {
        // On failure, send error message back to THIS page and redirect
        $_SESSION['error_message'] = "Invalid, expired, or already used code. Please request a new one.";
        header('Location: reset_password.php?method=' . htmlspecialchars($method)); // Redirect back
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Reset Password</title>
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" />
  <link rel="stylesheet" href="../styles.css" />
</head>
<body>
  <div class="container-fluid">
    <div class="row no-gutters h-100">
      <div class="col-md-6 login-left d-none d-md-flex">
        <div>
          <h1>Bakery Management System</h1>
          <p>Secure access for inventory, sales, production, and user management.</p>
        </div>
      </div>
      <div class="col-md-6 login-right">
        <div class="form-box">
          <h2>Set New Password</h2>
          
          <form action="reset_password.php?method=<?php echo htmlspecialchars($method); ?>" method="POST">
            <?php 
              // This now reads the error message from the session
              if (!empty($error_message)) { 
                echo '<div class="alert alert-danger">' . htmlspecialchars($error_message) . '</div>'; 
              }
            ?>
            
            <p class="text-muted">Enter the <?php echo $method === 'email' ? 'token from your email' : '6-digit code sent to your phone'; ?> and choose a new password.</p>
            <div class="form-group">
              <label for="otp_code"><?php echo htmlspecialchars($code_label); ?></label>
              <input type="text" name="otp_code" class="form-control" placeholder="<?php echo htmlspecialchars($placeholder_text); ?>" required />
            </div>
            <div class="form-group">
              <label for="new_password">New Password</label>
              <input type="password" name="new_password" class="form-control" placeholder="Enter new password" required />
            </div>
            <div class="form-group">
              <label for="confirm_password">Confirm New Password</label>
              <input type="password" name="confirm_password" class="form-control" placeholder="Confirm new password" required />
            </div>
            <button type="submit" class="btn btn-primary mt-3">Reset Password</button>

            <div class="text-center mt-3">
              <a href="login.php">Back to Login</a>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</body>
</html>