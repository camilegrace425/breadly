<?php
require_once '../db_connection.php';

class UserManager {
    private $conn;

    private $api_token = '4882e7a9d4704d5afc136eebb463d298d1f15c20'; 
    private $api_send_otp_url = 'https://sms.iprogtech.com/api/v1/otp/send_otp';
    // ---------------------------------

    public function __construct() {
        $db = new Database();
        $this->conn = $db->getConnection();
    }

    // Authenticates a user by checking username and verifying the hashed password.
    public function login($username, $plain_password) {
        
        $stmt = $this->conn->prepare("CALL UserLogin(?)");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($plain_password, $user['password'])) {
            unset($user['password']); 
            return $user;
        }
        
        return false;
    }

    // Finds a user by their ID.
    public function findUserById($user_id) {
        $stmt = $this->conn->prepare("CALL UserFindById(?)");
        $stmt->execute([$user_id]);
        return $stmt->fetch();
    }

    private function findUserByPhone($phone_number) {
        try {
            $stmt = $this->conn->prepare("CALL UserFindByPhone(?)");
            $stmt->execute([$phone_number]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return false;
        }
    }
    // Calls the 'UserRequestPasswordReset' stored procedure for email.
    public function requestEmailReset($email) {
        try {
            $stmt = $this->conn->prepare("CALL UserRequestPasswordReset(?, @token)");
            $stmt->execute([$email]);
            
            $token_stmt = $this->conn->query("SELECT @token AS reset_token");
            $result = $token_stmt->fetch();

            if ($result && $result['reset_token']) {
                return $result['reset_token'];
            }
            return false;
        } catch (PDOException $e) {
            return false;
        }
    }

    public function requestPhoneReset($phone_number) {
        
        // 1. Check if user exists in our DB using new SP
        $user = $this->findUserByPhone($phone_number);
        if (!$user) {
            // No user found with this phone number
            return false;
        }
        $user_id = $user['user_id'];

        // 2. Format phone number for the API (e.g., 0917... -> 63917...)
        $formatted_phone = $phone_number;
        if (strlen($phone_number) == 11 && substr($phone_number, 0, 2) == '09') {
            $formatted_phone = '63' . substr($phone_number, 1);
        }

        // 3. Prepare data for the API
        $data = [
            'api_token' => $this->api_token,
            'phone_number' => $formatted_phone,
            'expires_in' => 600, // --- MODIFIED: Set OTP expiration to 10 minutes (600 seconds) ---
            'interval' => 180    // --- MODIFIED: Set resend interval to 3 minutes (180 seconds) ---
        ];
        // --- FIX: Change payload to http_build_query ---
        $payload = http_build_query($data);

        // 4. Send cURL request to iProg API
        $ch = curl_init($this->api_send_otp_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        // --- FIX: Change Content-Type to form-urlencoded ---
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json' // Keep Accept as application/json
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            error_log("OTP API cURL Error: " . $curl_error);
            return false;
        }
        
        if ($http_code != 200) {
             error_log("OTP API HTTP Error: " . $http_code . " Response: " . $response);
             return false;
        }

        $result = json_decode($response, true);

        // 5. Check API response
        if (isset($result['status']) && $result['status'] === 'success' && isset($result['data']['otp_code'])) {
            
            $otp_code = $result['data']['otp_code'];
            $expiration_time = $result['data']['otp_code_expires_at'];

            // 6. Save the OTP to our local database using new SP
            try {
                $stmt = $this->conn->prepare("CALL UserStorePhoneOTP(?, ?, ?)");
                return $stmt->execute([$user_id, $otp_code, $expiration_time]);

            } catch (PDOException $e) {
                error_log("OTP DB Insert Error: " . $e->getMessage());
                return false;
            }
            
        } else {
            error_log("OTP API Response Error: " . $response);
            return false;
        }
    }

    // Finalizes the password reset using either a token or OTP.
    public function resetPassword($token_or_otp, $new_plain_password) {
        
        $new_hashed_password = password_hash($new_plain_password, PASSWORD_DEFAULT);

        try {
            // This procedure is now created from the SQL in Step 1
            $stmt = $this->conn->prepare("CALL UserResetPassword(?, ?)");
            $stmt->execute([$token_or_otp, $new_hashed_password]);
            $result = $stmt->fetch();

            return ($result && $result['status'] === 'Success');
        } catch (PDOException $e) {
            return false;
        }
    }
    public function getUserSettings($user_id) {
        try {
            $stmt = $this->conn->prepare("CALL AdminGetMySettings(?)");
            $stmt->execute([$user_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching user settings: " . $e->getMessage());
            return ['phone_number' => '', 'enable_daily_report' => 0];
        }
    }

    public function updateMySettings($user_id, $phone_number, $enable_report) {
        try {
            $stmt = $this->conn->prepare("CALL AdminUpdateMySettings(?, ?, ?)");
            return $stmt->execute([$user_id, $phone_number, $enable_report]);
        } catch (PDOException $e) {
            error_log("Error updating user settings: " . $e->getMessage());
            return false;
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
} // This is the existing closing brace for the class
?>