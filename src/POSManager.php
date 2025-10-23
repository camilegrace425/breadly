<?php
require_once '../db_connection.php';

class PosFunctions {
    private $conn;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->getConnection();
    }

    // Fetches all products that are marked as 'available' for sale.
    public function getAvailableProducts() {
        try {
            $stmt = $this->conn->query("SELECT product_id, name, price, stock_qty FROM view_ProductInventory WHERE status = 'available' AND stock_qty > 0 ORDER BY name");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }
}
?>