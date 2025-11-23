<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: nav/login.php");
    exit();
}
if ($_SESSION["role"] !== "manager" && $_SESSION["role"] !== "assistant_manager" && $_SESSION["role"] !== "cashier") {
    header("Location: index.php"); // Not authorized (or redirect to error page)
    exit();
}

$username = $_SESSION['username'];
$role = $_SESSION['role'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Main Menu - Breadly</title>
    <link rel="icon" href="images/kzklogo.png" type="image/x-icon">
    
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    
    <script src="https://cdn.tailwindcss.com"></script>
    
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Lora:ital,wght@0,400;1,400&display=swap" rel="stylesheet">

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Poppins', 'sans-serif'],
                        serif: ['Lora', 'serif'],
                    },
                    colors: {
                        breadly: {
                            bg: '#F8F1E7',
                            panel: '#E4A26C',
                            btn: '#af6223',
                            'btn-hover': '#9b4a10',
                            dark: '#333333',
                            cream: '#FFFBF5'
                        }
                    }
                }
            }
        }
    </script>
</head>

<body class="h-screen overflow-hidden bg-breadly-bg text-breadly-dark">

    <div class="flex flex-col lg:flex-row h-full w-full">

        <div class="hidden lg:block lg:w-1/2 h-full shadow-2xl relative">
            <div class="absolute inset-0 bg-[url('images/landingbakery.jpg')] bg-cover bg-center">
                <div class="absolute inset-0 bg-black/20"></div>
            </div>
        </div>

        <div class="w-full lg:w-1/2 flex items-center justify-center p-8 lg:p-16 bg-breadly-bg relative z-10">
            <div class="w-full max-w-md space-y-6">
                
                <div class="mb-8">
                    <h1 class="text-4xl lg:text-5xl font-bold font-serif mb-2 text-gray-800 leading-tight">
                        Welcome, <br> <span class="text-breadly-btn"><?php echo htmlspecialchars($username); ?></span>
                    </h1>
                    <p class="text-lg text-gray-600">
                        You are logged in as: <strong class="text-breadly-dark uppercase tracking-wide"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $role))); ?></strong>
                    </p>
                </div>

                <div class="space-y-4">
                    <?php if ($role == 'cashier' || $role == 'manager' || $role == 'assistant_manager'): ?>
                    <form action="nav/pos.php" method="get">
                        <button type="submit" class="w-full py-4 px-6 bg-breadly-btn text-white font-bold rounded-xl shadow-lg hover:bg-breadly-btn-hover hover:shadow-xl transition-all duration-300 transform hover:-translate-y-1 flex items-center justify-center gap-2">
                            <i class='bx bxs-calculator text-xl'></i> Go to Point of Sale (POS)
                        </button>
                    </form>
                    <?php endif; ?>
                    
                    <?php if ($role == 'cashier'): ?>
                        <form action="nav/sales_history.php" method="get">
                            <button type="submit" class="w-full py-3 px-6 bg-white text-breadly-dark border-2 border-breadly-btn font-bold rounded-xl shadow-sm hover:bg-orange-50 transition-all duration-300 flex items-center justify-center gap-2">
                                <i class='bx bx-history text-xl'></i> Sales History
                            </button>
                        </form>
                    <?php endif; ?>
                    
                    <?php if ($role == 'manager' || $role == 'assistant_manager'): ?>
                        <form action="nav/dashboard_panel.php" method="get">
                            <button type="submit" class="w-full py-4 px-6 bg-gray-800 text-white font-bold rounded-xl shadow-lg hover:bg-black transition-all duration-300 flex items-center justify-center gap-2">
                                <i class='bx bxs-dashboard text-xl'></i> Manager Dashboard
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if ($role == 'manager'): ?>
                        <form action="nav/account_management.php" method="get">
                            <button type="submit" class="w-full py-3 px-6 bg-gray-200 text-gray-700 font-bold rounded-xl hover:bg-gray-300 transition-all duration-300 flex items-center justify-center gap-2">
                                <i class='bx bxs-user-account text-xl'></i> Account Management
                            </button>
                        </form>
                    <?php endif; ?>

                    <div class="pt-6 border-t border-orange-200">
                        <form action="nav/logout.php" method="post">
                            <button type="submit" class="w-full py-2 text-red-500 font-semibold hover:text-red-700 hover:underline transition-colors flex items-center justify-center gap-2">
                                <i class='bx bx-log-out'></i> Logout
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

    </div>

</body>
</html>