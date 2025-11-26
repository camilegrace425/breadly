<?php
require_once '../db_connection.php'; 
/**
 * Abstract base class for all manager classes.
 * It handles the initialization of the PDO database connection.
 */
abstract class AbstractManager {
    protected $conn;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->getConnection();
    }
}
?>