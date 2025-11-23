<?php
require_once 'AbstractManager.php';
require_once 'ListableData.php';

class BakeryManager extends AbstractManager implements ListableData {
    public function fetchAllData(): array {
        return $this->getAllProductsSimple();
    }

    // --- INGREDIENT METHODS ---

    public function addIngredient($name, $unit, $stock_qty, $reorder_level) {
        $check = $this->conn->prepare("SELECT COUNT(*) FROM ingredients WHERE LOWER(name) = LOWER(?)");
        $check->execute([$name]);
        if ($check->fetchColumn() > 0) {
            return "duplicate";
        }

        $stmt = $this->conn->prepare("CALL IngredientAdd(?, ?, ?, ?)");
        $stmt->execute([$name, $unit, $stock_qty, $reorder_level]);
        return "success";
    }

    public function ingredientUpdate($ingredient_id, $name, $unit, $reorder_level) {
        $stmt = $this->conn->prepare("CALL IngredientUpdate(?, ?, ?, ?)");
        return $stmt->execute([$ingredient_id, $name, $unit, $reorder_level]);
    }

    public function ingredientDelete($ingredient_id) {
        try {
            $stmt = $this->conn->prepare("CALL IngredientDelete(?, @p_status)");
            $stmt->execute([$ingredient_id]);
            $stmt->closeCursor();
            $status_stmt = $this->conn->query("SELECT @p_status AS status");
            $result = $status_stmt->fetch(PDO::FETCH_ASSOC);
            return $result['status'] ?? 'Error: Unknown status.';
        } catch (PDOException $e) {
            error_log("Error in ingredientDelete: " . $e->getMessage());
            return 'Error: A database error occurred during deletion.';
        }
    }

    public function adjustIngredientStock($ingredient_id, $user_id, $added_qty, $reason) {
        try {
            $stmt = $this->conn->prepare("CALL IngredientAdjustStock(?, ?, ?, ?)");
            $stmt->execute([$ingredient_id, $user_id, $added_qty, $reason]);
            return "Success: Ingredient stock adjusted.";
        } catch (PDOException $e) {
            error_log("Error in adjustIngredientStock: " . $e->getMessage());
            return "Error: " . $e->getMessage();
        }
    }

    public function checkLowStock() {
        $stmt = $this->conn->prepare("CALL IngredientCheckLowStock()");
        return $stmt->execute();
    }

    // --- PRODUCT METHODS ---

    public function addProduct($name, $price, $image_url) {
        $check = $this->conn->prepare("SELECT COUNT(*) FROM products WHERE LOWER(name) = LOWER(?) AND status != 'discontinued'");
        $check->execute([$name]);
        
        if ($check->fetchColumn() > 0) {
            return "duplicate";
        }

        $stmt = $this->conn->prepare("CALL ProductAdd(?, ?, ?)");
        $stmt->execute([$name, $price, $image_url]);
        return "success";
    }

    public function productUpdate($product_id, $name, $price, $status, $image_url) {
        $stmt = $this->conn->prepare("CALL ProductUpdate(?, ?, ?, ?, ?)");
        return $stmt->execute([$product_id, $name, $price, $status, $image_url]);
    }
    
    public function productDelete($product_id) {
        try {
            $stmt = $this->conn->prepare("CALL ProductDelete(?, @p_status)");
            $stmt->execute([$product_id]);
            $stmt->closeCursor();
            $status_stmt = $this->conn->query("SELECT @p_status AS status");
            $result = $status_stmt->fetch(PDO::FETCH_ASSOC);
            return $result['status'] ?? 'Error: Unknown status.';
        } catch (PDOException $e) {
            error_log("Error in productDelete: " . $e->getMessage());
            return 'Error: Could not delete product. A database error occurred.';
        }
    }

    public function adjustProductStock($product_id, $user_id, $adjustment_qty, $reason) {
        try {
            $stmt = $this->conn->prepare("CALL ProductAdjustStock(?, ?, ?, ?, @status)");
            $stmt->execute([$product_id, $user_id, $adjustment_qty, $reason]);
            $stmt->closeCursor();
            $status_stmt = $this->conn->query("SELECT @status AS status");
            $result = $status_stmt->fetch(PDO::FETCH_ASSOC);
            return $result['status'] ?? 'Error: Unknown status.';
        } catch (PDOException $e) {
            error_log("Error adjusting stock: " . $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }

    // --- SALES & RECALLS ---

    public function recordSale($user_id, $product_id, $qty_sold, $discount_percent = 0) {
        try {
            $stmt = $this->conn->prepare("CALL SaleRecordTransaction(?, ?, ?, ?, @status, @sale_id)");
            $success = $stmt->execute([$user_id, $product_id, $qty_sold, $discount_percent]);
            if (!$success) return 'Error: Execution failed.';
            $stmt->closeCursor();
            $status_stmt = $this->conn->query("SELECT @status AS status, @sale_id AS sale_id");
            $result = $status_stmt->fetch(PDO::FETCH_ASSOC);
            return $result['status'] ?? 'Error: Unknown status.';
        } catch (PDOException $e) {
            error_log("Error in recordSale: " . $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }
    
    public function returnSale($sale_id, $user_id, $return_qty, $reason) {
         try {
            $stmt = $this->conn->prepare("CALL SaleProcessReturn(?, ?, ?, ?)");
            $stmt->execute([$sale_id, $user_id, $return_qty, $reason]);
            return "Success: Return processed. Stock has been updated.";
        } catch (PDOException $e) {
            error_log("Error processing sale return: " . $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }

    public function initiateRecall($product_id, $reason, $batch_start_date, $batch_end_date) {
        $stmt = $this->conn->prepare("CALL RecallInitiate(?, ?, ?, ?)");
        return $stmt->execute([$product_id, $reason, $batch_start_date, $batch_end_date]);
    }

    public function logRecallRemoval($recall_id, $user_id, $qty_removed, $notes) {
        $stmt = $this->conn->prepare("CALL RecallLogRemoval(?, ?, ?, ?)");
        return $stmt->execute([$recall_id, $user_id, $qty_removed, $notes]);
    }

    // --- RECIPE METHODS ---

    public function getAllProductsSimple() {
        $stmt = $this->conn->prepare("CALL ProductGetAllSimple()");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllIngredientsSimple() {
        $stmt = $this->conn->prepare("CALL IngredientGetAllSimple()");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getRecipeForProduct($product_id) {
        $stmt = $this->conn->prepare("CALL RecipeGetByProductId(?)");
        $stmt->execute([$product_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addIngredientToRecipe($product_id, $ingredient_id, $qty_needed, $unit) {
        $stmt = $this->conn->prepare("CALL RecipeAddIngredient(?, ?, ?, ?)");
        $stmt->execute([$product_id, $ingredient_id, $qty_needed, $unit]);
        
        if ($stmt->rowCount() > 0) {
            return "success";
        } else {
            return "duplicate";
        }
    }

    public function removeIngredientFromRecipe($recipe_id) {
        $stmt = $this->conn->prepare("CALL RecipeRemoveIngredient(?)");
        return $stmt->execute([$recipe_id]);
    }
    
    public function updateProductBatchSize($product_id, $batch_size) {
        $stmt = $this->conn->prepare("CALL ProductUpdateBatchSize(?, ?)");
        return $stmt->execute([$product_id, $batch_size]);
    }
}
?>