<?php
session_start();
require_once '../src/InventoryManager.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing Ingredient ID']);
    exit();
}

$inventoryManager = new InventoryManager();
$batches = $inventoryManager->getIngredientBatches($_GET['id']);

echo json_encode($batches);
?>