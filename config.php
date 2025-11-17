<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'bakery');
define('DB_USER', 'root');
define('DB_PASS', '');

define('SMS_API_TOKEN', '4882e7a9d4704d5afc136eebb463d298d1f15c20');
define('SMS_OTP_URL', 'https://sms.iprogtech.com/api/v1/otp/send_otp');
define('SMS_SEND_URL', 'https://sms.iprogtech.com/api/v1/sms_messages');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(0);

?>