<?php
// Force show errors for this test
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h2>SMS API Connection Test</h2>";

$url = 'https://sms.iprogtech.com/api/v1/otp/send_otp';
$token = '4882e7a9d4704d5afc136eebb463d298d1f15c20'; // Your token from config.php
$phone = '639123456789'; // Replace with your real test number (format 639...)

$data = [
    'api_token' => $token,
    'message'   => 'Test message from Hostinger',
    'phone_number' => $phone
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
// curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Uncomment if you want to test SSL bypass

$response = curl_exec($ch);
$error = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($error) {
    echo "<p style='color:red'><strong>CURL Error:</strong> " . $error . "</p>";
} else {
    echo "<p><strong>HTTP Status Code:</strong> " . $http_code . "</p>";
    echo "<p><strong>API Response:</strong> " . htmlspecialchars($response) . "</p>";
}
?>