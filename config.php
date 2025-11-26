<?php
date_default_timezone_set('Asia/Manila');

// --- Database Configuration ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'bakery');
define('DB_USER', 'root');
define('DB_PASS', '');

// --- SMS API Configuration ---
define('SMS_API_TOKEN', '4882e7a9d4704d5afc136eebb463d298d1f15c20');
define('SMS_OTP_URL', 'https://sms.iprogtech.com/api/v1/otp/send_otp');
define('SMS_SEND_URL', 'https://sms.iprogtech.com/api/v1/sms_messages');

// --- SMTP Configuration (PHPMailer) ---
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_USER', 'breadlysystem@gmail.com');
define('SMTP_PASS', 'uegm mqwq xpkq rpwg');
define('SMTP_PORT', 587);
define('SMTP_FROM_EMAIL', 'no-reply@breadly.com');
define('SMTP_FROM_NAME', 'Breadly Bakery System');

// --- Error Reporting ---
// Set to '0' for production, '1' for development
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL); // Capture all errors in logs even if not displayed