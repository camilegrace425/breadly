<?php
require_once '../db_connection.php';
class InventoryManager {
    private $conn;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->getConnection();
    }

    public function getProductsInventory() {
        try {
            $stmt = $this->conn->query("CALL InventoryGetProducts()");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching product inventory: " . $e->getMessage());
            return [];
        }
    }

    public function getProductById($product_id) {
         try {
            $stmt = $this->conn->prepare("CALL ProductGetById(?)");
            $stmt->execute([$product_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null; // Return null if fetch fails
        } catch (PDOException $e) {
            error_log("Error fetching product by ID {$product_id}: " . $e->getMessage());
            return null;
        }
    }

    public function getIngredientsInventory() {
        try {
            $stmt = $this->conn->query("CALL InventoryGetIngredients()");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching ingredient inventory: " . $e->getMessage());
            return [];
        }
    }

    public function getRecallHistoryByDate($date_start, $date_end) {
        try {
            $stmt = $this->conn->prepare("CALL InventoryGetRecallHistory(?, ?)");
            $stmt->execute([$date_start, $date_end]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            return $data;
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
}
?>