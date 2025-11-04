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
    <title>BREADLY Main Menu</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles.css"> 
    
    <style>
        .content-box form {
            margin-bottom: 1rem;
        }
        .content-box .btn {
            width: 100%;
            max-width: 350px; /* Prevent buttons from being too wide */
            padding: 0.75rem;
            border-radius: 0.5rem;
            font-size: 1.1rem;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        .btn-pos {
            background-color: #198754; border-color: #198754; color: white;
        }
        .btn-pos:hover {
            background-color: #157347; border-color: #146c43; color: white;
        }
        .btn-dash {
            background-color: #0d6efd; border-color: #0d6efd; color: white;
        }
        .btn-dash:hover {
            background-color: #0b5ed7; border-color: #0a58ca; color: white;
        }
        .btn-logout {
            background-color: transparent;
            border: 2px solid #dc3545;
            color: #dc3545;
        }
        .btn-logout:hover {
            background-color: #dc3545;
            border-color: #dc3545;
            color: white;
        }
    </style>
</head>

<body class="page-landing">

    <div class="container-fluid landing-page">
        <div class="row d-flex">

            <div class="col-6 d-flex align-items-center text-column">
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

            <div class="col-6 bakery-column">
                <img src="../images/landingbakery.jpg" alt="KZ and Khyle's Front" class="bakery-img">
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>