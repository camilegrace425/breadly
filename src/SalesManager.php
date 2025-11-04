<?php
require_once '../db_connection.php';

class SalesManager {
    private $conn;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->getConnection();
    }

    public function getSalesHistory($date_start, $date_end, $sort_column, $sort_direction) {
        try {
            $stmt = $this->conn->prepare("CALL ReportGetSalesHistory(?, ?, ?, ?)");
            $stmt->execute([$date_start, $date_end, $sort_column, $sort_direction]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }
}
?>
