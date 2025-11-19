<?php
session_start();
require_once '../src/UserManager.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard_panel.php');
    exit();
}

// --- ADDED: Handle Cancel Action ---
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
// -----------------------------------

// Check if reset is already in progress (Auto-redirect if so)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_SESSION['reset_in_progress']) && $_SESSION['reset_in_progress'] === true) {
        $reset_started_at = $_SESSION['reset_started_at'] ?? 0;
        if ((time() - $reset_started_at) < 300) { 
            $method = $_SESSION['reset_method'] ?? 'phone';
            header('Location: reset_password.php?method=' . htmlspecialchars($method));
            exit();
        }
    }
}

$error_message = '';
$action = $_POST['action'] ?? 'request'; 

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $resend_available_at = $_SESSION['resend_available_at'] ?? 0;
    $time_now = time();

    if ($time_now < $resend_available_at) {
        $wait_time = $resend_available_at - $time_now;
        $error_message = "Please wait $wait_time more seconds before resending.";
        if ($action === 'resend') {
            $_SESSION['error_message'] = $error_message;
            header('Location: reset_password.php?method=' . htmlspecialchars($_SESSION['reset_method'] ?? 'phone'));
            exit();
        }
    } else {
        $userManager = new UserManager();
        $identifier = ($action === 'request') ? $_POST['identifier'] : ($_SESSION['reset_identifier'] ?? '');

        if (empty($identifier)) {
             $error_message = "An error occurred. Please try again.";
        } else {
            unset($_SESSION['reset_in_progress']); 

            if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
                $result = $userManager->requestEmailReset($identifier);
                if ($result === 'USER_NOT_FOUND') {
                    $error_message = "No account found with that email address.";
                } elseif ($result === 'EMAIL_FAILED') {
                    $error_message = "Failed to send email. Please try again later.";
                } else {
                    $method = 'email';
                    $_SESSION['reset_in_progress'] = true;
                    $_SESSION['resend_available_at'] = time() + 180; 
                    $_SESSION['reset_started_at'] = time(); 
                    $_SESSION['reset_identifier'] = $identifier;
                    $_SESSION['reset_method'] = $method;
                    $_SESSION['email_sent_success'] = true; 
                    header('Location: reset_password.php?method=email');
                    exit();
                }
            } else {
                $result = $userManager->requestPhoneReset($identifier);
                if ($result === 'USER_NOT_FOUND') {
                    $error_message = "No account found with that phone number.";
                } elseif ($result === 'SMS_FAILED') {
                    $error_message = "System Error: SMS sending failed. Please contact support.";
                } else {
                    $method = 'phone';
                    $_SESSION['reset_in_progress'] = true;
                    $_SESSION['resend_available_at'] = time() + 180; 
                    $_SESSION['reset_started_at'] = time(); 
                    $_SESSION['reset_identifier'] = $identifier;
                    $_SESSION['reset_method'] = $method;
                    header('Location: reset_password.php?method=phone'); 
                    exit();
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Forgot Password</title>
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
          <section class="login-card p-4 p-md-5">
            <div class="d-flex align-items-center justify-content-center mb-4">
              <img src="../images/breadlylogo.png" alt="Breadly Bakery Logo" class="img-fluid me-3" style="max-height: 95px"/>
            </div>
            <h2 class="login-heading text-center mb-4">Forgot Password</h2>

            <form action="forgot_password.php" method="POST">
              <input type="hidden" name="action" value="request">
              
              <div class="form-floating mb-3">
                <input type="text" class="form-control" id="identifier" name="identifier" placeholder="Enter your Phone Number" required />
                <label for="identifier">Phone Number or Email</label>
              </div>
              <div class="text-center">
                <button type="submit" class="btn login-btn btn-lg w-100">Send Reset Code</button>
              </div>
                
                <p class="forgot text-center mt-3 mb-0">
                  <a href="forgot_password.php?action=cancel" class="text-decoration-none">Back to Login</a>
                </p>
                
            </form>

            <?php if (!empty($error_message) && isset($_SESSION['reset_in_progress'])): ?>
              <hr>
              <div class="text-center">
                <p class="text-muted small">Made a typo?</p>
                <a href="reset_password.php" class="btn btn-outline-secondary btn-sm">Go Back to Code Entry</a>
              </div>
            <?php endif; ?>

          </section>
        </div>
      </div>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
      document.addEventListener('DOMContentLoaded', function() {
        <?php if (!empty($error_message)): ?>
          Swal.fire({
            icon: 'error',
            title: 'Error',
            text: '<?php echo addslashes($error_message); ?>',
            confirmButtonColor: '#d33',
            confirmButtonText: 'Try Again'
          });
        <?php endif; ?>
      });
    </script>
  </body>
</html>