<?php
require_once 'db_connection.php';

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
            $stmt = $this->conn->query("SELECT COUNT(*) AS alertCount FROM view_ActiveLowStockAlerts");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor(); 
            return $row['alertCount'] ?? 0;
            
        } catch (PDOException $e) {
            return 0;
        }
    }

    // Gets the top N selling products for charts. Calls: ReportGetBestSellers(?, ?)
    public function getTopSellingProducts($date_start, $date_end, $limit = 5) {
        try {
            // This query now uses SQL's CURDATE() to get the correct 30-day range,
            // bypassing the incorrect PHP clock.
            $stmt = $this->conn->prepare("CALL ReportGetBestSellers(CURDATE() - INTERVAL 30 DAY, CURDATE())");
            $stmt->execute(); // No parameters needed
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor(); 
            
            // The limit (which is 5 from dashboard_panel.php) is applied here
            return array_slice($results, 0, $limit);
        } catch (PDOException $e) {
            return [];
        }
    }
    
    // Gets the single most urgent low-stock alert. Queries: view_ActiveLowStockAlerts
    public function getActiveLowStockAlerts($limit = 1) {
         try {
            $stmt = $this->conn->query("SELECT * FROM view_ActiveLowStockAlerts ORDER BY current_stock ASC LIMIT $limit");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }
}
?>