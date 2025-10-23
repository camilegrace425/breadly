<?php
require_once '../db_connection.php';

class UserManager {
    private $conn;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->getConnection();
    }

    // Creates a new user account (Manager or Cashier) and hashes the password.
    public function createAccount($username, $plain_password, $role, $email, $phone_number = null) {
        
        $hashed_password = password_hash($plain_password, PASSWORD_DEFAULT);

        if ($role !== 'manager' && $role !== 'cashier') {
            return false;
        }

        try {
            // UPDATED: Changed from INSERT query to stored procedure
            $stmt = $this->conn->prepare("CALL UserCreateAccount(?, ?, ?, ?, ?)");
            return $stmt->execute([$username, $hashed_password, $role, $email, $phone_number]);
        } catch (PDOException $e) {
            return false;
        }
    }

    // Authenticates a user by checking username and verifying the hashed password.
    public function login($username, $plain_password) {
        
        // UPDATED: Changed from SELECT query to stored procedure
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
        // UPDATED: Changed from SELECT query to stored procedure
        $stmt = $this->conn->prepare("CALL UserFindById(?)");
        $stmt->execute([$user_id]);
        return $stmt->fetch();
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

    // Calls the 'UserRequestPasswordResetOTP' stored procedure for phone.
    public function requestPhoneReset($phone_number) {
        try {
            $stmt = $this->conn->prepare("CALL UserRequestPasswordResetOTP(?)");
            $stmt->execute([$phone_number]);
            $result = $stmt->fetch(); 
            
            if ($result && $result['otp']) {
                return $result['otp'];
            }
            return false;
        } catch (PDOException $e) {
            return false;
        }
    }

    // Finalizes the password reset using either a token or OTP.
    public function resetPassword($token_or_otp, $new_plain_password) {
        
        $new_hashed_password = password_hash($new_plain_password, PASSWORD_DEFAULT);

        try {
            $stmt = $this->conn->prepare("CALL UserResetPassword(?, ?)");
            $stmt->execute([$token_or_otp, $new_hashed_password]);
            $result = $stmt->fetch();

            return ($result && $result['status'] === 'Success');
        } catch (PDOException $e) {
            return false;
        }
    }
}
?>