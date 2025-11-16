<?php
// --- ADDED: Include config file if not already loaded ---
if (!defined('SMS_API_TOKEN')) {
    // Adjust path as this file is in /src/
    require_once __DIR__ . '/../config.php'; 
}
require_once __DIR__ . '/../db_connection.php';

class UserManager {
    private $conn;
    // --- MODIFIED: Use constants from config.php ---
    private $api_token = SMS_API_TOKEN; 
    private $api_send_otp_url = SMS_OTP_URL;
    // --- END MODIFICATION ---

    public function __construct() {
        $db = new Database();
        $this->conn = $db->getConnection();
    }
    
    // --- ::: MODIFIED: This function no longer logs IP address ::: ---
    public function login($username, $password) {
        // --- IP ADDRESS LINE REMOVED ---
    
        $stmt = $this->conn->prepare("CALL UserLogin(?)");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if ($user && password_verify($password, $user['password'])) {
            // --- MODIFIED BLOCK (Success Log) ---
            try {
                $log_stmt = $this->conn->prepare("CALL LogLoginAttempt(?, ?, ?)");
                $log_stmt->execute([$user['user_id'], $username, 'success']); // IP param removed
                $log_stmt->closeCursor();
            } catch (PDOException $e) {
                error_log("Failed to log login success: " . $e->getMessage());
            }
            // --- END OF BLOCK ---
    
            return $user; // Success
        } else {
            // --- MODIFIED BLOCK (Failure Log) ---
            $user_id_to_log = $user ? $user['user_id'] : null;
            try {
                $log_stmt = $this->conn->prepare("CALL LogLoginAttempt(?, ?, ?)");
                $log_stmt->execute([$user_id_to_log, $username, 'failure']); // IP param removed
                $log_stmt->closeCursor();
            } catch (PDOException $e) {
                error_log("Failed to log login failure: " . $e->getMessage());
            }
            // --- END OF BLOCK ---
    
            return false; // Failure
        }
    }

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

    public function requestEmailReset($email) {
        $stmt = $this->conn->prepare("CALL UserRequestPasswordReset(?, @token)");
        $stmt->execute([$email]);
        $stmt->closeCursor();
        
        $token_row = $this->conn->query("SELECT @token AS token")->fetch(PDO::FETCH_ASSOC);
        $token = $token_row['token'];

        if ($token) {
            // --- SIMULATE EMAIL ---
            error_log("--- PASSWORD RESET (EMAIL) ---");
            error_log("User: $email");
            error_log("Token: $token");
            error_log("Link: http://localhost/breadly/nav/reset_password.php?token=$token");
            error_log("---------------------------------");
            return $token;
        }
        return null;
    }

    public function requestPhoneReset($phone_number) {
        // 1. Find user by phone
        $stmt = $this->conn->prepare("CALL UserFindByPhone(?)");
        $stmt->execute([$phone_number]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if (!$user) {
            return null; // No user with this phone number
        }

        // 2. Generate OTP
        $otp = (string)rand(100000, 999999);
        $user_id = $user['user_id'];
        $expiration_time = date('Y-m-d H:i:s', time() + 300); // 5 minutes

        // 3. Store OTP in database
        $stmt = $this->conn->prepare("CALL UserStorePhoneOTP(?, ?, ?)");
        $stmt->execute([$user_id, $otp, $expiration_time]);
        $stmt->closeCursor();
        
        // 4. Format phone number for API
        $formatted_phone = $this->formatPhoneNumberForAPI($phone_number);

        // 5. Send OTP via SMS API
        $data = [
            'api_token' => $this->api_token,
            'message' => "Your BREADLY password reset code is: $otp. It will expire in 5 minutes.",
            'phone_number' => $formatted_phone
        ];
        
        $ch = curl_init($this->api_send_otp_url);
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
        if ($http_code == 200 && isset($result['status']) && $result['status'] == 200) {
            return $otp; // Return OTP on success
        } else {
            // Log the error
            error_log("SMS API Error: " . $response);
            return null; // Return null on failure
        }
    }

    public function resetPassword($token_or_otp, $new_password) {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        $stmt = $this->conn->prepare("CALL UserResetPassword(?, ?)");
        $stmt->execute([$token_or_otp, $hashed_password]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return ($result && $result['status'] === 'Success');
    }
    
    public function getUserSettings($user_id) {
         try {
            $stmt = $this->conn->prepare("CALL AdminGetMySettings(?)");
            $stmt->execute([$user_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching user settings: " . $e->getMessage());
            return [];
        }
    }
    
    public function updateMySettings($user_id, $phone_number, $enable_daily_report) {
         try {
            $stmt = $this->conn->prepare("CALL AdminUpdateMySettings(?, ?, ?)");
            $stmt->execute([$user_id, $phone_number, $enable_daily_report]);
            return true;
        } catch (PDOException $e) {
            error_log("Error updating user settings: " . $e->getMessage());
            return false;
        }
    }

    public function getLoginHistory() {
        try {
            $stmt = $this->conn->prepare("CALL ReportGetLoginHistory()");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching login history: " . $e->getMessage());
            return [];
        }
    }
}
?>