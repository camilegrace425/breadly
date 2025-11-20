<?php
// Resolve paths relative to 'src' directory
if (!defined('SMS_API_TOKEN')) {
    require_once __DIR__ . '/../config.php';
}
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../phpmailer/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class UserManager {
    private $conn;
    private $api_token = SMS_API_TOKEN;
    private $api_send_otp_url = SMS_OTP_URL;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->getConnection();
    }

    // --- Authentication Methods ---

    public function login($username, $password) {
        try {
            $stmt = $this->conn->prepare("CALL UserLogin(?)");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            if ($user && password_verify($password, $user['password'])) {
                return $user;
            }
            return false;
        } catch (PDOException $e) {
            error_log("Login Error: " . $e->getMessage());
            return false;
        }
    }

    public function logLoginAttempt($username_attempt, $status, $device_type) {
        try {
            // First, try to get the user ID if they exist
            $stmt = $this->conn->prepare("CALL UserLogin(?)");
            $stmt->execute([$username_attempt]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            $user_id_to_log = $user ? $user['user_id'] : null;

            $log_stmt = $this->conn->prepare("CALL LogLoginAttempt(?, ?, ?, ?)");
            $log_stmt->execute([$user_id_to_log, $username_attempt, $status, $device_type]);
            $log_stmt->closeCursor();
        } catch (PDOException $e) {
            error_log("Failed to log login attempt: " . $e->getMessage());
        }
    }

    // --- User Management Methods ---

    public function getAllUsers() {
        try {
            $stmt = $this->conn->prepare("CALL AdminGetAllUsers()");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Get Users Error: " . $e->getMessage());
            return [];
        }
    }

    public function getUserById($user_id) {
        try {
            $stmt = $this->conn->prepare("CALL UserFindById(?)");
            $stmt->execute([$user_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return null;
        }
    }

    public function createUser($username, $password, $role, $email, $phone) {
        if (empty($email)) $email = null;

        try {
            $check = $this->conn->prepare("CALL UserCheckAvailability(?, ?, ?)");
            $check->execute([$username, $email, $phone]);
            
            if ($check->rowCount() > 0) {
                $check->closeCursor();
                return "Error: Username, Email, or Phone Number already exists.";
            }
            $check->closeCursor();

            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $this->conn->prepare("CALL UserCreateAccount(?, ?, ?, ?, ?)");
            $stmt->execute([$username, $hashed_password, $role, $email, $phone]);
            return true;
        } catch (PDOException $e) {
            if (isset($e->errorInfo[1]) && $e->errorInfo[1] == 1062) {
                return "Error: Duplicate entry found.";
            }
            return "Database error: " . $e->getMessage();
        }
    }

    public function updateUser($user_id, $username, $password, $role, $email, $phone) {
        if (empty($email)) $email = null;

        try {
            $check = $this->conn->prepare("CALL UserCheckAvailabilityForUpdate(?, ?, ?, ?)");
            $check->execute([$user_id, $username, $email, $phone]);

            if ($check->rowCount() > 0) {
                $check->closeCursor();
                return "Error: Username, Email, or Phone Number is already taken by another user.";
            }
            $check->closeCursor();

            $hashed_password = '';
            if (!empty($password)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            }

            $stmt = $this->conn->prepare("CALL AdminUpdateUser(?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $username, $hashed_password, $role, $email, $phone]);
            return true;
        } catch (PDOException $e) {
            return "Database error: " . $e->getMessage();
        }
    }

    public function deleteUser($user_id) {
        try {
            $stmt = $this->conn->prepare("CALL AdminDeleteUser(?)");
            $stmt->execute([$user_id]);
            return true;
        } catch (PDOException $e) {
            error_log("Error deleting user: " . $e->getMessage());
            return false;
        }
    }

    // --- Password Reset & OTP Methods ---

    public function requestEmailReset($email) {
        try {
            $stmt = $this->conn->prepare("SELECT user_id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            if ($user) {
                $user_id = $user['user_id'];
                $otp = (string)rand(100000, 999999);

                // Direct insert for OTP tracking
                $stmt = $this->conn->prepare("INSERT INTO password_resets (user_id, reset_method, otp_code, expiration, used) VALUES (?, 'email_token', ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), 0)");
                $stmt->execute([$user_id, $otp]);
                $stmt->closeCursor();

                $this->sendEmailOTP($email, $otp);
                return $otp;
            }
            return 'USER_NOT_FOUND';
        } catch (Exception $e) {
            return 'EMAIL_FAILED';
        }
    }

    private function sendEmailOTP($email, $otp) {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'Breadly Password Reset OTP';
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #ddd;'>
                <h2 style='color: #d97706;'>Password Reset Request</h2>
                <p>You requested a password reset for your Breadly account.</p>
                <p>Your Verification Code is:</p>
                <h1 style='font-size: 32px; letter-spacing: 5px; color: #333;'>$otp</h1>
                <p>This code expires in 5 minutes.</p>
            </div>
        ";
        $mail->AltBody = "Your Password Reset Code is: $otp";
        $mail->send();
    }

    public function requestPhoneReset($phone_number) {
        try {
            $stmt = $this->conn->prepare("CALL UserFindByPhone(?)");
            $stmt->execute([$phone_number]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            if (!$user) return 'USER_NOT_FOUND';

            $otp = (string)rand(100000, 999999);
            $user_id = $user['user_id'];
            $expiration_time = date('Y-m-d H:i:s', time() + 300); // 5 minutes

            $stmt = $this->conn->prepare("CALL UserStorePhoneOTP(?, ?, ?)");
            $stmt->execute([$user_id, $otp, $expiration_time]);
            $stmt->closeCursor();

            $formatted_phone = $this->formatPhoneNumberForAPI($phone_number);

            // Send SMS via API
            $data = [
                'api_token' => $this->api_token,
                'message' => "Your BREADLY password reset code is: $otp. It will expire in 5 minutes.",
                'phone_number' => $formatted_phone
            ];

            $ch = curl_init($this->api_send_otp_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code >= 200 && $http_code < 300) {
                return $otp;
            } else {
                error_log("SMS API Error: " . $response);
                return 'SMS_FAILED';
            }
        } catch (Exception $e) {
            return 'SMS_FAILED';
        }
    }

    public function resetPassword($token_or_otp, $new_password) {
        try {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $this->conn->prepare("CALL UserResetPassword(?, ?)");
            $stmt->execute([$token_or_otp, $hashed_password]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            return ($result && $result['status'] === 'Success');
        } catch (PDOException $e) {
            return false;
        }
    }

    // --- Settings & History ---

    public function getUserSettings($user_id) {
        try {
            $stmt = $this->conn->prepare("CALL AdminGetMySettings(?)");
            $stmt->execute([$user_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    public function updateMySettings($user_id, $phone_number, $enable_daily_report) {
        try {
            $stmt = $this->conn->prepare("CALL AdminUpdateMySettings(?, ?, ?)");
            $stmt->execute([$user_id, $phone_number, $enable_daily_report]);
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    public function getLoginHistory() {
        try {
            $stmt = $this->conn->prepare("CALL ReportGetLoginHistory()");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    // --- Helpers ---

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
}
?>