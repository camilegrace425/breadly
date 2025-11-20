<?php
require_once '../db_connection.php';

class InventoryManager {
    private $conn;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->getConnection();
    }

    // =================================================================
    // EXISTING READ FUNCTIONS
    // =================================================================

    public function getProductsInventory() {
        try {
            $stmt = $this->conn->query("CALL InventoryGetProducts()");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching products: " . $e->getMessage());
            return [];
        }
    }
    
    public function getProductById($product_id) {
         try {
            $stmt = $this->conn->prepare("CALL ProductGetById(?)");
            $stmt->execute([$product_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            error_log("Error fetching product by ID: " . $e->getMessage());
            return null;
        }
    }

    public function getIngredientsInventory() {
        try {
            // Uses the view that sums up total stock
            $stmt = $this->conn->query("SELECT * FROM view_IngredientStockLevel ORDER BY name");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching ingredients: " . $e->getMessage());
            return [];
        }
    }

    // --- UPDATED: Filter out positive (Undo) entries from the log view ---
    public function getRecallHistoryByDate($date_start, $date_end) {
        try {
            $sql = "SELECT
                        sa.adjustment_id,
                        sa.timestamp,
                        u.username,
                        sa.item_type,
                        sa.item_id,
                        COALESCE(p.name, i.name) AS item_name,
                        sa.adjustment_qty,
                        sa.reason,
                        CASE
                            WHEN sa.item_type = 'product' AND sa.adjustment_qty < 0 THEN sa.adjustment_qty * p.price
                            ELSE 0
                        END AS removed_value
                    FROM
                        stock_adjustments sa
                    LEFT JOIN
                        users u ON sa.user_id = u.user_id
                    LEFT JOIN
                        products p ON sa.item_id = p.product_id AND sa.item_type = 'product'
                    LEFT JOIN
                        ingredients i ON sa.item_id = i.ingredient_id AND sa.item_type = 'ingredient'
                    WHERE
                        sa.reason LIKE '%recall%'
                        AND sa.adjustment_qty < 0  -- ::: ADDED THIS LINE ::: Only show removals
                        AND DATE(sa.timestamp) BETWEEN ? AND ?
                    ORDER BY
                        sa.timestamp DESC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$date_start, $date_end]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching recall history: " . $e->getMessage());
            return [];
        }
    }

    public function getDiscontinuedProducts() {
        try {
            $stmt = $this->conn->query("CALL InventoryGetDiscontinued()");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching discontinued products: " . $e->getMessage());
            return [];
        }
    }

    public function getAdjustmentHistory() {
        try {
            $stmt = $this->conn->query("CALL ReportGetStockAdjustmentHistory()");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching adjustment history: " . $e->getMessage());
            return [];
        }
    }

    // Legacy/Direct adjustment wrapper
    public function adjustIngredientStock($ingredient_id, $user_id, $qty, $reason, $expiration_date = null) {
        try {
            $stmt = $this->conn->prepare("CALL IngredientAdjustStock(?, ?, ?, ?, ?)");
            $expiry = empty($expiration_date) ? null : $expiration_date;
            $stmt->execute([$ingredient_id, $user_id, $qty, $reason, $expiry]);
            return ['success' => true, 'message' => 'Stock adjusted successfully.'];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // --- UNDO RECALL FUNCTION ---
    public function undoRecall($adjustment_id, $user_id) {
        try {
            $this->conn->beginTransaction();

            // 1. Get the original recall adjustment
            $stmt = $this->conn->prepare("SELECT * FROM stock_adjustments WHERE adjustment_id = ?");
            $stmt->execute([$adjustment_id]);
            $original = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$original) {
                throw new Exception("Recall record not found.");
            }
            
            // Verify it is a recall (negative quantity)
            if ($original['adjustment_qty'] >= 0) {
                 throw new Exception("Cannot undo: This record does not appear to be a stock removal.");
            }

            $qty_to_add_back = abs($original['adjustment_qty']);
            $new_reason = "[Undo Recall] Reversing Adj #" . $adjustment_id;

            // 2. Add Stock Back
            if ($original['item_type'] === 'product') {
                $stmtUpd = $this->conn->prepare("UPDATE products SET stock_qty = stock_qty + ? WHERE product_id = ?");
                $stmtUpd->execute([$qty_to_add_back, $original['item_id']]);
            } elseif ($original['item_type'] === 'ingredient') {
                $stmtUpd = $this->conn->prepare("UPDATE ingredients SET stock_qty = stock_qty + ? WHERE ingredient_id = ?");
                $stmtUpd->execute([$qty_to_add_back, $original['item_id']]);
            }

            // 3. Log the counter-adjustment
            $stmtLog = $this->conn->prepare("INSERT INTO stock_adjustments (item_id, item_type, user_id, adjustment_qty, reason) VALUES (?, ?, ?, ?, ?)");
            $stmtLog->execute([
                $original['item_id'],
                $original['item_type'],
                $user_id,
                $qty_to_add_back,
                $new_reason
            ]);

            // 4. Mark the original record as Undone in the reason text (for UI feedback)
            $updateReason = $original['reason'] . " (Undone)";
            $stmtMark = $this->conn->prepare("UPDATE stock_adjustments SET reason = ? WHERE adjustment_id = ?");
            $stmtMark->execute([$updateReason, $adjustment_id]);

            $this->conn->commit();
            return ['success' => true, 'message' => 'Recall undone successfully. Stock has been returned.'];

        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // =================================================================
    // BATCH MANAGEMENT FUNCTIONS
    // =================================================================

    public function getIngredientBatches($ingredient_id) {
        try {
            $sql = "SELECT * FROM ingredient_batches 
                    WHERE ingredient_id = ? AND quantity > 0 
                    ORDER BY (expiration_date IS NULL), expiration_date ASC";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$ingredient_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching batches: " . $e->getMessage());
            return [];
        }
    }

    public function createBatch($ingredient_id, $user_id, $qty, $expiration_date, $reason) {
        try {
            $this->conn->beginTransaction();

            $expiry = empty($expiration_date) ? null : $expiration_date;
            $stmt = $this->conn->prepare("INSERT INTO ingredient_batches (ingredient_id, quantity, expiration_date, date_received) VALUES (?, ?, ?, CURDATE())");
            $stmt->execute([$ingredient_id, $qty, $expiry]);
            
            $stmt2 = $this->conn->prepare("UPDATE ingredients SET stock_qty = stock_qty + ? WHERE ingredient_id = ?");
            $stmt2->execute([$qty, $ingredient_id]);

            $stmt3 = $this->conn->prepare("INSERT INTO stock_adjustments (item_id, item_type, user_id, adjustment_qty, reason) VALUES (?, 'ingredient', ?, ?, ?)");
            $stmt3->execute([$ingredient_id, $user_id, $qty, $reason]);

            $stmt4 = $this->conn->query("CALL IngredientCheckLowStock()");

            $this->conn->commit();
            return ['success' => true, 'message' => 'Batch created successfully.'];

        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log("Error creating batch: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database Error: ' . $e->getMessage()];
        }
    }

    public function updateBatchExpiry($batch_id, $new_date) {
        try {
            $date_val = empty($new_date) ? null : $new_date;
            $stmt = $this->conn->prepare("UPDATE ingredient_batches SET expiration_date = ? WHERE batch_id = ?");
            $stmt->execute([$date_val, $batch_id]);
            
            return ['success' => true, 'message' => 'Expiration date updated.'];
        } catch (PDOException $e) {
            error_log("Error updating batch expiry: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database Error: ' . $e->getMessage()];
        }
    }

    public function updateBatchQuantity($batch_id, $user_id, $new_qty, $reason) {
        try {
            $this->conn->beginTransaction();

            $stmt = $this->conn->prepare("SELECT quantity, ingredient_id FROM ingredient_batches WHERE batch_id = ? FOR UPDATE");
            $stmt->execute([$batch_id]);
            $batch = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$batch) {
                throw new Exception("Batch not found.");
            }
            
            $old_qty = floatval($batch['quantity']);
            $target_qty = floatval($new_qty);
            $diff = $target_qty - $old_qty;

            if ($diff == 0) {
                $this->conn->rollBack();
                return ['success' => true, 'message' => 'No change in quantity detected.'];
            }

            $stmt2 = $this->conn->prepare("UPDATE ingredient_batches SET quantity = ? WHERE batch_id = ?");
            $stmt2->execute([$target_qty, $batch_id]);

            $stmt3 = $this->conn->prepare("UPDATE ingredients SET stock_qty = stock_qty + ? WHERE ingredient_id = ?");
            $stmt3->execute([$diff, $batch['ingredient_id']]);

            $stmt4 = $this->conn->prepare("INSERT INTO stock_adjustments (item_id, item_type, user_id, adjustment_qty, reason) VALUES (?, 'ingredient', ?, ?, ?)");
            $stmt4->execute([$batch['ingredient_id'], $user_id, $diff, $reason]);

            $this->conn->commit();
            return ['success' => true, 'message' => 'Batch quantity updated successfully.'];

        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Error updating batch quantity: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteBatch($batch_id, $user_id, $reason) {
        try {
            $this->conn->beginTransaction();

            $stmt = $this->conn->prepare("SELECT quantity, ingredient_id FROM ingredient_batches WHERE batch_id = ? FOR UPDATE");
            $stmt->execute([$batch_id]);
            $batch = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$batch) {
                throw new Exception("Batch not found.");
            }
            
            $qty_to_remove = floatval($batch['quantity']);

            $stmt2 = $this->conn->prepare("DELETE FROM ingredient_batches WHERE batch_id = ?");
            $stmt2->execute([$batch_id]);

            $stmt3 = $this->conn->prepare("UPDATE ingredients SET stock_qty = stock_qty - ? WHERE ingredient_id = ?");
            $stmt3->execute([$qty_to_remove, $batch['ingredient_id']]);

            $stmt4 = $this->conn->prepare("INSERT INTO stock_adjustments (item_id, item_type, user_id, adjustment_qty, reason) VALUES (?, 'ingredient', ?, ?, ?)");
            $stmt4->execute([$batch['ingredient_id'], $user_id, -$qty_to_remove, $reason]);

            $this->conn->commit();
            return ['success' => true, 'message' => 'Batch deleted successfully.'];

        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Error deleting batch: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
?>