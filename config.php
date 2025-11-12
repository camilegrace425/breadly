<?php
// --- NEW FILE ---
// Store this file one directory ABOVE your 'breadly' web root for security.
// Database Credentials
define('DB_HOST', 'localhost');
define('DB_NAME', 'bakery');
define('DB_USER', 'root');
define('DB_PASS', '');

// SMS API Credentials
define('SMS_API_TOKEN', '4882e7a9d4704d5afc136eebb463d298d1f15c20');
define('SMS_OTP_URL', 'https://sms.iprogtech.com/api/v1/otp/send_otp');
define('SMS_SEND_URL', 'https://sms.iprogtech.com/api/v1/sms_messages');

// Set error reporting for production
// Comment out the 'error_reporting(0)' line while developing.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
// Optional: Define a path for your error log
// ini_set('error_log', __DIR__ . '/error.log');
error_reporting(0); // Turn off error display to users

?>