<?php
session_start();
require_once '../src/InventoryManager.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$inventoryManager = new InventoryManager();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'restock_ingredient') {
        $id = $_POST['ingredient_id'];
        $qty = floatval($_POST['quantity']);
        $expiration_date = $_POST['expiration_date'] ?? null;
        
        // Basic Validation
        if ($qty <= 0) {
            $_SESSION['flash_message'] = "Quantity must be greater than zero.";
            $_SESSION['flash_type'] = "danger";
        } else {
            $reason = "[Restock] Manual Restock";
            $result = $inventoryManager->adjustIngredientStock($id, $_SESSION['user_id'], $qty, $reason, $expiration_date);

            if ($result['success']) {
                $_SESSION['flash_message'] = "Stock added successfully.";
                $_SESSION['flash_type'] = "success";
            } else {
                $_SESSION['flash_message'] = "Error: " . $result['message'];
                $_SESSION['flash_type'] = "danger";
            }
        }
        header('Location: inventory_management.php');
        exit();
    }
}

header('Location: inventory_management.php');
exit();
?>