<?php
require_once '../db_connection.php';

// Manages all data fetching for the manager dashboard.
class DashboardManager {
    private $conn;
    private $api_token = '4882e7a9d4704d5afc136eebb463d298d1f15c20'; 
    private $api_send_sms_url = 'https://sms.iprogtech.com/api/v1/sms_messages';

    public function __construct() {
        $db = new Database();
        $this->conn = $db->getConnection();
    }
    
    // --- ADDED: New robust phone formatting function ---
    /**
     * Formats a phone number to the 639XXXXXXXXX format required by the API.
     * Handles 09, 9, and 639 prefixes.
     */
    private function formatPhoneNumberForAPI($phone_number) {
        // 1. Remove all non-numeric characters (like +, -, spaces)
        $cleaned = preg_replace('/[^0-9]/', '', $phone_number);

        // 2. Check for 11-digit format (0917...)
        if (strlen($cleaned) == 11 && substr($cleaned, 0, 2) == '09') {
            return '63' . substr($cleaned, 1);
        }

        // 3. Check for 10-digit format (917...)
        if (strlen($cleaned) == 10 && substr($cleaned, 0, 1) == '9') {
            return '63' . $cleaned;
        }

        // 4. Check for 12-digit format (63917...)
        if (strlen($cleaned) == 12 && substr($cleaned, 0, 3) == '639') {
            return $cleaned;
        }

        // If it's none of the above, return the (likely invalid) original for the API to reject
        return $phone_number;
    }

    public function getSalesSummaryByDateRange($date_start, $date_end) {
        try {
            $stmt = $this->conn->prepare("CALL DashboardGetSalesSummaryByDateRange(?, ?)");
            $stmt->execute([$date_start, $date_end]);
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
    
    public function getActiveLowStockAlerts($limit = 1) {
         try {
            $stmt = $this->conn->prepare("CALL DashboardGetActiveLowStockAlerts(?)");
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getRecalledStockValue() {
        try {
            $stmt = $this->conn->query("CALL DashboardGetRecalledStockValue()");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor(); 
            return $row['totalRecalledValue'] ?? 0.00;
        } catch (PDOException $e) {
            return 0.00;
        }
    }

    public function sendDailySummaryReport($phone_number, $date_start, $date_end) {
        
        $date_str = ($date_start == $date_end) ? $date_start : "$date_start to $date_end";
        $message = "Sales Report ($date_str):\n";

        // 1. Fetch Sales Data
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

        // 2. Fetch Recall Data
        $recall_data = [];
        try {
            // It now correctly calls the procedure with the date range
            $stmt = $this->conn->prepare("CALL InventoryGetRecallHistory(?, ?)");
            $stmt->execute([$date_start, $date_end]);
            $recall_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
        } catch (PDOException $e) {
            error_log("Report Fetch Error (Recall): " . $e->getMessage());
        }

        // 3. Format Sales Message
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

        // 4. Format Recall Message
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
        
        if (strlen($message) > 315) { // Approx 2 SMS messages
             $message = substr($message, 0, 315) . "...";
        }

        // 5. Format phone number (e.g., 0917... -> 63917...)
        // --- MODIFIED: Using new robust function ---
        $formatted_phone = $this->formatPhoneNumberForAPI($phone_number);
        // -------------------------------------------

        // 6. Send the SMS via cURL
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

        // Check for success
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