<?php
require_once '../db_connection.php';

// Handles fetching data for the inventory management page.
class InventoryManager {
    private $conn;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->getConnection();
    }

    // Gets all products from the inventory view (excluding discontinued).
    public function getProductsInventory() {
        try {
            $stmt = $this->conn->query("CALL InventoryGetProducts()");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    // Gets a single product's details.
    public function getProductById($product_id) {
         try {
            $stmt = $this->conn->prepare("CALL ProductGetById(?)");
            $stmt->execute([$product_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return null;
        }
    }


    // Gets all ingredients from the stock level view.
    public function getIngredientsInventory() {
        try {
            $stmt = $this->conn->query("CALL InventoryGetIngredients()");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    // Gets only discontinued products.
    public function getDiscontinuedProducts() {
        try {
            $stmt = $this->conn->query("CALL InventoryGetDiscontinued()");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }
}
?>