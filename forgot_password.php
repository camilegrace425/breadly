<?php
session_start();
require_once 'src/UserManager.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard_panel.php');
    exit();
}
$error_message = '';
$success_message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $identifier = $_POST['identifier'];
    $userManager = new UserManager();
    if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
        $token = $userManager->requestEmailReset($identifier);
        if ($token) {
            $success_message = "A password reset link has been sent to your email.";
        } else {
            $error_message = "No account found with that email address.";
        }
    } else {
        $otp = $userManager->requestPhoneReset($identifier);
        if ($otp) {
            $success_message = "A 6-digit OTP has been sent to your phone.";
        } else {
            $error_message = "No account found with that phone number.";
        }
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
  
  <link rel="stylesheet" href="styles.css" />
  
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
          <h2>Reset Password</h2>
          
          <form action="forgot_password.php" method="POST">
            <?php 
              if (!empty($error_message)) { 
                echo '<div class="alert alert-danger">' . htmlspecialchars($error_message) . '</div>'; 
              } 
              if (!empty($success_message)) { 
                echo '<div class="alert alert-success">' . htmlspecialchars($success_message) . '</div>'; 
              } 
            ?>
            <div class="form-group">
              <label for="identifier">Email or Phone Number</label>
              <input type="text" name="identifier" class="form-control" placeholder="Enter your email or phone" required />
            </div>
            <button type="submit" class="btn btn-primary mt-3">Send Reset Code</button>
            <div class="text-center mt-3">
              <a href="login.php">Remembered your password? Login</a>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</body>
</html>