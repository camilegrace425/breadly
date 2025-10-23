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
            // UPDATED: Changed from SELECT query to stored procedure
            $stmt = $this->conn->query("CALL PosGetAvailableProducts()");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }
}
?>