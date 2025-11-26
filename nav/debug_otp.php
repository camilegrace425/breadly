<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once '../db_connection.php';

echo "<h1>OTP & Time Debugger</h1>";

try {
    $db = new Database();
    $conn = $db->getConnection();

    // 1. Check Database Time
    $stmt = $conn->query("SELECT NOW() as db_time, @@session.time_zone as db_timezone");
    $timeData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<h3>1. Time Synchronization</h3>";
    echo "<ul>";
    echo "<li><strong>PHP Time (Manila):</strong> " . date('Y-m-d H:i:s') . "</li>";
    echo "<li><strong>Database Time (NOW):</strong> " . $timeData['db_time'] . " <span style='color:gray'>(Should match PHP time)</span></li>";
    echo "<li><strong>DB Timezone:</strong> " . $timeData['db_timezone'] . "</li>";
    echo "</ul>";

    // 2. Check Last 5 Codes
    echo "<h3>2. Recent Reset Codes</h3>";
    $stmt = $conn->query("SELECT * FROM password_resets ORDER BY reset_id DESC LIMIT 5");
    $codes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($codes)) {
        echo "<p>No codes found in the database.</p>";
    } else {
        echo "<table border='1' cellpadding='5' style='border-collapse:collapse;'>";
        echo "<tr style='background:#eee'><th>ID</th><th>Code</th><th>Expiration</th><th>Used?</th><th>Status (vs DB Time)</th></tr>";
        
        foreach ($codes as $code) {
            $isExpired = strtotime($code['expiration']) < strtotime($timeData['db_time']);
            $status = "";
            
            if ($code['used'] == 1) {
                $status = "<strong style='color:red'>ALREADY USED</strong>";
            } elseif ($isExpired) {
                $status = "<strong style='color:orange'>EXPIRED</strong>";
            } else {
                $status = "<strong style='color:green'>VALID</strong>";
            }

            echo "<tr>";
            echo "<td>" . $code['reset_id'] . "</td>";
            echo "<td>" . htmlspecialchars($code['otp_code'] ?? $code['reset_token']) . "</td>";
            echo "<td>" . $code['expiration'] . "</td>";
            echo "<td>" . ($code['used'] ? 'Yes' : 'No') . "</td>";
            echo "<td>" . $status . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }

} catch (Exception $e) {
    echo "<h3 style='color:red'>Error: " . $e->getMessage() . "</h3>";
}
?>