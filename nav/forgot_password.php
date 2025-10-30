<?php
session_start();
require_once '../src/UserManager.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard_panel.php');
    exit();
}

// --- MODIFIED: Check if a reset is already in progress (for GET request) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_SESSION['reset_in_progress']) && $_SESSION['reset_in_progress'] === true) {
        
        // Check how long ago the reset was initiated
        $reset_started_at = $_SESSION['reset_started_at'] ?? 0;
        $time_since_started = time() - $reset_started_at;

        // User's 5-minute (300 seconds) grace period to return
        if ($time_since_started < 300) { 
            // A reset is active *and* within the 5-min grace period.
            // Send them back to the code entry page.
            $method = $_SESSION['reset_method'] ?? 'phone';
            header('Location: reset_password.php?method=' . htmlspecialchars($method));
            exit();
        }
        // If it's over 5 minutes, we let the page load normally,
        // forcing them to request a new code.
    }
}

$error_message = '';
$success_message = '';
$action = $_POST['action'] ?? 'request'; // Default action is 'request'

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // Check if a resend is attempted too soon
    $resend_available_at = $_SESSION['resend_available_at'] ?? 0;
    $time_now = time();

    if ($time_now < $resend_available_at) {
        $wait_time = $resend_available_at - $time_now;
        $error_message = "Please wait $wait_time more seconds before resending.";
        
        // If it was a resend, redirect back with the error
        if ($action === 'resend') {
            $_SESSION['error_message'] = $error_message;
            header('Location: reset_password.php?method=' . htmlspecialchars($_SESSION['reset_method'] ?? 'phone'));
            exit();
        }
        
    } else {
        // Timer has passed, or it's a new request.
        $userManager = new UserManager();
        
        // Determine the identifier (either from new form or session for resend)
        $identifier = '';
        if ($action === 'request') {
            $identifier = $_POST['identifier'];
        } elseif ($action === 'resend' && isset($_SESSION['reset_identifier'])) {
            $identifier = $_SESSION['reset_identifier'];
        }

        if (empty($identifier)) {
             $error_message = "An error occurred. Please try again from the beginning.";
        
        } else {
            // Unset any previous attempts (but not timers, the timer check already happened)
            unset($_SESSION['reset_in_progress']); 

            if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
                $method = 'email';
                $token = $userManager->requestEmailReset($identifier);
                if ($token) {
                    // Set session flag and redirect
                    $_SESSION['reset_in_progress'] = true;
                    $_SESSION['resend_available_at'] = time() + 180; // 3 minutes
                    $_SESSION['reset_started_at'] = time(); // --- ADDED ---
                    $_SESSION['reset_identifier'] = $identifier;
                    $_SESSION['reset_method'] = $method;
                    header('Location: reset_password.php?method=email'); // Redirect to reset page
                    exit();
                } else {
                    $error_message = "No account found with that email address.";
                }
            } else {
                $method = 'phone';
                $otp = $userManager->requestPhoneReset($identifier);
                if ($otp) {
                    // Set session flag and redirect
                    $_SESSION['reset_in_progress'] = true;
                    $_SESSION['resend_available_at'] = time() + 180; // 3 minutes
                    $_SESSION['reset_started_at'] = time(); // --- ADDED ---
                    $_SESSION['reset_identifier'] = $identifier;
                    $_SESSION['reset_method'] = $method;
                    header('Location: reset_password.php?method=phone'); // Redirect to reset page
                    exit();
                } else {
                    $error_message = "No account found with that phone number.";
                }
            }
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
          <h2>Reset Password</h2>
          
          <form action="forgot_password.php" method="POST">
            <input type="hidden" name="action" value="request">
            
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

          <?php 
            // --- ADDED: Conditional "Go Back" button ---
            // Show this button if an error occurred BUT a valid reset is still in progress
            if (!empty($error_message) && isset($_SESSION['reset_in_progress']) && $_SESSION['reset_in_progress'] === true):
              $method = $_SESSION['reset_method'] ?? 'phone';
          ?>
            <hr>
            <div class="text-center">
              <p class="text-muted small">Made a typo?</p>
              <a href="reset_password.php?method=<?php echo htmlspecialchars($method); ?>" class="btn btn-outline-secondary btn-sm">
                Go Back to Code Entry
              </a>
            </div>
          <?php endif; ?>
          </div>
      </div>
    </div>
  </div>
</body>
</html>