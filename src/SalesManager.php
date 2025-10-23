<?php
require_once '../db_connection.php';

// Manages fetching sales history data.
class SalesManager {
    private $conn;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->getConnection();
    }

    /**
     * Gets a paginated or date-ranged list of sales transactions.
     * Calls: ReportGetSalesHistory(?, ?)
     */
    public function getSalesHistory($date_start, $date_end) {
        try {
            $stmt = $this->conn->prepare("CALL ReportGetSalesHistory(?, ?)");
            $stmt->execute([$date_start, $date_end]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }
}
?>