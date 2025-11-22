<?php
require_once '../db_connection.php';
require_once '../src/SalesManager.php'; 

if (!defined('SMS_API_TOKEN')) {
    require_once __DIR__ . '/../config.php'; 
}

// Manages all data fetching for the manager dashboard.
class DashboardManager {
    private $conn;
    private $api_token = SMS_API_TOKEN; 
    private $api_send_sms_url = SMS_SEND_URL;
    private $salesManager;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->getConnection();
        $this->salesManager = new SalesManager(); 
    }
    
    private function formatPhoneNumberForAPI($phone_number) {
        $cleaned = preg_replace('/[^0-9]/', '', $phone_number);
        if (strlen($cleaned) == 11 && substr($cleaned, 0, 2) == '09') {
            return '63' . substr($cleaned, 1);
        }
        if (strlen($cleaned) == 10 && substr($cleaned, 0, 1) == '9') {
            return '63' . $cleaned;
        }
        if (strlen($cleaned) == 12 && substr($cleaned, 0, 3) == '639') {
            return $cleaned;
        }
        return $phone_number;
    }

    private function getTotalReturnsByDateRange($date_start, $date_end) {
        try {
            $all_returns = $this->salesManager->getReturnHistory();
            $totalReturnValue = 0.00;
            $totalReturnCount = 0;
            
            $startDateObj = new DateTime($date_start . ' 00:00:00');
            $endDateObj = new DateTime($date_end . ' 23:59:59');

            foreach ($all_returns as $return) {
                $returnDateObj = new DateTime($return['timestamp']);
                if ($returnDateObj >= $startDateObj && $returnDateObj <= $endDateObj) {
                    $totalReturnValue += $return['return_value'] ?? 0.00;
                    $totalReturnCount++;
                }
            }
            return ['value' => $totalReturnValue, 'count' => $totalReturnCount];
            
        } catch (Exception $e) {
            error_log("Error in getTotalReturnsByDateRange: " . $e->getMessage());
            return ['value' => 0.00, 'count' => 0];
        }
    }

    public function getSalesSummaryByDateRange($date_start, $date_end) {
        $summary = ['totalSales' => 0, 'totalRevenue' => 0.00];
        
        try {
            $stmt = $this->conn->prepare("CALL DashboardGetSalesSummaryByDateRange(?, ?)");
            $stmt->execute([$date_start, $date_end]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor(); 
            
            $summary['totalSales'] = $row['totalSales'] ?? 0;
            $summary['totalRevenue'] = $row['totalRevenue'] ?? 0.00;
            
        } catch (PDOException $e) {
             error_log("DashboardManager Error (SalesSummary): " . $e->getMessage());
        }
        
        $returnsSummary = $this->getTotalReturnsByDateRange($date_start, $date_end);
        $summary['totalReturnsValue'] = $returnsSummary['value'];
        $summary['totalReturnsCount'] = $returnsSummary['count'];
        
        return $summary;
    }

    public function getLowStockAlertsCount() {
        try {
            $stmt = $this->conn->query("CALL DashboardGetLowStockAlertsCount()");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor(); 
            return $row['alertCount'] ?? 0;
            
        } catch (PDOException $e) {
            return 0;
        }
    }

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

    // --- Daily Sales Trend for Line Chart ---
    public function getDailySalesTrend($date_start, $date_end) {
        try {
            // Query to sum total revenue per day
            $sql = "SELECT 
                        DATE(timestamp) as sale_date, 
                        SUM(total_price) as daily_revenue 
                    FROM sales 
                    WHERE DATE(timestamp) BETWEEN ? AND ? 
                    GROUP BY DATE(timestamp) 
                    ORDER BY DATE(timestamp) ASC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$date_start, $date_end]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching daily sales trend: " . $e->getMessage());
            return [];
        }
    }

    // --- Daily Returns Trend for Line Chart ---
    public function getDailyReturnsTrend($date_start, $date_end) {
        try {
            // Query to sum return value per day
            $sql = "SELECT 
                        DATE(timestamp) as return_date, 
                        SUM(return_value) as daily_return_value 
                    FROM `returns` 
                    WHERE DATE(timestamp) BETWEEN ? AND ? 
                    GROUP BY DATE(timestamp) 
                    ORDER BY DATE(timestamp) ASC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$date_start, $date_end]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching daily returns trend: " . $e->getMessage());
            return [];
        }
    }

    public function getUnsoldProducts($date_start, $date_end) {
        try {
            $stmt = $this->conn->prepare("CALL ReportGetUnsoldProducts(?, ?)");
            $stmt->execute([$date_start, $date_end]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching unsold products: " . $e->getMessage());
            return [];
        }
    }
    
    public function getActiveLowStockAlerts($limit = 1) {
         try {
            $stmt = $this->conn->prepare("CALL DashboardGetActiveLowStockAlerts(?)");
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    // --- UPDATED: Calculate Recalled Stock Value (Net) ---
    // Sums negatives (recalls) and positives (undos) to get the true net value
    public function getRecalledStockValue($date_start, $date_end) {
        try {
            // Raw SQL to include positive (undo) adjustments and calculate net loss
            // Using raw SQL instead of stored procedure to ensure Undo records are included
            $sql = "SELECT SUM(sa.adjustment_qty * p.price) as net_value
                    FROM stock_adjustments sa
                    JOIN products p ON sa.item_id = p.product_id
                    WHERE sa.item_type = 'product'
                      AND sa.reason LIKE '%recall%'
                      AND DATE(sa.timestamp) BETWEEN ? AND ?";
                      
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$date_start, $date_end]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // The net_value will be negative if there are more recalls than undos.
            // We return the absolute value to display the "Cost" or "Loss".
            return abs($row['net_value'] ?? 0.00);
            
        } catch (PDOException $e) {
            error_log("Error getting recalled stock value: " . $e->getMessage());
            return 0.00;
        }
    }
    
    // --- UPDATED: Calculate Recall Count For Today (Net) ---
    // Sums quantities to account for undo actions
    public function getRecallCountForToday() {
        $today = date('Y-m-d');
        try {
            $sql = "SELECT SUM(adjustment_qty) as net_qty 
                    FROM stock_adjustments 
                    WHERE reason LIKE '%recall%' 
                    AND DATE(timestamp) = ?";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$today]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return abs($row['net_qty'] ?? 0);
            
        } catch (PDOException $e) {
            error_log("Error fetching recall count for today: " . $e->getMessage());
            return 0;
        }
    }

    // --- Expiration Tracker Method ---
    public function getExpiringBatches($days_threshold = 7) {
        try {
            $sql = "SELECT 
                        b.batch_id, 
                        i.name AS ingredient_name, 
                        b.quantity, 
                        i.unit, 
                        b.expiration_date,
                        DATEDIFF(b.expiration_date, CURDATE()) AS days_remaining
                    FROM ingredient_batches b
                    JOIN ingredients i ON b.ingredient_id = i.ingredient_id
                    WHERE b.quantity > 0 
                      AND b.expiration_date IS NOT NULL 
                      AND b.expiration_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
                    ORDER BY b.expiration_date ASC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$days_threshold]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching expiring batches: " . $e->getMessage());
            return [];
        }
    }

    public function sendDailySummaryReport($phone_number, $date_start, $date_end) {
        
        $date_str = ($date_start == $date_end) ? $date_start : "$date_start to $date_end";
        $message = "Sales Report ($date_str):\n";

        $sales_data = [];
        $overall_total_sales = 0;
        $overall_total_qty = 0;
        try {
            $stmt = $this->conn->prepare("CALL ReportGetSalesSummaryByDate(?, ?)");
            $stmt->execute([$date_start, $date_end]);
            $sales_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
        } catch (PDOException $e) {
            error_log("Report Fetch Error (Sales): " . $e->getMessage());
            return false;
        }

        $recall_data = [];
        try {
            $stmt = $this->conn->prepare("CALL InventoryGetRecallHistory(?, ?)");
            $stmt->execute([$date_start, $date_end]);
            $recall_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
        } catch (PDOException $e) {
            error_log("Report Fetch Error (Recall): " . $e->getMessage());
        }

        if (empty($sales_data)) {
            $message .= "No sales recorded on this day.\n";
        } else {
            foreach ($sales_data as $sale) {
                $qty = $sale['total_qty_sold'];
                $name = $sale['product_name'];
                $revenue = number_format($sale['total_revenue'], 2);
                $message .= "$qty x $name = P$revenue\n";
                
                $overall_total_qty += $qty;
                $overall_total_sales += $sale['total_revenue'];
            }
            $message .= "$overall_total_qty breads were sold.\n";
            $message .= "Total Sales: P" . number_format($overall_total_sales, 2) . "\n";
        }

        $message .= "\nTotal Recalled:\n";
        if (empty($recall_data)) {
            $message .= "No recall events.\n";
        } else {
            foreach ($recall_data as $recall) {
                $qty = abs($recall['adjustment_qty']);
                $name = $recall['item_name'];
                $reason = $recall['reason'];
                $message .= "$qty x $name (Reason: $reason)\n";
            }
        }
        
        if (strlen($message) > 315) {
             $message = substr($message, 0, 315) . "...";
        }

        $formatted_phone = $this->formatPhoneNumberForAPI($phone_number);
    
        $data = [
            'api_token' => $this->api_token,
            'message' => $message,
            'phone_number' => $formatted_phone
        ];
        
        $ch = curl_init($this->api_send_sms_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $result = json_decode($response, true);

        return ($http_code == 200 && isset($result['status']) && $result['status'] == 200);
    }

    public function getManagers() {
        try {
            $stmt = $this->conn->query("CALL AdminGetManagers()");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching managers: " . $e->getMessage());
            return [];
        }
    }
    public function getSalesSummaryByDate($date_start, $date_end) {
        try {
            $stmt = $this->conn->prepare("CALL ReportGetSalesSummaryByDate(?, ?)");
            $stmt->execute([$date_start, $date_end]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            return $data;
        } catch (PDOException $e) {
            error_log("Error fetching sales summary: " . $e->getMessage());
            return [];
        }
    }
}
?>