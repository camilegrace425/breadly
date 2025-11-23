<?php
// File Location: breadly/nav/logout.php

session_start();

// Unset all session values
$_SESSION = array();

// Delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logging Out...</title>
    <link rel="icon" href="../images/kzklogo.png" type="image/x-icon">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        breadly: {
                            bg: '#F8F1E7',
                            btn: '#af6223',
                        }
                    }
                }
            }
        }
    </script>
    <meta http-equiv="refresh" content="1;url=login.php">
</head>
<body class="bg-breadly-bg h-screen flex flex-col items-center justify-center">
    <div class="bg-white p-8 rounded-2xl shadow-lg flex flex-col items-center border border-orange-100">
        <div class="w-12 h-12 border-4 border-breadly-btn border-t-transparent rounded-full animate-spin mb-4"></div>
        <h2 class="text-xl font-bold text-gray-800">Logging out...</h2>
        <p class="text-sm text-gray-500 mt-2">Redirecting you to the login page.</p>
    </div>
    <script>
        setTimeout(() => { window.location.href = 'login.php'; }, 1000);
    </script>
</body>
</html>