<?php
require_once 'AbstractManager.php';
require_once '../src/SalesManager.php'; 

if (!defined('SMS_API_TOKEN')) {
    require_once __DIR__ . '/../config.php'; 
}

class DashboardManager extends AbstractManager {
    private $api_token = SMS_API_TOKEN; 
    private $api_send_sms_url = SMS_SEND_URL;
    private $salesManager;

    public function __construct() {
        parent::__construct(); // Call the parent constructor to set $this->conn
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

    public function getDailySalesTrend($date_start, $date_end) {
        try {
            $sql = "SELECT DATE(o.timestamp) as sale_date, SUM(s.total_price) as daily_revenue 
                    FROM sales s
                    JOIN orders o ON s.order_id = o.order_id
                    WHERE DATE(o.timestamp) BETWEEN ? AND ? 
                    GROUP BY DATE(o.timestamp) 
                    ORDER BY DATE(o.timestamp) ASC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$date_start, $date_end]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching daily sales trend: " . $e->getMessage());
            return [];
        }
    }

    public function getDailyReturnsTrend($date_start, $date_end) {
        try {
            $sql = "SELECT DATE(timestamp) as return_date, SUM(return_value) as daily_return_value 
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

    // NEW FUNCTION: Combines count and value of recalled items in a date range
    public function getRecallSummaryByDateRange($date_start, $date_end) {
        try {
            // Calculates the sum of negative product adjustments marked as 'recall'
            $sql = "SELECT 
                        ABS(SUM(CASE WHEN sa.item_type = 'product' AND sa.adjustment_qty < 0 AND sa.reason LIKE '%recall%' THEN sa.adjustment_qty ELSE 0 END)) AS total_recalled_count,
                        ABS(SUM(CASE WHEN sa.item_type = 'product' AND sa.adjustment_qty < 0 AND sa.reason LIKE '%recall%' THEN sa.adjustment_qty * p.price ELSE 0 END)) AS total_recalled_value
                    FROM stock_adjustments sa
                    LEFT JOIN products p ON sa.item_id = p.product_id AND sa.item_type = 'product'
                    WHERE DATE(sa.timestamp) BETWEEN ? AND ?";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$date_start, $date_end]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            return [
                'count' => (int)($row['total_recalled_count'] ?? 0),
                'value' => (float)($row['total_recalled_value'] ?? 0.00)
            ];
        } catch (PDOException $e) {
            error_log("Error getting recall summary: " . $e->getMessage());
            return ['count' => 0, 'value' => 0.00];
        }
    }

    // --- ADDED: Fetch detailed list of recalls for the modal ---
    public function getRecallsByDateRange($date_start, $date_end) {
        try {
            $stmt = $this->conn->prepare("CALL InventoryGetRecallHistory(?, ?)");
            $stmt->execute([$date_start, $date_end]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            return $data;
        } catch (PDOException $e) {
            error_log("Error fetching recall history: " . $e->getMessage());
            return [];
        }
    }
    // -----------------------------------------------------------

    public function getExpiringBatches($days_threshold = 7) {
        try {
            $sql = "SELECT b.batch_id, i.name AS ingredient_name, b.quantity, i.unit, b.expiration_date,
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

        // 1. Get Sales Summary (Gross Revenue and Returns)
        $salesSummary = $this->getSalesSummaryByDateRange($date_start, $date_end);
        
        $grossRevenue = $salesSummary['totalRevenue'] ?? 0.00;
        $totalReturns = $salesSummary['totalReturnsValue'] ?? 0.00;
        
        // 2. Calculate Net Revenue
        $netRevenue = $grossRevenue - $totalReturns;

        // 3. Get Recall Summary (Total Value of Recalled Products)
        $recallSummary = $this->getRecallSummaryByDateRange($date_start, $date_end);
        $totalRecalledValue = $recallSummary['value'] ?? 0.00;

        // 4. Construct Message
        $message .= "Gross Revenue: P" . number_format($grossRevenue, 2) . "\n";
        $message .= "Total Returns: P" . number_format($totalReturns, 2) . "\n";
        $message .= "Net Revenue: P" . number_format($netRevenue, 2) . "\n";
        $message .= "Total Recalled: P" . number_format($totalRecalledValue, 2) . "\n";
        
        // Truncate if too long (though this format should be short enough)
        if (strlen($message) > 400) {
             $message = substr($message, 0, 400) . "...";
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
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
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