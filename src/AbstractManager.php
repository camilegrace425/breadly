<?php
require_once '../db_connection.php'; 
abstract class AbstractManager {
    protected $conn;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->getConnection();
    }
}
?>