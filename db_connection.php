<?php
// FIX: Correctly locating config.php regardless of where this file is included from
$configPath = __DIR__ . '/config.php';

if (file_exists($configPath)) {
    require_once $configPath;
} else {
    // Fallback logging if file is missing
    error_log("CRITICAL: config.php not found at " . $configPath);
    die("System Error: Configuration file missing.");
}

class Database {
    private $host = DB_HOST;
    private $db_name = DB_NAME;
    private $username = DB_USER;
    private $password = DB_PASS;
    public $conn;

    public function __construct() {
        $this->connect();
    }

    private function connect() {
        $this->conn = null;
        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
            
            // --- FIX: FORCE PHILIPPINE TIMEZONE (+08:00) ---
            // This ensures NOW() and CURRENT_TIMESTAMP match your local time
            $this->conn->exec("SET time_zone = '+08:00';");

        } catch (PDOException $e) {
            // Log the error but don't show password to user
            error_log("Database Connection Failed: " . $e->getMessage());
            die("Database Connection Error. Check error logs.");
        }
    }

    public function getConnection() {
        return $this->conn;
    }
}
?>