<?php
session_start();
require_once '../src/UserManager.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard_panel.php');
    exit();
}

// Handle Cancel Action
if (isset($_GET['action']) && $_GET['action'] === 'cancel') {
    $vars_to_unset = ['reset_in_progress', 'resend_available_at', 'reset_identifier', 'reset_method', 'reset_started_at', 'email_sent_success', 'error_message'];
    foreach ($vars_to_unset as $var) unset($_SESSION[$var]);
    header('Location: login.php');
    exit();
}

// Check if reset is already in progress (Auto-redirect)
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
                    $_SESSION['reset_in_progress'] = true;
                    $_SESSION['resend_available_at'] = time() + 180; 
                    $_SESSION['reset_started_at'] = time(); 
                    $_SESSION['reset_identifier'] = $identifier;
                    $_SESSION['reset_method'] = 'email';
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
                    $_SESSION['reset_in_progress'] = true;
                    $_SESSION['resend_available_at'] = time() + 180; 
                    $_SESSION['reset_started_at'] = time(); 
                    $_SESSION['reset_identifier'] = $identifier;
                    $_SESSION['reset_method'] = 'phone';
                    header('Location: reset_password.php?method=phone'); 
                    exit();
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Forgot Password</title>
    <link rel="icon" href="../images/kzklogo.png" type="image/x-icon"> 
    
    <link rel="stylesheet" href="../styles/global.css">

    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Poppins', 'sans-serif'] },
                    colors: {
                        breadly: {
                            bg: '#FFFBF5',
                            panel: '#E4A26C',
                            btn: '#af6223',
                            'btn-hover': '#9b4a10',
                            dark: '#333333',
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-breadly-bg min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-md animate-slide-in">
        <div class="bg-white rounded-2xl shadow-xl border border-orange-100 overflow-hidden p-8">
            
            <div class="flex flex-col items-center justify-center mb-6">
                <img src="../images/breadlylogo.png" alt="Breadly Bakery Logo" class="h-24 w-auto mb-4 object-contain"/>
                <h2 class="text-2xl font-bold text-gray-800">Forgot Password</h2>
                <p class="text-sm text-gray-500 text-center mt-2 px-4">Enter your email or phone number to receive a password reset code.</p>
            </div>

            <form action="forgot_password.php" method="POST" class="space-y-6">
                <input type="hidden" name="action" value="request">
                
                <div>
                    <label for="identifier" class="block text-sm font-medium text-gray-700 mb-1">Phone Number or Email</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class='bx bx-user text-gray-400 text-xl'></i>
                        </div>
                        <input type="text" class="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-300 rounded-lg focus:ring-2 focus:ring-breadly-btn focus:border-breadly-btn outline-none transition-all" 
                               id="identifier" name="identifier" placeholder="e.g. 0917... or user@email.com" required />
                    </div>
                </div>

                <button type="submit" class="w-full py-3 bg-breadly-btn text-white font-bold rounded-lg shadow-md hover:bg-breadly-btn-hover hover:shadow-lg transition-all transform hover:-translate-y-0.5">
                    Send Reset Code
                </button>
                
                <div class="text-center pt-2">
                    <a href="forgot_password.php?action=cancel" class="text-sm font-medium text-gray-600 hover:text-breadly-btn transition-colors flex items-center justify-center gap-1">
                        <i class='bx bx-arrow-back'></i> Back to Login
                    </a>
                </div>
            </form>

            <?php if (!empty($error_message) && isset($_SESSION['reset_in_progress'])): ?>
                <div class="mt-6 pt-6 border-t border-gray-100 text-center">
                    <p class="text-xs text-gray-500 mb-2">Made a typo or need to enter code?</p>
                    <a href="reset_password.php" class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                        Go Back to Code Entry
                    </a>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
      document.addEventListener('DOMContentLoaded', function() {
        <?php if (!empty($error_message)): ?>
          Swal.fire({
            icon: 'error',
            title: 'Error',
            text: '<?php echo addslashes($error_message); ?>',
            confirmButtonColor: '#af6223',
            confirmButtonText: 'Try Again'
          });
        <?php endif; ?>
      });
    </script>
</body>
</html>