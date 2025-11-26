<?php
session_start();
require_once "../src/InventoryManager.php";

// 1. Security Check
if (!isset($_SESSION["user_id"])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if (!in_array($_SESSION['role'], ['manager', 'assistant_manager'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

// 2. Validation
if (!isset($_GET['ingredient_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing ingredient ID']);
    exit();
}

// 3. Fetch Data
try {
    $inventoryManager = new InventoryManager();
    $batches = $inventoryManager->getIngredientBatches($_GET['ingredient_id']);
    
    header('Content-Type: application/json');
    echo json_encode($batches);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>