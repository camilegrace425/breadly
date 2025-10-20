<?php
require_once 'db.php';

class DashboardManager {
    private $conn;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->getConnection();
    }

    public function getDashboardSummary($date_start, $date_end) {
        try {
            $stmt = $this->conn->prepare("CALL ReportGetDashboardSummary(?, ?)");
            $stmt->execute([$date_start, $date_end]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            
            // Return default values if no data
            return $result ? $result : [
                'TotalSales' => 0.00,
                'TotalOrders' => 0,
                'BestSellerName' => 'N/A'
            ];
        } catch (PDOException $e) {
            return ['TotalSales' => 0.00, 'TotalOrders' => 0, 'BestSellerName' => 'Error'];
        }
    }

    public function getTopSellingProducts($date_start, $date_end, $limit = 4) {
        try {
            // This uses the procedure we already have
            $stmt = $this->conn->prepare("CALL ReportGetBestSellers(?, ?)");
            $stmt->execute([$date_start, $date_end]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            
            // Return only the top N items
            return array_slice($results, 0, $limit);
        } catch (PDOException $e) {
            return [];
        }
    }
    
    public function getActiveLowStockAlerts($limit = 1) {
         try {
            // This uses the view we already have
            $stmt = $this->conn->query("SELECT * FROM view_ActiveLowStockAlerts ORDER BY date_triggered DESC LIMIT $limit");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getSalesByCategory($date_start, $date_end) {
        try {
            $stmt = $this->conn->prepare("CALL ReportGetSalesByCategory(?, ?)");
            $stmt->execute([$date_start, $date_end]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            return $results;
        } catch (PDOException $e) {
            // This will fail until you run the SQL queries below.
            return [];
        }
    }
}
?>