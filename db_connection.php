<?php
// --- MODIFIED ---
// Include the secure config file located one level up.
require_once __DIR__ . '../config.php';

class Database {
    // --- MODIFIED: Use constants from config.php ---
    private $host = 'localhost';
    private $db_name = 'bakery';
    private $username = 'root';
    private $password = '';
    private $conn;

    public function __construct() {
        $this->connect();
    }

    private function connect() {
        try {
            $this->conn = new PDO(
                "mysql:host={$this->host};dbname={$this->db_name};charset=utf8mb4",
                $this->username,
                $this->password 
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            // --- MODIFIED: Harden error reporting ---
            // Log the real error for the developer (to the server's error log)
            error_log("Database Connection Failed: " . $e->getMessage());
            // Show a generic message to the user
            die("A critical database connection error occurred. Please contact support.");
            // --- END MODIFICATION ---
        }
    }

    public function getConnection() {
        return $this->conn;
    }
}
?>