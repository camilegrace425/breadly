<?php
require_once 'db.php'; // Assumes db.php is in the same directory

class PosFunctions {
    private $conn;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->getConnection();
    }

    /**
     * Fetches all products that are marked as 'available' for sale.
     * It uses the view_ProductInventory we created earlier.
     */
    public function getAvailableProducts() {
        try {
            $stmt = $this->conn->query("SELECT product_id, name, price, stock_qty FROM view_ProductInventory WHERE status = 'available' AND stock_qty > 0 ORDER BY name");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // In case the view doesn't exist or there's an error
            return [];
        }
    }
}
?>