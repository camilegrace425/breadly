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
        return $stmt->execute([$name, $unit, $stock_qty, $reorder_level]);
    }

    // Updates an existing ingredient. Calls: IngredientUpdate(?, ?, ?, ?)
    public function ingredientUpdate($ingredient_id, $name, $unit, $reorder_level) {
        $stmt = $this->conn->prepare("CALL IngredientUpdate(?, ?, ?, ?)");
        return $stmt->execute([$ingredient_id, $name, $unit, $reorder_level]);
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

    // Adds stock to an existing ingredient. Calls: IngredientRestock(?, ?)
    public function restockIngredient($ingredient_id, $added_qty) {
        $stmt = $this->conn->prepare("CALL IngredientRestock(?, ?)");
        return $stmt->execute([$ingredient_id, $added_qty]);
    }

    // Adds an ingredient to a product's recipe. Calls: RecipeAddIngredient(?, ?, ?, ?)
    public function addIngredientToRecipe($product_id, $ingredient_id, $qty_needed, $unit) {
        $stmt = $this->conn->prepare("CALL RecipeAddIngredient(?, ?, ?, ?)");
        return $stmt->execute([$product_id, $ingredient_id, $qty_needed, $unit]);
    }

    // Gets the full recipe for a single product. Calls: RecipeGetForProduct(?)
    public function getRecipeForProduct($product_id) {
        $stmt = $this->conn->prepare("CALL RecipeGetForProduct(?)");
        $stmt->execute([$product_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Manually triggers a scan to find all low-stock ingredients. Calls: IngredientCheckLowStock()
    public function checkLowStock() {
        $stmt = $this->conn->prepare("CALL IngredientCheckLowStock()");
        return $stmt->execute();
    }

    // Marks a specific low-stock alert as resolved. Calls: AlertMarkResolved(?)
    public function resolveAlert($alert_id) {
        $stmt = $this->conn->prepare("CALL AlertMarkResolved(?)");
        return $stmt->execute([$alert_id]);
    }

    // Adds a new finished product. Calls: ProductAdd(?, ?)
    public function addProduct($name, $price) {
        $stmt = $this->conn->prepare("CALL ProductAdd(?, ?)");
        return $stmt->execute([$name, $price]);
    }



    // Updates an existing product's details. Calls: ProductUpdate(?, ?, ?, ?)
    public function productUpdate($product_id, $name, $price, $status) {
        $stmt = $this->conn->prepare("CALL ProductUpdate(?, ?, ?, ?)");
        return $stmt->execute([$product_id, $name, $price, $status]);
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


    // Manually adjusts product stock for spoilage, etc. Calls: ProductAdjustStock(?, ?, ?)
    public function adjustProductStock($product_id, $adjustment_qty, $reason) {
        try {
            $stmt = $this->conn->prepare("CALL ProductAdjustStock(?, ?, ?)");
            // Just return whether the execute command itself succeeded or failed
            $success = $stmt->execute([$product_id, $adjustment_qty, $reason]);
            $stmt->closeCursor(); // Close cursor after execution
            return $success; // Return true if execute worked, false if it failed
        } catch (PDOException $e) {
            // Log error for debugging
            error_log("Error adjusting stock: " . $e->getMessage());
            return false;
        }
    }

    // Records a baking run, deducting ingredients and adding product stock. Returns status message.
    public function recordBaking($product_id, $qty_baked) {
         try {
            $stmt = $this->conn->prepare("CALL ProductionRecordBaking(?, ?, @status)");
            $stmt->execute([$product_id, $qty_baked]);
            $stmt->closeCursor();

            $status_stmt = $this->conn->query("SELECT @status AS status");
            $result = $status_stmt->fetch(PDO::FETCH_ASSOC);
            $status_stmt->closeCursor();

            return $result['status'] ?? 'Error: Unknown status.';
        } catch (PDOException $e) {
            return 'Error: ' . $e->getMessage();
        }
    }

    // Records a new sale transaction, deducting product stock. Returns status message.
    public function recordSale($user_id, $product_id, $qty_sold) {
        try {
            $stmt = $this->conn->prepare("CALL SaleRecordTransaction(?, ?, ?, @status, @sale_id)");
            $success = $stmt->execute([$user_id, $product_id, $qty_sold]);

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
}
?>