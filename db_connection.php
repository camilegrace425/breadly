<?php

$configPath = __DIR__ . '/config.php';

if (file_exists($configPath)) {
    require_once $configPath;
} else {
    error_log("CRITICAL: config.php not found at " . $configPath);
    die("System Error: Configuration file missing.");
}

class Database {
    public $conn;

    public function __construct() {
        $this->connect();
    }

    private function connect() {
        $this->conn = null;
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];

            $this->conn = new PDO($dsn, DB_USER, DB_PASS, $options);
            
            $this->conn->exec("SET time_zone = '+08:00';");

        } catch (PDOException $e) {
            error_log("Database Connection Failed: " . $e->getMessage());
            die("Database Connection Error. Check error logs.");
        }
    }

    public function getConnection() {
        return $this->conn;
    }
}
?>