<?php
session_start();
require_once '../src/InventoryManager.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Access Control
if (!in_array($_SESSION['role'], ['manager', 'assistant_manager'])) {
    header('Location: ../index.php');
    exit();
}

$inventoryManager = new InventoryManager();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'restock_ingredient') {
        $id = $_POST['ingredient_id'];
        $qty = floatval($_POST['quantity']);
        $expiration_date = $_POST['expiration_date'] ?? null;
        
        if ($qty <= 0) {
            $_SESSION['flash_message'] = "Quantity must be greater than zero.";
            $_SESSION['flash_type'] = "danger";
        } else {
            $reason = "[Restock] Manual Restock";
            $result = $inventoryManager->adjustIngredientStock($id, $_SESSION['user_id'], $qty, $reason, $expiration_date);

            $_SESSION['flash_message'] = $result['success'] ? "Stock added successfully." : "Error: " . $result['message'];
            $_SESSION['flash_type'] = $result['success'] ? "success" : "danger";
        }
        header('Location: inventory_management.php');
        exit();
    }
}

header('Location: inventory_management.php');
exit();
?>