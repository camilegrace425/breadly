<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php'); // Redirect to login.php in the same /nav/ folder
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
    <title>Main Menu</title>
    <link rel="icon" href="../images/kzklogo.png" type="image/x-icon"> 
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/global.css"> 
    <link rel="stylesheet" href="../styles/index.css"> 

</head>

<body class="page-landing">

    <div class="container-fluid landing-page">
        <div class="row d-flex">

            <div class="col-12 col-lg-6 d-flex align-items-center text-column">
                <div class="content-box w-100">

                    <h1 class="fw-bold mb-2">
                        Welcome, <br> <?php echo htmlspecialchars($username); ?>
                    </h1>

                    <p class="mb-4 description-text">
                        You are logged in as: <strong><?php echo htmlspecialchars(ucfirst($role)); ?></strong>
                    </p>

                    <?php if ($role == 'cashier' || $role == 'manager'): ?>
                        <form action="pos.php" method="get">
                            <button type="submit" class="btn btn-pos fw-bold">Go to Point of Sale (POS)</button>
                        </form>
                    <?php endif; ?>
                    
                    <?php if ($role == 'manager'): ?>
                        <form action="dashboard_panel.php" method="get">
                            <button type="submit" class="btn btn-dash fw-bold">Manager Dashboard</button>
                        </form>
                    <?php endif; ?>

                    <form action="logout.php" method="post">
                        <button type="submit" class="btn btn-logout fw-bold">Logout</button>
                    </form>
                    </div>
            </div>

            <div class="col-12 col-lg-6 bakery-column">
                <img src="../images/landingbakery.jpg" alt="KZ and Khyle's Front" class="bakery-img">
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>