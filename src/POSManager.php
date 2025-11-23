<?php
require_once 'AbstractManager.php';
require_once 'ListableData.php'; // Required for the interface

class PosFunctions extends AbstractManager implements ListableData { // Implements ListableData
    public function fetchAllData(): array {
        return $this->getAvailableProducts();
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