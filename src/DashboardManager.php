<?php
require_once '../db_connection.php';

// Manages all data fetching for the manager dashboard.
class DashboardManager {
    private $conn;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->getConnection();
    }

    // Gets today's total sales and total revenue. Calls: ReportGetSalesSummaryToday()
    public function getSalesSummaryToday() {
        try {
            $stmt = $this->conn->query("CALL ReportGetSalesSummaryToday()");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor(); 
            
            return [
                'totalSales' => $row['totalSales'] ?? 0,
                'totalRevenue' => $row['totalRevenue'] ?? 0.00
            ];
            
        } catch (PDOException $e) {
            return ['totalSales' => 0, 'totalRevenue' => 0.00];
        }
    }

    // Gets the count of ingredients that are low on stock. Queries: view_ActiveLowStockAlerts
    public function getLowStockAlertsCount() {
        try {
            // UPDATED: Changed from SELECT query to stored procedure
            $stmt = $this->conn->query("CALL DashboardGetLowStockAlertsCount()");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor(); 
            return $row['alertCount'] ?? 0;
            
        } catch (PDOException $e) {
            return 0;
        }
    }

    // Gets the top N selling products for charts. Calls: ReportGetBestSellers(?, ?)
    // This includes the fix from our previous conversation.
    public function getTopSellingProducts($date_start, $date_end, $limit = 5) {
        try {
            $stmt = $this->conn->prepare("CALL ReportGetBestSellers(?, ?)");
            $stmt->execute([$date_start, $date_end]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            
            return array_slice($results, 0, $limit);
        } catch (PDOException $e) {
            return [];
        }
    }
    
    // Gets the single most urgent low-stock alert. Queries: view_ActiveLowStockAlerts
    public function getActiveLowStockAlerts($limit = 1) {
         try {
            // UPDATED: Changed from SELECT query to stored procedure
            $stmt = $this->conn->prepare("CALL DashboardGetActiveLowStockAlerts(?)");
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }
}
?>