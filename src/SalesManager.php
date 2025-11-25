<?php
require_once 'AbstractManager.php';
require_once 'ListableData.php';

class SalesManager extends AbstractManager implements ListableData {
    public function fetchAllData(): array {
        $year = date('Y');
        $start = "$year-01-01";
        $end = date('Y-m-d');
        return $this->getSalesHistory($start, $end, 'timestamp', 'DESC');
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

    public function getReturnHistory() {
        try {
            $stmt = $this->conn->prepare("CALL ReportGetReturnHistory()");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching return history: " . $e->getMessage());
            return [];
        }
    }
}
?>