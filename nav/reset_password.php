<?php
session_start();
require_once '../src/UserManager.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// --- ADDED: Handle Cancel/Exit Action ---
// This clears the session so the user isn't "stuck" in the reset flow if they return.
if (isset($_GET['action']) && $_GET['action'] === 'cancel') {
    unset($_SESSION['reset_in_progress']);
    unset($_SESSION['resend_available_at']);
    unset($_SESSION['reset_identifier']);
    unset($_SESSION['reset_method']);
    unset($_SESSION['reset_started_at']);
    unset($_SESSION['email_sent_success']);
    unset($_SESSION['error_message']);
    header('Location: login.php');
    exit();
}
// ----------------------------------------

if (!isset($_SESSION['reset_in_progress']) || $_SESSION['reset_in_progress'] !== true) {
    header('Location: login.php');
    exit();
}

$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['error_message']); 

// Check for Success Flag
$show_email_success = false;
if (isset($_SESSION['email_sent_success'])) {
    $show_email_success = true;
    unset($_SESSION['email_sent_success']);
}

$method = $_GET['method'] ?? 'phone';
$code_label = '6-Digit OTP Code';
$placeholder_text = 'Enter 6-digit code';
$instruction_text = "Enter the 6-digit code sent to your " . ($method === 'email' ? 'email' : 'phone') . ".";

$resend_available_at = $_SESSION['resend_available_at'] ?? 0;
$time_now = time();
$resend_cooldown = $resend_available_at - $time_now;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset') {
    $otp_code = $_POST['otp_code'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if ($new_password !== $confirm_password) {
        $_SESSION['error_message'] = "The new passwords do not match. Please try again.";
        header('Location: reset_password.php?method=' . htmlspecialchars($method)); 
        exit();
    } 
        
    $userManager = new UserManager();
    $success = $userManager->resetPassword($otp_code, $new_password);

    if ($success) {
        $_SESSION['success_message'] = "Password reset successfully! You can now log in.";
        unset($_SESSION['reset_in_progress']); 
        unset($_SESSION['resend_available_at']); 
        unset($_SESSION['reset_identifier']); 
        unset($_SESSION['reset_method']); 
        unset($_SESSION['reset_started_at']); 
        header('Location: login.php'); 
        exit();
    } else {
        $_SESSION['error_message'] = "Invalid, expired, or already used code. Please request a new one.";
        header('Location: reset_password.php?method=' . htmlspecialchars($method)); 
        exit();
    }
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-tr" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Reset Password</title>
    <link rel="icon" href="../images/kzklogo.png" type="image/x-icon"> 
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
    <link rel="stylesheet" href="../styles/global.css"> 
    <link rel="stylesheet" href="../styles/forgot_password.css"> 
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  </head>
  <body class="page-forgot-password"> <main class="container py-4 py-md-5">
      <div class="row justify-content-center">
        <div class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-5">
          <section class="login-card p-4 p-md-5 shadow">
            <div class="d-flex align-items-center justify-content-center mb-4">
              <img src="../images/breadlylogo.png" alt="Breadly Bakery Logo" class="img-fluid me-3" style="max-height: 95px;">
            </div>
            <h2 class="login-heading text-center mb-4">Set New Password</h2>

            <form action="reset_password.php?method=<?php echo htmlspecialchars($method); ?>" method="POST">
              <input type="hidden" name="action" value="reset">

              <?php 
                if (!empty($error_message)) { 
                  echo '<div class="alert alert-danger">' . htmlspecialchars($error_message) . '</div>'; 
                }
              ?>
              
              <p class="text-center text-muted small"><?php echo htmlspecialchars($instruction_text); ?></p>

              <div class="form-floating mb-3">
                <input 
                  type="text" 
                  name="otp_code" 
                  class="form-control" 
                  id="otp_code"
                  placeholder="<?php echo htmlspecialchars($placeholder_text); ?>" 
                  maxlength="6"
                  pattern="\d{6}"
                  title="Please enter a 6-digit code"
                  required />
                <label for="otp_code"><?php echo htmlspecialchars($code_label); ?></label>
              </div>

              <div class="form-floating mb-3">
                <input type="password" class="form-control" id="new_password" name="new_password" placeholder="New Password" required>
                <label for="new_password">New Password</label>
              </div>

              <div class="form-floating mb-3">
                <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Confirm Password" required>
                <label for="confirm_password">Confirm Password</label>
              </div>
              
              <div class="d-flex justify-content-between">
                  <div class="form-check">
                      <input class="form-check-input" type="checkbox" onclick="newPassword()" id="showNewPass">
                      <label class="form-check-label" for="showNewPass" style="font-size: 0.9rem;">Show New Password</label>
                  </div>
                  <div class="form-check">
                      <input class="form-check-input" type="checkbox" onclick="confPassword()" id="showConfPass">
                      <label class="form-check-label" for="showConfPass" style="font-size: 0.9rem;">Show Confirm Password</label>
                  </div>
              </div>

              <div class="text-center mt-3">
                <button type="submit" class="btn login-btn btn-lg w-100">Update Password</button>
              </div>
            </form>
            
            <form action="forgot_password.php" method="POST" class="mt-3 text-center">
              <input type="hidden" name="action" value="resend">
              <button type="submit" id="resend-button" class="btn btn-link" disabled>Resend Code</button>
              <span id="timer-text" class="text-muted small"></span>
            </form>

            <div class="text-center mt-4">
                <p class="mb-0 small text-muted">Remembered your password?</p>
                <a href="reset_password.php?action=cancel" class="btn btn-outline-secondary btn-sm mt-1">
                    <i class="bi bi-arrow-left"></i> Exit to Login
                </a>
            </div>
            </section>
        </div>
      </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function newPassword() {
      var x = document.getElementById("new_password");
      x.type = (x.type === "password") ? "text" : "password";
    }

    function confPassword() {
      var x = document.getElementById("confirm_password");
      x.type = (x.type === "password") ? "text" : "password";
    }

    document.addEventListener('DOMContentLoaded', function() {
        <?php if ($show_email_success): ?>
            Swal.fire({
                title: 'Email Sent!',
                text: 'Please check your inbox for the 6-digit verification code.',
                icon: 'success',
                confirmButtonText: 'OK',
                confirmButtonColor: '#d97706'
            });
        <?php endif; ?>

        const resendButton = document.getElementById('resend-button');
        const timerText = document.getElementById('timer-text');
        let cooldown = <?php echo $resend_cooldown > 0 ? $resend_cooldown : 0; ?>;

        if (cooldown > 0) {
            resendButton.disabled = true;
            timerText.textContent = `(Wait ${cooldown}s)`;
            const interval = setInterval(() => {
                cooldown--;
                if (cooldown <= 0) {
                    clearInterval(interval);
                    timerText.textContent = '';
                    resendButton.disabled = false;
                } else {
                    timerText.textContent = `(Wait ${cooldown}s)`;
                }
            }, 1000);
        } else {
            resendButton.disabled = false;
            timerText.textContent = '';
        }
    });
  </script>
  </body>
</html>