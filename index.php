<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$username = $_SESSION['username'];
$role = $_SESSION['role'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Bakery System Panel</title>
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" />
  <style>
    body {
      background-color: #fdfaf6;
    }
    .system-name {
      text-align: center;
      font-size: 2rem;
      margin: 2rem 0;
      font-weight: bold;
      color: #6a381f;
    }
    .welcome-user {
      text-align: center;
      font-size: 1.2rem;
      margin-bottom: 2rem;
    }
    .button-row {
      max-width: 400px;
      margin: auto;
      padding: 2rem;
      background-color: white;
      border-radius: 0.75rem;
      box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
    }
    .button-row form {
      margin-bottom: 1rem;
    }
    .button-row button {
      width: 100%;
      font-size: 1.1rem;
      padding: 0.75rem;
    }
  </style>
</head>
<body>
  
  <main>
    <div class="system-name">BAKERY MANAGEMENT SYSTEM</div>
    <div class="welcome-user">
      Welcome, <strong><?php echo htmlspecialchars($username); ?></strong> (<?php echo htmlspecialchars($role); ?>)
    </div>

    <div class="button-row">
    
      <?php if ($role == 'cashier' || $role == 'manager'): ?>
        <form action="pos.php" method="get">
          <button type="submit" class="btn btn-success">Go to Point of Sale (POS)</button>
        </form>
      <?php endif; ?>
      
      <?php if ($role == 'manager'): ?>
        <form action="dashboard_panel.php" method="get">
          <button type="submit" class="btn btn-primary">Manager Dashboard</button>
        </form>
      <?php endif; ?>

      <form action="logout.php" method="post">
        <button type="submit" class="btn btn-outline-danger">Logout</button>
      </form>
    </div>
  </main>
</body>
</html>