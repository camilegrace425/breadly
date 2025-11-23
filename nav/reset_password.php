<?php
session_start();
require_once '../src/UserManager.php';

if (isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

// --- ADDED: Handle Cancel/Exit Action ---
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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Reset Password</title>
    <link rel="icon" href="../images/kzklogo.png" type="image/x-icon"> 
    
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

    <div class="w-full max-w-md">
        <div class="bg-white rounded-2xl shadow-xl border border-orange-100 overflow-hidden p-8">
            
            <div class="flex flex-col items-center justify-center mb-6">
                <img src="../images/breadlylogo.png" alt="Breadly Bakery Logo" class="h-24 w-auto mb-4 object-contain"/>
                <h2 class="text-2xl font-bold text-gray-800">Set New Password</h2>
                <p class="text-sm text-gray-500 text-center mt-2 px-4"><?php echo htmlspecialchars($instruction_text); ?></p>
            </div>

            <?php if (!empty($error_message)): ?>
                <div class="mb-4 p-3 bg-red-50 text-red-700 rounded-lg text-sm font-medium border border-red-200 text-center">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <form action="reset_password.php?method=<?php echo htmlspecialchars($method); ?>" method="POST" class="space-y-5">
                <input type="hidden" name="action" value="reset">
                
                <div>
                    <label for="otp_code" class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars($code_label); ?></label>
                    <input type="text" 
                           name="otp_code" 
                           id="otp_code" 
                           class="w-full pl-4 pr-4 py-3 bg-gray-50 border border-gray-300 rounded-lg focus:ring-2 focus:ring-breadly-btn focus:border-breadly-btn outline-none transition-all text-center font-mono text-lg tracking-widest" 
                           placeholder="<?php echo htmlspecialchars($placeholder_text); ?>" 
                           maxlength="6"
                           pattern="\d{6}"
                           title="Please enter a 6-digit code"
                           required />
                </div>

                <div>
                    <label for="new_password" class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                    <div class="relative">
                        <input type="password" class="w-full pl-4 pr-10 py-3 bg-gray-50 border border-gray-300 rounded-lg focus:ring-2 focus:ring-breadly-btn focus:border-breadly-btn outline-none transition-all" 
                               id="new_password" name="new_password" placeholder="New Password" required />
                        <div class="absolute inset-y-0 right-0 pr-3 flex items-center">
                            <input type="checkbox" onclick="togglePassword('new_password')" id="showNewPass" class="hidden peer">
                            <label for="showNewPass" class="cursor-pointer text-gray-400 hover:text-breadly-btn">
                                <i class='bx bx-hide text-xl'></i>
                            </label>
                        </div>
                    </div>
                </div>

                <div>
                    <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm Password</label>
                    <div class="relative">
                        <input type="password" class="w-full pl-4 pr-10 py-3 bg-gray-50 border border-gray-300 rounded-lg focus:ring-2 focus:ring-breadly-btn focus:border-breadly-btn outline-none transition-all" 
                               id="confirm_password" name="confirm_password" placeholder="Confirm Password" required />
                        <div class="absolute inset-y-0 right-0 pr-3 flex items-center">
                            <input type="checkbox" onclick="togglePassword('confirm_password')" id="showConfPass" class="hidden peer">
                            <label for="showConfPass" class="cursor-pointer text-gray-400 hover:text-breadly-btn">
                                <i class='bx bx-hide text-xl'></i>
                            </label>
                        </div>
                    </div>
                </div>

                <button type="submit" class="w-full py-3 bg-breadly-btn text-white font-bold rounded-lg shadow-md hover:bg-breadly-btn-hover hover:shadow-lg transition-all transform hover:-translate-y-0.5">
                    Update Password
                </button>
            </form>
            
            <form action="forgot_password.php" method="POST" class="mt-4 text-center">
                <input type="hidden" name="action" value="resend">
                <button type="submit" id="resend-button" class="text-sm font-medium text-breadly-btn hover:text-breadly-btn-hover underline disabled:opacity-50 disabled:cursor-not-allowed disabled:no-underline" disabled>
                    Resend Code
                </button>
                <span id="timer-text" class="text-xs text-gray-500 ml-1"></span>
            </form>

            <div class="text-center mt-6 pt-4 border-t border-gray-100">
                <p class="text-xs text-gray-500 mb-2">Remembered your password?</p>
                <a href="reset_password.php?action=cancel" class="inline-flex items-center gap-1 px-4 py-2 bg-gray-100 text-gray-600 rounded-lg text-sm font-medium hover:bg-gray-200 transition-colors">
                    <i class='bx bx-arrow-back'></i> Exit to Login
                </a>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    function togglePassword(inputId) {
        const input = document.getElementById(inputId);
        const label = document.querySelector(`label[for="${inputId === 'new_password' ? 'showNewPass' : 'showConfPass'}"] i`);
        
        if (input.type === "password") {
            input.type = "text";
            label.classList.remove('bx-hide');
            label.classList.add('bx-show');
        } else {
            input.type = "password";
            label.classList.remove('bx-show');
            label.classList.add('bx-hide');
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        <?php if ($show_email_success): ?>
            Swal.fire({
                title: 'Email Sent!',
                text: 'Please check your inbox for the 6-digit verification code.',
                icon: 'success',
                confirmButtonText: 'OK',
                confirmButtonColor: '#af6223'
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