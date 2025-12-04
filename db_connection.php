<?php
// File Location: breadly/db_connection.php

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

    // Establish the database connection
    private function connect() {
        $this->conn = null;
        try {
            // Using constants defined in config.php
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            $this->conn = new PDO($dsn, DB_USER, DB_PASS, $options);
            
            // Force Philippine Timezone (+08:00)
            $this->conn->exec("SET time_zone = '+08:00';");

        } catch (PDOException $e) {
            error_log("Database Connection Failed: " . $e->getMessage());
            die("Database Connection Error. Check error logs.");
        }
    }

    // Retrieve the connection object
    public function getConnection() {
        return $this->conn;
    }
}
?>