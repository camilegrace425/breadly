<?php
// --- 1. SECURE LOGIN LOGIC ---
session_start();
require_once '../src/UserManager.php'; // Include your secure user manager class

// UPDATED: Redirect to correct page if already logged in
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'manager') {
         header('Location: dashboard_panel.php');
    } else {
         header('Location: index.php');
    }
    exit();
}

$error_message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $userManager = new UserManager();
    $user = $userManager->login($username, $password);

    if ($user) {
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];

        // UPDATED: Redirect to correct page based on role
        if ($user['role']) {
            header('Location: index.php');
        }
        exit();
        
    } else {
        $error_message = "Invalid username or password. Please try again.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Bakery Admin Login</title>
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
          <h2>Admin & Cashier Login</h2>
          
          <form action="login.php" method="POST">
            <?php 
              if (!empty($error_message)) { 
                echo '<div class="alert alert-danger">' . htmlspecialchars($error_message) . '</div>'; 
              } 
            ?>
            <div class="form-group">
              <label for="username">Username</label>
              <input type="text" name="username" class="form-control" placeholder="Enter username" required />
            </div>
            <div class="form-group">
              <label for="password">Password</label>
              <input type="password" name="password" class="form-control" placeholder="Enter password" required />
            </div>
            <button type="submit" class="btn btn-primary mt-3">Login</button>
            <div class="text-center mt-3">
              <a href="forgot_password.php">Forgot Password?</a>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</body>
</html>