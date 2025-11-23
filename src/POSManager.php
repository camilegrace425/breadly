<?php
require_once '../db_connection.php';

class PosFunctions {
    private $conn;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->getConnection();
    }

    public function getAvailableProducts() {
        try {
            $stmt = $this->conn->query("CALL PosGetAvailableProducts()");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }
}
?>