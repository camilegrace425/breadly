<?php
// We only need the db.php file which contains the Database class
require_once 'db.php'; 

class DashboardFunctions {
    private $conn;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->getConnection();
    }

    /**
     * Gets today's sales and revenue by calling a new Stored Procedure
     * ✅ Call: ReportGetSalesSummaryToday()
     */
    public function getSalesSummaryToday() {
        try {
            // This is a NEW procedure you must add (see SQL below)
            $stmt = $this->conn->query("CALL ReportGetSalesSummaryToday()");
            
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor(); 
            
            // Return defaults if no sales yet
            return [
                'totalSales' => $row['totalSales'] ?? 0,
                'totalRevenue' => $row['totalRevenue'] ?? 0.00
            ];
            
        } catch (PDOException $e) {
            // On error (like procedure not existing), return defaults
            return ['totalSales' => 0, 'totalRevenue' => 0.00];
        }
    }

    /**
     * Gets the count of ingredients that are low on stock
     * ✅ Queries: view_ActiveLowStockAlerts
     */
    public function getLowStockAlertsCount() {
        try {
            // This uses the view we created earlier
            $stmt = $this->conn->query("SELECT COUNT(*) AS alertCount FROM view_ActiveLowStockAlerts");
            
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor(); 
            return $row['alertCount'] ?? 0;
            
        } catch (PDOException $e) {
            return 0; // Return 0 if view doesn't exist
        }
    }
}
?>