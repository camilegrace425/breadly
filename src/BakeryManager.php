<?php
require_once '../db_connection.php';

// Manages all core bakery operations (CRUD, production, sales) by calling stored procedures.
class BakeryManager {
    private $conn;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->getConnection();
    }

    // Adds a new ingredient to the database. Calls: IngredientAdd(?, ?, ?, ?)
    public function addIngredient($name, $unit, $stock_qty, $reorder_level) {
        $stmt = $this->conn->prepare("CALL IngredientAdd(?, ?, ?, ?)");
        $success = $stmt->execute([$name, $unit, $stock_qty, $reorder_level]);
        $stmt->closeCursor();
        return $success;
    }

    // Updates an existing ingredient. Calls: IngredientUpdate(?, ?, ?, ?)
    public function ingredientUpdate($ingredient_id, $name, $unit, $reorder_level) {
        $stmt = $this->conn->prepare("CALL IngredientUpdate(?, ?, ?, ?)");
        $success = $stmt->execute([$ingredient_id, $name, $unit, $reorder_level]);
        $stmt->closeCursor();
        return $success;
    }

    // Safely deletes an ingredient. Returns the status message from the procedure.
    public function ingredientDelete($ingredient_id) {
        try {
            $stmt = $this->conn->prepare("CALL IngredientDelete(?, @p_status)");
            $stmt->execute([$ingredient_id]);
            $stmt->closeCursor();

            $status_stmt = $this->conn->query("SELECT @p_status AS status");
            $result = $status_stmt->fetch(PDO::FETCH_ASSOC);
            $status_stmt->closeCursor();

            return $result['status'] ?? 'Error: Unknown status.';
        } catch (PDOException $e) {
            return 'Error: ' . $e->getMessage();
        }
    }

    // --- ::: MODIFIED: Replaced restockIngredient ::: ---
    // Adds/Removes stock from an existing ingredient. Calls: IngredientAdjustStock(?, ?, ?, ?)
    public function adjustIngredientStock($ingredient_id, $user_id, $added_qty, $reason) {
        try {
            $stmt = $this->conn->prepare("CALL IngredientAdjustStock(?, ?, ?, ?)");
            $stmt->execute([$ingredient_id, $user_id, $added_qty, $reason]);
            $stmt->closeCursor();
            return "Success: Ingredient stock adjusted."; // Return a success string
        } catch (PDOException $e) {
            // Log the actual database error for debugging
            error_log("Error in adjustIngredientStock: " . $e->getMessage());
            // Return an error string for the controller
            return "Error: " . $e->getMessage();
        }
    }

    // Manually triggers a scan to find all low-stock ingredients. Calls: IngredientCheckLowStock()
    public function checkLowStock() {
        $stmt = $this->conn->prepare("CALL IngredientCheckLowStock()");
        return $stmt->execute();
    }

    // --- MODIFIED: Added $image_url ---
    // Adds a new finished product. Calls: ProductAdd(?, ?, ?)
    public function addProduct($name, $price, $image_url) {
        $stmt = $this->conn->prepare("CALL ProductAdd(?, ?, ?)");
        $success = $stmt->execute([$name, $price, $image_url]);
        $stmt->closeCursor();
        return $success;
    }

    // --- MODIFIED: Added $image_url ---
    // Updates an existing product's details. Calls: ProductUpdate(?, ?, ?, ?, ?)
    public function productUpdate($product_id, $name, $price, $status, $image_url) {
        $stmt = $this->conn->prepare("CALL ProductUpdate(?, ?, ?, ?, ?)");
        $success = $stmt->execute([$product_id, $name, $price, $status, $image_url]);
        $stmt->closeCursor();
        return $success;
    }
    
    // Safely deletes a product. Returns the status message from the procedure.
    public function productDelete($product_id) {
        try {
            $stmt = $this->conn->prepare("CALL ProductDelete(?, @p_status)");
            $stmt->execute([$product_id]);
            $stmt->closeCursor();

            $status_stmt = $this->conn->query("SELECT @p_status AS status");
            $result = $status_stmt->fetch(PDO::FETCH_ASSOC);
            $status_stmt->closeCursor();

            return $result['status'] ?? 'Error: Unknown status.';
        } catch (PDOException $e) {
            return 'Error: Could not delete product. ' . $e->getMessage();
        }
    }


    // Manually adjusts product stock. 
    public function adjustProductStock($product_id, $user_id, $adjustment_qty, $reason) {
        try {
            $stmt = $this->conn->prepare("CALL ProductAdjustStock(?, ?, ?, ?, @status)");
            $stmt->execute([$product_id, $user_id, $adjustment_qty, $reason]);
            $stmt->closeCursor();

            $status_stmt = $this->conn->query("SELECT @status AS status");
            $result = $status_stmt->fetch(PDO::FETCH_ASSOC);
            $status_stmt->closeCursor();

            return $result['status'] ?? 'Error: Unknown status.';
        
        } catch (PDOException $e) {
            error_log("Error adjusting stock: " . $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }

    // Records a new sale transaction, deducting product stock. Returns status message.
    public function recordSale($user_id, $product_id, $qty_sold, $discount_percent = 0) {
        try {
            $stmt = $this->conn->prepare("CALL SaleRecordTransaction(?, ?, ?, ?, @status, @sale_id)");
            $success = $stmt->execute([$user_id, $product_id, $qty_sold, $discount_percent]);

            if (!$success) {
                return 'Error: Execution failed.';
            }
            
            $stmt->closeCursor();
            $status_stmt = $this->conn->query("SELECT @status AS status, @sale_id AS sale_id");
            $result = $status_stmt->fetch(PDO::FETCH_ASSOC);
            $status_stmt->closeCursor();

            return $result['status'] ?? 'Error: Unknown status.';

        } catch (PDOException $e) {
            return 'Error: ' . $e->getMessage();
        }
    }
    
    // Processes a return against a specific sale
    public function returnSale($sale_id, $user_id, $return_qty, $reason) {
         try {
            $stmt = $this->conn->prepare("CALL SaleProcessReturn(?, ?, ?, ?)");
            $stmt->execute([$sale_id, $user_id, $return_qty, $reason]);
            $stmt->closeCursor();
            return "Success: Return processed. Stock has been updated.";
        
        } catch (PDOException $e) {
            error_log("Error processing sale return: " . $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }

    // Initiates a product recall. Calls: RecallInitiate(?, ?, ?, ?)
    public function initiateRecall($product_id, $reason, $batch_start_date, $batch_end_date) {
        $stmt = $this->conn->prepare("CALL RecallInitiate(?, ?, ?, ?)");
        return $stmt->execute([$product_id, $reason, $batch_start_date, $batch_end_date]);
    }

    // Logs the physical removal of recalled items from stock. Calls: RecallLogRemoval(?, ?, ?, ?)
    public function logRecallRemoval($recall_id, $user_id, $qty_removed, $notes) {
        $stmt = $this->conn->prepare("CALL RecallLogRemoval(?, ?, ?, ?)");
        return $stmt->execute([$recall_id, $user_id, $qty_removed, $notes]);
    }

    // Gets all available products for the recipe dropdown.
    public function getAllProductsSimple() {
        $stmt = $this->conn->prepare("CALL ProductGetAllSimple()");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Gets all ingredients for the recipe dropdown.
    public function getAllIngredientsSimple() {
        $stmt = $this->conn->prepare("CALL IngredientGetAllSimple()");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Gets the current recipe for a single product.
    public function getRecipeForProduct($product_id) {
        $stmt = $this->conn->prepare("CALL RecipeGetByProductId(?)");
        $stmt->execute([$product_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Adds a new ingredient to a product's recipe.
    public function addIngredientToRecipe($product_id, $ingredient_id, $qty_needed, $unit) {
        $stmt = $this->conn->prepare("CALL RecipeAddIngredient(?, ?, ?, ?)");
        return $stmt->execute([$product_id, $ingredient_id, $qty_needed, $unit]);
    }

    // Removes an ingredient from a product's recipe.
    public function removeIngredientFromRecipe($recipe_id) {
        $stmt = $this->conn->prepare("CALL RecipeRemoveIngredient(?)");
        return $stmt->execute([$recipe_id]);
    }
    
    // Updates the batch size for a product.
    public function updateProductBatchSize($product_id, $batch_size) {
        $stmt = $this->conn->prepare("CALL ProductUpdateBatchSize(?, ?)");
        return $stmt->execute([$product_id, $batch_size]);
    }
}
?>